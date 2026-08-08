<?php
/**
 * Operating hours.
 *
 * A venue that opens at noon has no use for lightning alerts at 4am, and on a
 * metered weather API those overnight polls are the bulk of the bill. This
 * confines monitoring to the hours the site is actually occupied, with a
 * buffer either side because staff arrive before opening and are still
 * clearing up afterwards.
 *
 * All times are wall-clock in the venue's own time zone, so the schedule keeps
 * meaning the same thing across a daylight-saving change.
 */
declare(strict_types=1);

namespace StormWatch;

final class Schedule
{
    /** Storage order, and the order the settings screen renders. */
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public const DAY_NAMES = [
        'mon' => 'Monday',
        'tue' => 'Tuesday',
        'wed' => 'Wednesday',
        'thu' => 'Thursday',
        'fri' => 'Friday',
        'sat' => 'Saturday',
        'sun' => 'Sunday',
    ];

    public static function isEnabled(): bool
    {
        return Settings::getBool('monitor_schedule_enabled');
    }

    /**
     * The stored schedule, with every day present and validated.
     *
     * @return array<string,array{closed:bool,open:string,close:string}>
     */
    public static function all(): array
    {
        $decoded = json_decode(Settings::getString('monitor_schedule'), true);
        $stored = is_array($decoded) ? $decoded : [];
        $out = [];

        foreach (self::DAYS as $day) {
            $entry = is_array($stored[$day] ?? null) ? $stored[$day] : [];
            $closed = !empty($entry['closed']);
            $open = self::normaliseTime((string) ($entry['open'] ?? '12:00'), '12:00');
            $close = self::normaliseTime((string) ($entry['close'] ?? '22:00'), '22:00');
            $out[$day] = ['closed' => $closed, 'open' => $open, 'close' => $close];
        }

        return $out;
    }

    /**
     * @param array<string,array<string,mixed>> $input
     */
    public static function save(array $input): void
    {
        $clean = [];
        foreach (self::DAYS as $day) {
            $entry = is_array($input[$day] ?? null) ? $input[$day] : [];
            $clean[$day] = [
                'closed' => !empty($entry['closed']),
                'open' => self::normaliseTime((string) ($entry['open'] ?? '12:00'), '12:00'),
                'close' => self::normaliseTime((string) ($entry['close'] ?? '22:00'), '22:00'),
            ];
        }
        Settings::putRaw('monitor_schedule', (string) json_encode($clean));
    }

    /** Is the venue being monitored right now? */
    public static function isMonitoring(?int $timestamp = null): bool
    {
        if (!self::isEnabled()) {
            return true;
        }
        return self::windowContaining($timestamp ?? time()) !== null;
    }

    /**
     * The monitoring window covering an instant, buffers included.
     *
     * @return array{start:int,end:int,day:string}|null
     */
    public static function windowContaining(int $timestamp): ?array
    {
        foreach (self::windowsAround($timestamp) as $window) {
            if ($timestamp >= $window['start'] && $timestamp < $window['end']) {
                return $window;
            }
        }
        return null;
    }

    /** When monitoring next starts, or null if it is already running. */
    public static function nextStart(?int $timestamp = null): ?int
    {
        $timestamp ??= time();
        if (!self::isEnabled() || self::windowContaining($timestamp) !== null) {
            return null;
        }

        // Look far enough ahead to survive a week of closed days.
        for ($dayOffset = 0; $dayOffset <= 8; $dayOffset++) {
            foreach (self::windowsAround($timestamp + ($dayOffset * 86400)) as $window) {
                if ($window['start'] > $timestamp) {
                    return $window['start'];
                }
            }
        }
        return null;
    }

    /** When the current monitoring window ends, or null if not monitoring. */
    public static function currentEnd(?int $timestamp = null): ?int
    {
        $timestamp ??= time();
        $window = self::windowContaining($timestamp);
        return $window === null ? null : $window['end'];
    }

    /**
     * Candidate windows near an instant. Yesterday is included because a
     * closing time past midnight belongs to the previous day's window.
     *
     * @return array<int,array{start:int,end:int,day:string}>
     */
    private static function windowsAround(int $timestamp): array
    {
        $tz = self::timezone();
        $schedule = self::all();
        $lead = max(0, Settings::getInt('monitor_lead_minutes')) * 60;
        $trail = max(0, Settings::getInt('monitor_trail_minutes')) * 60;

        $windows = [];
        foreach ([-1, 0, 1] as $offset) {
            $date = (new \DateTimeImmutable('@' . $timestamp))
                ->setTimezone($tz)
                ->modify(sprintf('%+d day', $offset));

            $day = strtolower($date->format('D'));
            $entry = $schedule[$day] ?? null;
            if ($entry === null || $entry['closed']) {
                continue;
            }

            [$openHour, $openMinute] = self::split($entry['open']);
            [$closeHour, $closeMinute] = self::split($entry['close']);

            $open = $date->setTime($openHour, $openMinute, 0);
            $close = $date->setTime($closeHour, $closeMinute, 0);
            if ($close <= $open) {
                // Closing after midnight belongs to the following day.
                $close = $close->modify('+1 day');
            }

            $windows[] = [
                'start' => $open->getTimestamp() - $lead,
                'end' => $close->getTimestamp() + $trail,
                'day' => $day,
            ];
        }

        return $windows;
    }

    /** A one-line summary for the settings screen and the dashboard. */
    public static function describe(): string
    {
        if (!self::isEnabled()) {
            return 'Monitoring around the clock.';
        }

        $schedule = self::all();
        $open = array_filter($schedule, static fn(array $d): bool => !$d['closed']);
        if ($open === []) {
            return 'Every day is marked closed, so nothing is being monitored.';
        }

        // Group consecutive days that share the same hours.
        $groups = [];
        foreach (self::DAYS as $day) {
            $entry = $schedule[$day];
            $key = $entry['closed'] ? 'closed' : $entry['open'] . '-' . $entry['close'];
            if ($groups !== [] && end($groups)['key'] === $key) {
                $groups[count($groups) - 1]['days'][] = $day;
                continue;
            }
            $groups[] = ['key' => $key, 'days' => [$day], 'entry' => $entry];
        }

        $parts = [];
        foreach ($groups as $group) {
            $label = count($group['days']) === 1
                ? ucfirst($group['days'][0])
                : ucfirst($group['days'][0]) . '–' . ucfirst((string) end($group['days']));
            $parts[] = $group['entry']['closed']
                ? $label . ' closed'
                : sprintf('%s %s–%s', $label, self::pretty($group['entry']['open']), self::pretty($group['entry']['close']));
        }

        $lead = Settings::getInt('monitor_lead_minutes');
        $trail = Settings::getInt('monitor_trail_minutes');
        $buffer = ($lead > 0 || $trail > 0)
            ? sprintf(', plus %d min before and %d min after', $lead, $trail)
            : '';

        return implode(', ', $parts) . $buffer . '.';
    }

    public static function timezone(): \DateTimeZone
    {
        try {
            return new \DateTimeZone(Settings::getString('timezone') ?: 'UTC');
        } catch (\Throwable $e) {
            return new \DateTimeZone('UTC');
        }
    }

    /** Hours per week the schedule covers, used for the cost projection. */
    public static function hoursPerWeek(): float
    {
        if (!self::isEnabled()) {
            return 24 * 7;
        }
        $lead = max(0, Settings::getInt('monitor_lead_minutes'));
        $trail = max(0, Settings::getInt('monitor_trail_minutes'));

        $total = 0.0;
        foreach (self::all() as $entry) {
            if ($entry['closed']) {
                continue;
            }
            [$oh, $om] = self::split($entry['open']);
            [$ch, $cm] = self::split($entry['close']);
            $minutes = (($ch * 60) + $cm) - (($oh * 60) + $om);
            if ($minutes <= 0) {
                $minutes += 24 * 60;
            }
            $total += ($minutes + $lead + $trail) / 60;
        }
        return min(24 * 7, $total);
    }

    public static function pretty(string $time): string
    {
        [$hour, $minute] = self::split($time);
        $suffix = $hour < 12 ? 'am' : 'pm';
        $display = $hour % 12;
        if ($display === 0) {
            $display = 12;
        }
        return $minute === 0
            ? sprintf('%d%s', $display, $suffix)
            : sprintf('%d:%02d%s', $display, $minute, $suffix);
    }

    /** @return array{0:int,1:int} */
    private static function split(string $time): array
    {
        $parts = explode(':', $time);
        return [
            max(0, min(23, (int) ($parts[0] ?? 0))),
            max(0, min(59, (int) ($parts[1] ?? 0))),
        ];
    }

    private static function normaliseTime(string $value, string $fallback): string
    {
        if (!preg_match('/^\s*(\d{1,2}):(\d{2})\s*$/', $value, $m)) {
            return $fallback;
        }
        $hour = (int) $m[1];
        $minute = (int) $m[2];
        if ($hour > 23 || $minute > 59) {
            return $fallback;
        }
        return sprintf('%02d:%02d', $hour, $minute);
    }
}

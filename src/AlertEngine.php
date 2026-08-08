<?php
/**
 * The state machine that decides when people get told.
 *
 * Levels are clear → watch → warning. A warning is raised the moment a strike
 * lands inside the alert radius, and it is only lifted once the configured
 * all-clear period has passed with nothing new inside that radius — the
 * "wait 30 minutes after the last strike" rule that lightning safety guidance
 * is built around.
 *
 * evaluate() is idempotent and safe to call from every cron tick: it works
 * from what is in the database, and it records what it has already announced
 * so nothing is sent twice.
 */
declare(strict_types=1);

namespace StormWatch;

use StormWatch\Notifiers\EmailNotifier;
use StormWatch\Notifiers\SlackNotifier;

final class AlertEngine
{
    public const LEVEL_CLEAR = 'clear';
    public const LEVEL_WATCH = 'watch';
    public const LEVEL_WARNING = 'warning';

    /**
     * Recompute the alert level and send whatever notifications that implies.
     *
     * @param bool $notify set false to update the state without notifying
     * @return array<string,mixed> the current state, as the dashboard sees it
     */
    public static function evaluate(bool $notify = true): array
    {
        // tick.php and worker.php hold separate locks and can overlap. Without
        // a lock here they could both see "not yet announced" and each send the
        // same alert. Losing the race means another process is already on it.
        $lock = $notify ? self::acquireLock(3.0) : null;
        if ($notify && $lock === null) {
            // Someone else is mid-decision and has been for a while; their run
            // covers this moment, so report the state rather than duplicate it.
            return self::publicState();
        }

        try {
            $outcome = self::decide($notify);
        } finally {
            if ($lock !== null) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }

        // Delivery happens outside the lock. Slack and SMTP can take seconds,
        // and the decision is already durable — no other process will send
        // this alert — so there is nothing to gain by holding it.
        if ($outcome['alert'] !== null) {
            if ($outcome['muted']) {
                Events::log(
                    'alert.suppressed',
                    Events::SEVERITY_WARNING,
                    sprintf(
                        'Alerts are muted until %s UTC — "%s" was not sent.',
                        gmdate('H:i', $outcome['muted_until']),
                        $outcome['alert']->title
                    ),
                    ['kind' => $outcome['alert']->kind]
                );
            } else {
                self::dispatch($outcome['alert']);
            }
        }

        return $outcome['state'];
    }

    /**
     * Take the alert lock, waiting briefly for it. The critical section is a
     * handful of queries, so a short wait beats returning a stale answer.
     *
     * @return resource|null
     */
    private static function acquireLock(float $waitSeconds = 0.0)
    {
        $dir = SW_DATA . '/locks';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $handle = @fopen($dir . '/alerts.lock', 'c');
        if ($handle === false) {
            // No writable lock file: carry on rather than stop alerting.
            return null;
        }
        $deadline = microtime(true) + max(0.0, $waitSeconds);
        do {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return $handle;
            }
            usleep(25000);
        } while (microtime(true) < $deadline);

        fclose($handle);
        return null;
    }

    /**
     * Work out the level, decide whether anything needs announcing, and record
     * that decision. Sends nothing itself.
     *
     * @return array{alert:Alert|null,muted:bool,muted_until:int,state:array<string,mixed>}
     */
    private static function decide(bool $notify): array
    {
        $state = self::state();
        $now = time();

        $alertRadius = Settings::getFloat('alert_radius_mi');
        $watchRadius = Settings::getFloat('watch_radius_mi');
        $window = max(60, Settings::getInt('all_clear_minutes') * 60);

        $lastInAlert = Strikes::latestWithin($alertRadius, $window);
        $lastInWatch = Strikes::latestWithin($watchRadius, $window);
        $nearest = Strikes::nearest($window);

        if ($lastInAlert !== null) {
            $level = self::LEVEL_WARNING;
        } elseif ($lastInWatch !== null) {
            $level = self::LEVEL_WATCH;
        } else {
            $level = self::LEVEL_CLEAR;
        }

        $previousLevel = (string) $state['level'];
        $nearestMi = $nearest !== null ? (float) $nearest['distance_mi'] : null;
        $nearestBearing = $nearest !== null ? (float) $nearest['bearing_deg'] : null;
        $nearestAt = $nearest !== null ? (int) $nearest['struck_at'] : null;

        $changes = [
            'level' => $level,
            'nearest_mi' => $nearestMi,
            'nearest_at' => $nearestAt,
        ];
        if ($level !== $previousLevel) {
            $changes['since'] = $now;
        }

        $muted = isset($state['muted_until']) && (int) $state['muted_until'] > $now;
        $notifiedLevel = $state['notified_level'] !== null ? (string) $state['notified_level'] : self::LEVEL_CLEAR;
        $notifiedAt = (int) ($state['notified_at'] ?? 0);
        $notifiedNearest = $state['notified_nearest_mi'] !== null ? (float) $state['notified_nearest_mi'] : null;

        $alert = null;

        if ($level === self::LEVEL_WARNING && $notifiedLevel !== self::LEVEL_WARNING) {
            $alert = self::buildWarning($lastInAlert, $nearest, $alertRadius, $window);
        } elseif ($level === self::LEVEL_WATCH
            && $notifiedLevel === self::LEVEL_CLEAR
            && Settings::getBool('notify_watch')) {
            $alert = self::buildWatch($lastInWatch, $nearest, $watchRadius, $window);
        } elseif ($level === self::LEVEL_CLEAR && $notifiedLevel !== self::LEVEL_CLEAR) {
            $alert = Settings::getBool('notify_all_clear')
                ? self::buildAllClear($window)
                : null;
            // Reset the notified level either way, so the next storm alerts.
            $changes['notified_level'] = self::LEVEL_CLEAR;
            $changes['notified_nearest_mi'] = null;
            if ($alert === null) {
                $changes['notified_at'] = $now;
            }
        } elseif ($level === self::LEVEL_WARNING && $notifiedLevel === self::LEVEL_WARNING) {
            $alert = self::buildUpdateIfDue($now, $notifiedAt, $notifiedNearest, $nearest, $alertRadius, $window);
        }

        // Record the decision now, inside the lock. A muted alert counts as
        // announced too, so an expiring mute does not release a burst of
        // backdated alerts.
        if ($alert !== null && ($notify || $muted)) {
            $changes['notified_level'] = $alert->kind === Alert::KIND_ALL_CLEAR ? self::LEVEL_CLEAR : $level;
            $changes['notified_at'] = $now;
            $changes['notified_nearest_mi'] = $nearestMi;
        }

        self::updateState($changes);

        return [
            'alert' => $notify ? $alert : null,
            'muted' => $muted,
            'muted_until' => (int) ($state['muted_until'] ?? 0),
            'state' => self::publicState(),
        ];
    }

    /**
     * @param array<string,mixed>|null $lastInAlert
     * @param array<string,mixed>|null $nearest
     */
    private static function buildWarning(?array $lastInAlert, ?array $nearest, float $radius, int $window): Alert
    {
        $units = Settings::getString('units');
        $radiusText = Geo::formatDistance($radius, $units);
        $alert = new Alert(
            Alert::KIND_WARNING,
            sprintf('Lightning within %s of %s', $radiusText, Settings::getString('venue_name')),
            sprintf(
                'A strike has been detected inside the %s alert radius. Move activities indoors and hold until the all clear.',
                $radiusText
            )
        );
        self::attachStrike($alert, $nearest ?? $lastInAlert);
        $alert->strikeCount = Strikes::countWithin($radius, $window);
        $alert->details['All clear after'] = sprintf(
            '%d min with no strike inside %s',
            Settings::getInt('all_clear_minutes'),
            $radiusText
        );
        return $alert;
    }

    /**
     * @param array<string,mixed>|null $lastInWatch
     * @param array<string,mixed>|null $nearest
     */
    private static function buildWatch(?array $lastInWatch, ?array $nearest, float $radius, int $window): Alert
    {
        $units = Settings::getString('units');
        $alert = new Alert(
            Alert::KIND_WATCH,
            sprintf('Storm approaching %s', Settings::getString('venue_name')),
            sprintf(
                'Lightning has been detected inside the %s watch radius but is still outside the %s alert radius. Keep an eye on it.',
                Geo::formatDistance($radius, $units),
                Geo::formatDistance(Settings::getFloat('alert_radius_mi'), $units)
            )
        );
        self::attachStrike($alert, $nearest ?? $lastInWatch);
        $alert->strikeCount = Strikes::countWithin($radius, $window);
        return $alert;
    }

    private static function buildAllClear(int $window): Alert
    {
        $units = Settings::getString('units');
        $minutes = Settings::getInt('all_clear_minutes');
        $alert = new Alert(
            Alert::KIND_ALL_CLEAR,
            sprintf('All clear at %s', Settings::getString('venue_name')),
            sprintf(
                'No lightning inside the %s alert radius for %d minutes. Normal operations can resume.',
                Geo::formatDistance(Settings::getFloat('alert_radius_mi'), $units),
                $minutes
            )
        );
        $alert->strikeCount = Strikes::countWithin(Settings::getFloat('display_radius_mi'), $window);
        return $alert;
    }

    /**
     * While a warning is active, send an update when the re-alert interval has
     * elapsed or when the storm has moved meaningfully closer.
     *
     * @param array<string,mixed>|null $nearest
     */
    private static function buildUpdateIfDue(
        int $now,
        int $notifiedAt,
        ?float $notifiedNearest,
        ?array $nearest,
        float $radius,
        int $window
    ): ?Alert {
        if ($nearest === null) {
            return null;
        }
        $nearestMi = (float) $nearest['distance_mi'];
        $realertMinutes = Settings::getInt('realert_minutes');
        $closerDelta = Settings::getFloat('closer_delta_mi');

        $dueByTime = $realertMinutes > 0 && ($now - $notifiedAt) >= ($realertMinutes * 60);
        $dueByDistance = $closerDelta > 0
            && $notifiedNearest !== null
            && ($notifiedNearest - $nearestMi) >= $closerDelta;

        if (!$dueByTime && !$dueByDistance) {
            return null;
        }

        $units = Settings::getString('units');
        $alert = new Alert(
            Alert::KIND_UPDATE,
            sprintf('Lightning still active near %s', Settings::getString('venue_name')),
            $dueByDistance
                ? sprintf('The storm has moved closer — now %s from the venue.', Geo::formatDistance($nearestMi, $units))
                : sprintf('The %s alert radius is still active.', Geo::formatDistance($radius, $units))
        );
        self::attachStrike($alert, $nearest);
        $alert->strikeCount = Strikes::countWithin($radius, $window);
        return $alert;
    }

    /** @param array<string,mixed>|null $strike */
    private static function attachStrike(Alert $alert, ?array $strike): void
    {
        if ($strike === null) {
            return;
        }
        $alert->nearestMi = (float) $strike['distance_mi'];
        $alert->bearingDeg = (float) $strike['bearing_deg'];
        $alert->struckAt = (int) $strike['struck_at'];
    }

    /** Send an alert through every enabled channel and log the outcome. */
    public static function dispatch(Alert $alert): void
    {
        $severity = $alert->isCritical() ? Events::SEVERITY_CRITICAL : Events::SEVERITY_INFO;
        Events::log('alert.' . $alert->kind, $severity, $alert->title, [
            'summary' => $alert->summary,
            'nearest_mi' => $alert->nearestMi,
            'bearing_deg' => $alert->bearingDeg,
        ]);

        foreach ([SlackNotifier::class, EmailNotifier::class] as $notifier) {
            /** @var class-string<\StormWatch\Notifiers\NotifierInterface> $notifier */
            if (!$notifier::isEnabled()) {
                continue;
            }
            try {
                $result = $notifier::send($alert);
            } catch (\Throwable $e) {
                $result = ['ok' => false, 'message' => $e->getMessage()];
            }
            Events::log(
                $notifier::channel() . '.' . ($result['ok'] ? 'sent' : 'failed'),
                $result['ok'] ? Events::SEVERITY_INFO : Events::SEVERITY_ERROR,
                ($result['ok'] ? 'Delivered: ' : 'Delivery failed: ') . $result['message'],
                ['kind' => $alert->kind]
            );
        }
    }

    /** Suppress notifications for a number of minutes. */
    public static function mute(int $minutes): int
    {
        $minutes = max(1, min(720, $minutes));
        $until = time() + ($minutes * 60);
        self::updateState(['muted_until' => $until]);
        Events::log('alert.muted', Events::SEVERITY_WARNING, sprintf('Alerts muted for %d minutes.', $minutes), []);
        return $until;
    }

    public static function unmute(): void
    {
        self::updateState(['muted_until' => null]);
        Events::log('alert.unmuted', Events::SEVERITY_INFO, 'Alerts un-muted.', []);
    }

    /** @return array<string,mixed> */
    public static function state(): array
    {
        $row = Database::instance()->first('SELECT * FROM alert_state WHERE id = 1');
        if ($row === null) {
            Database::instance()->run(
                'INSERT INTO alert_state (id, level, since) VALUES (1, ?, ?)',
                [self::LEVEL_CLEAR, time()]
            );
            $row = Database::instance()->first('SELECT * FROM alert_state WHERE id = 1') ?? [];
        }
        return $row;
    }

    /**
     * The state as the dashboard and API present it.
     *
     * @return array<string,mixed>
     */
    public static function publicState(): array
    {
        $state = self::state();
        $now = time();
        $alertRadius = Settings::getFloat('alert_radius_mi');
        $window = max(60, Settings::getInt('all_clear_minutes') * 60);
        $lastInAlert = Strikes::latestWithin($alertRadius, $window);

        return [
            'level' => (string) $state['level'],
            'since' => (int) $state['since'],
            'nearest_mi' => $state['nearest_mi'] !== null ? round((float) $state['nearest_mi'], 2) : null,
            'nearest_at' => $state['nearest_at'] !== null ? (int) $state['nearest_at'] : null,
            'muted_until' => isset($state['muted_until']) && (int) $state['muted_until'] > $now
                ? (int) $state['muted_until']
                : null,
            'all_clear_at' => $lastInAlert !== null ? ((int) $lastInAlert['struck_at'] + $window) : null,
        ];
    }

    /** @param array<string,mixed> $changes */
    private static function updateState(array $changes): void
    {
        if ($changes === []) {
            return;
        }
        $sets = [];
        $params = [];
        foreach ($changes as $column => $value) {
            $sets[] = $column . ' = ?';
            $params[] = $value;
        }
        Database::instance()->run(
            'UPDATE alert_state SET ' . implode(', ', $sets) . ' WHERE id = 1',
            $params
        );
    }
}

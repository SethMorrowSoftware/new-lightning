<?php
/**
 * Job orchestration for the cron entry points.
 *
 * Two jobs:
 *  - tick()   runs every minute: polls a REST feed if one is due, advances the
 *             simulator, re-evaluates the alert state, trims old data.
 *  - worker() holds a Blitzortung WebSocket open for most of a minute.
 *
 * Both take a lock, so an overrunning job never stacks up behind itself — the
 * classic way a once-a-minute cron takes down a shared host.
 */
declare(strict_types=1);

namespace StormWatch;

use StormWatch\Providers\Blitzortung;
use StormWatch\Providers\Rest;
use StormWatch\Providers\Simulator;

final class Runner
{
    /** @var array<string,resource> */
    private static array $locks = [];

    /**
     * The every-minute job.
     *
     * @return array{ok:bool,job:string,ingested:int,message:string,skipped?:bool}
     */
    public static function tick(): array
    {
        $startedAt = time();
        if (!self::acquireLock('tick')) {
            return ['ok' => true, 'job' => 'tick', 'ingested' => 0, 'skipped' => true, 'message' => 'A previous tick is still running; skipped this one.'];
        }

        $provider = Settings::getString('provider');
        $ingested = 0;
        $messages = [];
        $ok = true;

        // Outside operating hours there is nobody to warn, and on a metered
        // feed those polls are most of the bill.
        if (!Schedule::isMonitoring()) {
            try {
                AlertEngine::standDownForClosedHours();
            } catch (\Throwable $e) {
                Events::log('system.error', Events::SEVERITY_ERROR, 'Stand-down failed: ' . $e->getMessage(), []);
            }
            self::releaseLock('tick');
            $next = Schedule::nextStart();
            $message = 'Outside operating hours; monitoring is paused'
                . ($next !== null ? ' until ' . self::localTime($next) : '') . '.';
            self::recordRun('tick', $provider, true, 0, $message, $startedAt);
            return ['ok' => true, 'job' => 'tick', 'ingested' => 0, 'skipped' => true, 'message' => $message];
        }

        try {
            if ($provider === 'rest' && self::restPollDue()) {
                $result = Rest::poll();
                $ingested += $result['ingested'];
                $messages[] = $result['message'];
                $ok = $result['ok'];
                self::recordRun('poll', 'rest', $result['ok'], $result['ingested'], $result['message'], $startedAt);
                if (!$result['ok']) {
                    self::reportSourceProblem('REST feed', $result['message']);
                }
            } elseif ($provider === 'simulator') {
                $result = Simulator::tick();
                $ingested += $result['ingested'];
                $messages[] = $result['message'];
                self::recordRun('poll', 'simulator', true, $result['ingested'], $result['message'], $startedAt);
            }

            // Always re-evaluate: the all-clear has to fire on a quiet feed too.
            AlertEngine::evaluate();

            // Housekeeping, cheap enough to do every minute.
            $pruned = Strikes::prune();
            if ($pruned > 0) {
                $messages[] = sprintf('Pruned %d expired strike(s).', $pruned);
            }
            if (random_int(1, 60) === 1) {
                Events::prune(30);
                self::pruneRuns();
            }
        } catch (\Throwable $e) {
            $ok = false;
            $messages[] = 'Tick failed: ' . $e->getMessage();
            Events::log('system.error', Events::SEVERITY_ERROR, 'Tick failed: ' . $e->getMessage(), []);
        } finally {
            self::releaseLock('tick');
        }

        $message = $messages === [] ? 'Nothing to do.' : implode(' ', $messages);
        self::recordRun('tick', $provider, $ok, $ingested, $message, $startedAt);

        return ['ok' => $ok, 'job' => 'tick', 'ingested' => $ingested, 'message' => $message];
    }

    /**
     * The streaming job, only meaningful for the Blitzortung provider.
     *
     * @return array{ok:bool,job:string,ingested:int,message:string,skipped?:bool}
     */
    public static function worker(): array
    {
        $startedAt = time();
        $provider = Settings::getString('provider');

        if ($provider !== 'blitzortung') {
            return [
                'ok' => true,
                'job' => 'worker',
                'ingested' => 0,
                'skipped' => true,
                'message' => sprintf('The %s provider does not use the streaming worker; nothing to do.', $provider),
            ];
        }

        if (!Schedule::isMonitoring()) {
            return [
                'ok' => true,
                'job' => 'worker',
                'ingested' => 0,
                'skipped' => true,
                'message' => 'Outside operating hours; the stream is not being opened.',
            ];
        }

        if (!self::acquireLock('worker')) {
            return ['ok' => true, 'job' => 'worker', 'ingested' => 0, 'skipped' => true, 'message' => 'A worker is already streaming; skipped this one.'];
        }

        try {
            $seconds = Settings::getInt('worker_seconds');
            $lastEvaluated = 0;

            // Evaluate as strikes arrive, not just at the end: a 50-second
            // delay on a lightning alert is 50 seconds too many.
            $result = Blitzortung::stream($seconds, static function () use (&$lastEvaluated): void {
                if (time() - $lastEvaluated >= 5) {
                    $lastEvaluated = time();
                    try {
                        AlertEngine::evaluate();
                    } catch (\Throwable $e) {
                        Events::log('system.error', Events::SEVERITY_ERROR, 'Alert evaluation failed mid-stream: ' . $e->getMessage(), []);
                    }
                }
            });

            AlertEngine::evaluate();
            self::recordRun('worker', 'blitzortung', $result['ok'], $result['ingested'], $result['message'], $startedAt);

            if (!$result['ok']) {
                self::reportSourceProblem('Blitzortung feed', $result['message']);
            }

            return ['ok' => $result['ok'], 'job' => 'worker', 'ingested' => $result['ingested'], 'message' => $result['message']];
        } catch (\Throwable $e) {
            self::recordRun('worker', 'blitzortung', false, 0, $e->getMessage(), $startedAt);
            Events::log('system.error', Events::SEVERITY_ERROR, 'Worker failed: ' . $e->getMessage(), []);
            return ['ok' => false, 'job' => 'worker', 'ingested' => 0, 'message' => $e->getMessage()];
        } finally {
            self::releaseLock('worker');
        }
    }

    /**
     * Health summary for the dashboard status line.
     *
     * @return array<string,mixed>
     */
    public static function status(): array
    {
        $provider = Settings::getString('provider');
        $lastTick = self::lastRun('tick');
        $lastIngestJob = $provider === 'blitzortung' ? 'worker' : 'poll';
        $lastIngest = self::lastRun($lastIngestJob);
        $now = time();

        $cronAge = $lastTick !== null ? $now - (int) $lastTick['started_at'] : null;
        // Two missed minutes is noise; five means something is wrong.
        $cronHealthy = $cronAge !== null && $cronAge < 300;

        if ($provider === 'relay') {
            $lastRelay = Database::instance()->first(
                "SELECT MAX(received_at) AS last_at FROM strikes WHERE source = 'relay'"
            );
            $lastRelayAt = isset($lastRelay['last_at']) && $lastRelay['last_at'] !== null ? (int) $lastRelay['last_at'] : null;
            $sourceHealthy = $lastRelayAt !== null && ($now - $lastRelayAt) < 900;
            $sourceMessage = $lastRelayAt === null
                ? 'No strikes have been relayed yet. Keep a dashboard tab open on a machine that stays on.'
                : sprintf('Last relayed strike %s ago.', self::humanAge($now - $lastRelayAt));
        } else {
            $sourceHealthy = $lastIngest !== null && (int) $lastIngest['ok'] === 1;
            $sourceMessage = $lastIngest !== null
                ? (string) $lastIngest['message']
                : 'This source has not run yet. Check that the cron jobs are installed.';
        }

        $monitoring = Schedule::isMonitoring();
        $nextStart = $monitoring ? null : Schedule::nextStart();

        return [
            'provider' => $provider,
            'monitoring' => $monitoring,
            'schedule_enabled' => Schedule::isEnabled(),
            'schedule_summary' => Schedule::describe(),
            'next_start' => $nextStart,
            'next_start_text' => $nextStart !== null ? self::localTime($nextStart) : null,
            'window_ends' => $monitoring ? Schedule::currentEnd() : null,
            'cron_healthy' => $cronHealthy,
            'cron_last_run' => $lastTick !== null ? (int) $lastTick['started_at'] : null,
            'cron_age_seconds' => $cronAge,
            'source_healthy' => $sourceHealthy,
            'source_message' => $sourceMessage,
            'source_last_run' => $lastIngest !== null ? (int) $lastIngest['started_at'] : null,
            'slack_ready' => \StormWatch\Notifiers\SlackNotifier::isEnabled(),
            'email_ready' => \StormWatch\Notifiers\EmailNotifier::isEnabled(),
        ];
    }

    /** @return array<string,mixed>|null */
    public static function lastRun(string $job): ?array
    {
        return Database::instance()->first(
            'SELECT * FROM runs WHERE job = ? ORDER BY id DESC LIMIT 1',
            [$job]
        );
    }

    public static function recordRun(
        string $job,
        string $provider,
        bool $ok,
        int $ingested,
        string $message,
        int $startedAt
    ): void {
        try {
            Database::instance()->run(
                'INSERT INTO runs (started_at, finished_at, job, provider, ok, ingested, message)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$startedAt, time(), $job, $provider, $ok ? 1 : 0, $ingested, mb_substr($message, 0, 1000)]
            );
        } catch (\Throwable $e) {
            error_log('[stormwatch] could not record run: ' . $e->getMessage());
        }
    }

    private static function pruneRuns(): void
    {
        Database::instance()->run('DELETE FROM runs WHERE started_at < ?', [time() - (7 * 86400)]);
    }

    /**
     * Poll slowly when the sky is clear and quickly when it is not.
     *
     * On a metered plan this is what makes the sums work: nearly all the time
     * there is nothing happening, so spending the allowance at a storm's pace
     * around the clock would exhaust it for no benefit.
     */
    public static function restPollInterval(): int
    {
        $idle = max(15, Settings::getInt('rest_poll_seconds'));
        $active = max(15, Settings::getInt('rest_active_poll_seconds'));

        try {
            $level = AlertEngine::publicState()['level'];
        } catch (\Throwable $e) {
            return $idle;
        }

        // A watch counts as active: that is precisely when the next strike
        // matters most, and it is the run-up to an alert.
        return $level === AlertEngine::LEVEL_CLEAR ? $idle : min($idle, $active);
    }

    private static function restPollDue(): bool
    {
        $interval = self::restPollInterval();
        $last = self::lastRun('poll');
        if ($last === null) {
            return true;
        }
        return (time() - (int) $last['started_at']) >= ($interval - 5);
    }

    /**
     * Tell the operator when a feed stops working — but only once per hour, so
     * a dead endpoint does not turn into a hundred Slack messages.
     */
    private static function reportSourceProblem(string $label, string $message): void
    {
        if (!Settings::getBool('notify_errors')) {
            return;
        }
        $lastReport = Database::instance()->first(
            "SELECT created_at FROM events WHERE type = 'system.source_error' ORDER BY id DESC LIMIT 1"
        );
        if ($lastReport !== null && (time() - (int) $lastReport['created_at']) < 3600) {
            return;
        }

        // Always record it — this is the evidence when someone asks why no
        // alerts arrived — but honour a mute for the outbound copy, so one
        // setting governs everything that would otherwise reach Slack.
        Events::log('system.source_error', Events::SEVERITY_ERROR, $label . ': ' . $message, []);

        if (AlertEngine::publicState()['muted_until'] !== null) {
            return;
        }

        $alert = new Alert(
            Alert::KIND_ERROR,
            sprintf('Storm Watch cannot reach the %s', $label),
            sprintf(
                'Lightning data has stopped arriving, so alerts for %s may not fire. Details: %s',
                Settings::getString('venue_name'),
                $message
            )
        );
        foreach ([\StormWatch\Notifiers\SlackNotifier::class, \StormWatch\Notifiers\EmailNotifier::class] as $notifier) {
            if (!$notifier::isEnabled()) {
                continue;
            }
            try {
                $notifier::send($alert);
            } catch (\Throwable $e) {
                // Nothing useful to do if the failure notice itself fails.
            }
        }
    }

    private static function lockFile(string $name): string
    {
        $dir = SW_DATA . '/locks';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . '/' . preg_replace('/[^a-z0-9_-]/i', '', $name) . '.lock';
    }

    public static function acquireLock(string $name): bool
    {
        $handle = @fopen(self::lockFile($name), 'c');
        if ($handle === false) {
            // Without a writable data directory we cannot lock; running is
            // still better than silently doing nothing.
            return true;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }
        self::$locks[$name] = $handle;
        return true;
    }

    public static function releaseLock(string $name): void
    {
        if (!isset(self::$locks[$name])) {
            return;
        }
        $handle = self::$locks[$name];
        flock($handle, LOCK_UN);
        fclose($handle);
        unset(self::$locks[$name]);
    }

    /** A timestamp in the venue's own time zone, for operator-facing text. */
    public static function localTime(int $timestamp): string
    {
        return (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone(Schedule::timezone())
            ->format('D g:ia');
    }

    public static function humanAge(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < 3600) {
            return (int) floor($seconds / 60) . 'm';
        }
        if ($seconds < 86400) {
            return (int) floor($seconds / 3600) . 'h';
        }
        return (int) floor($seconds / 86400) . 'd';
    }
}

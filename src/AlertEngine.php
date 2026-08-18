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
    /** Floor between distance-triggered updates when no repeat interval is set. */
    private const MIN_UPDATE_SECONDS = 300;

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
        $lock = null;
        if ($notify) {
            $lock = self::acquireLock(3.0);
            if ($lock === false) {
                // Another process holds the lock and has for a while; its run
                // covers this moment, so report state rather than duplicate.
                return self::publicState();
            }
            // $lock === null means locking is impossible here (no writable
            // data/locks). Carry on unlocked: a small risk of a duplicate
            // alert is vastly better than silently never alerting at all.
            if ($lock === null) {
                self::warnAboutMissingLock();
            }
        }

        try {
            $outcome = self::decide($notify);
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }

        // Delivery happens outside the lock. Slack and SMTP can take seconds,
        // and the decision is already durable — no other process will send
        // this alert — so there is nothing to gain by holding it.
        if ($outcome['alert'] !== null) {
            $delivery = self::dispatch($outcome['alert']);

            // The decision was recorded before delivery was attempted, which is
            // what stops two overlapping cron runs sending the same alert
            // twice. It also means an alert nobody could deliver is filed as
            // announced: a Slack outage or a refused SMTP login during the one
            // storm of the summer, and the venue is never told at all, because
            // the next tick sees the warning as already given.
            //
            // So when every enabled channel failed, put the announcement back
            // and let the next run try again. Only when *every* one failed —
            // a partial success has reached somebody, and repeating it would
            // be the alert spam the cooldown exists to prevent.
            if ($delivery['attempted'] > 0 && $delivery['delivered'] === 0) {
                // Only undo what this run wrote. Delivery takes seconds and the
                // lock is long released, so the streaming worker may have
                // evaluated and moved the announcement on in the meantime —
                // restoring blindly would erase a decision somebody else made.
                $current = self::state();
                if ((int) ($current['notified_at'] ?? 0) === $outcome['announced_stamp']) {
                    self::updateState($outcome['announced_before']);
                }
                Events::log(
                    'alert.undelivered',
                    Events::SEVERITY_ERROR,
                    'No alert channel accepted the ' . $outcome['alert']->kind
                        . ' notification, so it is being held for the next run rather than counted as sent.',
                    []
                );
            }
        } elseif (!empty($outcome['log_suppression'])) {
            Events::log(
                'alert.suppressed',
                Events::SEVERITY_WARNING,
                sprintf(
                    'Alerts are muted until %s UTC. The alert is being held, not dropped — it will be sent when the mute expires.',
                    gmdate('H:i', $outcome['muted_until'])
                ),
                []
            );
        }

        return $outcome['state'];
    }

    /**
     * Take the alert lock, waiting briefly for it. The critical section is a
     * handful of queries, so a short wait beats returning a stale answer.
     *
     * Three outcomes, and the difference matters:
     *   resource — the lock is held
     *   false    — someone else holds it; skip this run
     *   null     — locking is not possible here; proceed without it
     *
     * @return resource|false|null
     */
    private static function acquireLock(float $waitSeconds = 0.0)
    {
        $dir = SW_DATA . '/locks';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $handle = @fopen($dir . '/alerts.lock', 'c');
        if ($handle === false) {
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
        return false;
    }

    /** Say so once an hour, rather than failing quietly or filling the log. */
    private static function warnAboutMissingLock(): void
    {
        try {
            $last = Database::instance()->first(
                "SELECT created_at FROM events WHERE type = 'system.lock_unavailable' ORDER BY id DESC LIMIT 1"
            );
            if ($last !== null && (time() - (int) $last['created_at']) < 3600) {
                return;
            }
            Events::log(
                'system.lock_unavailable',
                Events::SEVERITY_WARNING,
                'Could not create a lock file in data/locks, so overlapping runs are not being coordinated. '
                . 'Alerts are still being sent. Make the data directory writable to remove the small risk of a duplicate alert.',
                []
            );
        } catch (\Throwable $e) {
            // Never let the warning path interfere with alerting.
        }
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

        $cooldownRadius = self::cooldownRadius();

        $previousLevel = (string) $state['level'];
        $notifiedLevel = $state['notified_level'] !== null ? (string) $state['notified_level'] : self::LEVEL_CLEAR;
        $notifiedAt = (int) ($state['notified_at'] ?? 0);

        // Once an all clear has gone out, that storm is closed: its strikes are
        // what the all clear was *about*, and they must never re-open it. Only
        // something that has arrived since can raise the next warning.
        //
        // Without this the lookback is the cooldown setting itself, which an
        // operator can lengthen whenever they like. Raising the hold from 30
        // minutes to four hours on a clear evening then reaches back over an
        // afternoon storm that is long over and already all-cleared, and posts
        // "move activities indoors" under an empty sky.
        //
        // The bound is on arrival, not on strike time, so a strike that merely
        // reached us late still alerts — that one is genuinely new information.
        $closedOutAt = ($notifiedLevel === self::LEVEL_CLEAR && $notifiedAt > 0) ? $notifiedAt : null;

        $lastInAlert = Strikes::latestWithin($alertRadius, $window, $closedOutAt);
        $lastInWatch = Strikes::latestWithin($watchRadius, $window, $closedOutAt);
        // The cooldown may be measured over a wider ring than the one that
        // triggers an alert, so a storm circling just outside the alert radius
        // does not produce a premature all clear. It also asks a different
        // question from the two above — "has the sky been quiet?" rather than
        // "is there anything new?" — so it reads everything on record.
        $lastInCooldown = Strikes::latestWithin(max($cooldownRadius, $alertRadius), $window);

        $nearest = Strikes::nearest($window);

        $muted = isset($state['muted_until']) && (int) $state['muted_until'] > $now;
        $notifiedNearest = $state['notified_nearest_mi'] !== null ? (float) $state['notified_nearest_mi'] : null;

        $wasElevated = $previousLevel === self::LEVEL_WARNING || $notifiedLevel === self::LEVEL_WARNING;

        if ($lastInAlert !== null) {
            $level = self::LEVEL_WARNING;
        } elseif ($wasElevated && $lastInCooldown !== null) {
            // Nothing inside the alert radius any more, but the cooldown has
            // not run out: stay in warning rather than sound the all clear.
            $level = self::LEVEL_WARNING;
        } elseif ($lastInWatch !== null) {
            $level = self::LEVEL_WATCH;
        } else {
            $level = self::LEVEL_CLEAR;
        }

        $nearestMi = $nearest !== null ? (float) $nearest['distance_mi'] : null;
        $nearestAt = $nearest !== null ? (int) $nearest['struck_at'] : null;

        $changes = [
            'level' => $level,
            'nearest_mi' => $nearestMi,
            'nearest_at' => $nearestAt,
        ];
        if ($level !== $previousLevel) {
            $changes['since'] = $now;
        }

        $alert = null;
        // Set when the decision resolves an announcement even if nothing is
        // sent — an all clear that is switched off still has to reset the
        // state, or the next storm would never alert.
        $resolvesTo = null;
        // Set on the run that stands a warning down, so the watch cooldown
        // below knows how long ago the venue was last told to go indoors.
        $warningCleared = false;
        $watchHeld = false;

        if ($level === self::LEVEL_WARNING && $notifiedLevel !== self::LEVEL_WARNING) {
            $alert = self::buildWarning($lastInAlert, $nearest, $alertRadius, $window);
        } elseif ($notifiedLevel === self::LEVEL_WARNING && $level !== self::LEVEL_WARNING) {
            // The cooldown has run out. Say so even when a storm is still being
            // tracked further out: staff were told to go indoors and are owed
            // the word that they can come back out.
            $alert = Settings::getBool('notify_all_clear')
                ? self::buildAllClear($window, $level, $nearest)
                : null;
            $resolvesTo = self::LEVEL_CLEAR;
            $warningCleared = true;
        } elseif ($level === self::LEVEL_WATCH
            && $notifiedLevel === self::LEVEL_CLEAR
            && Settings::getBool('notify_watch')) {
            // A storm rarely stops at the alert radius on its way out: it goes
            // on flashing in the watch ring for a while, which would post
            // "storm approaching" minutes after the all clear said it had
            // gone. Hold the watch until the departing storm has had time to
            // leave; a storm that is genuinely still around when the hold
            // expires still gets its watch.
            if (self::watchHeldAfterWarning($now, $state)) {
                $watchHeld = true;
            } else {
                $alert = self::buildWatch($lastInWatch, $nearest, $watchRadius, $window);
            }
        } elseif ($level === self::LEVEL_CLEAR && $notifiedLevel !== self::LEVEL_CLEAR) {
            // A watch that was announced has now faded out entirely.
            $alert = Settings::getBool('notify_all_clear')
                ? self::buildAllClear($window, $level, $nearest, false)
                : null;
            $resolvesTo = self::LEVEL_CLEAR;
        } elseif ($level === self::LEVEL_WARNING && $notifiedLevel === self::LEVEL_WARNING) {
            $alert = self::buildUpdateIfDue($now, $notifiedAt, $notifiedNearest, $nearest, $alertRadius, $window);
        }

        // Record the decision now, inside the lock — but only when this run is
        // actually going to deliver it.
        //
        // While muted nothing is consumed: an alert raised during a mute is
        // still pending when the mute expires, so a storm that started under a
        // mute is announced rather than lost. A read-only evaluation
        // ($notify false) likewise leaves the announcement state alone.
        $deliver = $notify && !$muted;

        if ($deliver && ($alert !== null || $resolvesTo !== null)) {
            if ($alert !== null) {
                $changes['notified_level'] = $alert->kind === Alert::KIND_ALL_CLEAR ? self::LEVEL_CLEAR : $level;
            } else {
                $changes['notified_level'] = $resolvesTo;
            }
            $changes['notified_at'] = $now;
            $changes['notified_nearest_mi'] = $alert !== null && $alert->kind !== Alert::KIND_ALL_CLEAR
                ? $nearestMi
                : null;
            if ($warningCleared) {
                $changes['warning_cleared_at'] = $now;
            }
        }

        if ($deliver && $watchHeld) {
            self::logWatchHold($state);
        }

        self::updateState($changes);

        return [
            'alert' => $deliver ? $alert : null,
            // What the announcement state was before this run touched it, so
            // an alert nobody could deliver can be un-announced and retried.
            'announced_before' => [
                'notified_level' => $state['notified_level'],
                'notified_at' => $state['notified_at'],
                'notified_nearest_mi' => $state['notified_nearest_mi'],
                // Rolled back with the rest: an all clear that reaches nobody
                // is retried, and the watch hold should start from the attempt
                // that actually landed.
                'warning_cleared_at' => $state['warning_cleared_at'] ?? null,
            ],
            // The notified_at this run stamped, so the rollback can tell its own
            // write from somebody else's.
            'announced_stamp' => $now,
            'muted' => $muted,
            // Only worth a log line when the situation actually changed;
            // a mute lasts many cron ticks.
            'log_suppression' => $muted && $alert !== null && $level !== $previousLevel,
            'muted_until' => (int) ($state['muted_until'] ?? 0),
            'state' => self::publicState(),
        ];
    }

    /**
     * Is a watch notification still being held after a warning stood down?
     *
     * The all clear is decided from the alert ring, so it fires while the
     * storm is still lit up between the alert and watch radii — the tail of
     * the storm that has just been announced as over. Announcing that tail as
     * a fresh "storm approaching" is the spam this exists to stop.
     *
     * @param array<string,mixed> $state
     */
    private static function watchHeldAfterWarning(int $now, array $state): bool
    {
        $minutes = Settings::getInt('watch_cooldown_minutes');
        if ($minutes <= 0) {
            return false;
        }
        $clearedAt = isset($state['warning_cleared_at']) ? (int) $state['warning_cleared_at'] : 0;
        if ($clearedAt <= 0) {
            return false;
        }
        // A clock that has gone backwards, or a restored database, could put
        // the stamp in the future and hold every watch from then on.
        if ($clearedAt > $now) {
            return false;
        }
        return ($now - $clearedAt) < ($minutes * 60);
    }

    /**
     * Record a held watch once per storm rather than once per tick, so the
     * event log shows what was suppressed without becoming the spam in a
     * different place.
     *
     * @param array<string,mixed> $state
     */
    private static function logWatchHold(array $state): void
    {
        $clearedAt = isset($state['warning_cleared_at']) ? (int) $state['warning_cleared_at'] : 0;
        try {
            $last = Database::instance()->first(
                "SELECT created_at FROM events WHERE type = 'alert.held' ORDER BY id DESC LIMIT 1"
            );
            if ($last !== null && (int) $last['created_at'] >= $clearedAt) {
                return; // already noted for this storm
            }
            $minutes = Settings::getInt('watch_cooldown_minutes');
            Events::log(
                'alert.held',
                Events::SEVERITY_INFO,
                sprintf(
                    'Lightning is still inside the watch radius as the storm moves off, so the watch '
                    . 'notification is being held. Nothing further is posted until %s UTC unless a strike '
                    . 'comes back inside the alert radius.',
                    gmdate('H:i', $clearedAt + ($minutes * 60))
                ),
                []
            );
        } catch (\Throwable $e) {
            // Never let the log line interfere with alerting.
        }
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

    /** @param array<string,mixed>|null $nearest */
    private static function buildAllClear(int $window, string $level, ?array $nearest = null, bool $fromWarning = true): Alert
    {
        $units = Settings::getString('units');
        $minutes = Settings::getInt('all_clear_minutes');
        $cooldownRadius = self::cooldownRadius();

        // A watch that simply faded out was never a "go indoors" instruction,
        // so telling people operations can resume would be confusing.
        if (!$fromWarning) {
            $alert = new Alert(
                Alert::KIND_ALL_CLEAR,
                sprintf('Storm cleared at %s', Settings::getString('venue_name')),
                sprintf(
                    'The storm has moved off — no lightning within %s for %d minutes.',
                    Geo::formatDistance(Settings::getFloat('watch_radius_mi'), $units),
                    $minutes
                )
            );
            $alert->strikeCount = Strikes::countWithin(Settings::getFloat('display_radius_mi'), $window);
            return $alert;
        }

        $summary = sprintf(
            'No lightning inside the %s for %d minutes. Normal operations can resume.',
            $cooldownRadius > Settings::getFloat('alert_radius_mi')
                ? Geo::formatDistance($cooldownRadius, $units) . ' cooldown radius'
                : Geo::formatDistance($cooldownRadius, $units) . ' alert radius',
            $minutes
        );

        // Being told "all clear" while a storm is plainly still on the map
        // reads as a malfunction unless it is spelled out.
        if ($level === self::LEVEL_WATCH) {
            $summary .= sprintf(
                ' A storm is still being tracked further out%s — keep an eye on it.',
                $nearest !== null
                    ? ', nearest ' . Geo::formatDistance((float) $nearest['distance_mi'], $units)
                    : ''
            );
        }

        $alert = new Alert(
            Alert::KIND_ALL_CLEAR,
            sprintf('All clear at %s', Settings::getString('venue_name')),
            $summary
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

        // A reminder is only worth sending if lightning has actually struck
        // since the last one. Without this, a storm that stopped an hour ago
        // keeps generating "still active" messages for the whole cooldown.
        $sinceNotified = max(1, $now - $notifiedAt);
        if (Strikes::latestWithin($radius, $sinceNotified) === null) {
            return null;
        }

        $dueByTime = $realertMinutes > 0 && ($now - $notifiedAt) >= ($realertMinutes * 60);

        // The engine runs far more often than once a minute during a storm, so
        // a steadily approaching cell would otherwise fire an update per tick.
        // This floor is deliberately independent of the repeat interval: a
        // storm bearing down on the venue should not wait out a long one.
        $dueByDistance = $closerDelta > 0
            && $notifiedNearest !== null
            && ($notifiedNearest - $nearestMi) >= $closerDelta
            && ($now - $notifiedAt) >= self::MIN_UPDATE_SECONDS;

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

    /**
     * Send an alert through every enabled channel and log the outcome.
     *
     * @return array{attempted:int,delivered:int} so the caller can tell "sent"
     *         from "nobody was listening" from "everything refused it"
     */
    public static function dispatch(Alert $alert): array
    {
        $severity = $alert->isCritical() ? Events::SEVERITY_CRITICAL : Events::SEVERITY_INFO;
        Events::log('alert.' . $alert->kind, $severity, $alert->title, [
            'summary' => $alert->summary,
            'nearest_mi' => $alert->nearestMi,
            'bearing_deg' => $alert->bearingDeg,
        ]);

        $attempted = 0;
        $delivered = 0;

        foreach ([SlackNotifier::class, EmailNotifier::class] as $notifier) {
            /** @var class-string<\StormWatch\Notifiers\NotifierInterface> $notifier */
            if (!$notifier::isEnabled()) {
                continue;
            }
            $attempted++;
            try {
                $result = $notifier::send($alert);
            } catch (\Throwable $e) {
                $result = ['ok' => false, 'message' => $e->getMessage()];
            }
            if ($result['ok']) {
                $delivered++;
            }
            Events::log(
                $notifier::channel() . '.' . ($result['ok'] ? 'sent' : 'failed'),
                $result['ok'] ? Events::SEVERITY_INFO : Events::SEVERITY_ERROR,
                ($result['ok'] ? 'Delivered: ' : 'Delivery failed: ') . $result['message'],
                ['kind' => $alert->kind]
            );
        }

        return ['attempted' => $attempted, 'delivered' => $delivered];
    }

    /**
     * Close out an active alert when the venue's operating hours end.
     *
     * Without this the state would simply stop being fed and, once the
     * cooldown elapsed, announce an all clear derived from no data — a
     * reassurance nobody checked. Saying "monitoring has ended for the day" is
     * the honest version, and it leaves the state clean for tomorrow.
     */
    public static function standDownForClosedHours(): void
    {
        $state = self::state();
        $notified = $state['notified_level'] !== null ? (string) $state['notified_level'] : self::LEVEL_CLEAR;

        if ($notified === self::LEVEL_CLEAR && (string) $state['level'] === self::LEVEL_CLEAR) {
            return; // nothing outstanding
        }

        if ($notified !== self::LEVEL_CLEAR) {
            $nextStart = Schedule::nextStart();
            $alert = new Alert(
                Alert::KIND_ALL_CLEAR,
                sprintf('Monitoring paused at %s', Settings::getString('venue_name')),
                sprintf(
                    'The venue is now outside its monitored hours, so lightning tracking has stopped for the day%s. '
                    . 'This is not an all clear — the last alert was still active when monitoring ended.',
                    $nextStart !== null
                        ? ' and resumes ' . (new \DateTimeImmutable('@' . $nextStart))
                            ->setTimezone(Schedule::timezone())->format('D g:ia')
                        : ''
                )
            );
            // A mute is one setting that governs everything reaching Slack and
            // email; closing time is not an exception to it. The log still gets
            // the line either way, so the record is complete.
            $muted = isset($state['muted_until']) && (int) $state['muted_until'] > time();
            if (Settings::getBool('notify_all_clear') && !$muted) {
                self::dispatch($alert);
            } else {
                Events::log('alert.stand_down', Events::SEVERITY_INFO, $alert->title, []);
            }
        }

        self::updateState([
            'level' => self::LEVEL_CLEAR,
            'notified_level' => self::LEVEL_CLEAR,
            'notified_nearest_mi' => null,
            'notified_at' => time(),
            'since' => time(),
        ]);
    }

    /**
     * Put the state machine back to a standing start without announcing
     * anything.
     *
     * Used when the strike history is deleted. The evidence the engine reasons
     * from has just gone, so leaving "a warning has been announced" on record
     * means the next cron tick sees a warning with no strikes behind it and
     * posts an all clear — one derived from an empty table rather than from a
     * quiet sky, and quite possibly while the storm is still overhead.
     */
    public static function reset(): void
    {
        self::updateState([
            'level' => self::LEVEL_CLEAR,
            'since' => time(),
            'nearest_mi' => null,
            'nearest_at' => null,
            'notified_level' => self::LEVEL_CLEAR,
            'notified_at' => time(),
            'notified_nearest_mi' => null,
            // The strikes the hold was about are gone too. Leaving the stamp
            // would mute the watch for a storm arriving in the next half hour.
            'warning_cleared_at' => null,
        ]);
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

    /**
     * The ring the cooldown timer watches.
     *
     * "Do not alert again until Y minutes with no strikes" begs the question
     * of which strikes count. By default only those inside the alert radius do
     * — the usual lightning-safety reading — but an operator can widen it so
     * any tracked strike keeps the venue on hold.
     */
    public static function cooldownRadius(): float
    {
        switch (Settings::getString('cooldown_scope')) {
            case 'watch':
                return Settings::getFloat('watch_radius_mi');
            case 'display':
                return Settings::getFloat('display_radius_mi');
            case 'alert':
            default:
                return Settings::getFloat('alert_radius_mi');
        }
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
        $window = max(60, Settings::getInt('all_clear_minutes') * 60);
        // Count down from the last strike the cooldown actually watches, so
        // the "all clear in" figure on the dashboard matches what will happen.
        $lastQualifying = Strikes::latestWithin(self::cooldownRadius(), $window);

        return [
            'level' => (string) $state['level'],
            'since' => (int) $state['since'],
            'nearest_mi' => $state['nearest_mi'] !== null ? round((float) $state['nearest_mi'], 2) : null,
            'nearest_at' => $state['nearest_at'] !== null ? (int) $state['nearest_at'] : null,
            'muted_until' => isset($state['muted_until']) && (int) $state['muted_until'] > $now
                ? (int) $state['muted_until']
                : null,
            'all_clear_at' => $lastQualifying !== null ? ((int) $lastQualifying['struck_at'] + $window) : null,
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

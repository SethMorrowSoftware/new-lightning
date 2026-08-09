<?php
/**
 * Storage and queries for lightning strikes.
 *
 * Every strike gets a stable `uid` derived from its rounded position and
 * timestamp, so the same strike arriving twice — from a retry, from an
 * overlapping cron worker, or from both a browser relay and the server —
 * is stored once and alerted on once.
 */
declare(strict_types=1);

namespace StormWatch;

final class Strikes
{
    /**
     * Insert a strike if it is new and within the display radius.
     *
     * @param float $lat
     * @param float $lon
     * @param int   $struckAt unix seconds
     * @return array<string,mixed>|null the stored row, or null when skipped
     */
    public static function record(float $lat, float $lon, int $struckAt, string $source): ?array
    {
        if (!Geo::isValidLatitude($lat) || !Geo::isValidLongitude($lon)) {
            return null;
        }

        $venueLat = Settings::getFloat('venue_lat');
        $venueLon = Settings::getFloat('venue_lon');
        $displayRadius = Settings::getFloat('display_radius_mi');

        $distance = Geo::distanceMiles($venueLat, $venueLon, $lat, $lon);
        if ($distance > $displayRadius) {
            return null;
        }

        $now = time();
        // Reject timestamps that are obviously wrong rather than let them
        // poison the "last hour" statistics.
        if ($struckAt <= 0 || $struckAt > $now + 300 || $struckAt < $now - 86400) {
            $struckAt = $now;
        }

        $bearing = Geo::bearingDegrees($venueLat, $venueLon, $lat, $lon);
        $uid = self::uid($lat, $lon, $struckAt);

        $inserted = Database::instance()->insertIgnore('strikes', [
            'uid' => $uid,
            'struck_at' => $struckAt,
            'received_at' => $now,
            'lat' => $lat,
            'lon' => $lon,
            'distance_mi' => $distance,
            'bearing_deg' => $bearing,
            'source' => mb_substr($source, 0, 32),
        ]);

        if (!$inserted) {
            return null;
        }

        return [
            'id' => Database::instance()->lastInsertId(),
            'uid' => $uid,
            'struck_at' => $struckAt,
            'received_at' => $now,
            'lat' => $lat,
            'lon' => $lon,
            'distance_mi' => $distance,
            'bearing_deg' => $bearing,
            'source' => $source,
        ];
    }

    /**
     * Deduplication key. Two reports of the same flash rarely agree to the
     * metre or the millisecond, so quantise: ~11 m of position and 2 seconds
     * of time.
     */
    public static function uid(float $lat, float $lon, int $struckAt): string
    {
        return sprintf('%.4f:%.4f:%d', $lat, $lon, intdiv($struckAt, 2));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function since(int $sinceId, int $limit = 500): array
    {
        $limit = max(1, min(2000, $limit));
        return Database::instance()->all(
            'SELECT id, uid, struck_at, lat, lon, distance_mi, bearing_deg, source
             FROM strikes WHERE id > ? ORDER BY id ASC LIMIT ' . $limit,
            [$sinceId]
        );
    }

    /**
     * Most recent strikes within a time window, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function recent(int $withinSeconds, int $limit = 500): array
    {
        $limit = max(1, min(2000, $limit));
        return Database::instance()->all(
            'SELECT id, uid, struck_at, lat, lon, distance_mi, bearing_deg, source
             FROM strikes WHERE struck_at >= ? ORDER BY struck_at DESC, id DESC LIMIT ' . $limit,
            [time() - $withinSeconds]
        );
    }

    public static function maxId(): int
    {
        return (int) (Database::instance()->value('SELECT MAX(id) FROM strikes') ?? 0);
    }

    public static function countWithin(float $radiusMi, int $withinSeconds): int
    {
        return (int) Database::instance()->value(
            'SELECT COUNT(*) FROM strikes WHERE distance_mi <= ? AND struck_at >= ?',
            [$radiusMi, time() - $withinSeconds]
        );
    }

    /**
     * The closest strike inside a time window.
     *
     * @return array<string,mixed>|null
     */
    public static function nearest(int $withinSeconds, ?float $maxRadiusMi = null): ?array
    {
        $params = [time() - $withinSeconds];
        $sql = 'SELECT id, struck_at, lat, lon, distance_mi, bearing_deg, source
                FROM strikes WHERE struck_at >= ?';
        if ($maxRadiusMi !== null) {
            $sql .= ' AND distance_mi <= ?';
            $params[] = $maxRadiusMi;
        }
        $sql .= ' ORDER BY distance_mi ASC LIMIT 1';
        return Database::instance()->first($sql, $params);
    }

    /**
     * The most recent strike inside a radius — used to decide whether the
     * all-clear timer has expired.
     *
     * @return array<string,mixed>|null
     */
    public static function latestWithin(float $radiusMi, int $withinSeconds): ?array
    {
        return Database::instance()->first(
            'SELECT id, struck_at, lat, lon, distance_mi, bearing_deg, source
             FROM strikes WHERE distance_mi <= ? AND struck_at >= ?
             ORDER BY struck_at DESC, id DESC LIMIT 1',
            [$radiusMi, time() - $withinSeconds]
        );
    }

    /**
     * @return array{total:int,close:int,nearest_mi:float|null,last_struck_at:int|null}
     */
    public static function stats(int $withinSeconds): array
    {
        $db = Database::instance();
        $cutoff = time() - $withinSeconds;
        $alertRadius = Settings::getFloat('alert_radius_mi');
        $row = $db->first(
            'SELECT COUNT(*) AS total, MIN(distance_mi) AS nearest, MAX(struck_at) AS last_at
             FROM strikes WHERE struck_at >= ?',
            [$cutoff]
        ) ?? [];
        $close = (int) $db->value(
            'SELECT COUNT(*) FROM strikes WHERE struck_at >= ? AND distance_mi <= ?',
            [$cutoff, $alertRadius]
        );
        return [
            'total' => (int) ($row['total'] ?? 0),
            'close' => $close,
            'nearest_mi' => isset($row['nearest']) && $row['nearest'] !== null ? (float) $row['nearest'] : null,
            'last_struck_at' => isset($row['last_at']) && $row['last_at'] !== null ? (int) $row['last_at'] : null,
        ];
    }

    /**
     * The oldest strike the rest of the application still needs.
     *
     * The cooldown asks "has anything struck inside the radius in the last N
     * minutes?" and reads that answer out of this table. A retention window
     * shorter than N does not make the answer "no strikes recently" — it makes
     * it "no strikes on record", which is the same answer, and the venue is
     * sent an all clear with lightning still overhead. So retention has a floor
     * that no setting can go under, plus a margin for a cron run that fires
     * late.
     */
    public static function minimumRetentionSeconds(): int
    {
        $cooldown = max(60, Settings::getInt('all_clear_minutes') * 60);
        $markers = max(60, Settings::getInt('marker_ttl_minutes') * 60);
        return max($cooldown, $markers) + 300;
    }

    public static function prune(?int $retentionHours = null): int
    {
        $hours = $retentionHours ?? Settings::getInt('retention_hours');
        $seconds = max(1, $hours) * 3600;
        // Housekeeping must never be able to delete the evidence the alert
        // state machine is reasoning from.
        $seconds = max($seconds, self::minimumRetentionSeconds());
        $stmt = Database::instance()->run(
            'DELETE FROM strikes WHERE struck_at < ?',
            [time() - $seconds]
        );
        return $stmt->rowCount();
    }

    public static function deleteAll(): int
    {
        $stmt = Database::instance()->run('DELETE FROM strikes');
        return $stmt->rowCount();
    }
}

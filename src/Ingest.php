<?php
/**
 * The single funnel every strike passes through, whatever produced it.
 *
 * Keeping this in one place means de-duplication, radius filtering and the
 * alert evaluation behave identically for the WebSocket worker, the REST
 * poller, the browser relay and the simulator.
 */
declare(strict_types=1);

namespace StormWatch;

final class Ingest
{
    /**
     * @param array<int,array{lat:float,lon:float,ts:int}> $records
     * @return int number of strikes actually stored
     */
    public static function recordMany(array $records, string $source): int
    {
        $stored = 0;
        foreach ($records as $record) {
            if (self::recordOne((float) $record['lat'], (float) $record['lon'], (int) $record['ts'], $source)) {
                $stored++;
            }
        }
        return $stored;
    }

    public static function recordOne(float $lat, float $lon, int $ts, string $source): bool
    {
        return Strikes::record($lat, $lon, $ts, $source) !== null;
    }

    /**
     * Normalise a timestamp from a feed into unix seconds.
     *
     * Feeds are wildly inconsistent — Blitzortung uses nanoseconds, most REST
     * APIs use ISO-8601 or milliseconds — so "auto" inspects the magnitude.
     */
    public static function parseTimestamp($value, string $format = 'auto'): int
    {
        $now = time();
        if ($value === null || $value === '') {
            return $now;
        }

        if ($format === 'iso8601' || (!is_numeric($value) && is_string($value))) {
            $parsed = strtotime((string) $value);
            return $parsed === false ? $now : $parsed;
        }

        $number = (float) $value;
        if ($number <= 0) {
            return $now;
        }

        switch ($format) {
            case 'epoch_s':
                return (int) $number;
            case 'epoch_ms':
                return (int) ($number / 1000);
            case 'epoch_ns':
                return (int) ($number / 1000000000);
            case 'auto':
            default:
                // Boundaries chosen so any plausible "now" lands correctly:
                // seconds ~1.7e9, milliseconds ~1.7e12, nanoseconds ~1.7e18.
                if ($number > 1e17) {
                    return (int) ($number / 1000000000);
                }
                if ($number > 1e14) {
                    return (int) ($number / 1000000);
                }
                if ($number > 1e11) {
                    return (int) ($number / 1000);
                }
                return (int) $number;
        }
    }

    /**
     * Could parseTimestamp() actually read this, or would it fall back to now?
     *
     * The fallback is deliberate for a single missing field — dropping a strike
     * is worse than dating it a few seconds early — but it hides the case that
     * matters: a time path pointing at the wrong key, which stamps every record
     * "now" and turns an endpoint's history into a storm happening this minute.
     * Callers use this to tell the two apart.
     *
     * @param mixed $value
     */
    public static function isReadableTimestamp($value, string $format = 'auto'): bool
    {
        if ($value === null || $value === '') {
            return false;
        }
        if ($format === 'iso8601' || (!is_numeric($value) && is_string($value))) {
            return strtotime((string) $value) !== false;
        }
        return is_numeric($value) && (float) $value > 0;
    }

    /**
     * Resolve a dot-separated path inside a decoded JSON structure.
     * An empty path returns the value unchanged.
     *
     * @param mixed $data
     * @return mixed
     */
    public static function dotPath($data, string $path)
    {
        $path = trim($path);
        if ($path === '') {
            return $data;
        }
        foreach (explode('.', $path) as $segment) {
            if (is_array($data) && array_key_exists($segment, $data)) {
                $data = $data[$segment];
                continue;
            }
            if (is_array($data) && ctype_digit($segment) && array_key_exists((int) $segment, $data)) {
                $data = $data[(int) $segment];
                continue;
            }
            return null;
        }
        return $data;
    }
}

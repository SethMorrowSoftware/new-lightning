<?php
/**
 * A storm-cell simulator, carried over from the original demo.
 *
 * This is not a toy: it is how you prove the whole chain — ingest, alerting,
 * Slack delivery, all-clear — before real weather arrives, and how a new
 * install can be demonstrated on a clear day. The cell drifts between cron
 * runs because its state is kept on disk.
 */
declare(strict_types=1);

namespace StormWatch\Providers;

use StormWatch\Geo;
use StormWatch\Ingest;
use StormWatch\Settings;

final class Simulator
{
    /**
     * Advance the simulated cell and store any strikes it produced.
     *
     * @return array{ok:bool,ingested:int,message:string}
     */
    public static function tick(int $strikesToEmit = 3): array
    {
        $state = self::loadState();
        $displayRadius = Settings::getFloat('display_radius_mi');
        $venueLat = Settings::getFloat('venue_lat');
        $venueLon = Settings::getFloat('venue_lon');

        // Drift the cell: wander the bearing, and close in on the venue more
        // often than not so a demo actually reaches an alert.
        $state['bearing'] = fmod($state['bearing'] + (self::rand() - 0.5) * 12.0 + 360.0, 360.0);
        $state['distance'] += (self::rand() - 0.62) * 2.4;
        $state['distance'] = max(0.5, min($displayRadius * 1.2, $state['distance']));

        $records = [];
        if ($state['distance'] <= $displayRadius) {
            $count = max(1, $strikesToEmit);
            for ($i = 0; $i < $count; $i++) {
                $bearing = $state['bearing'] + (self::rand() - 0.5) * 22.0;
                $distance = max(0.2, $state['distance'] + (self::rand() - 0.5) * 4.0);
                if ($distance > $displayRadius) {
                    continue;
                }
                $point = Geo::destination($venueLat, $venueLon, $bearing, $distance);
                $records[] = [
                    'lat' => $point['lat'],
                    'lon' => $point['lon'],
                    // Spread the strikes across the last minute rather than
                    // stacking them all on the same second.
                    'ts' => time() - random_int(0, 50),
                ];
            }
        }

        self::saveState($state);
        $stored = Ingest::recordMany($records, 'simulator');

        return [
            'ok' => true,
            'ingested' => $stored,
            'message' => sprintf(
                'Simulated cell is %.1f mi %s of the venue; stored %d strike%s.',
                $state['distance'],
                Geo::compassPoint($state['bearing']),
                $stored,
                $stored === 1 ? '' : 's'
            ),
        ];
    }

    /**
     * Drop a single strike at a chosen distance — this backs the "Simulate
     * strike" button on the dashboard.
     *
     * @return array<string,mixed>|null
     */
    public static function singleStrike(?float $distanceMi = null, ?float $bearingDeg = null): ?array
    {
        $alertRadius = Settings::getFloat('alert_radius_mi');
        $distance = $distanceMi ?? (self::rand() * $alertRadius * 0.9);
        $bearing = $bearingDeg ?? (self::rand() * 360.0);
        $point = Geo::destination(
            Settings::getFloat('venue_lat'),
            Settings::getFloat('venue_lon'),
            $bearing,
            $distance
        );
        return \StormWatch\Strikes::record($point['lat'], $point['lon'], time(), 'simulator');
    }

    public static function reset(): void
    {
        @unlink(self::stateFile());
    }

    /** @return array{bearing:float,distance:float} */
    private static function loadState(): array
    {
        $file = self::stateFile();
        if (is_file($file)) {
            $data = json_decode((string) @file_get_contents($file), true);
            if (is_array($data) && isset($data['bearing'], $data['distance'])) {
                return [
                    'bearing' => (float) $data['bearing'],
                    'distance' => (float) $data['distance'],
                ];
            }
        }
        return [
            'bearing' => self::rand() * 360.0,
            'distance' => Settings::getFloat('display_radius_mi') * 0.95,
        ];
    }

    /** @param array{bearing:float,distance:float} $state */
    private static function saveState(array $state): void
    {
        @file_put_contents(self::stateFile(), json_encode($state), LOCK_EX);
    }

    private static function stateFile(): string
    {
        return SW_DATA . '/simulator.json';
    }

    private static function rand(): float
    {
        return random_int(0, PHP_INT_MAX - 1) / PHP_INT_MAX;
    }
}

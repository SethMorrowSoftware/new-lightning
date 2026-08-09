<?php
/**
 * The dashboard's polling endpoint: current alert state, statistics, source
 * health and any strikes the caller has not seen yet.
 */
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

use StormWatch\AlertEngine;
use StormWatch\App;
use StormWatch\Forecast;
use StormWatch\Geo;
use StormWatch\Http;
use StormWatch\Runner;
use StormWatch\Settings;
use StormWatch\Strikes;

App::boot('view', true);

$sinceId = isset($_GET['since_id']) ? max(0, (int) $_GET['since_id']) : null;
$markerTtl = max(60, Settings::getInt('marker_ttl_minutes') * 60);

// Read the high-water mark before the strike list, not after: a worker
// inserting in between would otherwise be counted as "already sent".
$highWaterBefore = Strikes::maxId();

if ($sinceId === null) {
    // First load: everything still worth drawing on the map, oldest first so
    // the client can render in chronological order.
    $strikes = array_reverse(Strikes::recent($markerTtl, 400));
} else {
    $strikes = Strikes::since($sinceId, 400);
}

$payload = [];
$highestSent = 0;
foreach ($strikes as $strike) {
    $highestSent = max($highestSent, (int) $strike['id']);
    $payload[] = [
        'id' => (int) $strike['id'],
        'ts' => (int) $strike['struck_at'],
        'lat' => round((float) $strike['lat'], 5),
        'lon' => round((float) $strike['lon'], 5),
        'mi' => round((float) $strike['distance_mi'], 2),
        'bearing' => round((float) $strike['bearing_deg'], 1),
        'dir' => Geo::compassPoint((float) $strike['bearing_deg']),
        'src' => (string) $strike['source'],
    ];
}

$stats = Strikes::stats(3600);
$status = Runner::status();

// The caller sends the stamp of the forecast it is already showing, and gets
// nothing back while that is still the current one. The forecast changes every
// few minutes; this poll runs every few seconds.
$forecastStamp = isset($_GET['forecast']) ? (string) $_GET['forecast'] : null;
$forecast = Forecast::summaryIfChanged($forecastStamp);

Http::json([
    'ok' => true,
    'server_time' => time(),
    // The client resumes from this, so it has to be the last strike actually
    // handed over — never the newest in the table. Both queries above are
    // capped at 400 rows, and during the storm that fills them is exactly when
    // the map must not quietly skip the rest.
    'max_id' => $highestSent > 0 ? $highestSent : $highWaterBefore,
    'state' => AlertEngine::publicState(),
    'strikes' => $payload,
    'stats' => [
        'total' => $stats['total'],
        'close' => $stats['close'],
        'nearest_mi' => $stats['nearest_mi'] !== null ? round($stats['nearest_mi'], 1) : null,
        'last_struck_at' => $stats['last_struck_at'],
        'window_minutes' => 60,
    ],
    'source' => $status,
    // Null when the caller is already current, or when the card is switched
    // off; the stamp beside it is what tells those two apart.
    'forecast' => $forecast,
    'forecast_stamp' => Forecast::stamp(),
]);

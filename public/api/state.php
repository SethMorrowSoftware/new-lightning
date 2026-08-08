<?php
/**
 * The dashboard's polling endpoint: current alert state, statistics, source
 * health and any strikes the caller has not seen yet.
 */
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

use StormWatch\AlertEngine;
use StormWatch\App;
use StormWatch\Geo;
use StormWatch\Http;
use StormWatch\Runner;
use StormWatch\Settings;
use StormWatch\Strikes;

App::boot('view', true);

$sinceId = isset($_GET['since_id']) ? max(0, (int) $_GET['since_id']) : null;
$markerTtl = max(60, Settings::getInt('marker_ttl_minutes') * 60);

if ($sinceId === null) {
    // First load: everything still worth drawing on the map, oldest first so
    // the client can render in chronological order.
    $strikes = array_reverse(Strikes::recent($markerTtl, 400));
} else {
    $strikes = Strikes::since($sinceId, 400);
}

$payload = [];
foreach ($strikes as $strike) {
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

Http::json([
    'ok' => true,
    'server_time' => time(),
    'max_id' => Strikes::maxId(),
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
]);

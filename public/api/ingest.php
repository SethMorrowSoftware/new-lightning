<?php
/**
 * Strike ingest for the browser relay.
 *
 * When a host cannot open the Blitzortung port itself, a dashboard tab can do
 * it instead and post the strikes here. Authentication is the ingest token, so
 * a kiosk display does not need a signed-in session.
 */
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

use StormWatch\AlertEngine;
use StormWatch\App;
use StormWatch\Geo;
use StormWatch\Http;
use StormWatch\Ingest;
use StormWatch\Runner;
use StormWatch\Settings;

App::boot('public', true);

if (!Http::isPost()) {
    Http::jsonError('This endpoint expects a POST request.', 405);
}

$expected = Settings::getString('ingest_token');
if ($expected === '') {
    Http::jsonError('No ingest token is configured. Generate one on the Data source settings tab.', 503);
}

$provided = (string) ($_SERVER['HTTP_X_INGEST_TOKEN'] ?? $_GET['token'] ?? '');
if ($provided === '' || !hash_equals($expected, $provided)) {
    Http::jsonError('Invalid ingest token.', 401);
}

$body = Http::jsonBody();
$items = $body['strikes'] ?? null;
if (!is_array($items)) {
    Http::jsonError('Send a JSON body of the form {"strikes":[{"lat":0,"lon":0,"time":0}]}.', 400);
}
if (count($items) > 500) {
    $items = array_slice($items, 0, 500);
}

$records = [];
foreach ($items as $item) {
    if (!is_array($item) || !isset($item['lat'], $item['lon'])) {
        continue;
    }
    if (!is_numeric($item['lat']) || !is_numeric($item['lon'])) {
        continue;
    }
    $lat = (float) $item['lat'];
    $lon = (float) $item['lon'];
    if (!Geo::isValidLatitude($lat) || !Geo::isValidLongitude($lon)) {
        continue;
    }
    $records[] = [
        'lat' => $lat,
        'lon' => $lon,
        'ts' => Ingest::parseTimestamp($item['time'] ?? null, 'auto'),
    ];
}

$startedAt = time();
$stored = Ingest::recordMany($records, 'relay');

if ($stored > 0) {
    AlertEngine::evaluate();
}

// Record the run so the dashboard's source status reflects relay activity.
if ($stored > 0 || $records !== []) {
    Runner::recordRun(
        'poll',
        'relay',
        true,
        $stored,
        sprintf('Browser relay delivered %d record(s); stored %d.', count($records), $stored),
        $startedAt
    );
}

Http::json([
    'ok' => true,
    'received' => count($records),
    'stored' => $stored,
    'state' => AlertEngine::publicState(),
]);

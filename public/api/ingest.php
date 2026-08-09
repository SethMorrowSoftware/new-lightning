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
use StormWatch\Schedule;
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

// Operating hours govern every path that can raise an alert, not only cron.
// A relay tab left running overnight keeps posting strikes; without this check
// each batch raises a warning, tick() stands it down a minute later as
// "outside monitored hours", and the next batch raises it again — a warning
// and an all clear every minute until morning. The strikes are still stored,
// so the map and the history are complete either way.
$monitoring = Schedule::isMonitoring();
if ($stored > 0 && $monitoring) {
    AlertEngine::evaluate();
}

// Always record the run, including an empty check-in. Lightning within thirty
// miles happens a few days a month, so "strikes have arrived recently" cannot
// tell a working relay from a tab somebody closed — and it is the tab closing
// that stops the alerts. The check-in is the liveness signal.
//
// But the tab being open is not the same as the feed being connected: a
// firewall that starts dropping port 3000 leaves the page up and reconnecting
// for ever while it collects nothing. So the relay reports whether its socket
// is actually open, and that is what health is recorded as. An older script
// that does not send the flag is taken at its word only when it has delivered
// strikes, which is proof enough on its own.
$connected = array_key_exists('connected', $body)
    ? (bool) $body['connected']
    : ($records !== []);

Runner::recordRun(
    'poll',
    'relay',
    $connected,
    $stored,
    $records === []
        ? ($connected
            ? 'Browser relay checked in; connected, no strikes within the display radius.'
            : 'Browser relay checked in, but its connection to Blitzortung is down and it is collecting nothing.')
        : sprintf(
            'Browser relay delivered %d record(s); stored %d.%s',
            count($records),
            $stored,
            $monitoring ? '' : ' Outside operating hours, so no alert was raised.'
        ),
    $startedAt
);

Http::json([
    'ok' => true,
    'received' => count($records),
    'stored' => $stored,
    'monitoring' => $monitoring,
    'state' => AlertEngine::publicState(),
]);

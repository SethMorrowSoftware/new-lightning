<?php
/**
 * Web-triggered cron, for hosts where a real cron job is not available.
 *
 * Point an external uptime/cron service at:
 *   https://your-site/api/cron.php?token=<cron token>&job=tick
 *
 * Authenticated by the cron token so the URL cannot be used to hammer the
 * data source by anyone who finds it.
 */
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

use StormWatch\App;
use StormWatch\Http;
use StormWatch\Runner;
use StormWatch\Settings;

App::boot('public', true);

$expected = Settings::getString('cron_token');
if ($expected === '') {
    Http::jsonError('No cron token is configured. Generate one on the System settings tab.', 503);
}

$provided = (string) ($_GET['token'] ?? $_POST['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '');
if ($provided === '' || !hash_equals($expected, $provided)) {
    Http::jsonError('Invalid cron token.', 401);
}

$job = (string) ($_GET['job'] ?? 'tick');

// A web request has a wall-clock limit; give the streaming worker room but
// never let it run past what the host will allow.
@set_time_limit(120);
ignore_user_abort(true);

$result = $job === 'worker' ? Runner::worker() : Runner::tick();

Http::json([
    'ok' => $result['ok'],
    'job' => $result['job'],
    'ingested' => $result['ingested'],
    'skipped' => $result['skipped'] ?? false,
    'message' => $result['message'],
]);

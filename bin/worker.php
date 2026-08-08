<?php
/**
 * The streaming job, for the Blitzortung provider. Add this to cron alongside
 * tick.php:
 *
 *   * * * * * /usr/local/bin/php /home/USER/stormwatch/bin/worker.php >/dev/null 2>&1
 *
 * It holds a WebSocket open for `worker_seconds` (50 by default) and exits, so
 * the next cron minute picks straight up. That keeps coverage near-continuous
 * without leaving a daemon running on a shared host — which most hosts kill,
 * and some forbid outright.
 *
 * On any other provider it exits immediately, so leaving the cron entry in
 * place costs nothing.
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use StormWatch\App;
use StormWatch\Config;
use StormWatch\Runner;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is for the command line. Use api/cron.php?job=worker for web-triggered runs.\n");
}

if (!Config::isInstalled()) {
    fwrite(STDERR, "Storm Watch is not installed yet. Open setup.php in a browser first.\n");
    exit(1);
}

// The stream deliberately runs for most of a minute.
set_time_limit(0);

try {
    App::migrateIfNeeded();
    $result = Runner::worker();
} catch (Throwable $e) {
    fwrite(STDERR, 'worker failed: ' . $e->getMessage() . "\n");
    exit(1);
}

if (in_array('-v', $argv, true) || in_array('--verbose', $argv, true)) {
    printf(
        "[%s] worker %s — ingested %d — %s\n",
        gmdate('Y-m-d H:i:s'),
        $result['ok'] ? 'ok' : 'FAILED',
        $result['ingested'],
        $result['message']
    );
}

exit($result['ok'] ? 0 : 1);

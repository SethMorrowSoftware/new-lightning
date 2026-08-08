<?php
/**
 * The every-minute job. Add this to cron:
 *
 *   * * * * * /usr/local/bin/php /home/USER/stormwatch/bin/tick.php >/dev/null 2>&1
 *
 * It polls a REST feed when one is due, advances the simulator, re-evaluates
 * the alert state (so the all-clear fires even when nothing is arriving) and
 * prunes expired data.
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use StormWatch\App;
use StormWatch\Config;
use StormWatch\Runner;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is for the command line. Use api/cron.php for web-triggered runs.\n");
}

if (!Config::isInstalled()) {
    fwrite(STDERR, "Storm Watch is not installed yet. Open setup.php in a browser first.\n");
    exit(1);
}

try {
    App::migrateIfNeeded();
    $result = Runner::tick();
} catch (Throwable $e) {
    fwrite(STDERR, 'tick failed: ' . $e->getMessage() . "\n");
    exit(1);
}

if (in_array('-v', $argv, true) || in_array('--verbose', $argv, true)) {
    printf(
        "[%s] tick %s — ingested %d — %s\n",
        gmdate('Y-m-d H:i:s'),
        $result['ok'] ? 'ok' : 'FAILED',
        $result['ingested'],
        $result['message']
    );
}

exit($result['ok'] ? 0 : 1);

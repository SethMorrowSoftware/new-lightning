<?php
/**
 * Runs a single AlertEngine::evaluate() against a scratch database.
 *
 * The test suite launches several of these at once to prove that overlapping
 * cron jobs cannot announce the same alert twice.
 *
 * Usage: php tests/support/evaluate_once.php <sqlite-path> <app-key>
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use StormWatch\AlertEngine;
use StormWatch\Config;
use StormWatch\Database;
use StormWatch\Settings;

$path = $argv[1] ?? '';
$appKey = $argv[2] ?? '';
if ($path === '' || $appKey === '') {
    fwrite(STDERR, "usage: evaluate_once.php <sqlite-path> <app-key>\n");
    exit(1);
}

Config::override([
    'db_driver' => 'sqlite',
    'db_path' => $path,
    'app_key' => $appKey,
    'base_url' => 'https://storm.test',
]);
Database::reset();
Settings::flushCache();

// Line the processes up on a shared start so they genuinely contend.
$startAt = (float) ($argv[3] ?? microtime(true));
while (microtime(true) < $startAt) {
    usleep(200);
}

try {
    $state = AlertEngine::evaluate();
    echo $state['level'], "\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

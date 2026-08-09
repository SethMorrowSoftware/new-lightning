<?php
/**
 * Command-line helper for install checks and day-to-day maintenance.
 *
 *   php bin/stormwatch.php status
 *   php bin/stormwatch.php test-slack
 *   php bin/stormwatch.php test-source
 *   php bin/stormwatch.php forecast [--refresh]
 *   php bin/stormwatch.php simulate [miles]
 *   php bin/stormwatch.php set <key> <value>
 *   php bin/stormwatch.php get [key]
 *   php bin/stormwatch.php passwd <username>
 *   php bin/stormwatch.php prune
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use StormWatch\AlertEngine;
use StormWatch\App;
use StormWatch\Auth;
use StormWatch\Config;
use StormWatch\Database;
use StormWatch\Events;
use StormWatch\Forecast;
use StormWatch\Geo;
use StormWatch\Notifiers\EmailNotifier;
use StormWatch\Notifiers\SlackNotifier;
use StormWatch\Providers\Blitzortung;
use StormWatch\Providers\Rest;
use StormWatch\Providers\Simulator;
use StormWatch\Runner;
use StormWatch\Settings;
use StormWatch\Strikes;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is for the command line only.\n");
}

if (!Config::isInstalled()) {
    fwrite(STDERR, "Storm Watch is not installed. Open public/setup.php in a browser first.\n");
    exit(1);
}

// The README points people here when the dashboard is unhappy, so this is
// often the first thing run on a broken install. An uncaught PDO exception
// would print nothing at all and exit 255 — the least useful possible answer
// to "why has it stopped".
try {
    App::migrateIfNeeded();
    Settings::all();
} catch (\Throwable $e) {
    fwrite(STDERR, "Storm Watch cannot reach its database.\n");
    fwrite(STDERR, '  ' . $e->getMessage() . "\n");
    fwrite(STDERR, "Check the credentials in config/config.php, and that data/ is writable.\n");
    fwrite(STDERR, "Lightning alerts are not being sent while this is true.\n");
    exit(1);
}

$command = $argv[1] ?? 'status';
$args = array_slice($argv, 2);

function out(string $line = ''): void
{
    echo $line . "\n";
}

switch ($command) {
    case 'status':
        $status = Runner::status();
        $state = AlertEngine::publicState();
        $stats = Strikes::stats(3600);
        out('Storm Watch ' . SW_VERSION);
        out('Venue        ' . Settings::getString('venue_name') . ' (' . Settings::getFloat('venue_lat') . ', ' . Settings::getFloat('venue_lon') . ')');
        out('Database     ' . Database::instance()->driver());
        out('Provider     ' . $status['provider']);
        out('Alert level  ' . strtoupper($state['level'])
            . ($state['nearest_mi'] !== null ? '  nearest ' . $state['nearest_mi'] . ' mi' : '')
            . ($state['muted_until'] ? '  (muted)' : ''));
        out('Last hour    ' . $stats['total'] . ' strikes, ' . $stats['close'] . ' inside the alert radius');
        out('Scheduled    ' . ($status['cron_healthy']
            ? 'healthy, last ran ' . Runner::humanAge((int) $status['cron_age_seconds']) . ' ago'
            : 'NOT RUNNING — install the cron jobs'));
        out('Feed         ' . ($status['source_healthy'] ? 'ok' : 'PROBLEM') . ' — ' . $status['source_message']);
        out('Slack        ' . (SlackNotifier::isEnabled() ? 'enabled' : 'off'));
        out('Email        ' . (EmailNotifier::isEnabled() ? 'enabled' : 'off'));
        out('Forecast     ' . Forecast::describe());
        exit($status['cron_healthy'] && $status['source_healthy'] ? 0 : 1);

    case 'forecast':
        if (in_array('--refresh', $args, true)) {
            $result = Forecast::refresh();
            out(($result['ok'] ? 'OK: ' : 'FAILED: ') . $result['message']);
            if (!$result['ok']) {
                exit(1);
            }
        }
        $summary = Forecast::summary();
        if ($summary === null) {
            out('The forecast card is switched off. Turn it on under Settings → Venue & map,');
            out('or run: php bin/stormwatch.php set nws_enabled true');
            exit(0);
        }
        out('Forecast     ' . Forecast::describe());
        out('Location     ' . ($summary['place'] !== '' ? $summary['place'] : '(not resolved yet)')
            . ($summary['office'] !== '' ? '  office ' . $summary['office'] : ''));
        if ($summary['message'] !== '') {
            out('Note         ' . $summary['message']);
        }
        if ($summary['alerts'] === []) {
            out('Alerts       none in force');
        }
        foreach ($summary['alerts'] as $alert) {
            out('Alert        ' . strtoupper((string) $alert['severity']) . '  ' . $alert['event']
                . ($alert['expires'] !== null ? '  until ' . gmdate('Y-m-d H:i', (int) $alert['expires']) . ' UTC' : ''));
        }
        foreach ($summary['periods'] as $period) {
            out(sprintf(
                '%-13s %s  %s%s',
                mb_substr((string) $period['name'], 0, 13),
                $period['temp'] === null ? '   ' : str_pad((string) $period['temp'] . '°' . $period['unit'], 5),
                (string) $period['short'],
                $period['pop'] ? '  (' . $period['pop'] . '%)' : ''
            ));
        }
        exit($summary['ok'] ? 0 : 1);

    case 'test-slack':
        $result = SlackNotifier::sendTest();
        out(($result['ok'] ? 'OK: ' : 'FAILED: ') . $result['message']);
        exit($result['ok'] ? 0 : 1);

    case 'test-email':
        $result = EmailNotifier::sendTest();
        out(($result['ok'] ? 'OK: ' : 'FAILED: ') . $result['message']);
        exit($result['ok'] ? 0 : 1);

    case 'test-source':
        $provider = Settings::getString('provider');
        if ($provider === 'blitzortung') {
            $result = Blitzortung::test((int) ($args[0] ?? 15));
        } elseif ($provider === 'rest') {
            $result = Rest::test();
        } else {
            $tick = Simulator::tick();
            $result = ['ok' => true, 'message' => $tick['message']];
        }
        out(($result['ok'] ? 'OK: ' : 'FAILED: ') . $result['message']);
        if (!empty($result['detail']['hint'])) {
            out('Hint: ' . $result['detail']['hint']);
        }
        exit($result['ok'] ? 0 : 1);

    case 'simulate':
        $miles = isset($args[0]) ? (float) $args[0] : null;
        $strike = Simulator::singleStrike($miles);
        if ($strike === null) {
            out('That strike fell outside the display radius, so nothing was stored.');
            exit(1);
        }
        AlertEngine::evaluate();
        out(sprintf(
            'Stored a simulated strike %.1f mi %s of the venue. Alert level is now %s.',
            (float) $strike['distance_mi'],
            Geo::compassPoint((float) $strike['bearing_deg']),
            strtoupper(AlertEngine::publicState()['level'])
        ));
        exit(0);

    case 'get':
        $key = $args[0] ?? null;
        $schema = Settings::schema();
        foreach (Settings::all() as $name => $value) {
            if ($key !== null && $name !== $key) {
                continue;
            }
            if (($schema[$name]['type'] ?? '') === 'secret') {
                $value = $value === '' ? '(not set)' : '(set)';
            }
            out(sprintf('%-22s %s', $name, is_bool($value) ? ($value ? 'true' : 'false') : (string) $value));
        }
        exit(0);

    case 'set':
        if (count($args) < 2) {
            out('Usage: php bin/stormwatch.php set <key> <value>');
            exit(1);
        }
        // Settings::put() ignores keys it does not recognise, which is right
        // for a posted form and wrong here: "set alert_radius_MI 4" would
        // report success, change nothing, and leave the venue on a radius
        // nobody meant.
        if (!array_key_exists($args[0], Settings::schema())) {
            out('Unknown setting: ' . $args[0]);
            out('Run "php bin/stormwatch.php get" to list the settings that exist.');
            exit(1);
        }
        $errors = Settings::put([$args[0] => implode(' ', array_slice($args, 1))]);
        if ($errors !== []) {
            foreach ($errors as $field => $message) {
                out('ERROR ' . $field . ': ' . $message);
            }
            exit(1);
        }
        out('Saved ' . $args[0] . '.');
        exit(0);

    case 'passwd':
        $username = $args[0] ?? '';
        $user = Database::instance()->first('SELECT id FROM users WHERE username = ?', [$username]);
        if ($user === null) {
            out('No such account: ' . $username);
            exit(1);
        }
        $password = $args[1] ?? '';
        if ($password === '') {
            out('Usage: php bin/stormwatch.php passwd <username> <new-password>');
            exit(1);
        }
        $problem = Auth::passwordProblem($password);
        if ($problem !== null) {
            out('ERROR: ' . $problem);
            exit(1);
        }
        Database::instance()->run('UPDATE users SET password_hash = ? WHERE id = ?', [
            password_hash($password, PASSWORD_DEFAULT),
            (int) $user['id'],
        ]);
        Events::log('auth.password_reset', Events::SEVERITY_WARNING, 'Password reset from the command line for ' . $username, []);
        out('Password updated for ' . $username . '.');
        exit(0);

    case 'prune':
        $strikes = Strikes::prune();
        $events = Events::prune(30);
        out(sprintf('Removed %d strike(s) and %d log entr%s.', $strikes, $events, $events === 1 ? 'y' : 'ies'));
        exit(0);

    default:
        out('Unknown command: ' . $command);
        out('');
        out('Commands: status, test-slack, test-email, test-source, forecast, simulate, get, set, passwd, prune');
        exit(1);
}

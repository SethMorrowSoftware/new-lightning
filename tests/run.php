<?php
/**
 * Storm Watch test suite.
 *
 *   php tests/run.php
 *
 * No framework: this has to run on the same bare PHP a shared host gives you.
 * Tests use a scratch SQLite database in the system temp directory and never
 * touch a real installation.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use StormWatch\Alert;
use StormWatch\AlertEngine;
use StormWatch\Auth;
use StormWatch\Config;
use StormWatch\Crypto;
use StormWatch\Database;
use StormWatch\Events;
use StormWatch\Geo;
use StormWatch\Ingest;
use StormWatch\Migrations;
use StormWatch\Notifiers\SlackNotifier;
use StormWatch\Providers\Simulator;
use StormWatch\Settings;
use StormWatch\Strikes;
use StormWatch\WebSocket\Client;
use StormWatch\WebSocket\Lzw;

// ---------------------------------------------------------------- harness --

final class T
{
    public static int $passed = 0;
    /** @var array<int,string> */
    public static array $failures = [];
    private static string $group = '';

    public static function group(string $name): void
    {
        self::$group = $name;
        echo "\n\033[1m" . $name . "\033[0m\n";
    }

    public static function ok(bool $condition, string $description): void
    {
        if ($condition) {
            self::$passed++;
            echo "  \033[32m✓\033[0m " . $description . "\n";
            return;
        }
        self::$failures[] = self::$group . ' — ' . $description;
        echo "  \033[31m✗ " . $description . "\033[0m\n";
    }

    public static function same($expected, $actual, string $description): void
    {
        $match = $expected === $actual;
        if (!$match && is_float($expected) && is_float($actual)) {
            $match = abs($expected - $actual) < 0.000001;
        }
        self::ok($match, $description . ($match ? '' : sprintf(
            ' (expected %s, got %s)',
            var_export($expected, true),
            var_export($actual, true)
        )));
    }

    public static function near(float $expected, float $actual, float $tolerance, string $description): void
    {
        self::ok(
            abs($expected - $actual) <= $tolerance,
            $description . (abs($expected - $actual) <= $tolerance ? '' : sprintf(' (expected ~%.4f, got %.4f)', $expected, $actual))
        );
    }
}

// ------------------------------------------------------------ scratch app --

$scratch = sys_get_temp_dir() . '/stormwatch-tests-' . getmypid();
@mkdir($scratch, 0777, true);
$dbFile = $scratch . '/test.sqlite';

Config::override([
    'db_driver' => 'sqlite',
    'db_path' => $dbFile,
    'app_key' => Config::generateAppKey(),
    'base_url' => 'https://storm.test',
]);
Database::reset();
Migrations::run(Database::instance());
Settings::flushCache();

register_shutdown_function(static function () use ($scratch): void {
    foreach (glob($scratch . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($scratch);
});

function resetData(): void
{
    $db = Database::instance();
    $db->run('DELETE FROM strikes');
    $db->run('DELETE FROM events');
    $db->run('DELETE FROM runs');
    $db->run(
        'UPDATE alert_state SET level = ?, since = ?, nearest_mi = NULL, nearest_at = NULL,
         notified_level = NULL, notified_at = NULL, notified_nearest_mi = NULL, muted_until = NULL WHERE id = 1',
        ['clear', time()]
    );
}

/** Store a strike at a bearing/distance from the venue, offset back in time. */
function placeStrike(float $distanceMi, float $bearingDeg = 45.0, int $secondsAgo = 0): ?array
{
    $point = Geo::destination(
        Settings::getFloat('venue_lat'),
        Settings::getFloat('venue_lon'),
        $bearingDeg,
        $distanceMi
    );
    return Strikes::record($point['lat'], $point['lon'], time() - $secondsAgo, 'test');
}

// ------------------------------------------------------------------ tests --

T::group('Geo');
{
    // London to Paris is ~213 statute miles.
    T::near(213.0, Geo::distanceMiles(51.5074, -0.1278, 48.8566, 2.3522), 3.0, 'haversine matches a known distance');
    T::near(0.0, Geo::distanceMiles(41.0, -74.0, 41.0, -74.0), 0.0001, 'distance to the same point is zero');
    T::near(90.0, Geo::bearingDegrees(0.0, 0.0, 0.0, 1.0), 0.5, 'due east reads as 90°');
    T::near(0.0, Geo::bearingDegrees(0.0, 0.0, 1.0, 0.0), 0.5, 'due north reads as 0°');
    T::same('N', Geo::compassPoint(0.0), 'compass point for 0°');
    T::same('SW', Geo::compassPoint(225.0), 'compass point for 225°');
    T::same('N', Geo::compassPoint(359.0), '359° rounds back to N');

    // destination() and distanceMiles() must agree, or every radius is a lie.
    foreach ([[5.0, 0.0], [12.5, 137.0], [29.9, 300.0], [1.0, 210.0]] as [$miles, $bearing]) {
        $point = Geo::destination(41.3608, -74.2854, $bearing, $miles);
        $back = Geo::distanceMiles(41.3608, -74.2854, $point['lat'], $point['lon']);
        T::near($miles, $back, 0.01, sprintf('destination/distance round-trip at %.1f mi bearing %.0f°', $miles, $bearing));
    }

    $box = Geo::boundingBox(41.3608, -74.2854, 30.0);
    T::ok($box['minLat'] < 41.3608 && $box['maxLat'] > 41.3608, 'bounding box brackets the latitude');
    T::ok(Geo::isValidLatitude(90.0) && !Geo::isValidLatitude(90.1), 'latitude validation');
    T::ok(Geo::isValidLongitude(-180.0) && !Geo::isValidLongitude(180.1), 'longitude validation');
    T::same('16.1 km', Geo::formatDistance(10.0, 'km'), 'kilometre formatting');
    T::same('10.0 mi', Geo::formatDistance(10.0, 'mi'), 'mile formatting');
}

T::group('LZW codec');
{
    $cases = [
        'simple repeated text' => 'TOBEORNOTTOBEORTOBEORNOT',
        'a strike record' => '{"time":1700000000000000000,"lat":41.3608,"lon":-74.2854,"alt":0,"status":0}',
        // The classic LZW edge case: a code that is used in the same step it is defined.
        'the KwKwK case' => 'aaaaaaaaaaaaaaaaaaaa',
        'single character' => 'x',
        'two characters' => 'ab',
        'long repetitive payload' => str_repeat('{"lat":41.0,"lon":-74.0}', 50),
    ];
    foreach ($cases as $label => $input) {
        T::same($input, Lzw::decode(Lzw::encode($input)), 'round-trips ' . $label);
    }
    T::same('', Lzw::decode(''), 'empty input decodes to empty');
    T::ok(mb_strlen(Lzw::encode(str_repeat('ab', 200))) < 400, 'output is actually compressed');
}

T::group('Timestamp and path parsing');
{
    $now = time();
    T::same(1700000000, Ingest::parseTimestamp(1700000000, 'epoch_s'), 'seconds');
    T::same(1700000000, Ingest::parseTimestamp(1700000000000, 'epoch_ms'), 'milliseconds');
    T::same(1700000000, Ingest::parseTimestamp(1700000000000000000, 'epoch_ns'), 'nanoseconds');
    T::same(1700000000, Ingest::parseTimestamp(1700000000, 'auto'), 'auto detects seconds');
    T::same(1700000000, Ingest::parseTimestamp(1700000000000, 'auto'), 'auto detects milliseconds');
    T::same(1700000000, Ingest::parseTimestamp(1700000000000000000, 'auto'), 'auto detects nanoseconds');
    T::same(1700000000, Ingest::parseTimestamp('2023-11-14T22:13:20Z', 'iso8601'), 'ISO-8601 text');
    T::same(1700000000, Ingest::parseTimestamp('2023-11-14T22:13:20Z', 'auto'), 'auto handles ISO-8601 text');
    T::ok(abs(Ingest::parseTimestamp(null) - $now) < 2, 'a missing timestamp falls back to now');
    T::ok(abs(Ingest::parseTimestamp('not a date') - $now) < 2, 'an unparseable timestamp falls back to now');

    $document = ['data' => ['strikes' => [['coords' => ['lat' => 1.5, 'lon' => 2.5]]]]];
    T::same(1.5, Ingest::dotPath($document, 'data.strikes.0.coords.lat'), 'dot path with a numeric segment');
    T::ok(is_array(Ingest::dotPath($document, 'data.strikes')), 'dot path to a list');
    T::same(null, Ingest::dotPath($document, 'data.missing.thing'), 'missing path returns null');
    T::same($document, Ingest::dotPath($document, ''), 'empty path returns the document');
}

T::group('Secret storage');
{
    // Deliberately not shaped like a real Slack token: a convincing fake in a
    // public repository trips secret scanners and wastes someone's afternoon.
    $secret = 'NOT-A-REAL-TOKEN-placeholder-for-tests';
    $encrypted = Crypto::encrypt($secret);
    T::ok($encrypted !== $secret, 'the stored form is not the plaintext');
    T::ok(strpos($encrypted, 'NOT-A-REAL') === false, 'the plaintext does not leak into the ciphertext');
    T::same($secret, Crypto::decrypt($encrypted), 'decrypts back to the original');
    T::same('', Crypto::encrypt(''), 'empty stays empty');
    T::ok(Crypto::encrypt($secret) !== Crypto::encrypt($secret), 'each encryption uses a fresh nonce');
    T::same('', Crypto::decrypt('sw1s:not-real-base64-!!'), 'corrupt ciphertext decrypts to empty, not an exception');
    T::ok(strlen(Crypto::randomToken()) >= 24, 'random tokens are long enough');
    T::ok(Crypto::randomToken() !== Crypto::randomToken(), 'random tokens differ');
}

T::group('Settings');
{
    Settings::flushCache();
    T::same([], Settings::put(['alert_radius_mi' => 8, 'watch_radius_mi' => 16, 'display_radius_mi' => 25]), 'valid radii save');
    T::same(8.0, Settings::getFloat('alert_radius_mi'), 'float round-trips');

    $errors = Settings::put(['watch_radius_mi' => 2]);
    T::ok(isset($errors['watch_radius_mi']), 'watch radius smaller than alert radius is rejected');
    T::same(16.0, Settings::getFloat('watch_radius_mi'), 'a rejected batch changes nothing');

    $errors = Settings::put(['display_radius_mi' => 5]);
    T::ok(isset($errors['display_radius_mi']), 'display radius smaller than watch radius is rejected');

    $errors = Settings::put(['all_clear_minutes' => 9999]);
    T::ok(isset($errors['all_clear_minutes']), 'out-of-range integers are rejected');

    $errors = Settings::put(['units' => 'furlongs']);
    T::ok(isset($errors['units']), 'unknown enum values are rejected');

    $errors = Settings::put(['timezone' => 'Mars/Olympus']);
    T::ok(isset($errors['timezone']), 'unknown time zones are rejected');

    T::same([], Settings::put(['slack_bot_token' => 'NOT-A-REAL-TOKEN-settings-value']), 'a secret saves');
    T::same('NOT-A-REAL-TOKEN-settings-value', Settings::getString('slack_bot_token'), 'the secret round-trips');
    T::same([], Settings::put(['slack_bot_token' => '']), 'an empty post leaves the secret alone');
    T::same('NOT-A-REAL-TOKEN-settings-value', Settings::getString('slack_bot_token'), 'the stored secret survived the empty post');
    T::same([], Settings::put(['slack_bot_token' => Settings::CLEAR_SECRET]), 'the clear sentinel is accepted');
    T::same('', Settings::getString('slack_bot_token'), 'the secret was cleared');

    $safe = Settings::safe();
    T::ok(!array_key_exists('slack_bot_token', $safe), 'safe() never exposes a secret value');
    T::ok(array_key_exists('slack_bot_token_set', $safe), 'safe() reports whether a secret is set');

    T::same([], Settings::put(['email_to' => ' a@example.com , b@example.com ']), 'csv normalises');
    T::same(['a@example.com', 'b@example.com'], Settings::getList('email_to'), 'csv parses into a list');

    $errors = Settings::put(['slack_enabled' => true, 'slack_mode' => 'bot', 'slack_channel' => '']);
    T::ok(isset($errors['slack_channel']) || isset($errors['slack_bot_token']), 'enabling Slack without credentials is rejected');

    Settings::put(['slack_enabled' => false]);
    Settings::ensureTokens();
    T::ok(Settings::getString('ingest_token') !== '', 'ingest token is generated');
    T::ok(Settings::getString('cron_token') !== Settings::getString('kiosk_token'), 'generated tokens are distinct');

    // Restore the defaults the alerting tests expect.
    Settings::put([
        'venue_lat' => 41.3608, 'venue_lon' => -74.2854,
        'alert_radius_mi' => 10, 'watch_radius_mi' => 20, 'display_radius_mi' => 30,
        'all_clear_minutes' => 30, 'realert_minutes' => 15, 'closer_delta_mi' => 3,
        'notify_watch' => false, 'notify_all_clear' => true, 'units' => 'mi',
    ]);
}

T::group('Strike storage');
{
    resetData();
    $strike = placeStrike(5.0, 90.0);
    T::ok($strike !== null, 'a strike inside the display radius is stored');
    T::near(5.0, (float) $strike['distance_mi'], 0.02, 'the stored distance is correct');
    T::near(90.0, (float) $strike['bearing_deg'], 0.5, 'the stored bearing is correct');

    T::same(null, placeStrike(5.0, 90.0), 'the same strike is not stored twice');
    T::same(null, placeStrike(45.0, 90.0), 'a strike beyond the display radius is dropped');
    T::same(null, Strikes::record(999.0, 0.0, time(), 'test'), 'an impossible latitude is dropped');

    $far = placeStrike(15.0, 180.0);
    T::ok($far !== null, 'a second distinct strike is stored');
    T::same(2, Strikes::stats(3600)['total'], 'statistics count both strikes');
    T::same(1, Strikes::stats(3600)['close'], 'only one is inside the alert radius');
    T::near(5.0, (float) Strikes::stats(3600)['nearest_mi'], 0.02, 'the nearest distance is reported');

    T::ok(Strikes::nearest(3600)['distance_mi'] < 6.0, 'nearest() finds the closest strike');
    T::ok(Strikes::latestWithin(10.0, 3600) !== null, 'latestWithin finds a strike inside a radius');
    T::same(null, Strikes::latestWithin(1.0, 3600), 'latestWithin respects the radius');

    // A timestamp from the far future must not poison the "last hour" window.
    $point = Geo::destination(41.3608, -74.2854, 12.0, 3.0);
    $odd = Strikes::record($point['lat'], $point['lon'], time() + 99999, 'test');
    T::ok($odd !== null && abs((int) $odd['struck_at'] - time()) < 5, 'an implausible timestamp is clamped to now');

    Database::instance()->run('UPDATE strikes SET struck_at = ?', [time() - 100000]);
    T::ok(Strikes::prune(1) > 0, 'prune removes expired strikes');
    T::same(0, Strikes::stats(86400)['total'], 'nothing survives the prune');
}

T::group('Alert state machine');
{
    resetData();
    T::same('clear', AlertEngine::evaluate(false)['level'], 'starts clear');

    placeStrike(15.0);
    T::same('watch', AlertEngine::evaluate(false)['level'], 'a strike inside the watch radius raises a watch');

    placeStrike(4.0);
    $state = AlertEngine::evaluate(false)['level'];
    T::same('warning', $state, 'a strike inside the alert radius raises a warning');

    resetData();
    placeStrike(4.0);
    AlertEngine::evaluate();
    T::same(1, count(Events::recent(50, 'alert.warning')), 'the warning is announced once');
    AlertEngine::evaluate();
    AlertEngine::evaluate();
    T::same(1, count(Events::recent(50, 'alert.warning')), 'repeated evaluation does not re-announce');

    // Age everything past the all-clear window.
    Database::instance()->run('UPDATE strikes SET struck_at = ?', [time() - (31 * 60)]);
    T::same('clear', AlertEngine::evaluate()['level'], 'the level clears once the window passes');
    T::same(1, count(Events::recent(50, 'alert.all_clear')), 'the all clear is announced');

    // A different bearing, so this is a genuinely new strike rather than one
    // the de-duplication key would collapse into the previous one.
    placeStrike(8.0, 120.0);
    AlertEngine::evaluate();
    T::same(2, count(Events::recent(50, 'alert.warning')), 'a new storm alerts again');

    // Re-alert once the storm has closed in by more than closer_delta_mi.
    placeStrike(0.5, 300.0);
    AlertEngine::evaluate();
    T::ok(count(Events::recent(50, 'alert.update')) >= 1, 'a closer strike sends an update');

    // Muting suppresses delivery but still resolves the state.
    resetData();
    placeStrike(4.0);
    AlertEngine::evaluate();
    AlertEngine::mute(30);
    Database::instance()->run('UPDATE strikes SET struck_at = ?', [time() - (31 * 60)]);
    $muted = AlertEngine::evaluate();
    T::same('clear', $muted['level'], 'the state still resolves while muted');
    T::same(0, count(Events::recent(50, 'alert.all_clear')), 'no announcement is made while muted');
    T::ok(count(Events::recent(50, 'alert.suppressed')) >= 1, 'the suppression is logged');
    AlertEngine::unmute();
    T::same(null, AlertEngine::publicState()['muted_until'], 'un-muting clears the mute');

    // notify_all_clear off: the state must still reset so the next storm alerts.
    resetData();
    Settings::put(['notify_all_clear' => false]);
    placeStrike(4.0);
    AlertEngine::evaluate();
    Database::instance()->run('UPDATE strikes SET struck_at = ?', [time() - (31 * 60)]);
    AlertEngine::evaluate();
    T::same(0, count(Events::recent(50, 'alert.all_clear')), 'no all clear is sent when it is switched off');
    resetData();
    placeStrike(4.0);
    AlertEngine::evaluate();
    T::same(1, count(Events::recent(50, 'alert.warning')), 'the next storm still alerts');
    Settings::put(['notify_all_clear' => true]);

    // all_clear_minutes controls the hold time.
    resetData();
    Settings::put(['all_clear_minutes' => 5]);
    placeStrike(4.0);
    AlertEngine::evaluate(false);
    Database::instance()->run('UPDATE strikes SET struck_at = ?', [time() - (4 * 60)]);
    T::same('warning', AlertEngine::evaluate(false)['level'], 'still warning inside the hold window');
    Database::instance()->run('UPDATE strikes SET struck_at = ?', [time() - (6 * 60)]);
    T::same('clear', AlertEngine::evaluate(false)['level'], 'clears once the hold window passes');
    Settings::put(['all_clear_minutes' => 30]);
}

T::group('Concurrent evaluation');
{
    // tick.php and worker.php run from the same cron minute and can overlap.
    // Whichever wins the lock announces; the others must stay quiet.
    resetData();
    placeStrike(4.0, 75.0);

    $appKey = (string) Config::get('app_key');
    $startAt = microtime(true) + 0.6;
    $processes = [];
    for ($i = 0; $i < 4; $i++) {
        $processes[] = proc_open(
            sprintf(
                '%s %s %s %s %s',
                escapeshellarg(PHP_BINARY),
                escapeshellarg(__DIR__ . '/support/evaluate_once.php'),
                escapeshellarg($dbFile),
                escapeshellarg($appKey),
                escapeshellarg((string) $startAt)
            ),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $processes[count($processes) - 1] = ['proc' => end($processes), 'pipes' => $pipes];
    }

    $levels = [];
    foreach ($processes as $entry) {
        if (!is_resource($entry['proc'])) {
            continue;
        }
        $levels[] = trim((string) stream_get_contents($entry['pipes'][1]));
        foreach ($entry['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($entry['proc']);
    }

    T::same(4, count($levels), 'all four processes completed');
    T::ok(count(array_unique($levels)) === 1 && $levels[0] === 'warning', 'every process agrees on the level');

    Settings::flushCache();
    T::same(1, count(Events::recent(50, 'alert.warning')), 'the alert is announced exactly once');
}

T::group('Slack message construction');
{
    Settings::put(['slack_mention' => 'channel', 'venue_name' => 'Castle Fun Center']);

    $warning = new Alert(Alert::KIND_WARNING, 'Lightning within 10.0 mi', 'Move activities indoors.');
    $warning->nearestMi = 4.2;
    $warning->bearingDeg = 90.0;
    $warning->struckAt = time();
    $warning->strikeCount = 7;

    $payload = SlackNotifier::messagePayload($warning);
    $json = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);

    T::ok(isset($payload['text']) && $payload['text'] !== '', 'a plain-text fallback is always present');
    T::ok(strpos($payload['text'], '<!channel>') !== false, 'the mention is in the notification text');
    T::same('#FF4D5E', $payload['attachments'][0]['color'], 'warnings use the danger colour');
    T::same('header', $payload['attachments'][0]['blocks'][0]['type'], 'the message leads with a header block');
    T::ok(strpos($json, '4.2 mi') !== false, 'the distance appears in the message');
    T::ok(strpos($json, 'E of the venue') !== false, 'the direction appears in the message');
    T::ok(strpos($json, 'https://storm.test') !== false, 'the dashboard link uses the configured base URL');
    T::ok(mb_strlen($payload['attachments'][0]['blocks'][0]['text']['text']) <= 150, 'the header respects Slack\'s length limit');

    $allClear = new Alert(Alert::KIND_ALL_CLEAR, 'All clear', 'Normal operations can resume.');
    $clearPayload = SlackNotifier::messagePayload($allClear);
    T::ok(strpos($clearPayload['text'], '<!channel>') === false, 'all-clear messages never mention the channel');
    T::same('#4ADE9C', $clearPayload['attachments'][0]['color'], 'the all clear uses the safe colour');

    $nasty = new Alert(Alert::KIND_WARNING, 'Lightning', 'Watch out <script>alert(1)</script> & friends');
    $nastyPayload = SlackNotifier::messagePayload($nasty);
    $nastySection = $nastyPayload['attachments'][0]['blocks'][1]['text']['text'];
    T::ok(strpos($nastySection, '&lt;script&gt;') !== false, 'angle brackets in message text are escaped for Slack');
    T::ok(strpos($nastySection, '&amp; friends') !== false, 'ampersands in message text are escaped for Slack');
    T::ok(strpos($nastySection, '<script>') === false, 'raw markup never reaches Slack');

    // A long field list has to be split across sections: Slack allows 10 each.
    $many = new Alert(Alert::KIND_WARNING, 'Lightning', 'Summary');
    for ($i = 0; $i < 14; $i++) {
        $many->details['Field ' . $i] = 'value ' . $i;
    }
    $manyPayload = SlackNotifier::messagePayload($many);
    foreach ($manyPayload['attachments'][0]['blocks'] as $block) {
        if (isset($block['fields'])) {
            T::ok(count($block['fields']) <= 10, 'no section exceeds ten fields');
        }
    }
    Settings::put(['slack_mention' => 'none']);
}

T::group('Accounts');
{
    T::ok(Auth::passwordProblem('short') !== null, 'short passwords are rejected');
    T::ok(Auth::passwordProblem('1234567890123') !== null, 'all-digit passwords are rejected');
    T::same(null, Auth::passwordProblem('a-reasonable-passphrase'), 'a decent passphrase is accepted');

    $created = Auth::createUser('tester', 'a-reasonable-passphrase', 'Test User');
    T::ok($created['ok'], 'an account can be created');
    $duplicate = Auth::createUser('tester', 'another-good-passphrase');
    T::ok(!$duplicate['ok'], 'duplicate usernames are refused');
    $badName = Auth::createUser('ab', 'another-good-passphrase');
    T::ok(!$badName['ok'], 'invalid usernames are refused');

    $changed = Auth::changePassword((int) $created['id'], 'wrong-password', 'a-new-passphrase');
    T::ok(!$changed['ok'], 'changing a password requires the current one');
    $changed = Auth::changePassword((int) $created['id'], 'a-reasonable-passphrase', 'a-new-passphrase');
    T::ok($changed['ok'], 'the password can be changed with the correct current one');

    $stored = Database::instance()->first('SELECT password_hash FROM users WHERE id = ?', [(int) $created['id']]);
    T::ok(strpos((string) $stored['password_hash'], 'a-new-passphrase') === false, 'passwords are stored hashed');
    T::ok(password_verify('a-new-passphrase', (string) $stored['password_hash']), 'the stored hash verifies');
}

T::group('Simulator');
{
    resetData();
    Simulator::reset();
    $produced = 0;
    for ($i = 0; $i < 30; $i++) {
        $produced += Simulator::tick()['ingested'];
    }
    T::ok($produced > 0, 'the simulator eventually produces strikes');
    $single = Simulator::singleStrike(3.0);
    T::ok($single !== null, 'a single strike can be placed on demand');
    T::near(3.0, (float) $single['distance_mi'], 0.05, 'the requested distance is honoured');
}

T::group('WebSocket client');
{
    $port = random_int(21000, 39000);
    $serverScript = __DIR__ . '/support/ws_server.php';
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(
        sprintf('%s %s %d', escapeshellarg(PHP_BINARY), escapeshellarg($serverScript), $port),
        $descriptors,
        $pipes
    );

    if (!is_resource($process)) {
        T::ok(false, 'the test WebSocket server could not be started');
    } else {
        stream_set_blocking($pipes[1], true);
        $ready = fgets($pipes[1]);

        if (trim((string) $ready) !== 'READY') {
            T::ok(false, 'the test WebSocket server did not come up: ' . trim((string) stream_get_contents($pipes[2])));
        } else {
            try {
                $client = Client::connect('ws://127.0.0.1:' . $port . '/', ['timeout' => 5.0]);
                T::ok(true, 'the handshake completes and Sec-WebSocket-Accept verifies');

                $client->sendText('{"a":111}');

                $echo = $client->receive(5.0);
                $echoData = json_decode($echo['payload'] ?? '', true);
                T::same('{"a":111}', $echoData['echo'] ?? null, 'the subscribe message arrives intact');
                T::same(true, $echoData['masked'] ?? null, 'client frames are masked, as the protocol requires');

                $frame = $client->receive(5.0);
                $strike = json_decode(Lzw::decode($frame['payload'] ?? ''), true);
                T::near(41.40, (float) ($strike['lat'] ?? 0), 0.001, 'a compressed strike frame decodes correctly');

                // The ping is answered inside receive(), so the next thing we
                // see is the fragmented message.
                $fragmented = $client->receive(5.0);
                $second = json_decode(Lzw::decode($fragmented['payload'] ?? ''), true);
                T::near(41.30, (float) ($second['lat'] ?? 0), 0.001, 'a fragmented message is reassembled');

                $pongReport = $client->receive(5.0);
                $reported = json_decode($pongReport['payload'] ?? '', true);
                T::same('1', $reported['pong_received'] ?? null, 'the client answers a ping with a pong');

                $large = $client->receive(5.0);
                $largeData = json_decode($large['payload'] ?? '', true);
                T::same(400, strlen((string) ($largeData['filler'] ?? '')), 'a frame using the 16-bit length path is read whole');

                $closed = false;
                try {
                    $client->receive(5.0);
                } catch (Throwable $e) {
                    $closed = strpos($e->getMessage(), 'closed') !== false;
                }
                T::ok($closed, 'a close frame surfaces as a clean error');

                $client->close();
            } catch (Throwable $e) {
                T::ok(false, 'WebSocket exchange failed: ' . $e->getMessage());
            }
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_terminate($process);
        proc_close($process);
    }

    // A refused connection must produce a useful message, not a warning.
    try {
        Client::connect('ws://127.0.0.1:1/', ['timeout' => 2.0]);
        T::ok(false, 'connecting to a dead port should throw');
    } catch (Throwable $e) {
        T::ok(strpos($e->getMessage(), 'Could not open a connection') === 0, 'a refused connection explains itself');
    }
}

T::group('Blitzortung frame decoding');
{
    Settings::put(['venue_lat' => 41.3608, 'venue_lon' => -74.2854, 'display_radius_mi' => 30]);
    Settings::flushCache();

    $near = Lzw::encode((string) json_encode(['time' => 1700000000000000000, 'lat' => 41.40, 'lon' => -74.30]));
    $decoded = \StormWatch\Providers\Blitzortung::decodeFrame($near, false);
    T::ok($decoded !== null, 'a nearby frame decodes');
    T::same(1700000000, $decoded['ts'] ?? 0, 'nanosecond timestamps are converted');

    $far = Lzw::encode((string) json_encode(['time' => 1700000000000000000, 'lat' => -33.8, 'lon' => 151.2]));
    T::same(null, \StormWatch\Providers\Blitzortung::decodeFrame($far, true), 'a distant frame is filtered out');
    T::ok(\StormWatch\Providers\Blitzortung::decodeFrame($far, false) !== null, 'the same frame decodes when filtering is off');
    T::same(null, \StormWatch\Providers\Blitzortung::decodeFrame('not compressed json'), 'garbage decodes to null, not an exception');
    T::same(null, \StormWatch\Providers\Blitzortung::decodeFrame(Lzw::encode('{"no":"coords"}')), 'a frame without coordinates is skipped');

    $servers = \StormWatch\Providers\Blitzortung::servers();
    T::ok($servers !== [], 'a server list is always available');
    Settings::put(['blitz_servers' => 'evil.example.com,ws3']);
    T::same(['ws3'], \StormWatch\Providers\Blitzortung::servers(), 'only well-formed server names are accepted');
    Settings::put(['blitz_servers' => 'ws1,ws7,ws8']);
}

// ----------------------------------------------------------------- report --

echo "\n";
if (T::$failures === []) {
    echo "\033[32m" . T::$passed . " checks passed.\033[0m\n";
    exit(0);
}

echo "\033[31m" . count(T::$failures) . ' of ' . (T::$passed + count(T::$failures)) . " checks failed:\033[0m\n";
foreach (T::$failures as $failure) {
    echo '  - ' . $failure . "\n";
}
exit(1);

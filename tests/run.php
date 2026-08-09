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
use StormWatch\Providers\Rest;
use StormWatch\Providers\Simulator;
use StormWatch\Runner;
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

    // That fallback is safe for one record and dangerous for all of them: it
    // is what turns a wrong time mapping into an endpoint's whole history
    // arriving as strikes happening this minute. Rest::fetch tells the two
    // apart with this.
    T::ok(Ingest::isReadableTimestamp(1700000000, 'auto'), 'epoch seconds read cleanly');
    T::ok(Ingest::isReadableTimestamp('2023-11-14T22:13:20Z', 'auto'), 'ISO-8601 text reads cleanly');
    T::ok(Ingest::isReadableTimestamp('1700000000', 'epoch_s'), 'a numeric string reads cleanly');
    T::ok(!Ingest::isReadableTimestamp(null, 'auto'), 'a missing field does not');
    T::ok(!Ingest::isReadableTimestamp('', 'auto'), 'nor an empty one');
    T::ok(!Ingest::isReadableTimestamp('not a date', 'auto'), 'nor unparseable text');
    T::ok(!Ingest::isReadableTimestamp(0, 'auto'), 'nor a zero epoch');

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
    // Updates have a floor between them, so backdate the last one past it.
    Database::instance()->run('UPDATE alert_state SET notified_at = ? WHERE id = 1', [time() - 600]);
    placeStrike(0.5, 300.0);
    AlertEngine::evaluate();
    T::ok(count(Events::recent(50, 'alert.update')) >= 1, 'a closer strike sends an update');

    // ...but a second one moments later is held back by that floor.
    $updatesBefore = count(Events::recent(50, 'alert.update'));
    placeStrike(0.4, 301.0);
    AlertEngine::evaluate();
    T::same($updatesBefore, count(Events::recent(50, 'alert.update')), 'a second update is held back by the floor');

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

T::group('Mount point detection');
{
    // The SCRIPT_NAME / REQUEST_URI pairs below were captured from a real
    // Apache 2.4 serving this app's own .htaccess from a subfolder. Under that
    // rewrite SCRIPT_NAME carries a "/public" segment the browser never sees,
    // and basePath() has to see through it — otherwise the session cookie is
    // scoped to a path the visitor is not on and sign-in fails.
    $mount = static function (string $script, string $uri): string {
        $_SERVER['SCRIPT_NAME'] = $script;
        $_SERVER['REQUEST_URI'] = $uri;
        // A web server also sets SCRIPT_FILENAME to the file it is executing,
        // and the mount detection uses it to tell a real public/api/ request
        // from an app that merely lives in a folder called "api".
        $segments = explode('/', trim($script, '/'));
        $tail = count($segments) >= 2 && $segments[count($segments) - 2] === 'api'
            ? 'api/' . end($segments)
            : end($segments);
        $_SERVER['SCRIPT_FILENAME'] = SW_ROOT . '/public/' . $tail;
        \StormWatch\Http::resetBasePath();
        return \StormWatch\Http::basePath();
    };

    T::same('/stormwatch', $mount('/stormwatch/public/index.php', '/stormwatch/'), 'subfolder root');
    T::same('/stormwatch', $mount('/stormwatch/public/settings.php', '/stormwatch/settings.php'), 'subfolder page');
    T::same('/stormwatch', $mount('/stormwatch/public/api/state.php', '/stormwatch/api/state.php'), 'subfolder API endpoint');
    T::same('/stormwatch', $mount('/stormwatch/public/settings.php', '/stormwatch/settings.php?tab=slack'), 'query string is ignored');
    T::same('/apps/storm', $mount('/apps/storm/public/index.php', '/apps/storm/'), 'nested subfolder');

    T::same('', $mount('/index.php', '/'), 'document root pointed at public/');
    T::same('', $mount('/settings.php', '/settings.php'), 'document root, page');
    T::same('', $mount('/api/state.php', '/api/state.php'), 'document root, API endpoint');
    T::same('', $mount('/public/settings.php', '/settings.php'), 'whole app at the domain root');

    // Someone who reaches the internal path directly must still get a base
    // path that matches the URL they are on, or their session breaks.
    T::same('/stormwatch/public', $mount('/stormwatch/public/settings.php', '/stormwatch/public/settings.php'), 'direct hit on the internal path stays consistent');
    // ...and an app that genuinely lives in a folder called "public".
    T::same('/public', $mount('/public/settings.php', '/public/settings.php'), 'a directory really named public is left alone');

    // The explicit override wins over anything inferred.
    Config::override(['base_path' => '/stormwatch']);
    T::same('/stormwatch', $mount('/anything/public/index.php', '/x'), 'configured base path overrides detection');
    Config::override(['base_path' => 'stormwatch/']);
    T::same('/stormwatch', $mount('/anything/public/index.php', '/x'), 'the override is normalised');
    Config::override(['base_path' => '/']);
    T::same('', $mount('/anything/public/index.php', '/x'), 'an override of "/" means the root');
    Config::override(['base_path' => '']);

    // Two installs on one domain must not share a session cookie or storage.
    $mount('/stormwatch/public/index.php', '/stormwatch/');
    $nameA = \StormWatch\Http::sessionName();
    $storeA = \StormWatch\Http::storagePrefix();
    $mount('/weather/public/index.php', '/weather/');
    T::ok($nameA !== \StormWatch\Http::sessionName(), 'session cookie names differ per mount');
    T::ok($storeA !== \StormWatch\Http::storagePrefix(), 'browser storage prefixes differ per mount');

    $mount('/index.php', '/');
    T::same('stormwatch_session', \StormWatch\Http::sessionName(), 'a root install keeps the plain cookie name');

    unset($_SERVER['SCRIPT_NAME'], $_SERVER['REQUEST_URI']);
    \StormWatch\Http::resetBasePath();
}

T::group('Sign-in redirect target');
{
    // Auth::requireLogin() stores where the visitor was heading, and
    // Http::redirect() puts the mount back on. If the stored value already
    // carried the mount, the two would compound into /stormwatch/stormwatch/…
    $relative = static function (string $script, string $uri): string {
        $_SERVER['SCRIPT_NAME'] = $script;
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['SCRIPT_FILENAME'] = SW_ROOT . '/public/index.php';
        \StormWatch\Http::resetBasePath();
        return \StormWatch\Http::relativeUri();
    };

    T::same('/settings.php', $relative('/stormwatch/public/settings.php', '/stormwatch/settings.php'), 'the mount is stripped from the stored path');
    T::same('/settings.php?tab=slack', $relative('/stormwatch/public/settings.php', '/stormwatch/settings.php?tab=slack'), 'the query string survives');
    T::same('/', $relative('/stormwatch/public/index.php', '/stormwatch/'), 'the mount root becomes /');
    T::same('/settings.php', $relative('/settings.php', '/settings.php'), 'a root install is unchanged');

    // The round trip is what actually matters.
    $_SERVER['SCRIPT_NAME'] = '/stormwatch/public/login.php';
    $_SERVER['REQUEST_URI'] = '/stormwatch/settings.php';
    $_SERVER['SCRIPT_FILENAME'] = SW_ROOT . '/public/login.php';
    \StormWatch\Http::resetBasePath();
    T::same(
        '/stormwatch/settings.php',
        \StormWatch\Http::url(\StormWatch\Http::relativeUri()),
        'storing then rebuilding the target gives the original URL back'
    );

    unset($_SERVER['SCRIPT_NAME'], $_SERVER['REQUEST_URI'], $_SERVER['SCRIPT_FILENAME']);
    \StormWatch\Http::resetBasePath();
}

T::group('Alerting when locking is impossible');
{
    // A host where data/locks cannot be created must still send alerts —
    // silently never alerting is the worst possible failure for this app.
    // Occupying the path with a file makes both mkdir and fopen fail.
    $lockDir = SW_DATA . '/locks';
    $restore = null;
    if (is_dir($lockDir)) {
        $restore = $lockDir . '-testbak';
        @rename($lockDir, $restore);
    }
    @file_put_contents($lockDir, 'not a directory');

    resetData();
    placeStrike(4.0, 33.0);
    AlertEngine::evaluate();
    T::same(1, count(Events::recent(50, 'alert.warning')), 'an alert is still sent with no lock available');
    T::ok(count(Events::recent(50, 'system.lock_unavailable')) >= 1, 'and the missing lock is reported');

    @unlink($lockDir);
    if ($restore !== null) {
        @rename($restore, $lockDir);
    }
}

T::group('Mute holds alerts rather than dropping them');
{
    resetData();
    Settings::put(['all_clear_minutes' => 30, 'cooldown_scope' => 'alert', 'realert_minutes' => 0, 'closer_delta_mi' => 0.0]);

    AlertEngine::mute(30);
    placeStrike(4.0, 55.0);
    AlertEngine::evaluate();
    T::same(0, count(Events::recent(50, 'alert.warning')), 'nothing is announced while muted');
    T::same('warning', AlertEngine::publicState()['level'], 'the level still tracks the storm');

    // Several cron ticks pass; the log must not fill with suppression notices.
    AlertEngine::evaluate();
    AlertEngine::evaluate();
    T::ok(count(Events::recent(50, 'alert.suppressed')) <= 1, 'the mute is logged once, not once per tick');

    // The storm is still going when the mute expires: staff must be told.
    AlertEngine::unmute();
    AlertEngine::evaluate();
    T::same(1, count(Events::recent(50, 'alert.warning')), 'the held alert is delivered once the mute expires');
}

T::group('Read-only evaluation');
{
    resetData();
    placeStrike(4.0, 66.0);
    AlertEngine::evaluate(false);
    T::same('warning', AlertEngine::publicState()['level'], 'a read-only evaluation still resolves the level');
    T::same(0, count(Events::recent(50, 'alert.warning')), 'and announces nothing');

    AlertEngine::evaluate();
    T::same(1, count(Events::recent(50, 'alert.warning')), 'the alert is not consumed by the read-only pass');
}

T::group('Alert cooldown');
{
    // "Alert when lightning is within X miles, then do not alert again until
    // it has been Y minutes without any strikes, then say it is safe."
    $applyCooldown = static function (int $minutes, string $scope = 'alert', int $repeat = 0, float $closer = 0.0): void {
        Settings::put([
            'alert_radius_mi' => 10, 'watch_radius_mi' => 20, 'display_radius_mi' => 30,
            'all_clear_minutes' => $minutes, 'cooldown_scope' => $scope,
            'realert_minutes' => $repeat, 'closer_delta_mi' => $closer,
            'notify_watch' => false, 'notify_all_clear' => true,
        ]);
    };

    // Defaults must be the quiet ones, or a storm produces a stream of alerts.
    Settings::flushCache();
    $defaults = Settings::defaults();
    T::same(0, $defaults['realert_minutes'], 'repeat alerts are off by default');
    T::same(0.0, $defaults['closer_delta_mi'], 'closing-in updates are off by default');
    T::same('alert', $defaults['cooldown_scope'], 'the cooldown watches the alert radius by default');

    // A 40-minute storm, one strike a minute, then quiet. Exactly one alert
    // and, once the cooldown expires, exactly one all clear.
    resetData();
    $applyCooldown(30);
    for ($minute = 0; $minute <= 40; $minute++) {
        placeStrike(5.0, ($minute * 37) % 360, (40 - $minute) * 60);
        AlertEngine::evaluate();
    }
    T::same(1, count(Events::recent(200, 'alert.warning')), 'a 40-minute storm produces exactly one alert');
    T::same(0, count(Events::recent(200, 'alert.update')), 'no update alerts are sent during the storm');
    T::same(0, count(Events::recent(200, 'alert.all_clear')), 'no all clear while strikes are still arriving');
    T::same('warning', AlertEngine::publicState()['level'], 'still in warning at the end of the storm');

    // 29 minutes of quiet is not enough.
    Database::instance()->run('UPDATE strikes SET struck_at = ?', [time() - (29 * 60)]);
    AlertEngine::evaluate();
    T::same('warning', AlertEngine::publicState()['level'], 'still holding one minute short of the cooldown');
    T::same(0, count(Events::recent(200, 'alert.all_clear')), 'no early all clear');

    // 31 minutes is.
    Database::instance()->run('UPDATE strikes SET struck_at = ?', [time() - (31 * 60)]);
    AlertEngine::evaluate();
    T::same('clear', AlertEngine::publicState()['level'], 'clears once the cooldown expires');
    T::same(1, count(Events::recent(200, 'alert.all_clear')), 'exactly one all clear');
    T::same(1, count(Events::recent(200, 'alert.warning')), 'still only one alert for the whole storm');

    // A fresh storm afterwards alerts again.
    placeStrike(4.0, 12.0);
    AlertEngine::evaluate();
    T::same(2, count(Events::recent(200, 'alert.warning')), 'the next storm alerts again');

    // Cooldown scope: a storm that triggers an alert, then drifts out to sit
    // between the alert and watch rings while still throwing lightning.
    $storyThenDrift = static function (string $scope): string {
        resetData();
        Settings::put([
            'alert_radius_mi' => 10, 'watch_radius_mi' => 20, 'display_radius_mi' => 30,
            'all_clear_minutes' => 30, 'cooldown_scope' => $scope,
            'realert_minutes' => 0, 'closer_delta_mi' => 0.0,
            'notify_watch' => false, 'notify_all_clear' => true,
        ]);
        placeStrike(5.0, 40.0);                 // inside the alert ring
        AlertEngine::evaluate();                // -> warning
        // The alert ring falls quiet, but the storm keeps striking at 15 mi.
        Database::instance()->run('UPDATE strikes SET struck_at = ?', [time() - (31 * 60)]);
        placeStrike(15.0, 41.0);
        AlertEngine::evaluate();
        return AlertEngine::publicState()['level'];
    };

    T::same('watch', $storyThenDrift('alert'), 'scope "alert" stands down once the alert ring is quiet');
    T::same(1, count(Events::recent(200, 'alert.all_clear')), 'and announces the all clear');

    T::same('warning', $storyThenDrift('watch'), 'scope "watch" holds the warning while the storm circles');
    T::same(0, count(Events::recent(200, 'alert.all_clear')), 'no all clear while the wider ring is still active');
    T::same(1, count(Events::recent(200, 'alert.warning')), 'holding the warning does not re-alert');

    // Opt-in repeats still work for operators who want them.
    resetData();
    $applyCooldown(30, 'alert', 1, 0.0);
    placeStrike(5.0, 77.0);
    AlertEngine::evaluate();
    Database::instance()->run('UPDATE alert_state SET notified_at = ? WHERE id = 1', [time() - 120]);
    placeStrike(5.0, 78.0);
    AlertEngine::evaluate();
    T::ok(count(Events::recent(200, 'alert.update')) >= 1, 'an opt-in repeat interval still sends updates');

    $applyCooldown(30);
}

T::group('A storm that has been all-cleared stays closed');
{
    // The lookback for "is there lightning about" is the cooldown period, and
    // the cooldown is a setting. Lengthening it must not reach back over a
    // storm that is finished and already all-cleared.
    resetData();
    Settings::put([
        'alert_radius_mi' => 10, 'watch_radius_mi' => 20, 'display_radius_mi' => 30,
        'all_clear_minutes' => 30, 'cooldown_scope' => 'alert', 'notify_all_clear' => true,
    ]);

    // An afternoon storm, run to its all clear.
    placeStrike(5.0, 33.0);
    AlertEngine::evaluate();
    T::same(1, count(Events::recent(200, 'alert.warning')), 'the afternoon storm alerts');
    Database::instance()->run('UPDATE strikes SET struck_at = ?, received_at = ?', [time() - 8400, time() - 8400]);
    AlertEngine::evaluate();
    T::same('clear', AlertEngine::publicState()['level'], 'and is all-cleared once it is over');
    Database::instance()->run('UPDATE alert_state SET notified_at = ? WHERE id = 1', [time() - 6600]);

    // Hours later, under a clear sky, the operator wants a longer hold.
    Settings::put(['all_clear_minutes' => 240]);
    AlertEngine::evaluate();
    T::same('clear', AlertEngine::publicState()['level'], 'a longer cooldown does not revive a finished storm');
    T::same(1, count(Events::recent(200, 'alert.warning')), 'and does not alert on it a second time');

    // A genuinely new strike still gets through immediately.
    placeStrike(6.0, 210.0);
    AlertEngine::evaluate();
    T::same(2, count(Events::recent(200, 'alert.warning')), 'a real strike still alerts under the longer hold');

    // A strike that merely reached us late is new information, not history:
    // it struck before the last all clear but arrived after it.
    resetData();
    Settings::put(['all_clear_minutes' => 30]);
    Database::instance()->run(
        'UPDATE alert_state SET notified_level = ?, notified_at = ? WHERE id = 1',
        ['clear', time() - 60]
    );
    $late = placeStrike(4.0, 250.0, 15 * 60);   // struck 15 min ago, arriving now
    T::ok($late !== null, 'the late strike is stored');
    AlertEngine::evaluate();
    T::same(1, count(Events::recent(200, 'alert.warning')), 'a late-arriving strike still raises the alert');

    Settings::put(['all_clear_minutes' => 30]);
}

T::group('Clearing the strike log resets the announcement too');
{
    // The button says it resets the alert state. If it left "a warning has been
    // announced" behind, the next tick would find a warning with no strikes
    // under it and post an all clear drawn from an empty table.
    resetData();
    Settings::put(['all_clear_minutes' => 30, 'notify_all_clear' => true]);
    placeStrike(4.0, 88.0);
    AlertEngine::evaluate();
    T::same(1, count(Events::recent(200, 'alert.warning')), 'a warning is live');

    Strikes::deleteAll();
    AlertEngine::reset();
    AlertEngine::evaluate();
    T::same(0, count(Events::recent(200, 'alert.all_clear')), 'clearing the log does not broadcast an all clear');
    T::same('clear', AlertEngine::publicState()['level'], 'and the state is back to a standing start');

    placeStrike(4.0, 89.0);
    AlertEngine::evaluate();
    T::same(2, count(Events::recent(200, 'alert.warning')), 'the next strike alerts normally');
}

T::group('Closing time honours a mute');
{
    // Mute is the one setting that governs everything reaching Slack and
    // email. Closing time is not an exception to it.
    resetData();
    Settings::put(['all_clear_minutes' => 30, 'notify_all_clear' => true]);
    placeStrike(4.0, 200.0);
    AlertEngine::evaluate();
    AlertEngine::mute(30);

    AlertEngine::standDownForClosedHours();
    T::same(0, count(Events::recent(200, 'alert.all_clear')), 'no stand-down is posted while muted');
    T::ok(count(Events::recent(200, 'alert.stand_down')) >= 1, 'but it is recorded in the log');

    // Un-muted, the same stand-down is announced.
    resetData();
    placeStrike(4.0, 201.0);
    AlertEngine::evaluate();
    AlertEngine::unmute();
    AlertEngine::standDownForClosedHours();
    T::ok(count(Events::recent(200, 'alert.all_clear')) >= 1, 'un-muted, the stand-down is announced');
}

T::group('Legible on a wall display');
{
    // This page is read off a screen behind the counter, from ten feet away,
    // by someone deciding whether to clear the go-kart track. Text that is
    // merely decorative elsewhere is operational here.
    $css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/css/app.css');

    $luminance = static function (string $hex): float {
        $hex = ltrim($hex, '#');
        $channel = static function (float $v): float {
            $v /= 255;
            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        };
        return 0.2126 * $channel((float) hexdec(substr($hex, 0, 2)))
            + 0.7152 * $channel((float) hexdec(substr($hex, 2, 2)))
            + 0.0722 * $channel((float) hexdec(substr($hex, 4, 2)));
    };
    $contrast = static function (string $a, string $b) use ($luminance): float {
        $la = $luminance($a);
        $lb = $luminance($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    };
    $token = static function (string $name) use ($css): ?string {
        return preg_match('/--' . preg_quote($name, '/') . ':\s*(#[0-9A-Fa-f]{6})/', $css, $m) === 1 ? $m[1] : null;
    };

    $panel = $token('panel');
    T::ok($panel !== null, 'the panel background colour is readable from the stylesheet');

    if ($panel !== null) {
        foreach (['text-hi', 'text-mid', 'text-lo'] as $name) {
            $colour = $token($name);
            T::ok(
                $colour !== null && $contrast($colour, $panel) >= 4.5,
                sprintf(
                    '--%s meets WCAG AA against the panel (%.2f:1)',
                    $name,
                    $colour !== null ? $contrast($colour, $panel) : 0
                )
            );
        }
    }

    // The banner's second line is the one that says what to do about it.
    if (preg_match('/\.alert-text \.t2\{[^}]*font-size:([0-9.]+)px/', $css, $m) === 1) {
        T::ok(
            (float) $m[1] >= 14.0,
            sprintf('the banner\'s instruction line is at least 14px (%.1fpx)', (float) $m[1])
        );
    } else {
        T::ok(false, 'the banner instruction line has a font size to check');
    }
}

T::group('An alert nobody could deliver is not counted as sent');
{
    // The decision is recorded before delivery is attempted — that is what
    // stops two overlapping cron runs sending the same alert twice. It also
    // means a Slack outage during the one storm of the summer would file the
    // warning as announced and never mention it again.
    resetData();
    Settings::put([
        'alert_radius_mi' => 10, 'watch_radius_mi' => 20, 'display_radius_mi' => 30,
        'all_clear_minutes' => 30, 'notify_all_clear' => true,
    ]);
    // A Slack channel that is switched on and cannot possibly deliver. The
    // secret goes in first: the cross-field rules refuse to enable a webhook
    // with no URL behind it, which is the whole save rejected.
    Settings::putRaw('slack_webhook_url', 'https://hooks.slack.example.invalid/services/nope');
    T::same([], Settings::put(['slack_enabled' => true, 'slack_mode' => 'webhook']), 'the channel saves');

    T::ok(SlackNotifier::isEnabled(), 'the failing channel counts as enabled');

    placeStrike(4.0, 44.0);
    AlertEngine::evaluate();
    T::ok(count(Events::recent(200, 'slack.failed')) >= 1, 'the delivery failure is logged');
    T::ok(count(Events::recent(200, 'alert.undelivered')) >= 1, 'and the alert is marked as held, not sent');

    $state = AlertEngine::state();
    T::same(null, $state['notified_level'], 'the announcement is rolled back so the next run retries');

    // The storm is still going, so the retry has something to announce. Switch
    // the broken channel off — as an operator would once they saw the log —
    // and the held warning goes out on the next tick rather than being lost.
    // (No live endpoint is contacted: this suite must not depend on one.)
    Settings::put(['slack_enabled' => false]);
    $before = count(Events::recent(200, 'alert.warning'));
    AlertEngine::evaluate();
    T::ok(
        count(Events::recent(200, 'alert.warning')) > $before,
        'the held warning is announced again on the next run rather than lost'
    );
    T::same(
        'warning',
        (string) AlertEngine::state()['notified_level'],
        'and stays announced once it has been'
    );

    Settings::putRaw('slack_webhook_url', '');
}

T::group('The dashboard reports whether a channel works, not whether it is on');
{
    // A revoked token, or a bot removed from its channel, leaves the switch on.
    // A green tick over that is how nobody finds out until the storm.
    resetData();
    Settings::putRaw('slack_webhook_url', 'https://hooks.slack.com/services/T0/B0/x');
    Settings::put(['slack_enabled' => true, 'slack_mode' => 'webhook']);

    $delivery = Runner::status()['delivery'];
    T::same([], $delivery, 'a channel that has never been used reports nothing either way');

    Events::log('slack.sent', Events::SEVERITY_INFO, 'Delivered: Posted to #ops.', []);
    $delivery = Runner::status()['delivery'];
    T::ok(isset($delivery['slack']) && $delivery['slack']['ok'] === true, 'a delivered alert reads healthy');

    Events::log('slack.failed', Events::SEVERITY_ERROR, 'Delivery failed: The bot is not a member of that channel.', []);
    $delivery = Runner::status()['delivery'];
    T::ok(isset($delivery['slack']) && $delivery['slack']['ok'] === false, 'a rejected alert reads unhealthy');
    T::ok(
        isset($delivery['slack']) && strpos($delivery['slack']['message'], 'not a member') !== false,
        'and carries the reason the channel gave'
    );
    T::ok(Runner::status()['slack_ready'], 'while the channel is still reported as configured');

    Settings::put(['slack_enabled' => false]);
    Settings::putRaw('slack_webhook_url', '');
    resetData();
}

T::group('An address that cannot be written to is refused, not dropped');
{
    // The mailer discards anything that will not validate. Accepting a typo
    // here means an address that is never written to and never mentioned
    // again, while the dashboard goes on reporting email ready.
    Settings::put(['email_enabled' => false]);
    $errors = Settings::put([
        'email_enabled' => true,
        'email_from' => 'stormwatch@example.com',
        'email_to' => 'ops@example.com, manager@example, gm@example.com',
    ]);
    T::ok(isset($errors['email_to']), 'a malformed recipient is refused');
    T::ok(
        isset($errors['email_to']) && strpos($errors['email_to'], 'manager@example') !== false,
        'and the message names the address that is wrong'
    );

    $errors = Settings::put([
        'email_enabled' => true,
        'email_from' => 'not-an-address',
        'email_to' => 'ops@example.com',
    ]);
    T::ok(isset($errors['email_from']), 'a malformed "from" address is refused too');

    T::same([], Settings::put([
        'email_enabled' => true,
        'email_from' => 'stormwatch@example.com',
        'email_to' => 'ops@example.com, gm@example.com',
    ]), 'a good pair saves');
    T::ok(\StormWatch\Notifiers\EmailNotifier::isEnabled(), 'and email reports ready');

    // Nothing deliverable means not ready, whatever the switch says.
    Settings::putRaw('email_to', 'nobody@, also-bad');
    Settings::flushCache();
    T::ok(!\StormWatch\Notifiers\EmailNotifier::isEnabled(),
        'a list of nothing but typos does not report ready');

    Settings::putRaw('email_to', '');
    Settings::put(['email_enabled' => false]);
}

T::group('Attributing a request to an address');
{
    // Behind a proxy, X-Forwarded-For is a list and only its last entry was
    // written by the proxy — everything left of it is whatever the client
    // typed. Trusting the leftmost made the sign-in throttle both useless
    // (a fresh forged address every attempt) and a weapon (eight attempts
    // carrying the duty manager's address lock the duty manager out).
    $saved = $_SERVER;
    $withProxy = static function (bool $trusted, ?string $header, string $remote): string {
        Config::override(['trusted_proxy' => $trusted]);
        $_SERVER['REMOTE_ADDR'] = $remote;
        if ($header === null) {
            unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        } else {
            $_SERVER['HTTP_X_FORWARDED_FOR'] = $header;
        }
        return \StormWatch\Http::clientIp();
    };

    T::same('198.51.100.7', $withProxy(false, '203.0.113.9', '198.51.100.7'),
        'without a trusted proxy the header is ignored entirely');
    T::same('10.0.0.7', $withProxy(true, '203.0.113.9, 198.51.100.4, 10.0.0.7', '172.16.0.1'),
        'with one, the address the proxy wrote is used, not the one the client sent');
    T::same('198.51.100.4', $withProxy(true, '203.0.113.9, 198.51.100.4, not-an-ip', '172.16.0.1'),
        'a junk final entry falls back to the next real one');
    T::same('172.16.0.1', $withProxy(true, 'garbage', '172.16.0.1'),
        'an unusable header falls back to the connecting address');
    T::same('172.16.0.1', $withProxy(true, null, '172.16.0.1'),
        'and so does a missing one');

    $_SERVER = $saved;
    Config::override(['trusted_proxy' => false]);
}

T::group('Correcting the venue coordinates re-measures the strikes');
{
    // Distance is worked out once, when a strike arrives, and stored. So a
    // mistyped coordinate does not just move the map pin — it leaves every
    // strike on record measured from the wrong place, and the alert engine
    // goes on holding a warning for lightning nowhere near the real venue.
    resetData();
    Settings::put([
        'venue_lat' => 41.3608, 'venue_lon' => -74.2854,
        'alert_radius_mi' => 10, 'watch_radius_mi' => 20, 'display_radius_mi' => 30,
        'all_clear_minutes' => 30, 'notify_all_clear' => true,
    ]);

    placeStrike(5.0, 90.0);
    AlertEngine::evaluate();
    T::same('warning', AlertEngine::publicState()['level'], 'a strike 5 mi from the entered location alerts');

    // The real venue is about 17 miles north — outside the alert radius, still
    // inside the display radius.
    $corrected = Geo::destination(41.3608, -74.2854, 0.0, 17.0);
    $errors = Settings::put(['venue_lat' => $corrected['lat'], 'venue_lon' => $corrected['lon']]);
    T::same([], $errors, 'the corrected coordinates save');

    $stored = Database::instance()->first('SELECT distance_mi FROM strikes ORDER BY id DESC LIMIT 1');
    T::ok(
        $stored !== null && (float) $stored['distance_mi'] > 10.0,
        'the stored strike is re-measured against the new location'
    );

    AlertEngine::evaluate();
    T::same('watch', AlertEngine::publicState()['level'], 'and the warning stands down to a watch');

    Settings::put(['venue_lat' => 41.3608, 'venue_lon' => -74.2854]);
}

T::group('Settings never quietly fall back to the shipped defaults');
{
    // The defaults put the venue at the demo coordinates and the provider on
    // the simulator, which fabricates strikes. Answering with them because a
    // query failed would invent a storm at somebody else's address. The live
    // behaviour needs a real config file, so smoke.sh drives that; this pins
    // down why it matters.
    $defaults = Settings::defaults();
    T::same('simulator', $defaults['provider'], 'the default provider is the one that fabricates strikes');
    T::same(41.3608, $defaults['venue_lat'], 'and the default venue is the shipped demo location');
}

T::group('Housekeeping cannot cause a false all clear');
{
    // The cooldown is decided by asking the strikes table "has anything struck
    // inside the radius in the last N minutes?". Prune the table faster than
    // that and the answer becomes "nothing on record" — which reads exactly
    // like "nothing happened", and the venue is told it is safe to go back out
    // while the storm is still overhead.
    resetData();
    Settings::put([
        'alert_radius_mi' => 10, 'watch_radius_mi' => 20, 'display_radius_mi' => 30,
        'all_clear_minutes' => 120, 'cooldown_scope' => 'alert', 'notify_all_clear' => true,
        'marker_ttl_minutes' => 60,
    ]);
    Settings::putRaw('retention_hours', 1);   // deliberately shorter than the cooldown

    placeStrike(5.0, 15.0);
    AlertEngine::evaluate();
    T::same('warning', AlertEngine::publicState()['level'], 'a nearby strike raises the warning');

    // 70 minutes later: past the 1-hour retention, well short of the 2-hour hold.
    Database::instance()->run('UPDATE strikes SET struck_at = ?', [time() - (70 * 60)]);
    Strikes::prune();
    T::same(1, Strikes::stats(86400)['total'], 'prune keeps a strike the cooldown is still counting');

    AlertEngine::evaluate();
    T::same('warning', AlertEngine::publicState()['level'], 'the hold survives a prune mid-storm');
    T::same(0, count(Events::recent(200, 'alert.all_clear')), 'no all clear is invented from a pruned table');

    // Past the cooldown, the all clear is real and the row can go.
    Database::instance()->run('UPDATE strikes SET struck_at = ?', [time() - (200 * 60)]);
    AlertEngine::evaluate();
    T::same('clear', AlertEngine::publicState()['level'], 'the all clear still arrives once the hold expires');
    T::ok(Strikes::prune() > 0, 'and the strike is prunable once nothing needs it');

    // The operator is told rather than silently given a longer history.
    Settings::putRaw('retention_hours', 72);
    $errors = Settings::put(['all_clear_minutes' => 120, 'retention_hours' => 1]);
    T::ok(isset($errors['all_clear_minutes']) || isset($errors['retention_hours']),
        'a history shorter than the cooldown is refused with an explanation');

    T::same([], Settings::put(['all_clear_minutes' => 30, 'retention_hours' => 72]),
        'a sensible pair still saves');
}

T::group('Operating hours');
{
    Settings::put([
        'timezone' => 'America/New_York',
        'monitor_schedule_enabled' => true,
        'monitor_lead_minutes' => 30,
        'monitor_trail_minutes' => 30,
    ]);
    // The venue's real hours: open noon daily, closing 10pm Mon–Thu and Sun,
    // 11pm Fri and Sat.
    \StormWatch\Schedule::save([
        'mon' => ['open' => '12:00', 'close' => '22:00'],
        'tue' => ['open' => '12:00', 'close' => '22:00'],
        'wed' => ['open' => '12:00', 'close' => '22:00'],
        'thu' => ['open' => '12:00', 'close' => '22:00'],
        'fri' => ['open' => '12:00', 'close' => '23:00'],
        'sat' => ['open' => '12:00', 'close' => '23:00'],
        'sun' => ['open' => '12:00', 'close' => '22:00'],
    ]);

    $tz = new DateTimeZone('America/New_York');
    $at = static function (string $when) use ($tz): int {
        return (new DateTimeImmutable($when, $tz))->getTimestamp();
    };
    $monitoring = static function (string $when) use ($at): bool {
        return \StormWatch\Schedule::isMonitoring($at($when));
    };

    T::same(false, $monitoring('2026-08-10 09:00'), 'Monday morning is outside the window');
    T::same(true, $monitoring('2026-08-10 11:35'), 'the pre-open buffer counts as monitored');
    T::same(false, $monitoring('2026-08-10 11:25'), 'but not before the buffer starts');
    T::same(true, $monitoring('2026-08-10 15:00'), 'the middle of a Monday is monitored');
    T::same(true, $monitoring('2026-08-10 22:25'), 'the post-close buffer counts as monitored');
    T::same(false, $monitoring('2026-08-10 22:40'), 'and stops after it');
    T::same(true, $monitoring('2026-08-14 23:20'), 'Friday runs an hour later');
    T::same(false, $monitoring('2026-08-13 23:20'), 'Thursday at the same time does not');
    T::same(false, $monitoring('2026-08-15 03:00'), 'the small hours are never monitored');

    $next = \StormWatch\Schedule::nextStart($at('2026-08-10 09:00'));
    T::same('11:30', $next === null ? 'none' : (new DateTimeImmutable('@' . $next))->setTimezone($tz)->format('H:i'), 'the next start is the buffered opening time');
    T::same(null, \StormWatch\Schedule::nextStart($at('2026-08-10 15:00')), 'no next start while already monitoring');

    // A closing time past midnight belongs to the day it started.
    \StormWatch\Schedule::save(['fri' => ['open' => '18:00', 'close' => '01:00']] + \StormWatch\Schedule::all());
    Settings::put(['monitor_lead_minutes' => 0, 'monitor_trail_minutes' => 0]);
    T::same(true, $monitoring('2026-08-15 00:30'), 'a small-hours closing time still belongs to Friday');
    T::same(false, $monitoring('2026-08-15 01:30'), 'and ends when it should');

    // A closed day is genuinely off.
    $schedule = \StormWatch\Schedule::all();
    $schedule['sun'] = ['closed' => true, 'open' => '12:00', 'close' => '22:00'];
    \StormWatch\Schedule::save($schedule);
    T::same(false, $monitoring('2026-08-16 15:00'), 'a day marked closed is not monitored');

    T::ok(\StormWatch\Schedule::hoursPerWeek() < 168, 'a schedule covers less than the whole week');

    // Switched off, the schedule must never reduce coverage.
    Settings::put(['monitor_schedule_enabled' => false]);
    T::same(true, $monitoring('2026-08-16 03:00'), 'with the schedule off, monitoring is round the clock');
    T::same(168.0, \StormWatch\Schedule::hoursPerWeek(), 'and covers the whole week');

    // Closing time must resolve an outstanding alert rather than leave it to
    // decay into an all clear derived from no data.
    resetData();
    Settings::put(['monitor_schedule_enabled' => true, 'notify_all_clear' => true]);
    placeStrike(4.0, 88.0);
    AlertEngine::evaluate();
    T::same('warning', AlertEngine::publicState()['level'], 'an alert is running at closing time');
    AlertEngine::standDownForClosedHours();
    T::same('clear', AlertEngine::publicState()['level'], 'the stand-down resolves the state');
    T::ok(count(Events::recent(50, 'alert.all_clear')) >= 1, 'and says monitoring has stopped for the day');
    $before = count(Events::recent(50, 'alert.'));
    AlertEngine::standDownForClosedHours();
    T::same($before, count(Events::recent(50, 'alert.')), 'standing down twice announces nothing further');

    Settings::put(['monitor_schedule_enabled' => false]);
}

T::group('Metered API budget');
{
    $meter = 'test-meter';
    Settings::put(['api_monthly_budget' => 100]);
    Database::instance()->run('DELETE FROM api_usage');

    T::same(0, \StormWatch\ApiBudget::usage($meter)['tokens'], 'a fresh period starts at zero');
    \StormWatch\ApiBudget::record($meter, 1);
    \StormWatch\ApiBudget::record($meter, 4);
    $usage = \StormWatch\ApiBudget::usage($meter);
    T::same(2, $usage['requests'], 'requests are counted');
    T::same(5, $usage['tokens'], 'and so is what the provider charged, which is not always one');
    T::same(95, \StormWatch\ApiBudget::remaining($meter), 'the remaining allowance follows the tokens');
    T::same(false, \StormWatch\ApiBudget::isExhausted($meter), 'not exhausted yet');

    \StormWatch\ApiBudget::record($meter, 95);
    T::same(true, \StormWatch\ApiBudget::isExhausted($meter), 'exhausted once the allowance is spent');

    Settings::put(['api_monthly_budget' => 0]);
    T::same(null, \StormWatch\ApiBudget::remaining($meter), 'a budget of zero means unmetered');
    T::same(false, \StormWatch\ApiBudget::isExhausted($meter), 'and can never be exhausted');

    // Usage is per calendar month, so a new period starts clean.
    Settings::put(['api_monthly_budget' => 100]);
    $lastMonth = \StormWatch\ApiBudget::period(strtotime('-2 months'));
    T::same(0, \StormWatch\ApiBudget::usage($meter, $lastMonth)['tokens'], 'a different period is separate');

    // The projection is what the settings screen uses to warn before the fact.
    $cost = \StormWatch\ApiBudget::projectMonthlyCost(300, 60, 10.0);
    T::ok($cost > 0 && $cost < 15000, 'five-minute polling projects inside the free tier');
    $expensive = \StormWatch\ApiBudget::projectMonthlyCost(60, 60, 10.0);
    T::ok($expensive > 15000, 'once-a-minute polling round the clock does not');

    Database::instance()->run('DELETE FROM api_usage');
    Settings::put(['api_monthly_budget' => 15000]);
}

T::group('Adaptive polling');
{
    resetData();
    Settings::put(['rest_poll_seconds' => 300, 'rest_active_poll_seconds' => 60]);

    AlertEngine::evaluate(false);
    T::same(300, Runner::restPollInterval(), 'a clear sky polls at the quiet cadence');

    placeStrike(4.0, 99.0);
    AlertEngine::evaluate(false);
    T::same(60, Runner::restPollInterval(), 'an active alert polls at the storm cadence');

    resetData();
    placeStrike(15.0, 99.0);
    AlertEngine::evaluate(false);
    T::same(60, Runner::restPollInterval(), 'a watch also counts as active — it is the run-up to an alert');

    // The active cadence must never be slower than the quiet one.
    Settings::put(['rest_poll_seconds' => 60, 'rest_active_poll_seconds' => 600]);
    T::same(60, Runner::restPollInterval(), 'a misconfigured active cadence never slows things down');
    Settings::put(['rest_poll_seconds' => 300, 'rest_active_poll_seconds' => 60]);
    resetData();

    // The browser relay records its deliveries under the same 'poll' job name
    // as the REST poller. A relay tab left open after switching to a REST feed
    // must not be able to answer "we polled a moment ago" on its behalf — that
    // starves the real feed while the dashboard reports the relay's success.
    Settings::putRaw('provider', 'rest');
    Runner::recordRun('poll', 'rest', true, 0, 'REST poll', time() - 600);
    Runner::recordRun('poll', 'relay', true, 1, 'Browser relay delivered 1 record(s).', time());

    $restRun = Runner::lastRun('poll', 'rest');
    T::ok($restRun !== null && $restRun['provider'] === 'rest', 'the REST run is found past a newer relay run');
    T::same('REST poll', Runner::status()['source_message'], 'feed health reports the REST feed, not the relay');

    Database::instance()->run('DELETE FROM runs');
    Settings::putRaw('provider', 'simulator');
    resetData();
}

T::group('Provider limits: radius cap and data window');
{
    resetData();
    foreach ([
        'provider' => 'rest',
        'rest_endpoint' => 'https://data.api.xweather.com/lightning/flash/closest?p={lat},{lon}&radius={radius_mi}miles',
        'alert_radius_mi' => 10.0,
        'watch_radius_mi' => 20.0,
        'display_radius_mi' => 30.0,
        'rest_max_radius_mi' => 0.0,
        'rest_data_window_minutes' => 0,
    ] as $key => $val) {
        Settings::putRaw($key, $val);
    }
    T::same(30.0, Rest::requestRadiusMi(), 'with no cap the request uses the full display radius');

    // Xweather's lightning/flash refuses anything over 40km, so a 30 mile
    // display radius has to be asked for as 25 or every poll fails.
    Settings::putRaw('rest_max_radius_mi', 25.0);
    T::same(25.0, Rest::requestRadiusMi(), 'a capped endpoint is asked for the cap, not the display radius');

    Settings::putRaw('display_radius_mi', 20.0);
    T::same(20.0, Rest::requestRadiusMi(), 'a display radius inside the cap is passed through untouched');
    Settings::putRaw('display_radius_mi', 30.0);

    // A watch ring wider than the feed can answer for means strikes in the gap
    // are never fetched — the dashboard stays green through a storm.
    $errors = Settings::put(['watch_radius_mi' => '30', 'display_radius_mi' => '30']);
    T::ok(isset($errors['watch_radius_mi']), 'a watch radius beyond the endpoint cap is refused');

    $errors = Settings::put(['watch_radius_mi' => '20', 'display_radius_mi' => '30']);
    T::ok(!isset($errors['watch_radius_mi']), 'a display radius beyond the cap is allowed — it only costs map coverage');

    // Polling an endpoint slower than its own data window drops strikes that
    // arrive and expire between two polls.
    Settings::putRaw('rest_data_window_minutes', 5);
    $errors = Settings::put(['rest_poll_seconds' => '300']);
    T::ok(isset($errors['rest_poll_seconds']), 'polling at the full 5 minute window is refused');

    $errors = Settings::put(['rest_poll_seconds' => '120']);
    T::ok(!isset($errors['rest_poll_seconds']), '120s leaves margin inside a 5 minute window');

    $errors = Settings::put(['rest_active_poll_seconds' => '240']);
    T::ok(isset($errors['rest_active_poll_seconds']), 'the storm cadence is held to the window too');

    Settings::putRaw('rest_data_window_minutes', 0);
    $errors = Settings::put(['rest_poll_seconds' => '600']);
    T::ok(!isset($errors['rest_poll_seconds']), 'an unknown window (0) imposes no ceiling');

    foreach ([
        'provider' => 'simulator',
        'rest_max_radius_mi' => 0.0,
        'rest_poll_seconds' => 300,
        'rest_active_poll_seconds' => 60,
        'display_radius_mi' => 30.0,
        'watch_radius_mi' => 20.0,
    ] as $key => $val) {
        Settings::putRaw($key, $val);
    }
}

T::group('Mapping verification');
{
    // Pointing the endpoint somewhere else is the whole mechanism, so an
    // endpoint with nowhere to substitute has to say so rather than silently
    // querying the venue five times and reporting a clean pass.
    Settings::putRaw('rest_endpoint', 'https://data.api.xweather.com/lightning/flash/closest?p=44.9,-93.2');
    $probe = Rest::probeMapping();
    T::ok(!$probe['ok'], 'an endpoint with no {lat}/{lon} cannot be probed');
    T::ok(stripos($probe['message'], 'placeholder') !== false, 'and the reason given is the missing placeholders');

    Settings::putRaw('rest_endpoint', 'https://data.api.xweather.com/lightning/flash/closest?p={lat},{lon}');
}

T::group('Provider error messages');
{
    // The message an operator reads at 9pm has to name the cause. This is the
    // exact body Xweather returns for an endpoint outside the plan.
    $scope = '{"success":false,"error":{"code":"insufficient_scope","description":'
        . '"The request requires a different account subscription level."},"response":[]}';
    $message = Rest::explainStatus(401, $scope);
    T::ok(stripos($message, 'plan does not cover') !== false, 'a scope failure is named as a plan problem');
    T::ok(stripos($message, 'lightning flash') !== false, 'and points at the endpoint with the widest availability');
    T::ok(stripos($message, 'Check the ID and secret') === false, 'without blaming the credentials, which were accepted');

    // "Your plan does not cover this endpoint" is useless without saying which
    // endpoint. Which one it was also decides whether the advice is "switch
    // endpoints" or "this account has no lightning data at all".
    $plain = Rest::explainStatus(401, $scope, 'https://data.api.xweather.com/lightning/closest?p=1,2');
    T::ok(stripos($plain, 'lightning/closest') !== false, 'the failing endpoint is quoted back');
    T::ok(stripos($plain, 'Try the "lightning flash" option') !== false, 'the plain endpoint is told to try flash');

    $flash = Rest::explainStatus(401, $scope, 'https://data.api.xweather.com/lightning/flash/closest?p=1,2');
    T::ok(stripos($flash, 'no change of endpoint will fix it') !== false, 'a scope failure on flash is called unfixable by endpoint');
    T::ok(stripos($flash, 'Blitzortung') !== false, 'and offers the free sources instead');
    T::ok(stripos($flash, 'Try the "lightning flash" option') === false, 'without suggesting the endpoint already in use');

    // The endpoint URL carries the client ID and secret, so quoting it back
    // would put both on screen and into the log.
    $withCreds = 'https://data.api.xweather.com/lightning/flash/closest?p=1,2'
        . '&client_id=AbCdEf123&client_secret=SuPerSecret456';
    $redacted = Rest::redactUrl($withCreds);
    T::ok(strpos($redacted, 'SuPerSecret456') === false, 'the client secret is stripped from a quoted URL');
    T::ok(strpos($redacted, 'AbCdEf123') === false, 'the client id is stripped too');
    T::ok(strpos($redacted, 'p=1,2') !== false, 'non-secret parameters survive redaction');
    T::ok(strpos($redacted, 'lightning/flash/closest') !== false, 'and the path — the whole point — survives');
    T::ok(strpos(Rest::explainStatus(401, $scope, $withCreds), 'SuPerSecret456') === false, 'no secret reaches the message');
    T::ok(
        strpos(Rest::redactUrl('https://x.test/a?apikey=zzz&token=yyy&other=keep'), 'zzz') === false
        && strpos(Rest::redactUrl('https://x.test/a?apikey=zzz&token=yyy&other=keep'), 'yyy') === false
        && strpos(Rest::redactUrl('https://x.test/a?apikey=zzz&token=yyy&other=keep'), 'keep') !== false,
        'single-key and bearer-style parameters are redacted as well'
    );

    $bad = '{"success":false,"error":{"code":"invalid_client","description":"Invalid client id."}}';
    T::ok(stripos(Rest::explainStatus(401, $bad), 'rejected the credentials') !== false, 'a bad client id is named as a credential problem');

    T::ok(stripos(Rest::explainStatus(429, '{}'), 'rate limiting') !== false, 'a 429 suggests slowing the poll down');
    T::ok(stripos(Rest::explainStatus(503, 'upstream down'), 'their end') !== false, 'a 5xx is attributed to the provider');
    T::ok(stripos(Rest::explainStatus(418, 'teapot'), 'HTTP 418') !== false, 'an unrecognised status still reports its code and body');
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

T::group('Email delivery over SMTP');
{
    // The alert email is what reaches people who are not watching Slack, and
    // the SMTP client that sends it is hand-rolled. Drive it against something
    // that answers like a mail server.
    $sendVia = static function (string $mode, array $recipients): array {
        $port = random_int(21000, 39000);
        $transcript = sys_get_temp_dir() . '/sw-smtp-' . $port . '.json';
        @unlink($transcript);
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            sprintf(
                '%s %s %d %s %s',
                escapeshellarg(PHP_BINARY),
                escapeshellarg(__DIR__ . '/support/smtp_server.php'),
                $port,
                escapeshellarg($transcript),
                escapeshellarg($mode)
            ),
            $descriptors,
            $pipes
        );
        if (!is_resource($process)) {
            return ['result' => ['ok' => false, 'message' => 'server would not start'], 'seen' => []];
        }
        stream_set_blocking($pipes[1], true);
        if (trim((string) fgets($pipes[1])) !== 'READY') {
            proc_close($process);
            return ['result' => ['ok' => false, 'message' => 'server did not come up'], 'seen' => []];
        }

        Settings::putRaw('email_transport', 'smtp');
        Settings::putRaw('smtp_host', '127.0.0.1');
        Settings::putRaw('smtp_port', $port);
        Settings::putRaw('smtp_secure', 'none');
        Settings::putRaw('smtp_user', 'alerts@example.com');
        Settings::putRaw('smtp_pass', 'a-secret');
        Settings::putRaw('email_from', 'stormwatch@example.com');
        Settings::putRaw('email_from_name', 'Storm Watch');

        $result = \StormWatch\Notifiers\Mailer::send(
            $recipients,
            'Lightning within 10 mi of Castle Fun Center',
            "A strike has been detected inside the alert radius.\n.a line starting with a dot",
            '<p>A strike has been detected.</p>'
        );
        proc_close($process);

        return [
            'result' => $result,
            'seen' => json_decode((string) @file_get_contents($transcript), true) ?: [],
        ];
    };

    $good = $sendVia('ok', ['ops@example.com', 'manager@example.com']);
    T::ok($good['result']['ok'] === true, 'a straightforward send succeeds');
    T::same(['ops@example.com', 'manager@example.com'], $good['seen']['accepted'] ?? [], 'both recipients are offered');
    T::ok(strpos((string) ($good['seen']['body'] ?? ''), 'Subject:') !== false, 'the message carries its headers');
    // The parts are base64, so prove the text survived the transport intact
    // rather than eyeballing it: an off-by-one in the DATA framing or the
    // dot-stuffing would corrupt this.
    $wire = preg_replace('/\s+/', '', (string) ($good['seen']['body'] ?? '')) ?? '';
    $expected = preg_replace('/\s+/', '', base64_encode(
        "A strike has been detected inside the alert radius.\n.a line starting with a dot"
    )) ?? '';
    T::ok(strpos($wire, $expected) !== false, 'the plain-text body arrives byte for byte');
    T::ok(
        in_array('AUTH LOGIN', $good['seen']['commands'] ?? [], true),
        'the client authenticates with LOGIN when the server offers it'
    );

    // One dud address — a typo, or someone who has left — must not stop the
    // people whose addresses are fine from being told about the storm.
    $partial = $sendVia('reject-one', ['ops@example.com', 'bad@example.com', 'manager@example.com']);
    T::ok($partial['result']['ok'] === true, 'a refused recipient does not fail the whole send');
    T::same(
        ['ops@example.com', 'manager@example.com'],
        $partial['seen']['accepted'] ?? [],
        'the good addresses still receive the alert'
    );
    T::ok(!empty($partial['seen']['body']), 'the message body is actually transmitted');
    T::ok(
        strpos($partial['result']['message'], 'bad@example.com') !== false,
        'and the operator is told which address was refused'
    );

    // A server that only speaks PLAIN must not lock the venue out of email.
    $plain = $sendVia('auth-plain', ['ops@example.com']);
    T::ok($plain['result']['ok'] === true, 'a PLAIN-only server is still usable');
    T::ok(
        count(array_filter($plain['seen']['commands'] ?? [], static fn(string $c): bool => strpos($c, 'AUTH PLAIN') === 0)) > 0,
        'the client falls back to AUTH PLAIN when LOGIN is not offered'
    );

    Settings::putRaw('email_transport', 'mail');
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

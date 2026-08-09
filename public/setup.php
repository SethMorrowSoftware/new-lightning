<?php
/**
 * First-run installer.
 *
 * Checks the host, writes config/config.php, creates the schema, and sets up
 * the first administrator. It refuses to run once an account exists, so it
 * cannot be used to take over a live install.
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use StormWatch\Auth;
use StormWatch\Config;
use StormWatch\Database;
use StormWatch\Http;
use StormWatch\Migrations;
use StormWatch\Settings;
use StormWatch\View;

Http::securityHeaders();
Http::startSession();

// If the app is already installed, setup is closed — and it stays closed
// unless the database positively says there is nobody to lock out.
//
// The two answers that used to be conflated are "no accounts yet" and "no
// answer at all", and on shared hosting the second one is routine: a MySQL
// server having a bad afternoon, a rotated password, a data directory that
// lost its permissions. Reading that as "nobody has signed up yet" served the
// install wizard to anonymous visitors on a live site, where submitting it
// would rewrite config.php, mint a fresh app key — making the stored Slack
// token and SMTP password permanently unreadable — and create an
// administrator account for whoever asked.
//
// So an installed app whose database is unreachable says exactly that and
// offers nothing. Re-running setup deliberately is still possible; it needs
// config/config.php to be removed first, which takes the file access that
// re-installing ought to require.
if (Config::isInstalled()) {
    $accounts = Auth::countAccounts();
    if ($accounts === null) {
        // Deliberately not rendered through View: the normal chrome reads the
        // venue name out of the settings table, which is the very thing that
        // cannot be reached. A page about a broken database must not need it.
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>Storm Watch</title>'
            . '<body style="font-family:system-ui;background:#080C16;color:#F3F5FA;padding:40px;line-height:1.6;">'
            . '<h1 style="font-size:20px;">Storm Watch cannot reach its database</h1>'
            . '<p style="color:#9AA4C0;max-width:60ch;">This installation is already set up, so the wizard will '
            . 'not run. The database named in <code>config/config.php</code> is not answering — a server that is '
            . 'down, credentials that have changed, or a data directory that is no longer writable.</p>'
            . '<p style="color:#9AA4C0;max-width:60ch;">Correct the credentials in <code>config/config.php</code> '
            . 'and reload this page. To install from scratch instead, delete that file first — which also discards '
            . 'the stored Slack token and SMTP password, so they will need entering again.</p>'
            . '<p style="color:#FF4D5E;max-width:60ch;"><strong>Lightning alerts are not being sent while this is '
            . 'true.</strong></p></body>';
        exit;
    }
    if ($accounts > 0) {
        Http::redirect('index.php');
    }
}

/** @return array<int,array{label:string,ok:bool,critical:bool,detail:string}> */
function requirementChecks(): array
{
    $checks = [];
    $checks[] = [
        'label' => 'PHP 8.0 or newer',
        'ok' => PHP_VERSION_ID >= 80000,
        'critical' => true,
        'detail' => 'Running PHP ' . PHP_VERSION . '.',
    ];
    $checks[] = [
        'label' => 'PDO with SQLite or MySQL',
        'ok' => class_exists('PDO') && array_intersect(['sqlite', 'mysql'], \PDO::getAvailableDrivers()) !== [],
        'critical' => true,
        'detail' => class_exists('PDO')
            ? 'Available drivers: ' . (implode(', ', \PDO::getAvailableDrivers()) ?: 'none')
            : 'The PDO extension is not loaded.',
    ];
    $checks[] = [
        'label' => 'config/ is writable',
        'ok' => is_writable(dirname(SW_CONFIG_FILE)) || is_writable(SW_CONFIG_FILE),
        'critical' => true,
        'detail' => dirname(SW_CONFIG_FILE) . ' — the installer writes config.php here.',
    ];
    $checks[] = [
        'label' => 'data/ is writable',
        'ok' => is_dir(SW_DATA) && is_writable(SW_DATA),
        'critical' => true,
        'detail' => SW_DATA . ' — holds the database, locks and logs.',
    ];
    $checks[] = [
        'label' => 'cURL or allow_url_fopen',
        'ok' => function_exists('curl_init') || (bool) ini_get('allow_url_fopen'),
        'critical' => true,
        'detail' => 'Needed to reach the Slack API and REST weather feeds.',
    ];
    $checks[] = [
        'label' => 'OpenSSL',
        'ok' => extension_loaded('openssl'),
        'critical' => true,
        'detail' => 'Encrypts stored secrets and secures outbound connections.',
    ];
    $checks[] = [
        'label' => 'Outbound sockets (Blitzortung)',
        'ok' => function_exists('stream_socket_client'),
        'critical' => false,
        'detail' => 'Only needed for the direct Blitzortung feed. Without it, use the REST or browser-relay source.',
    ];

    // Only meaningful for the subfolder deployment, where the root .htaccess
    // does the work. Apache will not always tell us, so an unknown answer is
    // reported as such rather than as a failure.
    $rewrite = null;
    if (function_exists('apache_get_modules')) {
        $rewrite = in_array('mod_rewrite', apache_get_modules(), true);
    } elseif (getenv('HTTP_MOD_REWRITE') !== false) {
        $rewrite = true;
    }
    $checks[] = [
        'label' => 'mod_rewrite',
        'ok' => $rewrite !== false,
        'critical' => false,
        'detail' => $rewrite === null
            ? 'Could not be detected on this server. It is only needed when Storm Watch is installed in a subfolder — if the dashboard loads, it is working.'
            : ($rewrite
                ? 'Available, so a subfolder install will work.'
                : 'Not available. Point a document root at the public/ folder instead of installing into a subfolder.'),
    ];

    return $checks;
}

$checks = requirementChecks();
$blocking = array_filter($checks, static fn(array $c): bool => $c['critical'] && !$c['ok']);

$errors = [];
$values = [
    'db_driver' => 'sqlite',
    'db_host' => 'localhost',
    'db_port' => '3306',
    'db_name' => '',
    'db_user' => '',
    'username' => 'admin',
    'venue_name' => 'Castle Fun Center',
    'venue_address' => '109 Brookside Ave, Chester, NY 10918',
    'venue_lat' => '41.3608',
    'venue_lon' => '-74.2854',
    'timezone' => 'America/New_York',
];

if (Http::isPost() && $blocking === []) {
    if (!Http::checkCsrf()) {
        $errors['form'] = 'Your session expired. Try again.';
    } else {
        foreach (array_keys($values) as $key) {
            $values[$key] = trim((string) ($_POST[$key] ?? $values[$key]));
        }
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
        $dbPass = (string) ($_POST['db_pass'] ?? '');

        $dbConfig = [
            'db_driver' => $values['db_driver'] === 'mysql' ? 'mysql' : 'sqlite',
            'db_host' => $values['db_host'],
            'db_port' => (int) $values['db_port'],
            'db_name' => $values['db_name'],
            'db_user' => $values['db_user'],
            'db_pass' => $dbPass,
            'db_path' => SW_DATA . '/stormwatch.sqlite',
        ];

        if ($dbConfig['db_driver'] === 'mysql' && ($dbConfig['db_name'] === '' || $dbConfig['db_user'] === '')) {
            $errors['db_name'] = 'Enter the MySQL database name and user created in cPanel.';
        }

        $passwordProblem = Auth::passwordProblem($password);
        if ($passwordProblem !== null) {
            $errors['password'] = $passwordProblem;
        } elseif ($password !== $passwordConfirm) {
            $errors['password_confirm'] = 'The two passwords do not match.';
        }
        if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $values['username'])) {
            $errors['username'] = 'Usernames must be 3–64 characters: letters, numbers, dot, dash or underscore.';
        }
        if (!is_numeric($values['venue_lat']) || abs((float) $values['venue_lat']) > 90) {
            $errors['venue_lat'] = 'Enter a latitude between -90 and 90.';
        }
        if (!is_numeric($values['venue_lon']) || abs((float) $values['venue_lon']) > 180) {
            $errors['venue_lon'] = 'Enter a longitude between -180 and 180.';
        }
        if (!in_array($values['timezone'], timezone_identifiers_list(), true)) {
            $errors['timezone'] = 'Choose a time zone from the list.';
        }

        if ($errors === []) {
            try {
                $probe = Database::connect($dbConfig);
                Migrations::run($probe);
            } catch (\Throwable $e) {
                $errors['db_name'] = 'Could not set up the database: ' . $e->getMessage();
            }
        }

        if ($errors === []) {
            try {
                Config::write(array_merge($dbConfig, [
                    'app_key' => Config::generateAppKey(),
                    'base_url' => rtrim(preg_replace('#/setup\.php.*$#', '', Http::absoluteUrl('setup.php')) ?? '', '/'),
                ]));
                Database::reset();
                $db = Database::instance();
                Migrations::run($db);

                $created = Auth::createUser($values['username'], $password, '', 'admin');
                if (!$created['ok']) {
                    throw new \RuntimeException($created['error'] ?? 'The account could not be created.');
                }

                Settings::flushCache();
                Settings::put([
                    'venue_name' => $values['venue_name'],
                    'venue_address' => $values['venue_address'],
                    'venue_lat' => (float) $values['venue_lat'],
                    'venue_lon' => (float) $values['venue_lon'],
                    'timezone' => $values['timezone'],
                ]);
                Settings::ensureTokens();

                Auth::attemptLogin($values['username'], $password);
                Http::redirect('settings.php?installed=1');
            } catch (\Throwable $e) {
                $errors['form'] = 'Setup failed: ' . $e->getMessage();
            }
        }
    }
}

View::start(['title' => 'Setup', 'bodyClass' => 'centered', 'wrap' => 'wrap narrow']);
?>
<div class="card">
  <div class="brand" style="margin-bottom:18px;">
    <div class="crest">
      <svg viewBox="0 0 24 24" fill="none" stroke="var(--amber)" stroke-width="1.6"><path d="M4 21V9l3-3 3 3V6l2-2 2 2v3l3-3 3 3v12H4z"/><path d="M9 21v-5h2v5M13 21v-5h2v5"/></svg>
    </div>
    <div>
      <h1>Set up Storm Watch</h1>
      <div class="addr">Lightning alerting for a single venue</div>
    </div>
  </div>

  <p class="lede">
    This runs once. It writes <code>config/config.php</code>, creates the database tables and sets up your
    administrator account. You can change everything else later in Settings.
  </p>

  <?php if (isset($errors['form'])): ?>
    <div class="notice err"><?= Http::e($errors['form']) ?></div>
  <?php endif; ?>

  <h2 style="margin-top:26px;">Server check</h2>
  <ul class="checklist">
    <?php foreach ($checks as $check): ?>
      <li>
        <?php if ($check['ok']): ?>
          <svg class="mark ok" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M20 6L9 17l-5-5"/></svg>
        <?php else: ?>
          <svg class="mark <?= $check['critical'] ? 'bad' : 'warn' ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M18 6L6 18M6 6l12 12"/></svg>
        <?php endif; ?>
        <span><strong><?= Http::e($check['label']) ?></strong><?= $check['critical'] ? '' : ' (optional)' ?><br><?= Http::e($check['detail']) ?></span>
      </li>
    <?php endforeach; ?>
  </ul>

  <?php if ($blocking !== []): ?>
    <div class="notice err">
      <b>Setup cannot continue.</b> Fix the items marked above and reload this page.
      On cPanel, most of these are solved by selecting a newer PHP version in <b>MultiPHP Manager</b>,
      or by setting the <code>config</code> and <code>data</code> folders to permission <code>0755</code>
      in File Manager.
    </div>
  <?php else: ?>

  <form method="post" autocomplete="off">
    <?= Http::csrfField() ?>

    <h2 style="margin-top:28px;">Database</h2>
    <div class="field">
      <label for="db_driver">Where should data be stored?</label>
      <select id="db_driver" name="db_driver">
        <option value="sqlite" <?= $values['db_driver'] === 'sqlite' ? 'selected' : '' ?>>SQLite file (simplest — no setup)</option>
        <option value="mysql" <?= $values['db_driver'] === 'mysql' ? 'selected' : '' ?>>MySQL / MariaDB (created in cPanel)</option>
      </select>
      <div class="field-note">
        SQLite keeps everything in <code>data/stormwatch.sqlite</code> and is fine for one venue.
        Choose MySQL if your host disables SQLite or you want the database in your normal backups.
      </div>
    </div>

    <div id="mysqlFields" style="<?= $values['db_driver'] === 'mysql' ? '' : 'display:none;' ?>">
      <div class="field-grid2">
        <div class="field">
          <label for="db_host">Host</label>
          <input type="text" id="db_host" name="db_host" value="<?= Http::e($values['db_host']) ?>">
        </div>
        <div class="field">
          <label for="db_port">Port</label>
          <input type="text" id="db_port" name="db_port" value="<?= Http::e($values['db_port']) ?>">
        </div>
      </div>
      <div class="field">
        <label for="db_name">Database name</label>
        <input type="text" id="db_name" name="db_name" value="<?= Http::e($values['db_name']) ?>" class="<?= isset($errors['db_name']) ? 'has-error' : '' ?>" placeholder="cpaneluser_stormwatch">
        <?php if (isset($errors['db_name'])): ?><div class="field-error"><?= Http::e($errors['db_name']) ?></div><?php endif; ?>
      </div>
      <div class="field-grid2">
        <div class="field">
          <label for="db_user">Database user</label>
          <input type="text" id="db_user" name="db_user" value="<?= Http::e($values['db_user']) ?>" placeholder="cpaneluser_storm">
        </div>
        <div class="field">
          <label for="db_pass">Database password</label>
          <input type="password" id="db_pass" name="db_pass" autocomplete="new-password">
        </div>
      </div>
    </div>

    <h2 style="margin-top:28px;">Administrator</h2>
    <div class="field">
      <label for="username">Username</label>
      <input type="text" id="username" name="username" value="<?= Http::e($values['username']) ?>" class="<?= isset($errors['username']) ? 'has-error' : '' ?>">
      <?php if (isset($errors['username'])): ?><div class="field-error"><?= Http::e($errors['username']) ?></div><?php endif; ?>
    </div>
    <div class="field-grid2">
      <div class="field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" autocomplete="new-password" class="<?= isset($errors['password']) ? 'has-error' : '' ?>">
        <?php if (isset($errors['password'])): ?><div class="field-error"><?= Http::e($errors['password']) ?></div><?php endif; ?>
      </div>
      <div class="field">
        <label for="password_confirm">Repeat password</label>
        <input type="password" id="password_confirm" name="password_confirm" autocomplete="new-password" class="<?= isset($errors['password_confirm']) ? 'has-error' : '' ?>">
        <?php if (isset($errors['password_confirm'])): ?><div class="field-error"><?= Http::e($errors['password_confirm']) ?></div><?php endif; ?>
      </div>
    </div>

    <h2 style="margin-top:28px;">Venue</h2>
    <div class="field">
      <label for="venue_name">Name</label>
      <input type="text" id="venue_name" name="venue_name" value="<?= Http::e($values['venue_name']) ?>">
    </div>
    <div class="field">
      <label for="venue_address">Address</label>
      <input type="text" id="venue_address" name="venue_address" value="<?= Http::e($values['venue_address']) ?>">
    </div>
    <div class="field-grid3">
      <div class="field">
        <label for="venue_lat">Latitude</label>
        <input type="text" id="venue_lat" name="venue_lat" value="<?= Http::e($values['venue_lat']) ?>" class="<?= isset($errors['venue_lat']) ? 'has-error' : '' ?>">
        <?php if (isset($errors['venue_lat'])): ?><div class="field-error"><?= Http::e($errors['venue_lat']) ?></div><?php endif; ?>
      </div>
      <div class="field">
        <label for="venue_lon">Longitude</label>
        <input type="text" id="venue_lon" name="venue_lon" value="<?= Http::e($values['venue_lon']) ?>" class="<?= isset($errors['venue_lon']) ? 'has-error' : '' ?>">
        <?php if (isset($errors['venue_lon'])): ?><div class="field-error"><?= Http::e($errors['venue_lon']) ?></div><?php endif; ?>
      </div>
      <div class="field">
        <label for="timezone">Time zone</label>
        <select id="timezone" name="timezone">
          <?php foreach (timezone_identifiers_list() as $tz): ?>
            <option value="<?= Http::e($tz) ?>" <?= $tz === $values['timezone'] ? 'selected' : '' ?>><?= Http::e($tz) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="field-note" style="margin-top:-6px;">
      The coordinates decide what "10 miles away" means, so get them right — look the address up on any
      mapping site and copy the decimal latitude and longitude.
    </div>

    <div class="btn-row" style="margin-top:24px;">
      <button type="submit" class="btn primary">Install Storm Watch</button>
    </div>
  </form>

  <?php endif; ?>
</div>

<script>
  var driver = document.getElementById('db_driver');
  if (driver) {
    driver.addEventListener('change', function () {
      document.getElementById('mysqlFields').style.display = driver.value === 'mysql' ? '' : 'none';
    });
  }
</script>
<?php
View::end([], 'Storm Watch v' . SW_VERSION);

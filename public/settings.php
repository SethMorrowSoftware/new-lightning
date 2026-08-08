<?php
/**
 * Settings. One tab per concern, each a plain form post so the screen works
 * even if a script fails to load — which matters on a machine in a back office
 * running whatever browser it shipped with.
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use StormWatch\App;
use StormWatch\Auth;
use StormWatch\Crypto;
use StormWatch\Events;
use StormWatch\Http;
use StormWatch\Settings;
use StormWatch\View;

App::boot('auth');

$user = Auth::user();
$tabs = [
    'venue' => 'Venue &amp; map',
    'alerts' => 'Alert rules',
    'slack' => 'Slack',
    'email' => 'Email',
    'source' => 'Data source',
    'system' => 'System',
];
$tab = (string) ($_GET['tab'] ?? 'venue');
if (!isset($tabs[$tab])) {
    $tab = 'venue';
}

/** Which settings each tab owns, so a save never touches another tab's values. */
$tabFields = [
    'venue' => ['venue_name', 'venue_address', 'venue_lat', 'venue_lon', 'timezone', 'units',
                'map_zoom', 'radar_overlay', 'radar_opacity', 'ui_refresh_seconds', 'marker_ttl_minutes'],
    'alerts' => ['alert_radius_mi', 'watch_radius_mi', 'display_radius_mi', 'all_clear_minutes',
                 'realert_minutes', 'closer_delta_mi', 'notify_watch', 'notify_all_clear', 'notify_errors'],
    'slack' => ['slack_enabled', 'slack_mode', 'slack_bot_token', 'slack_webhook_url', 'slack_channel',
                'slack_mention', 'slack_extra_mention', 'slack_username', 'slack_icon_emoji'],
    'email' => ['email_enabled', 'email_to', 'email_from', 'email_from_name', 'email_transport',
                'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user', 'smtp_pass'],
    'source' => ['provider', 'worker_seconds', 'blitz_servers', 'blitz_init_json', 'rest_endpoint',
                 'rest_api_key', 'rest_auth_mode', 'rest_auth_name', 'rest_extra_headers',
                 'rest_poll_seconds', 'rest_map_root', 'rest_map_lat', 'rest_map_lon', 'rest_map_time',
                 'rest_time_format'],
    'system' => ['retention_hours', 'dashboard_public'],
];

$errors = [];
$notice = null;
$noticeKind = 'ok';
$posted = [];
$schema = Settings::schema();

if (Http::isPost()) {
    if (!Http::checkCsrf()) {
        $notice = 'Your session expired. Sign in again and retry.';
        $noticeKind = 'err';
    } else {
        $action = (string) ($_POST['action'] ?? 'save');

        if ($action === 'save') {
            $input = [];
            foreach ($tabFields[$tab] as $key) {
                $type = $schema[$key]['type'];
                if ($type === 'bool') {
                    $input[$key] = isset($_POST[$key]);
                } elseif ($type === 'secret') {
                    if (!empty($_POST['clear_' . $key])) {
                        $input[$key] = Settings::CLEAR_SECRET;
                    } elseif (isset($_POST[$key]) && trim((string) $_POST[$key]) !== '') {
                        $input[$key] = (string) $_POST[$key];
                    }
                } elseif (isset($_POST[$key])) {
                    $input[$key] = (string) $_POST[$key];
                }
            }
            $posted = $input;
            $errors = Settings::put($input);
            if ($errors === []) {
                Settings::ensureTokens();
                Events::log('settings.saved', Events::SEVERITY_INFO, sprintf('Settings saved (%s tab).', $tab), [
                    'by' => $user['username'],
                ]);
                $notice = 'Settings saved.';
                $posted = [];
            } else {
                $notice = 'Nothing was saved — check the highlighted fields.';
                $noticeKind = 'err';
            }
        } elseif ($action === 'regenerate_token') {
            $key = (string) ($_POST['token_key'] ?? '');
            if (in_array($key, ['ingest_token', 'kiosk_token', 'cron_token'], true)) {
                Settings::putRaw($key, Crypto::randomToken());
                $notice = 'A new token was generated. Update anything that used the old one.';
            } else {
                $notice = 'Unknown token.';
                $noticeKind = 'err';
            }
        } elseif ($action === 'add_user') {
            $result = Auth::createUser(
                (string) ($_POST['new_username'] ?? ''),
                (string) ($_POST['new_password'] ?? ''),
                (string) ($_POST['new_display_name'] ?? '')
            );
            $notice = $result['ok'] ? 'Account created.' : ($result['error'] ?? 'The account could not be created.');
            $noticeKind = $result['ok'] ? 'ok' : 'err';
        } elseif ($action === 'delete_user') {
            $result = Auth::deleteUser((int) ($_POST['user_id'] ?? 0), (int) $user['id']);
            $notice = $result['ok'] ? 'Account removed.' : ($result['error'] ?? 'That account could not be removed.');
            $noticeKind = $result['ok'] ? 'ok' : 'err';
        } elseif ($action === 'change_password') {
            $result = Auth::changePassword(
                (int) $user['id'],
                (string) ($_POST['current_password'] ?? ''),
                (string) ($_POST['new_password'] ?? '')
            );
            $notice = $result['ok'] ? 'Your password was changed.' : ($result['error'] ?? 'The password was not changed.');
            $noticeKind = $result['ok'] ? 'ok' : 'err';
        }
    }
}

if (isset($_GET['installed'])) {
    $notice = 'Storm Watch is installed. Next: choose a data source, then connect Slack.';
    $noticeKind = 'ok';
}

Settings::ensureTokens();

// Show what the operator typed when a save failed, otherwise what is stored.
$value = static function (string $key) use ($posted) {
    if (array_key_exists($key, $posted) && !is_bool($posted[$key])) {
        return (string) $posted[$key];
    }
    return (string) Settings::get($key, '');
};
$checked = static function (string $key) use ($posted): string {
    $on = array_key_exists($key, $posted) ? (bool) $posted[$key] : Settings::getBool($key);
    return $on ? ' checked' : '';
};
$selected = static function (string $key, string $option) use ($value): string {
    return $value($key) === $option ? ' selected' : '';
};
$errorClass = static function (string $key) use ($errors): string {
    return isset($errors[$key]) ? ' has-error' : '';
};
$errorNote = static function (string $key) use ($errors): string {
    return isset($errors[$key])
        ? '<div class="field-error">' . Http::e($errors[$key]) . '</div>'
        : '';
};
$secretSet = static function (string $key): bool {
    return Settings::getString($key) !== '';
};

View::start(['title' => 'Settings', 'wrap' => 'wrap settings']);
View::header('settings');
?>

<div class="tabs">
  <?php foreach ($tabs as $key => $label): ?>
    <a href="<?= Http::e(Http::url('settings.php?tab=' . $key)) ?>" class="<?= $tab === $key ? 'active' : '' ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>

<?php if ($notice !== null): ?>
  <div class="notice <?= Http::e($noticeKind) ?>"><?= Http::e($notice) ?></div>
<?php endif; ?>

<?php if ($tab === 'venue'): ?>
  <form method="post">
    <?= Http::csrfField() ?>
    <input type="hidden" name="action" value="save">
    <div class="panel">
      <div class="panel-title">Venue</div>
      <div class="field">
        <label for="venue_name">Venue name</label>
        <input type="text" id="venue_name" name="venue_name" value="<?= Http::e($value('venue_name')) ?>" class="<?= $errorClass('venue_name') ?>">
        <?= $errorNote('venue_name') ?>
      </div>
      <div class="field">
        <label for="venue_address">Address</label>
        <input type="text" id="venue_address" name="venue_address" value="<?= Http::e($value('venue_address')) ?>">
        <div class="field-note">Shown on the dashboard and in every alert.</div>
      </div>
      <div class="field-grid2">
        <div class="field">
          <label for="venue_lat">Latitude</label>
          <input type="text" id="venue_lat" name="venue_lat" value="<?= Http::e($value('venue_lat')) ?>" class="<?= $errorClass('venue_lat') ?>">
          <?= $errorNote('venue_lat') ?>
        </div>
        <div class="field">
          <label for="venue_lon">Longitude</label>
          <input type="text" id="venue_lon" name="venue_lon" value="<?= Http::e($value('venue_lon')) ?>" class="<?= $errorClass('venue_lon') ?>">
          <?= $errorNote('venue_lon') ?>
        </div>
      </div>
      <div class="field-note" style="margin-top:-8px;margin-bottom:14px;">
        Every distance in the system is measured from this point. Check it on the dashboard map before relying on alerts.
      </div>
      <div class="field-grid2">
        <div class="field">
          <label for="timezone">Time zone</label>
          <select id="timezone" name="timezone" class="<?= $errorClass('timezone') ?>">
            <?php foreach (timezone_identifiers_list() as $tz): ?>
              <option value="<?= Http::e($tz) ?>"<?= $selected('timezone', $tz) ?>><?= Http::e($tz) ?></option>
            <?php endforeach; ?>
          </select>
          <?= $errorNote('timezone') ?>
        </div>
        <div class="field">
          <label for="units">Distance units</label>
          <select id="units" name="units">
            <option value="mi"<?= $selected('units', 'mi') ?>>Miles</option>
            <option value="km"<?= $selected('units', 'km') ?>>Kilometres</option>
          </select>
          <div class="field-note">Radii are always entered in miles; this changes how they are displayed.</div>
        </div>
      </div>
    </div>

    <div class="panel">
      <div class="panel-title">Map</div>
      <div class="field-grid2">
        <div class="field">
          <label for="radar_overlay">Radar overlay</label>
          <select id="radar_overlay" name="radar_overlay">
            <option value="rainviewer"<?= $selected('radar_overlay', 'rainviewer') ?>>RainViewer (worldwide, no key)</option>
            <option value="nexrad"<?= $selected('radar_overlay', 'nexrad') ?>>NEXRAD mosaic (United States)</option>
            <option value="none"<?= $selected('radar_overlay', 'none') ?>>None</option>
          </select>
          <div class="field-note">Precipitation drawn under the strike markers. Free public tiles — no account needed.</div>
        </div>
        <div class="field">
          <label for="radar_opacity">Radar opacity (%)</label>
          <input type="number" id="radar_opacity" name="radar_opacity" min="5" max="100" value="<?= Http::e($value('radar_opacity')) ?>" class="<?= $errorClass('radar_opacity') ?>">
          <?= $errorNote('radar_opacity') ?>
        </div>
      </div>
      <div class="field-grid3">
        <div class="field">
          <label for="map_zoom">Default zoom</label>
          <input type="number" id="map_zoom" name="map_zoom" min="3" max="16" value="<?= Http::e($value('map_zoom')) ?>" class="<?= $errorClass('map_zoom') ?>">
          <?= $errorNote('map_zoom') ?>
        </div>
        <div class="field">
          <label for="ui_refresh_seconds">Dashboard refresh (s)</label>
          <input type="number" id="ui_refresh_seconds" name="ui_refresh_seconds" min="3" max="300" value="<?= Http::e($value('ui_refresh_seconds')) ?>" class="<?= $errorClass('ui_refresh_seconds') ?>">
          <?= $errorNote('ui_refresh_seconds') ?>
        </div>
        <div class="field">
          <label for="marker_ttl_minutes">Keep markers (min)</label>
          <input type="number" id="marker_ttl_minutes" name="marker_ttl_minutes" min="1" max="720" value="<?= Http::e($value('marker_ttl_minutes')) ?>" class="<?= $errorClass('marker_ttl_minutes') ?>">
          <?= $errorNote('marker_ttl_minutes') ?>
        </div>
      </div>
    </div>

    <div class="btn-row"><button type="submit" class="btn primary">Save venue settings</button></div>
  </form>

<?php elseif ($tab === 'alerts'): ?>
  <form method="post">
    <?= Http::csrfField() ?>
    <input type="hidden" name="action" value="save">
    <div class="panel">
      <div class="panel-title">Distances</div>
      <div class="field-grid3">
        <div class="field">
          <label for="alert_radius_mi">Alert radius (mi)</label>
          <input type="number" step="0.5" id="alert_radius_mi" name="alert_radius_mi" value="<?= Http::e($value('alert_radius_mi')) ?>" class="<?= $errorClass('alert_radius_mi') ?>">
          <?= $errorNote('alert_radius_mi') ?>
        </div>
        <div class="field">
          <label for="watch_radius_mi">Watch radius (mi)</label>
          <input type="number" step="0.5" id="watch_radius_mi" name="watch_radius_mi" value="<?= Http::e($value('watch_radius_mi')) ?>" class="<?= $errorClass('watch_radius_mi') ?>">
          <?= $errorNote('watch_radius_mi') ?>
        </div>
        <div class="field">
          <label for="display_radius_mi">Display radius (mi)</label>
          <input type="number" step="0.5" id="display_radius_mi" name="display_radius_mi" value="<?= Http::e($value('display_radius_mi')) ?>" class="<?= $errorClass('display_radius_mi') ?>">
          <?= $errorNote('display_radius_mi') ?>
        </div>
      </div>
      <div class="field-note" style="margin-top:-6px;">
        <b>Alert</b> is the one that matters: a strike inside it raises a warning and sends notifications.
        <b>Watch</b> is an early heads-up as a storm approaches. <b>Display</b> only decides what is
        stored and drawn on the map. Ten miles is the common choice for outdoor venues — lightning
        regularly travels that far from its parent storm.
      </div>
    </div>

    <div class="panel">
      <div class="panel-title">Timing</div>
      <div class="field">
        <label for="all_clear_minutes">All clear after (minutes)</label>
        <input type="number" id="all_clear_minutes" name="all_clear_minutes" min="1" max="240" value="<?= Http::e($value('all_clear_minutes')) ?>" class="<?= $errorClass('all_clear_minutes') ?>">
        <?= $errorNote('all_clear_minutes') ?>
        <div class="field-note">
          How long the alert radius must stay quiet before the all clear is announced.
          Thirty minutes after the last strike is the widely used guidance.
        </div>
      </div>
      <div class="field-grid2">
        <div class="field">
          <label for="realert_minutes">Repeat while active (minutes)</label>
          <input type="number" id="realert_minutes" name="realert_minutes" min="0" max="240" value="<?= Http::e($value('realert_minutes')) ?>" class="<?= $errorClass('realert_minutes') ?>">
          <?= $errorNote('realert_minutes') ?>
          <div class="field-note">A reminder while a warning is running. Set to 0 for one alert only.</div>
        </div>
        <div class="field">
          <label for="closer_delta_mi">Re-alert if closer by (mi)</label>
          <input type="number" step="0.5" id="closer_delta_mi" name="closer_delta_mi" min="0" max="100" value="<?= Http::e($value('closer_delta_mi')) ?>" class="<?= $errorClass('closer_delta_mi') ?>">
          <?= $errorNote('closer_delta_mi') ?>
          <div class="field-note">Sends an update when the storm closes in by this much. 0 disables it.</div>
        </div>
      </div>
    </div>

    <div class="panel">
      <div class="panel-title">What gets announced</div>
      <div class="toggle-row">
        <div>
          <div class="lbl">Watch notifications</div>
          <div class="sub">Post when lightning enters the watch radius, before it becomes an alert.</div>
        </div>
        <label class="switch"><input type="checkbox" name="notify_watch"<?= $checked('notify_watch') ?>><span class="slider"></span></label>
      </div>
      <div class="toggle-row">
        <div>
          <div class="lbl">All-clear notifications</div>
          <div class="sub">Post when the alert radius has been quiet long enough to resume.</div>
        </div>
        <label class="switch"><input type="checkbox" name="notify_all_clear"<?= $checked('notify_all_clear') ?>><span class="slider"></span></label>
      </div>
      <div class="toggle-row" style="margin-bottom:0;">
        <div>
          <div class="lbl">Feed failure notifications</div>
          <div class="sub">Tell you when lightning data stops arriving, at most once an hour. Strongly recommended — a silent dashboard looks identical to clear skies.</div>
        </div>
        <label class="switch"><input type="checkbox" name="notify_errors"<?= $checked('notify_errors') ?>><span class="slider"></span></label>
      </div>
    </div>

    <div class="btn-row"><button type="submit" class="btn primary">Save alert rules</button></div>
  </form>

<?php elseif ($tab === 'slack'): ?>
  <form method="post">
    <?= Http::csrfField() ?>
    <input type="hidden" name="action" value="save">
    <div class="panel">
      <div class="panel-title">Slack alerts</div>
      <div class="toggle-row">
        <div>
          <div class="lbl">Send alerts to Slack</div>
          <div class="sub">Posted by the server, so they arrive whether or not anyone has the dashboard open.</div>
        </div>
        <label class="switch"><input type="checkbox" name="slack_enabled"<?= $checked('slack_enabled') ?>><span class="slider"></span></label>
      </div>

      <div class="field">
        <label for="slack_mode">Connection type</label>
        <select id="slack_mode" name="slack_mode">
          <option value="bot"<?= $selected('slack_mode', 'bot') ?>>Bot token (recommended)</option>
          <option value="webhook"<?= $selected('slack_mode', 'webhook') ?>>Incoming webhook URL</option>
        </select>
      </div>

      <div class="field">
        <label for="slack_bot_token">Bot user OAuth token</label>
        <input type="password" id="slack_bot_token" name="slack_bot_token" autocomplete="off"
               placeholder="<?= $secretSet('slack_bot_token') ? 'Saved — leave blank to keep it' : 'xoxb-…' ?>"
               class="<?= $errorClass('slack_bot_token') ?>">
        <?= $errorNote('slack_bot_token') ?>
        <?php if ($secretSet('slack_bot_token')): ?>
          <div class="field-note"><label style="display:inline-flex;gap:6px;align-items:center;cursor:pointer;">
            <input type="checkbox" name="clear_slack_bot_token" style="width:auto;"> Remove the stored token
          </label></div>
        <?php endif; ?>
        <div class="field-note">
          Stored encrypted. Create it at <b>api.slack.com/apps</b> → your app → <b>OAuth &amp; Permissions</b>.
          The app needs the <code>chat:write</code> scope, and you must invite the bot to the channel with
          <code>/invite @YourBot</code>.
        </div>
      </div>

      <div class="field">
        <label for="slack_channel">Channel</label>
        <input type="text" id="slack_channel" name="slack_channel" value="<?= Http::e($value('slack_channel')) ?>" placeholder="#storm-alerts or C0123ABCDEF" class="<?= $errorClass('slack_channel') ?>">
        <?= $errorNote('slack_channel') ?>
        <div class="field-note">A channel ID is the most reliable: in Slack, open the channel → its name → <b>Copy channel ID</b>.</div>
      </div>

      <div class="field">
        <label for="slack_webhook_url">Incoming webhook URL</label>
        <input type="password" id="slack_webhook_url" name="slack_webhook_url" autocomplete="off"
               placeholder="<?= $secretSet('slack_webhook_url') ? 'Saved — leave blank to keep it' : 'https://hooks.slack.com/services/…' ?>"
               class="<?= $errorClass('slack_webhook_url') ?>">
        <?= $errorNote('slack_webhook_url') ?>
        <?php if ($secretSet('slack_webhook_url')): ?>
          <div class="field-note"><label style="display:inline-flex;gap:6px;align-items:center;cursor:pointer;">
            <input type="checkbox" name="clear_slack_webhook_url" style="width:auto;"> Remove the stored webhook
          </label></div>
        <?php endif; ?>
        <div class="field-note">Only used in webhook mode. The channel is fixed by the webhook itself.</div>
      </div>
    </div>

    <div class="panel">
      <div class="panel-title">Message style</div>
      <div class="field">
        <label for="slack_mention">Mention on alerts</label>
        <select id="slack_mention" name="slack_mention">
          <option value="none"<?= $selected('slack_mention', 'none') ?>>No mention</option>
          <option value="here"<?= $selected('slack_mention', 'here') ?>>@here — everyone currently online</option>
          <option value="channel"<?= $selected('slack_mention', 'channel') ?>>@channel — everyone in the channel</option>
        </select>
        <div class="field-note">Only applied to lightning warnings, never to all-clear or test messages.</div>
      </div>
      <div class="field">
        <label for="slack_extra_mention">Additional mention text</label>
        <input type="text" id="slack_extra_mention" name="slack_extra_mention" value="<?= Http::e($value('slack_extra_mention')) ?>" placeholder="&lt;!subteam^S012ABC&gt; or &lt;@U012ABC&gt;">
        <div class="field-note">Optional. Paste a Slack user or user-group mention to ping a specific duty manager.</div>
      </div>
      <div class="field-grid2">
        <div class="field">
          <label for="slack_username">Override bot name</label>
          <input type="text" id="slack_username" name="slack_username" value="<?= Http::e($value('slack_username')) ?>" placeholder="Storm Watch">
        </div>
        <div class="field">
          <label for="slack_icon_emoji">Override icon emoji</label>
          <input type="text" id="slack_icon_emoji" name="slack_icon_emoji" value="<?= Http::e($value('slack_icon_emoji')) ?>" placeholder="zap">
        </div>
      </div>
    </div>

    <div class="btn-row">
      <button type="submit" class="btn primary">Save Slack settings</button>
      <button type="button" class="btn" data-test="test_slack">Send a test message</button>
    </div>
    <div id="testResult"></div>
  </form>

<?php elseif ($tab === 'email'): ?>
  <form method="post">
    <?= Http::csrfField() ?>
    <input type="hidden" name="action" value="save">
    <div class="panel">
      <div class="panel-title">Email alerts</div>
      <div class="toggle-row">
        <div>
          <div class="lbl">Send alerts by email</div>
          <div class="sub">A useful backup for Slack, and it reaches people who are not in the workspace.</div>
        </div>
        <label class="switch"><input type="checkbox" name="email_enabled"<?= $checked('email_enabled') ?>><span class="slider"></span></label>
      </div>
      <div class="field">
        <label for="email_to">Recipients</label>
        <input type="text" id="email_to" name="email_to" value="<?= Http::e($value('email_to')) ?>" placeholder="ops@example.com, manager@example.com" class="<?= $errorClass('email_to') ?>">
        <?= $errorNote('email_to') ?>
        <div class="field-note">Separate several addresses with commas. Carrier SMS gateways work here too.</div>
      </div>
      <div class="field-grid2">
        <div class="field">
          <label for="email_from">From address</label>
          <input type="text" id="email_from" name="email_from" value="<?= Http::e($value('email_from')) ?>" placeholder="stormwatch@yourdomain.com" class="<?= $errorClass('email_from') ?>">
          <?= $errorNote('email_from') ?>
          <div class="field-note">Use a real mailbox on this domain, or the host will reject the message.</div>
        </div>
        <div class="field">
          <label for="email_from_name">From name</label>
          <input type="text" id="email_from_name" name="email_from_name" value="<?= Http::e($value('email_from_name')) ?>">
        </div>
      </div>
    </div>

    <div class="panel">
      <div class="panel-title">Delivery</div>
      <div class="field">
        <label for="email_transport">Transport</label>
        <select id="email_transport" name="email_transport">
          <option value="mail"<?= $selected('email_transport', 'mail') ?>>Server mail (PHP mail)</option>
          <option value="smtp"<?= $selected('email_transport', 'smtp') ?>>SMTP</option>
        </select>
        <div class="field-note">SMTP is worth the extra fields — mail sent straight from a shared host is often filtered as spam.</div>
      </div>
      <div class="field-grid2">
        <div class="field">
          <label for="smtp_host">SMTP host</label>
          <input type="text" id="smtp_host" name="smtp_host" value="<?= Http::e($value('smtp_host')) ?>" placeholder="mail.yourdomain.com" class="<?= $errorClass('smtp_host') ?>">
          <?= $errorNote('smtp_host') ?>
        </div>
        <div class="field">
          <label for="smtp_port">Port</label>
          <input type="number" id="smtp_port" name="smtp_port" value="<?= Http::e($value('smtp_port')) ?>" class="<?= $errorClass('smtp_port') ?>">
          <?= $errorNote('smtp_port') ?>
        </div>
      </div>
      <div class="field-grid3">
        <div class="field">
          <label for="smtp_secure">Encryption</label>
          <select id="smtp_secure" name="smtp_secure">
            <option value="tls"<?= $selected('smtp_secure', 'tls') ?>>STARTTLS (587)</option>
            <option value="ssl"<?= $selected('smtp_secure', 'ssl') ?>>SSL/TLS (465)</option>
            <option value="none"<?= $selected('smtp_secure', 'none') ?>>None</option>
          </select>
        </div>
        <div class="field">
          <label for="smtp_user">Username</label>
          <input type="text" id="smtp_user" name="smtp_user" value="<?= Http::e($value('smtp_user')) ?>" autocomplete="off">
        </div>
        <div class="field">
          <label for="smtp_pass">Password</label>
          <input type="password" id="smtp_pass" name="smtp_pass" autocomplete="new-password"
                 placeholder="<?= $secretSet('smtp_pass') ? 'Saved' : '' ?>">
        </div>
      </div>
    </div>

    <div class="btn-row">
      <button type="submit" class="btn primary">Save email settings</button>
      <button type="button" class="btn" data-test="test_email">Send a test email</button>
    </div>
    <div id="testResult"></div>
  </form>

<?php elseif ($tab === 'source'): ?>
  <form method="post">
    <?= Http::csrfField() ?>
    <input type="hidden" name="action" value="save">
    <div class="panel">
      <div class="panel-title">Where lightning data comes from</div>
      <div class="field">
        <label for="provider">Provider</label>
        <select id="provider" name="provider">
          <option value="blitzortung"<?= $selected('provider', 'blitzortung') ?>>Blitzortung live feed (free, server-side)</option>
          <option value="rest"<?= $selected('provider', 'rest') ?>>REST endpoint (commercial feed)</option>
          <option value="relay"<?= $selected('provider', 'relay') ?>>Browser relay (Blitzortung via an open tab)</option>
          <option value="simulator"<?= $selected('provider', 'simulator') ?>>Simulator (demo data)</option>
        </select>
        <div class="field-note">
          Start with <b>Blitzortung</b> and press Test. If your host blocks the port, switch to
          <b>Browser relay</b> — same free data, fetched by a browser tab instead of the server.
          A commercial <b>REST</b> feed is the choice when you need a service-level guarantee.
        </div>
      </div>
      <div class="notice warn" id="simulatorWarning" style="<?= $value('provider') === 'simulator' ? '' : 'display:none;' ?>">
        <b>Simulator selected.</b> The map and alerts are driven by invented data. Switch to a live
        source before this is relied on for anything real.
      </div>
    </div>

    <div class="panel" data-provider="blitzortung relay">
      <div class="panel-title">Blitzortung</div>
      <div class="field-grid2">
        <div class="field">
          <label for="blitz_servers">Servers to try</label>
          <input type="text" id="blitz_servers" name="blitz_servers" value="<?= Http::e($value('blitz_servers')) ?>" placeholder="ws1,ws7,ws8">
          <div class="field-note">Comma separated. The worker rotates through them when one drops.</div>
        </div>
        <div class="field">
          <label for="worker_seconds">Worker run time (s)</label>
          <input type="number" id="worker_seconds" name="worker_seconds" min="5" max="300" value="<?= Http::e($value('worker_seconds')) ?>" class="<?= $errorClass('worker_seconds') ?>">
          <?= $errorNote('worker_seconds') ?>
          <div class="field-note">Keep just under your cron interval — 50 seconds for a once-a-minute job.</div>
        </div>
      </div>
      <div class="field">
        <label for="blitz_init_json">Subscribe message</label>
        <input type="text" id="blitz_init_json" name="blitz_init_json" value="<?= Http::e($value('blitz_init_json')) ?>">
        <div class="field-note">The JSON sent on connect. Leave as <code>{"a":111}</code> unless the feed's protocol changes.</div>
      </div>
      <div class="notice">
        Blitzortung is a volunteer network with an undocumented feed. It is free and remarkably good, but it
        comes with no guarantees — pair it with feed-failure notifications on the Alert rules tab.
      </div>
    </div>

    <div class="panel" data-provider="rest">
      <div class="panel-title">REST endpoint</div>
      <div class="field">
        <label for="rest_endpoint">Endpoint URL</label>
        <input type="text" id="rest_endpoint" name="rest_endpoint" value="<?= Http::e($value('rest_endpoint')) ?>" placeholder="https://api.example.com/lightning?lat={lat}&amp;lon={lon}" class="<?= $errorClass('rest_endpoint') ?>">
        <?= $errorNote('rest_endpoint') ?>
        <div class="field-note">
          Placeholders you can use: <code>{lat}</code> <code>{lon}</code> <code>{radius_mi}</code>
          <code>{radius_km}</code> <code>{now}</code> <code>{now_iso}</code> <code>{since}</code> <code>{since_iso}</code>.
        </div>
      </div>
      <div class="field-grid2">
        <div class="field">
          <label for="rest_auth_mode">Send the key as</label>
          <select id="rest_auth_mode" name="rest_auth_mode">
            <option value="query"<?= $selected('rest_auth_mode', 'query') ?>>Query parameter</option>
            <option value="header"<?= $selected('rest_auth_mode', 'header') ?>>Request header</option>
            <option value="bearer"<?= $selected('rest_auth_mode', 'bearer') ?>>Authorization: Bearer</option>
            <option value="none"<?= $selected('rest_auth_mode', 'none') ?>>No key</option>
          </select>
        </div>
        <div class="field">
          <label for="rest_auth_name">Parameter / header name</label>
          <input type="text" id="rest_auth_name" name="rest_auth_name" value="<?= Http::e($value('rest_auth_name')) ?>" placeholder="apikey">
        </div>
      </div>
      <div class="field">
        <label for="rest_api_key">API key</label>
        <input type="password" id="rest_api_key" name="rest_api_key" autocomplete="off"
               placeholder="<?= $secretSet('rest_api_key') ? 'Saved — leave blank to keep it' : '' ?>">
        <?php if ($secretSet('rest_api_key')): ?>
          <div class="field-note"><label style="display:inline-flex;gap:6px;align-items:center;cursor:pointer;">
            <input type="checkbox" name="clear_rest_api_key" style="width:auto;"> Remove the stored key
          </label></div>
        <?php endif; ?>
      </div>
      <div class="field">
        <label for="rest_extra_headers">Extra headers</label>
        <textarea id="rest_extra_headers" name="rest_extra_headers" placeholder="X-Client: stormwatch"><?= Http::e($value('rest_extra_headers')) ?></textarea>
        <div class="field-note">One <code>Name: value</code> per line.</div>
      </div>
      <div class="field">
        <label for="rest_poll_seconds">Poll every (seconds)</label>
        <input type="number" id="rest_poll_seconds" name="rest_poll_seconds" min="15" max="3600" value="<?= Http::e($value('rest_poll_seconds')) ?>" class="<?= $errorClass('rest_poll_seconds') ?>">
        <?= $errorNote('rest_poll_seconds') ?>
        <div class="field-note">Rounded up to the cron interval — a once-a-minute cron cannot poll faster than that.</div>
      </div>

      <div class="panel-title" style="margin-top:22px;">Response mapping</div>
      <div class="field-note" style="margin-bottom:10px;">
        Dot paths into the JSON the endpoint returns. For
        <code>{"data":{"strikes":[{"coords":{"lat":1,"lon":2},"t":"…"}]}}</code>
        the list path is <code>data.strikes</code>, latitude is <code>coords.lat</code> and time is <code>t</code>.
      </div>
      <div class="field-grid2">
        <div class="field">
          <label for="rest_map_root">List path</label>
          <input type="text" id="rest_map_root" name="rest_map_root" value="<?= Http::e($value('rest_map_root')) ?>" placeholder="data">
        </div>
        <div class="field">
          <label for="rest_map_time">Time field</label>
          <input type="text" id="rest_map_time" name="rest_map_time" value="<?= Http::e($value('rest_map_time')) ?>" placeholder="time">
        </div>
      </div>
      <div class="field-grid3">
        <div class="field">
          <label for="rest_map_lat">Latitude field</label>
          <input type="text" id="rest_map_lat" name="rest_map_lat" value="<?= Http::e($value('rest_map_lat')) ?>" placeholder="lat">
        </div>
        <div class="field">
          <label for="rest_map_lon">Longitude field</label>
          <input type="text" id="rest_map_lon" name="rest_map_lon" value="<?= Http::e($value('rest_map_lon')) ?>" placeholder="lon">
        </div>
        <div class="field">
          <label for="rest_time_format">Time format</label>
          <select id="rest_time_format" name="rest_time_format">
            <option value="auto"<?= $selected('rest_time_format', 'auto') ?>>Detect automatically</option>
            <option value="iso8601"<?= $selected('rest_time_format', 'iso8601') ?>>ISO-8601 text</option>
            <option value="epoch_s"<?= $selected('rest_time_format', 'epoch_s') ?>>Unix seconds</option>
            <option value="epoch_ms"<?= $selected('rest_time_format', 'epoch_ms') ?>>Unix milliseconds</option>
            <option value="epoch_ns"<?= $selected('rest_time_format', 'epoch_ns') ?>>Unix nanoseconds</option>
          </select>
        </div>
      </div>
    </div>

    <div class="panel" data-provider="relay">
      <div class="panel-title">Browser relay</div>
      <p class="field-note" style="margin-top:0;">
        The dashboard connects to Blitzortung from the browser and posts nearby strikes back here.
        Keep one tab open on a machine that stays on — the venue's wall display is ideal. Alerts are still
        sent by the server, so Slack works normally; it is only the data collection that depends on the tab.
      </p>
      <div class="field">
        <label>Ingest token</label>
        <div class="token-box"><?= Http::e(Settings::getString('ingest_token')) ?></div>
        <div class="field-note">Sent by the relay with every batch. Regenerate it on the System tab if it leaks.</div>
      </div>
    </div>

    <div class="btn-row">
      <button type="submit" class="btn primary">Save data source</button>
      <button type="button" class="btn" data-test="test_source">Test this source</button>
    </div>
    <div id="testResult"></div>
  </form>

<?php else: ?>
  <div class="panel">
    <div class="panel-title">Access</div>
    <form method="post">
      <?= Http::csrfField() ?>
      <input type="hidden" name="action" value="save">
      <div class="toggle-row">
        <div>
          <div class="lbl">Public dashboard</div>
          <div class="sub">Let anyone with the link see the dashboard, read only. Settings always require signing in.</div>
        </div>
        <label class="switch"><input type="checkbox" name="dashboard_public"<?= $checked('dashboard_public') ?>><span class="slider"></span></label>
      </div>
      <div class="field">
        <label for="retention_hours">Keep strike history for (hours)</label>
        <input type="number" id="retention_hours" name="retention_hours" min="1" max="8760" value="<?= Http::e($value('retention_hours')) ?>" class="<?= $errorClass('retention_hours') ?>">
        <?= $errorNote('retention_hours') ?>
      </div>
      <div class="btn-row"><button type="submit" class="btn primary">Save</button></div>
    </form>
  </div>

  <div class="panel">
    <div class="panel-title">Tokens and URLs</div>
    <?php
    $tokenRows = [
        'kiosk_token' => ['Kiosk link', 'Open the dashboard on a wall display without signing in.', Http::absoluteUrl('index.php') . '?kiosk=' . Settings::getString('kiosk_token')],
        'cron_token' => ['Web cron URL', 'For hosts without cron jobs: call this once a minute from an external scheduler.', Http::absoluteUrl('api/cron.php') . '?token=' . Settings::getString('cron_token') . '&job=tick'],
        'ingest_token' => ['Ingest token', 'Used by the browser relay to post strikes.', Settings::getString('ingest_token')],
    ];
    foreach ($tokenRows as $key => [$label, $help, $display]): ?>
      <div class="field">
        <label><?= Http::e($label) ?></label>
        <div class="token-box"><?= Http::e($display) ?></div>
        <div class="field-note"><?= Http::e($help) ?></div>
        <form method="post" style="margin-top:8px;">
          <?= Http::csrfField() ?>
          <input type="hidden" name="action" value="regenerate_token">
          <input type="hidden" name="token_key" value="<?= Http::e($key) ?>">
          <button type="submit" class="btn small auto">Regenerate</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="panel">
    <div class="panel-title">Accounts</div>
    <div class="table-scroll">
      <table class="table">
        <thead><tr><th>User</th><th>Role</th><th>Last signed in</th><th></th></tr></thead>
        <tbody>
        <?php foreach (Auth::listUsers() as $row): ?>
          <tr>
            <td><strong><?= Http::e((string) $row['username']) ?></strong><?= $row['display_name'] !== '' ? '<br>' . Http::e((string) $row['display_name']) : '' ?></td>
            <td><span class="badge"><?= Http::e((string) $row['role']) ?></span></td>
            <td class="mono"><?= $row['last_login_at'] ? Http::e(date('Y-m-d H:i', (int) $row['last_login_at'])) . ' UTC' : 'never' ?></td>
            <td style="text-align:right;">
              <?php if ((int) $row['id'] !== (int) $user['id']): ?>
                <form method="post" onsubmit="return confirm('Remove this account?');">
                  <?= Http::csrfField() ?>
                  <input type="hidden" name="action" value="delete_user">
                  <input type="hidden" name="user_id" value="<?= (int) $row['id'] ?>">
                  <button type="submit" class="btn small auto danger">Remove</button>
                </form>
              <?php else: ?>
                <span class="text-lo" style="font-size:11.5px;">you</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <form method="post" style="margin-top:20px;">
      <?= Http::csrfField() ?>
      <input type="hidden" name="action" value="add_user">
      <div class="field-grid3">
        <div class="field"><label for="new_username">New username</label><input type="text" id="new_username" name="new_username" autocomplete="off"></div>
        <div class="field"><label for="new_display_name">Display name</label><input type="text" id="new_display_name" name="new_display_name" autocomplete="off"></div>
        <div class="field"><label for="new_password">Password</label><input type="password" id="new_password" name="new_password" autocomplete="new-password"></div>
      </div>
      <div class="btn-row"><button type="submit" class="btn auto">Add account</button></div>
    </form>
  </div>

  <div class="panel">
    <div class="panel-title">Change your password</div>
    <form method="post">
      <?= Http::csrfField() ?>
      <input type="hidden" name="action" value="change_password">
      <div class="field-grid2">
        <div class="field"><label for="current_password">Current password</label><input type="password" id="current_password" name="current_password" autocomplete="current-password"></div>
        <div class="field"><label for="np">New password</label><input type="password" id="np" name="new_password" autocomplete="new-password"></div>
      </div>
      <div class="btn-row"><button type="submit" class="btn auto">Update password</button></div>
    </form>
  </div>

  <div class="panel">
    <div class="panel-title">About</div>
    <table class="table">
      <tr><td>Version</td><td><strong><?= Http::e(SW_VERSION) ?></strong></td></tr>
      <tr><td>PHP</td><td><strong><?= Http::e(PHP_VERSION) ?></strong></td></tr>
      <tr><td>Database</td><td><strong><?= Http::e(\StormWatch\Database::instance()->driver()) ?></strong></td></tr>
      <tr><td>Server time</td><td class="mono"><?= Http::e(gmdate('Y-m-d H:i:s')) ?> UTC</td></tr>
    </table>
  </div>
<?php endif; ?>

<?php
View::bootData('sw-settings', [
    'actionUrl' => Http::url('api/action.php'),
    'csrf' => Http::csrfToken(),
]);
View::end(['js/settings.js'], View::footerText());

<?php
/**
 * The dashboard: live map, alert state, statistics and the strike log.
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use StormWatch\App;
use StormWatch\Auth;
use StormWatch\Geo;
use StormWatch\Http;
use StormWatch\Settings;
use StormWatch\View;

App::boot('view');

$canAct = Auth::check();
$units = Settings::getString('units');
$provider = Settings::getString('provider');

$boot = [
    'stateUrl' => Http::url('api/state.php'),
    'actionUrl' => Http::url('api/action.php'),
    'loginUrl' => Http::url('login.php'),
    // Scoped so a second install on the same domain keeps its own preferences.
    'storagePrefix' => Http::storagePrefix(),
    'csrf' => $canAct ? Http::csrfToken() : '',
    'canAct' => $canAct,
    'venue' => [
        'name' => Settings::getString('venue_name'),
        'address' => Settings::getString('venue_address'),
        'lat' => Settings::getFloat('venue_lat'),
        'lon' => Settings::getFloat('venue_lon'),
    ],
    'radii' => [
        'alert' => Settings::getFloat('alert_radius_mi'),
        'watch' => Settings::getFloat('watch_radius_mi'),
        'display' => Settings::getFloat('display_radius_mi'),
    ],
    'units' => $units,
    'timezone' => Settings::getString('timezone'),
    'refreshSeconds' => Settings::getInt('ui_refresh_seconds'),
    'mapZoom' => Settings::getInt('map_zoom'),
    'markerTtlMinutes' => Settings::getInt('marker_ttl_minutes'),
    'allClearMinutes' => Settings::getInt('all_clear_minutes'),
    'radar' => [
        'type' => Settings::getString('radar_overlay'),
        'opacity' => Settings::getInt('radar_opacity') / 100,
    ],
    'provider' => $provider,
];

if ($provider === 'relay') {
    $boot['relay'] = [
        'ingestUrl' => Http::url('api/ingest.php'),
        'token' => Settings::getString('ingest_token'),
        'servers' => \StormWatch\Providers\Blitzortung::servers(),
        'initJson' => Settings::getString('blitz_init_json') ?: '{"a":111}',
        'displayRadiusMi' => Settings::getFloat('display_radius_mi'),
    ];
}

$fmt = static fn(float $mi): string => Geo::formatDistance($mi, $units);

View::start(['title' => 'Dashboard', 'leaflet' => true]);
View::header('dashboard');
?>

<?php /* Neutral until the first poll answers: a green tick before anything has
         been read would say "all clear" on no evidence at all. */ ?>
<div id="alertBanner" class="alert-banner unknown">
  <div class="alert-icon" id="alertIcon">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M9.6 9.2a2.5 2.5 0 0 1 4.6 1.3c0 1.6-2.2 2-2.2 3.2"/><path d="M12 17h.01"/></svg>
  </div>
  <div class="alert-text">
    <div class="t1" id="alertT1">Loading…</div>
    <div class="t2" id="alertT2">Checking the current alert state.</div>
  </div>
  <?php if ($canAct): ?>
  <div class="alert-actions">
    <button class="btn small" id="muteBtn" type="button" title="Suppress Slack and email alerts temporarily">Mute 30m</button>
  </div>
  <?php endif; ?>
</div>

<div class="grid">
  <div>
    <div class="panel">
      <div class="panel-title">
        <span>Live map — strikes within <?= Http::e($fmt(Settings::getFloat('display_radius_mi'))) ?></span>
        <span class="muted" id="strikeCount">0 strikes tracked</span>
      </div>
      <div class="map-wrap"><div id="map"></div></div>
      <div class="radar-legend">
        <span><i class="swatch" style="background:var(--danger)"></i>Within <?= Http::e($fmt(Settings::getFloat('alert_radius_mi'))) ?></span>
        <span><i class="swatch" style="background:var(--amber)"></i>To <?= Http::e($fmt(Settings::getFloat('watch_radius_mi'))) ?></span>
        <span><i class="swatch" style="background:var(--bolt)"></i>To <?= Http::e($fmt(Settings::getFloat('display_radius_mi'))) ?></span>
        <span class="grow"></span>
        <?php if (Settings::getString('radar_overlay') !== 'none'): ?>
        <label class="opacity-control" for="radarOpacity">
          Radar
          <input type="range" id="radarOpacity" min="0" max="100" value="<?= (int) Settings::getInt('radar_opacity') ?>">
        </label>
        <?php endif; ?>
      </div>
    </div>

    <div class="panel">
      <div class="panel-title">
        <span>Last hour</span>
        <span class="muted" id="lastStrikeAge"></span>
      </div>
      <div class="stat-row">
        <div class="stat"><div class="n" id="statTotal">0</div><div class="l">TOTAL STRIKES</div></div>
        <div class="stat"><div class="n" id="statClose">0</div><div class="l">WITHIN <?= Http::e(strtoupper($fmt(Settings::getFloat('alert_radius_mi')))) ?></div></div>
        <div class="stat"><div class="n" id="statNearest">—</div><div class="l">NEAREST STRIKE</div></div>
        <div class="stat"><div class="n" id="statAllClear">—</div><div class="l">ALL CLEAR IN</div></div>
      </div>
    </div>
  </div>

  <div>
    <div class="panel">
      <div class="panel-title">Data source</div>
      <div class="toggle-row" style="margin-bottom:12px;">
        <div>
          <div class="lbl" id="providerLabel"><?= Http::e(ucfirst($provider)) ?></div>
          <div class="sub" id="providerSub">Checking feed health…</div>
        </div>
      </div>
      <div class="status-line" style="margin-top:0;border-top:0;padding-top:0;">
        <span class="status-dot" id="sourceDot"></span>
        <span id="sourceText">Loading…</span>
      </div>
      <div class="status-line">
        <span class="status-dot" id="cronDot"></span>
        <span id="cronText">Checking the scheduled task…</span>
      </div>
      <div class="status-line">
        <span class="status-dot" id="notifyDot"></span>
        <span id="notifyText">Checking alert channels…</span>
      </div>
      <?php if ($canAct): ?>
      <div class="btn-row" style="margin-top:14px;">
        <a class="btn small" href="<?= Http::e(Http::url('settings.php?tab=source')) ?>">Configure</a>
        <button class="btn small" id="runTickBtn" type="button">Run check now</button>
      </div>
      <?php endif; ?>
    </div>

    <div class="panel">
      <div class="panel-title">Alerts</div>
      <div class="row-between field">
        <div>
          <label style="margin-bottom:2px;">Browser notifications</label>
          <div class="helptext">Alerts this device when a strike lands within <?= Http::e($fmt(Settings::getFloat('alert_radius_mi'))) ?>.</div>
        </div>
        <label class="switch">
          <input type="checkbox" id="notifToggle">
          <span class="slider"></span>
        </label>
      </div>
      <div class="helptext" style="margin-bottom:14px;">
        Slack and email alerts are sent by the server, so they work whether or not this page is open.
        Manage them in <a href="<?= Http::e(Http::url('settings.php?tab=slack')) ?>">Settings</a>.
      </div>
      <?php if ($canAct): ?>
      <div class="btn-row">
        <button class="btn" id="simulateBtn" type="button">Simulate strike</button>
        <button class="btn" id="unmuteBtn" type="button" style="display:none;">Un-mute alerts</button>
      </div>
      <?php endif; ?>
    </div>

    <div class="panel">
      <div class="panel-title">
        <span>Strike log</span>
        <?php if ($canAct): ?><button class="link" id="logClearBtn" type="button">Clear</button><?php endif; ?>
      </div>
      <div class="log-list" id="logList">
        <div class="log-empty">No strikes recorded yet.</div>
      </div>
    </div>
  </div>
</div>

<?php
View::bootData('sw-boot', $boot);

$scripts = ['js/dashboard.js'];
if ($provider === 'relay') {
    $scripts[] = 'js/relay.js';
}
View::end($scripts, View::footerText());

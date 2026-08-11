<?php
/**
 * The dashboard: live map, alert state, statistics and the strike log.
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use StormWatch\App;
use StormWatch\Auth;
use StormWatch\Forecast;
use StormWatch\Geo;
use StormWatch\Http;
use StormWatch\Settings;
use StormWatch\View;

App::boot('view');

$canAct = Auth::check();
$units = Settings::getString('units');
$provider = Settings::getString('provider');
$showForecast = Forecast::isEnabled();

// A kiosk display is authenticated by the token in its URL rather than by a
// session, so the polling URL needs it too — see App::activeKioskToken().
$kioskToken = App::activeKioskToken();
$stateUrl = Http::url('api/state.php');
if ($kioskToken !== null && !$canAct) {
    $stateUrl .= '?kiosk=' . rawurlencode($kioskToken);
}

$boot = [
    'stateUrl' => $stateUrl,
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
    'mapStyle' => Settings::getString('map_style'),
    'markerTtlMinutes' => Settings::getInt('marker_ttl_minutes'),
    'allClearMinutes' => Settings::getInt('all_clear_minutes'),
    'radar' => [
        'type' => Settings::getString('radar_overlay'),
        'opacity' => Settings::getInt('radar_opacity') / 100,
    ],
    'provider' => $provider,
    // Read from the cache table, never from api.weather.gov — a page load must
    // not wait on somebody else's server. The cron tick keeps it current, and
    // the poll below replaces this copy when it changes.
    'forecast' => $showForecast ? Forecast::summary() : null,
];

// The relay block carries the ingest token, which is a write credential: it
// posts strikes the alert engine acts on. Staff and the venue's own kiosk
// display get it — that is what runs the relay. An anonymous viewer of a
// public dashboard does not, because "read only" has to mean it.
if ($provider === 'relay' && ($canAct || $kioskToken !== null)) {
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
  <?php /* Only during a warning hold: how long until the all clear, ticking
           by the second. Deliberately outside the sign-in gate — the wall
           display is where this number is actually read from. The dashboard
           script shows it, hides it, and keeps it running. */ ?>
  <div class="alert-countdown" id="alertCountdown" hidden>
    <span class="cd" id="alertCountdownClock">—</span>
    <span class="cd-l">to all clear</span>
  </div>
  <?php if ($canAct): ?>
  <div class="alert-actions">
    <button class="btn small" id="muteBtn" type="button" title="Suppress Slack and email alerts temporarily">Mute 30m</button>
    <?php /* Lives beside the mute button it undoes. It used to sit in the
             alerts panel; when that panel went, muting must not become a
             one-way door. */ ?>
    <button class="btn small" id="unmuteBtn" type="button" style="display:none;">Un-mute alerts</button>
  </div>
  <?php endif; ?>
</div>

<?php /* Directly under the alert banner and above everything else. The banner
         answers "is it dangerous now"; this answers "what is coming today",
         which is the question behind every decision made an hour ahead —
         whether to run the four o'clock party outside, when to start clearing
         the water park. Watches and warnings surface at the top of it, because
         those arrive before the first strike does.

         It stops at the end of the day deliberately. The attribution lives in
         the page footer and the note below only appears when something is
         wrong, so in normal weather the card is three short rows. */ ?>
<?php if ($showForecast): ?>
<section class="panel wx" id="wxCard">
  <div class="panel-title">
    <span>Today<span class="wx-place" id="wxPlace"></span></span>
    <span class="wx-meta">
      <span class="muted" id="wxUpdated">Loading the forecast…</span>
      <?php if ($canAct): ?><button type="button" class="link" id="wxRefreshBtn">Refresh</button><?php endif; ?>
    </span>
  </div>
  <div class="wx-alerts" id="wxAlerts" hidden></div>
  <div class="wx-today" id="wxToday" hidden></div>
  <div class="wx-hours" id="wxHours" hidden></div>
  <div class="wx-note" id="wxNote" hidden></div>
</section>
<?php endif; ?>

<div class="grid">
  <div>
    <div class="panel">
      <div class="panel-title">
        <span>Live map — strikes within <?= Http::e($fmt(Settings::getFloat('display_radius_mi'))) ?></span>
        <span class="muted" id="strikeCount">0 strikes tracked</span>
      </div>
      <div class="map-wrap"><div id="map"></div></div>
      <?php /* Two things are colour-coded on this map and they mean different
               things: the dashed rings are distance, the strike markers are
               age. Saying which is which is the difference between a legend
               and a row of dots. */ ?>
      <div class="radar-legend">
        <span class="legend-set">
          <span class="legend-label">Rings</span>
          <span><i class="swatch" style="background:var(--danger)"></i><?= Http::e($fmt(Settings::getFloat('alert_radius_mi'))) ?></span>
          <span><i class="swatch" style="background:var(--amber)"></i><?= Http::e($fmt(Settings::getFloat('watch_radius_mi'))) ?></span>
          <span><i class="swatch" id="ringSwatch" style="background:var(--bolt)"></i><?= Http::e($fmt(Settings::getFloat('display_radius_mi'))) ?></span>
        </span>
        <?php /* Filled by the dashboard script: the age ramp lives there with
                 the markers it colours, so the two cannot drift apart. */ ?>
        <span class="legend-set" id="strikeLegend"></span>
        <span class="grow"></span>
        <?php if (Settings::getString('radar_overlay') !== 'none'): ?>
        <label class="opacity-control" for="radarOpacity">
          Radar
          <input type="range" id="radarOpacity" min="0" max="100" value="<?= (int) Settings::getInt('radar_opacity') ?>">
        </label>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php /* One panel, the full height of the map beside it. The feed-health
           detail that used to live here rides on the header badge now, and the
           deep version is on the History and Settings pages. */ ?>
  <div>
    <div class="panel">
      <div class="panel-title">
        <span>Strike log</span>
        <?php if ($canAct): ?><button class="link" id="logClearBtn" type="button">Clear</button><?php endif; ?>
      </div>
      <div class="log-body">
        <div class="log-list" id="logList">
          <div class="log-empty">No strikes recorded yet.</div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php /* Full width beneath both columns: the summary of what the map above has
         been showing, rather than a fifth panel squeezed into one column. */ ?>
<div class="panel stats-panel">
  <div class="panel-title">
    <span>Last hour</span>
    <span class="muted" id="lastStrikeAge"></span>
  </div>
  <div class="stat-row">
    <div class="stat"><div class="n" id="statTotal">0</div><div class="l">Total strikes</div></div>
    <div class="stat"><div class="n" id="statClose">0</div><div class="l">Within <?= Http::e($fmt(Settings::getFloat('alert_radius_mi'))) ?></div></div>
    <div class="stat"><div class="n" id="statNearest">—</div><div class="l">Nearest strike</div></div>
    <div class="stat" id="statAllClearTile"><div class="n" id="statAllClear">—</div><div class="l">All clear in</div></div>
  </div>
</div>

<?php
View::bootData('sw-boot', $boot);

$scripts = ['js/dashboard.js'];
if ($provider === 'relay') {
    $scripts[] = 'js/relay.js';
}
View::end($scripts, View::footerText());

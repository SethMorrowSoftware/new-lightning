<?php
/**
 * Activity history: what was alerted, what was delivered, and what failed.
 * The answer to "did Slack actually get it?" lives here.
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use StormWatch\App;
use StormWatch\Database;
use StormWatch\Events;
use StormWatch\Geo;
use StormWatch\Http;
use StormWatch\Settings;
use StormWatch\View;

App::boot('auth');

$filterType = (string) ($_GET['type'] ?? '');
$filterSeverity = (string) ($_GET['severity'] ?? '');
$events = Events::recent(200, $filterType !== '' ? $filterType : null, $filterSeverity !== '' ? $filterSeverity : null);

try {
    $tz = new DateTimeZone(Settings::getString('timezone') ?: 'UTC');
} catch (Throwable $e) {
    $tz = new DateTimeZone('UTC');
}
$localTime = static function (int $timestamp) use ($tz): string {
    return (new DateTimeImmutable('@' . $timestamp))->setTimezone($tz)->format('D j M, H:i:s');
};

$units = Settings::getString('units');
$recentStrikes = Database::instance()->all(
    'SELECT struck_at, distance_mi, bearing_deg, source FROM strikes ORDER BY struck_at DESC LIMIT 60'
);
$runs = Database::instance()->all('SELECT * FROM runs ORDER BY id DESC LIMIT 25');

View::start(['title' => 'History']);
View::header('history');
?>

<div class="panel">
  <div class="panel-title">
    <span>Activity log</span>
    <span class="muted"><?= count($events) ?> most recent entries</span>
  </div>

  <form method="get" class="field-grid3" style="margin-bottom:6px;">
    <div class="field">
      <label for="type">Type</label>
      <select id="type" name="type" onchange="this.form.submit()">
        <option value="">Everything</option>
        <?php foreach (['alert' => 'Alerts', 'slack' => 'Slack delivery', 'email' => 'Email delivery', 'system' => 'System', 'source' => 'Source tests', 'settings' => 'Settings changes', 'auth' => 'Sign-ins'] as $prefix => $label): ?>
          <option value="<?= Http::e($prefix) ?>"<?= $filterType === $prefix ? ' selected' : '' ?>><?= Http::e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="severity">Severity</label>
      <select id="severity" name="severity" onchange="this.form.submit()">
        <option value="">Any</option>
        <?php foreach (['critical', 'error', 'warning', 'info'] as $level): ?>
          <option value="<?= Http::e($level) ?>"<?= $filterSeverity === $level ? ' selected' : '' ?>><?= Http::e(ucfirst($level)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field" style="display:flex;align-items:flex-end;">
      <a class="btn" href="<?= Http::e(Http::url('history.php')) ?>">Clear filters</a>
    </div>
  </form>

  <div class="table-scroll">
    <table class="table">
      <thead><tr><th>When</th><th>Type</th><th>Severity</th><th>Detail</th></tr></thead>
      <tbody>
      <?php if ($events === []): ?>
        <tr><td colspan="4" class="text-lo" style="padding:22px 10px;text-align:center;">Nothing logged yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($events as $event): ?>
        <tr>
          <td class="mono" style="white-space:nowrap;"><?= Http::e($localTime((int) $event['created_at'])) ?></td>
          <td class="mono"><?= Http::e((string) $event['type']) ?></td>
          <td><span class="badge <?= Http::e((string) $event['severity']) ?>"><?= Http::e((string) $event['severity']) ?></span></td>
          <td><?= Http::e((string) $event['message']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="grid">
  <div class="panel">
    <div class="panel-title">Recent strikes</div>
    <div class="table-scroll">
      <table class="table">
        <thead><tr><th>When</th><th>Distance</th><th>Direction</th><th>Source</th></tr></thead>
        <tbody>
        <?php if ($recentStrikes === []): ?>
          <tr><td colspan="4" class="text-lo" style="padding:22px 10px;text-align:center;">No strikes stored.</td></tr>
        <?php endif; ?>
        <?php foreach ($recentStrikes as $strike): ?>
          <tr>
            <td class="mono" style="white-space:nowrap;"><?= Http::e($localTime((int) $strike['struck_at'])) ?></td>
            <td class="mono"><?= Http::e(Geo::formatDistance((float) $strike['distance_mi'], $units)) ?></td>
            <td class="mono"><?= Http::e(Geo::compassPoint((float) $strike['bearing_deg'])) ?></td>
            <td><span class="badge"><?= Http::e((string) $strike['source']) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="panel">
    <div class="panel-title">Scheduled runs</div>
    <div class="table-scroll">
      <table class="table">
        <thead><tr><th>Started</th><th>Job</th><th>Result</th></tr></thead>
        <tbody>
        <?php if ($runs === []): ?>
          <tr><td colspan="3" class="text-lo" style="padding:22px 10px;text-align:center;">
            No scheduled runs recorded. Install the cron jobs — alerts depend on them.
          </td></tr>
        <?php endif; ?>
        <?php foreach ($runs as $run): ?>
          <tr>
            <td class="mono" style="white-space:nowrap;"><?= Http::e($localTime((int) $run['started_at'])) ?></td>
            <td class="mono"><?= Http::e((string) $run['job']) ?></td>
            <td>
              <span class="badge <?= ((int) $run['ok'] === 1) ? 'ok' : 'error' ?>"><?= ((int) $run['ok'] === 1) ? 'ok' : 'failed' ?></span>
              <?php if ((int) $run['ingested'] > 0): ?>
                <span class="text-lo"> +<?= (int) $run['ingested'] ?></span>
              <?php endif; ?>
              <div class="text-lo" style="font-size:11.5px;margin-top:3px;"><?= Http::e(mb_substr((string) $run['message'], 0, 160)) ?></div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
View::end([], View::footerText());

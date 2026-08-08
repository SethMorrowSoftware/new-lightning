<?php
/**
 * Page chrome. Every screen shares the same head, header and navigation, so
 * the app looks like one product rather than a pile of scripts.
 */
declare(strict_types=1);

namespace StormWatch;

final class View
{
    private const FONTS_URL = 'https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap';
    private const LEAFLET_CSS = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
    private const LEAFLET_JS = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';

    /**
     * @param array{title?:string,leaflet?:bool,bodyClass?:string,wrap?:string} $options
     */
    public static function start(array $options = []): void
    {
        $title = $options['title'] ?? 'Storm Watch';
        $venue = Config::isInstalled() ? Settings::getString('venue_name') : 'Storm Watch';
        $bodyClass = $options['bodyClass'] ?? '';
        $useLeaflet = !empty($options['leaflet']);
        $asset = static fn(string $path): string => Http::url('assets/' . $path) . '?v=' . SW_VERSION;

        echo '<!DOCTYPE html>' . "\n";
        echo '<html lang="en">' . "\n<head>\n";
        echo '<meta charset="UTF-8">' . "\n";
        echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">' . "\n";
        echo '<meta name="robots" content="noindex, nofollow">' . "\n";
        echo '<title>' . Http::e($title . ' — ' . $venue) . '</title>' . "\n";
        echo '<link rel="icon" href="data:image/svg+xml,'
            . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M13 2L4 14h6l-1 8 9-12h-6l1-8z" fill="#FFB627"/></svg>')
            . '">' . "\n";
        echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
        echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
        echo '<link href="' . self::FONTS_URL . '" rel="stylesheet">' . "\n";
        if ($useLeaflet) {
            echo '<link rel="stylesheet" href="' . Http::e(self::leafletUrl('css')) . '">' . "\n";
        }
        echo '<link rel="stylesheet" href="' . $asset('css/app.css') . '">' . "\n";
        echo "</head>\n";
        echo '<body' . ($bodyClass !== '' ? ' class="' . Http::e($bodyClass) . '"' : '') . ">\n";

        if ($useLeaflet) {
            echo '<script src="' . Http::e(self::leafletUrl('js')) . '"></script>' . "\n";
        }

        echo '<div class="toast-stack" id="toastStack"></div>' . "\n";
        echo '<div class="' . Http::e($options['wrap'] ?? 'wrap') . '">' . "\n";
    }

    /**
     * Prefer a locally vendored Leaflet when one has been dropped into
     * public/assets/vendor/leaflet/, so a site behind a restrictive network —
     * or a CDN having a bad day — still gets a map. Falls back to the CDN.
     */
    private static function leafletUrl(string $kind): string
    {
        $file = $kind === 'css' ? 'leaflet.css' : 'leaflet.js';
        if (is_file(__DIR__ . '/../public/assets/vendor/leaflet/' . $file)) {
            return Http::url('assets/vendor/leaflet/' . $file) . '?v=' . SW_VERSION;
        }
        return $kind === 'css' ? self::LEAFLET_CSS : self::LEAFLET_JS;
    }

    /** The venue header used on the dashboard and admin screens. */
    public static function header(string $active = ''): void
    {
        $user = Auth::user();
        $isDashboard = $active === 'dashboard';

        // Only the dashboard has a script driving the badge and clock. On the
        // other screens show the alert level as it stands right now, which is
        // worth knowing while you are changing settings.
        $badgeClass = '';
        $badgeText = 'Connecting…';
        if (!$isDashboard) {
            $level = AlertEngine::publicState()['level'];
            $badgeClass = $level === 'warning' ? ' live-err' : ($level === 'clear' ? ' live-ok' : '');
            $badgeText = $level === 'warning'
                ? 'Lightning alert active'
                : ($level === 'watch' ? 'Storm within the watch radius' : 'All clear');
        }
        ?>
        <header class="app-header">
          <div class="brand">
            <div class="crest">
              <svg viewBox="0 0 24 24" fill="none" stroke="var(--amber)" stroke-width="1.6"><path d="M4 21V9l3-3 3 3V6l2-2 2 2v3l3-3 3 3v12H4z"/><path d="M9 21v-5h2v5M13 21v-5h2v5"/></svg>
            </div>
            <div>
              <h1>Storm Watch</h1>
              <div class="addr"><?= Http::e(Settings::getString('venue_name')) ?><?= Settings::getString('venue_address') !== '' ? ' · ' . Http::e(Settings::getString('venue_address')) : '' ?></div>
            </div>
          </div>
          <div class="headmeta">
            <div class="mode-badge<?= $badgeClass ?>" id="modeBadge"><span class="dot"></span><span id="modeBadgeText"><?= Http::e($badgeText) ?></span></div>
            <?php if ($isDashboard): ?><div id="clock">--:--:--</div><?php endif; ?>
          </div>
        </header>

        <?php if ($user !== null): ?>
        <nav class="nav">
          <a href="<?= Http::e(Http::url('index.php')) ?>" class="<?= $active === 'dashboard' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M13 2L4 14h6l-1 8 9-12h-6l1-8z"/></svg>
            Dashboard
          </a>
          <a href="<?= Http::e(Http::url('history.php')) ?>" class="<?= $active === 'history' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
            History
          </a>
          <a href="<?= Http::e(Http::url('settings.php')) ?>" class="<?= $active === 'settings' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1A1.7 1.7 0 0 0 9 19.4a1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>
            Settings
          </a>
          <span class="spacer"></span>
          <span class="who">Signed in as <?= Http::e((string) ($user['display_name'] !== '' ? $user['display_name'] : $user['username'])) ?></span>
          <a href="<?= Http::e(Http::url('logout.php')) ?>">Sign out</a>
        </nav>
        <?php endif;
    }

    /** @param array<int,string> $scripts */
    public static function end(array $scripts = [], ?string $footerHtml = null): void
    {
        if ($footerHtml !== null) {
            echo '<footer class="app-footer">' . $footerHtml . '</footer>' . "\n";
        }
        echo "</div>\n"; // .wrap
        foreach ($scripts as $script) {
            echo '<script src="' . Http::e(Http::url('assets/' . $script)) . '?v=' . SW_VERSION . '"></script>' . "\n";
        }
        echo "</body>\n</html>\n";
    }

    /** Inline a JSON blob for the page scripts to read. */
    public static function bootData(string $variable, array $data): void
    {
        // Escaping "<" keeps a stray "</script>" inside the data from ending
        // the block early; it stays valid JSON either way.
        echo '<script id="' . Http::e($variable) . '" type="application/json">'
            . (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP)
            . "</script>\n";
    }

    public static function footerText(): string
    {
        $bits = [];
        $bits[] = 'Map tiles © OpenStreetMap contributors, © CARTO.';
        if (Settings::getString('radar_overlay') === 'rainviewer') {
            $bits[] = 'Radar © RainViewer.';
        } elseif (Settings::getString('radar_overlay') === 'nexrad') {
            $bits[] = 'NEXRAD mosaic © Iowa Environmental Mesonet.';
        }
        if (Settings::getString('provider') === 'blitzortung') {
            $bits[] = 'Strike data from the Blitzortung.org volunteer network.';
        }
        $bits[] = 'Storm Watch v' . SW_VERSION . '.';
        return implode(' ', array_map(static fn(string $b): string => Http::e($b), $bits))
            . '<br>Lightning detection is an aid, not a guarantee. Follow your venue\'s severe weather policy.';
    }
}

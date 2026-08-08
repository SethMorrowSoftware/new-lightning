<?php
/**
 * Router that makes PHP's built-in server behave like a subfolder install.
 *
 * The built-in server ignores .htaccess, so this reproduces what Apache does
 * when the app is uploaded to public_html/<mount>/ : requests to /<mount>/x
 * are served from public/x, and SCRIPT_NAME becomes /<mount>/public/x — the
 * exact value a real Apache reports, and the one Http::basePath() has to
 * unpick to find the mount point.
 *
 *   SW_MOUNT=/stormwatch php -S 127.0.0.1:8400 -t <repo-root> tests/router.php
 *
 * Set SW_MOUNT to an empty string to serve at the root instead.
 */
declare(strict_types=1);

$mount = rtrim((string) getenv('SW_MOUNT'), '/');
$root = dirname(__DIR__);
$uri = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
$query = (string) ($_SERVER['QUERY_STRING'] ?? '');
$suffix = $query !== '' ? '?' . $query : '';

if ($mount !== '') {
    // Anything outside the mount belongs to the rest of the main domain.
    if ($uri !== $mount && strpos($uri, $mount . '/') !== 0) {
        http_response_code(404);
        echo 'Outside the Storm Watch mount.';
        return true;
    }
    $rel = substr($uri, strlen($mount));
    if ($rel === '') {
        header('Location: ' . $mount . '/' . $suffix, true, 301);
        return true;
    }
} else {
    $rel = $uri;
}

// Mirrors the deny rule in the root .htaccess.
if (preg_match('#^/(?:config|data|src|bin|tests)(?:/|$)#', $rel)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

// Mirrors the canonical redirect that keeps /public/ out of the address bar.
if (preg_match('#^/public(/.*)?$#', $rel, $m)) {
    header('Location: ' . $mount . (($m[1] ?? '') !== '' ? $m[1] : '/') . $suffix, true, 301);
    return true;
}

if ($rel === '/') {
    $rel = '/index.php';
}

$file = $root . '/public' . $rel;
$real = realpath($file);
if ($real === false || strpos($real, realpath($root . '/public') ?: '') !== 0 || !is_file($real)) {
    http_response_code(404);
    echo 'Not found';
    return true;
}

if (substr($real, -4) === '.php') {
    // The values Apache reports for this request under the rewrite.
    $_SERVER['SCRIPT_NAME'] = $mount . '/public' . $rel;
    $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
    $_SERVER['SCRIPT_FILENAME'] = $real;
    chdir(dirname($real));
    require $real;
    return true;
}

$types = [
    'css' => 'text/css',
    'js' => 'application/javascript',
    'json' => 'application/json',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'svg' => 'image/svg+xml',
    'ico' => 'image/x-icon',
    'woff2' => 'font/woff2',
];
$ext = strtolower((string) pathinfo($real, PATHINFO_EXTENSION));
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
readfile($real);
return true;

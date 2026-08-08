<?php
/**
 * Storm Watch — application bootstrap.
 *
 * Every entry point (web page, API endpoint, cron script) includes this file
 * first. It defines the path constants, registers the class autoloader and
 * loads config/config.php when the app has been installed.
 */
declare(strict_types=1);

if (defined('SW_ROOT')) {
    return;
}

define('SW_ROOT', dirname(__DIR__));
define('SW_SRC', SW_ROOT . '/src');
define('SW_CONFIG_FILE', SW_ROOT . '/config/config.php');
define('SW_DATA', SW_ROOT . '/data');
define('SW_VERSION', '1.0.0');

if (PHP_VERSION_ID < 80000) {
    http_response_code(500);
    exit('Storm Watch requires PHP 8.0 or newer. This server is running PHP ' . PHP_VERSION . '.');
}

mb_internal_encoding('UTF-8');

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'StormWatch\\', 11) !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, 11));
    $file = SW_SRC . '/' . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

// Errors are logged, never printed — a stack trace on a public page is a leak.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
if (is_dir(SW_DATA) && is_writable(SW_DATA)) {
    ini_set('error_log', SW_DATA . '/php-error.log');
}

require_once SW_SRC . '/Config.php';
StormWatch\Config::load();

date_default_timezone_set('UTC');

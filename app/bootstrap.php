<?php

declare(strict_types=1);

/**
 * Application bootstrap: autoloading, configuration and runtime setup.
 */

require_once __DIR__ . '/Helpers/helpers.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use App\Config\Config;
use App\Core\Session;

$root = dirname(__DIR__);
Config::load($root . '/.env');

date_default_timezone_set((string) Config::get('APP_TIMEZONE', 'Europe/Berlin'));
mb_internal_encoding('UTF-8');

error_reporting(E_ALL);
ini_set('display_errors', Config::isDebug() ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', $root . '/storage/logs/php-error.log');

if (PHP_SAPI === 'cli') {
    ini_set('html_errors', '0');
} else {
    Session::start();
}

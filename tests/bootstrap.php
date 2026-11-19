<?php
/**
 * Loads the SDK the way Composer would: vendor/autoload.php when `composer dump-autoload` has
 * run (CI does, which also checks the PSR-4 and `files` entries of composer.json), else a PSR-4
 * loader for src/ plus src/functions.php.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('UTC');

// Any notice, warning or deprecation is a failure: the SDK must run clean on PHP 7.4 to 8.5.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) {
    require $root . '/vendor/autoload.php';
    define('WOLVY_TEST_AUTOLOADER', 'composer (vendor/autoload.php)');
} else {
    spl_autoload_register(static function (string $class) use ($root): void {
        if (strpos($class, 'Wolvy\\') !== 0) {
            return;
        }
        $file = $root . '/src/' . str_replace('\\', '/', substr($class, 6)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
    require $root . '/src/functions.php';
    define('WOLVY_TEST_AUTOLOADER', 'tests/bootstrap.php (PSR-4 on src/)');
}

require __DIR__ . '/support/assert.php';
require __DIR__ . '/support/FakeTransport.php';
require __DIR__ . '/support/RecordingTransport.php';

<?php
declare(strict_types=1);

if (defined('AUBASE_CONFIG_LOADED')) {
    return;
}
define('AUBASE_CONFIG_LOADED', true);

$envFile = dirname(__DIR__) . '/.env';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v, " \t\"'");
        if ($k !== '' && getenv($k) === false) {
            putenv("$k=$v");
        }
    }
}

/**
 * Reference "now" for the bundled 2001-era eBay JSON dataset (auction open/closed).
 * Override with env if you migrate to real-time auctions.
 */
define('AUBASE_DEMO_NOW', getenv('AUBASE_DEMO_NOW') ?: '2001-12-14');

define('AUBASE_DB_HOST', getenv('AUBASE_DB_HOST') ?: '127.0.0.1');
define('AUBASE_DB_PORT', (int) (getenv('AUBASE_DB_PORT') ?: 3306));
define('AUBASE_DB_NAME', getenv('AUBASE_DB_NAME') ?: 'aubase');
define('AUBASE_DB_USER', getenv('AUBASE_DB_USER') ?: 'root');
define('AUBASE_DB_PASS', getenv('AUBASE_DB_PASS') ?: '12345678');

/** Empty string = import.php only runnable from CLI. Set to allow ?key=... in browser. */
define('AUBASE_IMPORT_KEY', getenv('AUBASE_IMPORT_KEY') ?: '');

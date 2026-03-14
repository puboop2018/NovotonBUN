<?php
declare(strict_types=1);
/**
 * Sphinx Holidays Cron Job Handler
 *
 * Usage:
 *   php cron.php access_key=YOUR_KEY mode=destinations
 *   curl "http://domain.com/app/addons/sphinx_holidays/cron.php?access_key=KEY&mode=destinations"
 *
 * Available modes:
 *   destinations - Sync destinations (countries, regions, cities) from Sphinx API
 */

if (!defined('AREA')) {
    define('AREA', 'A');
    define('CONSOLE', true);
}

require dirname(__FILE__) . '/../../../init.php';

use Tygh\Addons\SphinxHolidays\Cron\CronDispatcher;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;

// Authenticate
$storedKey = ConfigProvider::getCronAccessKey();

$providedKey = $_GET['access_key'] ?? '';
$mode = $_GET['mode'] ?? '';

// Parse CLI arguments (key=value format)
if (isset($argv) && is_array($argv)) {
    foreach ($argv as $i => $arg) {
        if ($i === 0) continue;
        if (strpos($arg, 'access_key=') === 0) {
            $providedKey = substr($arg, strlen('access_key='));
        } elseif (strpos($arg, 'mode=') === 0) {
            $mode = substr($arg, strlen('mode='));
        }
    }
}

if (empty($storedKey)) {
    exit("ERROR: Cron Access Key not set in Sphinx Holidays addon settings.\n");
}
if (empty($providedKey) || !hash_equals($storedKey, $providedKey)) {
    exit("ERROR: Invalid or missing access key.\n");
}
if (empty($mode)) {
    $mode = 'destinations';
}

// Sanitize mode
$mode = preg_replace('/[^a-z0-9_]/', '', strtolower($mode));

echo "[" . date('Y-m-d H:i:s') . "] Sphinx Cron Started - Mode: {$mode}\n";

fn_log_event('general', 'runtime', [
    'message' => "Sphinx cron job started (mode: {$mode})",
]);

try {
    $dispatcher = new CronDispatcher();

    if (!$dispatcher->hasMode($mode)) {
        echo "Unknown mode: {$mode}\n\n";
        echo "Available modes:\n";
        foreach (CronDispatcher::getAvailableModes() as $m => $desc) {
            echo "  {$m} - {$desc}\n";
        }
        exit(1);
    }

    // Parse CLI params
    $params = [];
    if (isset($argv)) {
        foreach ($argv as $arg) {
            if (strpos($arg, '=') !== false && strpos($arg, 'access_key') !== 0 && strpos($arg, 'mode') !== 0) {
                [$k, $v] = explode('=', $arg, 2);
                $params[$k] = $v;
            }
        }
    }

    $result = $dispatcher->dispatch($mode, $params);

    echo "\n[" . date('Y-m-d H:i:s') . "] Cron job completed.\n";

} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";

    fn_log_event('general', 'runtime', [
        'message' => 'Sphinx cron error: ' . $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);

    exit(1);
}

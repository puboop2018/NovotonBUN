<?php
/**
 * Novoton Cron Job Handler (Legacy Entry Point)
 *
 * Usage: php cron.php access_key=YOUR_KEY mode=resinfo
 * Or:    http://domain.com/app/addons/novoton_holidays/cron.php?access_key=KEY&mode=resinfo
 *
 * Recommended: use index.php?dispatch=novoton_cron.run&access_key=KEY&mode=... instead.
 */

if (!defined('AREA')) {
    define('AREA', 'A');
    define('CONSOLE', true);
}

require dirname(__FILE__) . '/../../../init.php';

use Tygh\Registry;
use Tygh\Addons\NovotonHolidays\Cron\CronDispatcher;
use Tygh\Addons\NovotonHolidays\Services\ConfigService;

// Authenticate
$storedKey = ConfigService::getCronAccessKey();

$providedKey = $_GET['access_key'] ?? '';
if (empty($providedKey) && isset($argv[1])) {
    $arg = $argv[1];
    if (strpos($arg, 'access_key=') === 0) {
        $providedKey = str_replace('access_key=', '', $arg);
    }
}

if (empty($storedKey)) {
    die("ERROR: Cron Access Key not set in addon settings.\n");
}
if (empty($providedKey) || $providedKey !== $storedKey) {
    die("ERROR: Invalid or missing API key.\n");
}

// Determine mode
$mode = $_GET['mode'] ?? '';
if (empty($mode) && isset($argv[2])) {
    $mode = str_replace('mode=', '', $argv[2]);
}
if (empty($mode)) {
    $mode = 'full';
}

echo "[" . date('Y-m-d H:i:s') . "] Novoton Cron Started - Mode: {$mode}\n";

fn_log_event('novoton_holidays', 'cron_start', [
    'timestamp' => time(),
    'mode' => $mode,
    'message' => 'Cron job started'
]);

// Load API
$src_dir = Registry::get('config.dir.addons') . 'novoton_holidays/src/';
if (file_exists($src_dir . 'NovotonApi.php')) {
    require_once($src_dir . 'NovotonApi.php');
}

try {
    $api = new \Tygh\Addons\NovotonHolidays\NovotonApi();
    $dispatcher = new CronDispatcher($api, null);

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
                list($k, $v) = explode('=', $arg, 2);
                $params[$k] = $v;
            }
        }
    }

    $result = $dispatcher->dispatch($mode, $params);

    echo "\n[" . date('Y-m-d H:i:s') . "] Cron job completed.\n";

} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";

    fn_log_event('novoton_holidays', 'cron_error', [
        'timestamp' => time(),
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);

    $companyData = fn_get_company_data(0);
    $adminEmail = $companyData['company_users_department'] ?? '';
    if (!empty($adminEmail)) {
        fn_send_mail($adminEmail, $adminEmail,
            'Novoton Cron Error - ' . date('Y-m-d H:i:s'),
            "Error: " . $e->getMessage() . "\nTime: " . date('Y-m-d H:i:s')
        );
    }

    exit(1);
}

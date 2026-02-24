<?php
declare(strict_types=1);
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

use Tygh\Addons\NovotonHolidays\Cron\CronDispatcher;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;

// Authenticate
$storedKey = ConfigProvider::getCronAccessKey();

$providedKey = $_GET['access_key'] ?? '';
$mode = $_GET['mode'] ?? '';

// Parse CLI arguments (key=value format, position-independent)
if (isset($argv) && is_array($argv)) {
    foreach ($argv as $i => $arg) {
        if ($i === 0) continue; // skip script name
        if (strpos($arg, 'access_key=') === 0) {
            $providedKey = substr($arg, strlen('access_key='));
        } elseif (strpos($arg, 'mode=') === 0) {
            $mode = substr($arg, strlen('mode='));
        }
    }
}

if (empty($storedKey)) {
    exit("ERROR: Cron Access Key not set in addon settings.\n");
}
if (empty($providedKey) || !hash_equals($storedKey, $providedKey)) {
    exit("ERROR: Invalid or missing API key.\n");
}
if (empty($mode)) {
    $mode = 'full';
}

// Sanitize mode to prevent XSS when echoed
$mode = preg_replace('/[^a-z0-9_]/', '', strtolower($mode)) ?? 'full';

echo "[" . date('Y-m-d H:i:s') . "] Novoton Cron Started - Mode: {$mode}\n";

fn_log_event('novoton_holidays', 'cron_start', [
    'timestamp' => time(),
    'mode' => $mode,
    'message' => 'Cron job started'
]);

// Classes are auto-loaded via PSR-4 autoloader registered in init.php

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
                [$k, $v] = explode('=', $arg, 2);
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
    if (!empty($adminEmail) && function_exists('fn_novoton_holidays_send_import_report_email')) {
        fn_novoton_holidays_send_import_report_email([], 'cron_error', [
            'error' => $e->getMessage(),
            'time' => date('Y-m-d H:i:s'),
        ], 'Cron error notification');
    }

    exit(1);
}

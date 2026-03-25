<?php
declare(strict_types=1);
/**
 * Travel Core - Cron Entry Point
 *
 * Centralized cron for shared travel operations (exchange rates).
 * Replaces per-addon exchange rate crons to avoid duplicate BNR requests.
 *
 * Usage (CLI):  php cron.php access_key=YOUR_KEY mode=exchange_rates
 * Usage (HTTP): http://domain.com/app/addons/travel_core/cron.php?access_key=KEY&mode=exchange_rates
 *
 * @package TravelCore
 * @since   1.1.0
 */

if (!defined('AREA')) {
    define('AREA', 'A');
    define('CONSOLE', true);
}

require dirname(__FILE__) . '/../../../init.php';

use Tygh\Registry;

// --- Parse arguments (CLI key=value or HTTP GET) ---
$providedKey = $_GET['access_key'] ?? '';
$mode = $_GET['mode'] ?? '';

if (isset($argv) && is_array($argv)) {
    foreach ($argv as $i => $arg) {
        if ($i === 0) continue;
        if (str_starts_with($arg, 'access_key=')) {
            $providedKey = substr($arg, strlen('access_key='));
        } elseif (str_starts_with($arg, 'mode=')) {
            $mode = substr($arg, strlen('mode='));
        }
    }
}

// --- Authenticate ---
$storedKey = Registry::get('addons.travel_core.cron_access_key');

if (empty($storedKey)) {
    exit("ERROR: Cron access key not set in Travel Core addon settings.\n");
}
if (empty($providedKey) || !hash_equals($storedKey, $providedKey)) {
    exit("ERROR: Invalid or missing access key.\n");
}

// --- Validate mode ---
$mode = preg_replace('/[^a-z0-9_]/', '', strtolower($mode));
$supported_modes = ['exchange_rates'];

if (empty($mode) || !in_array($mode, $supported_modes, true)) {
    echo "Travel Core Cron - Available modes:\n";
    echo "  exchange_rates - Update BNR exchange rates (shared across all addons)\n";
    exit(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Travel Core Cron - Mode: {$mode}\n\n";

// --- Execute ---
if ($mode === 'exchange_rates') {
    $commission = (float) Registry::get('addons.travel_core.currency_risk_commission');

    $result = fn_travel_core_update_exchange_rates($commission, true);

    if (!is_array($result)) {
        $result = ['success' => false, 'message' => 'No response from exchange rate service'];
    }

    echo fn_travel_core_format_exchange_rate_output($result) . "\n";

    $exitCode = ($result['success'] ?? false) ? 0 : 1;
    echo "\n[" . date('Y-m-d H:i:s') . "] Cron job completed.\n";
    exit($exitCode);
}

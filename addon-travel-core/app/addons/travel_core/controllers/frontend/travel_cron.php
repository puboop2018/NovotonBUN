<?php
declare(strict_types=1);
/**
 * Travel Core - Cron Controller (frontend dispatch)
 *
 * Provides URL: index.php?dispatch=travel_cron.run&access_key=KEY&mode=exchange_rates
 *
 * This is the CS-Cart dispatch controller. For CLI usage, see cron.php.
 *
 * @package TravelCore
 * @since   1.1.0
 */

use Tygh\Registry;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

// --- Authenticate ---
$storedKey = Registry::get('addons.travel_core.cron_access_key');
$providedKey = isset($_REQUEST['access_key']) ? (string)$_REQUEST['access_key'] : '';

if (empty($storedKey)) {
    die("ERROR: Cron access key not set in Travel Core addon settings.\n");
}
if (empty($providedKey) || !hash_equals($storedKey, $providedKey)) {
    die("ERROR: Invalid or missing access key.\n");
}

if ($mode === 'run') {
    $cron_mode = isset($_REQUEST['mode']) ? preg_replace('/[^a-z0-9_]/', '', strtolower((string)$_REQUEST['mode'])) : '';

    // Fix: 'mode' param was consumed by CS-Cart dispatch, use 'cron_mode' as fallback
    if (empty($cron_mode) || $cron_mode === 'run') {
        $cron_mode = isset($_REQUEST['cron_mode']) ? preg_replace('/[^a-z0-9_]/', '', strtolower((string)$_REQUEST['cron_mode'])) : '';
    }

    $supported_modes = ['exchange_rates'];

    if (empty($cron_mode) || !in_array($cron_mode, $supported_modes, true)) {
        header('Content-Type: text/plain');
        echo "Travel Core Cron - Available modes:\n";
        echo "  exchange_rates - Update BNR exchange rates\n\n";
        echo "Usage: dispatch=travel_cron.run&access_key=KEY&cron_mode=exchange_rates\n";
        exit;
    }

    header('Content-Type: text/plain');
    echo "[" . date('Y-m-d H:i:s') . "] Travel Core Cron - Mode: {$cron_mode}\n\n";

    if ($cron_mode === 'exchange_rates') {
        $commission = (float) Registry::get('addons.travel_core.currency_risk_commission');

        $result = fn_travel_core_update_exchange_rates($commission, true);

        if (!is_array($result)) {
            $result = ['success' => false, 'message' => 'No response from exchange rate service'];
        }

        echo "Status: " . (($result['success'] ?? false) ? 'SUCCESS' : 'FAILED') . "\n";
        echo "Message: " . ($result['message'] ?? 'Unknown') . "\n";

        if (!empty($result['publishing_date'])) {
            echo "Publishing Date: " . $result['publishing_date'] . "\n";
        }
        echo "\n";

        if (!empty($result['bnr_rates'])) {
            echo "BNR Rates (RON-based):\n";
            foreach ($result['bnr_rates'] as $currency => $rate) {
                echo "  {$currency}: {$rate}\n";
            }
            echo "\n";
        }

        if (!empty($result['coefficients'])) {
            echo "Calculated Coefficients (EUR-based, commission: " . ($result['commission'] ?? 0) . "%):\n";
            foreach ($result['coefficients'] as $currency => $coefficient) {
                echo "  {$currency}: {$coefficient}\n";
            }
            echo "\n";
        }

        if (!empty($result['updates'])) {
            echo "Update Results:\n";
            foreach ($result['updates'] as $currency => $update) {
                if ($update['success']) {
                    echo "  {$currency}: " . ($update['old_rate'] ?? '-') . " -> " . ($update['new_rate'] ?? '-') . "\n";
                } else {
                    echo "  {$currency}: FAILED - " . ($update['error'] ?? 'Unknown') . "\n";
                }
            }
        }

        echo "\n[" . date('Y-m-d H:i:s') . "] Cron job completed.\n";
    }

    exit;
}

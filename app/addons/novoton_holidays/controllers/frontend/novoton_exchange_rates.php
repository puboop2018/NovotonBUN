<?php
/**
 * Novoton Holidays - Exchange Rates Frontend Controller
 *
 * Frontend controller for cron-based exchange rate updates.
 * Accessible via: index.php?dispatch=novoton_exchange_rates.cron&cron_password=XXX
 *
 * @package NovotonHolidays
 * @since 3.0.0
 */

use Tygh\Registry;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

/**
 * Cron mode - called via system cron
 * URL: index.php?dispatch=novoton_exchange_rates.cron&cron_password=XXX
 */
if ($mode == 'cron') {
    // Verify cron password
    $cron_password = Registry::get('addons.novoton_holidays.cron_access_key');

    if (empty($cron_password)) {
        header('Content-Type: text/plain');
        echo "Error: Cron access key not configured in addon settings.\n";
        exit;
    }

    if (empty($_REQUEST['cron_password']) || $_REQUEST['cron_password'] !== $cron_password) {
        header('Content-Type: text/plain');
        http_response_code(403);
        echo "Error: Invalid cron password.\n";
        exit;
    }

    // Run exchange rate update
    $result = fn_novoton_update_exchange_rates(true);

    // Output result for cron logging
    header('Content-Type: text/plain');
    echo "Novoton Exchange Rates Update\n";
    echo "============================\n";
    echo "Timestamp: " . $result['timestamp'] . "\n";
    echo "Status: " . ($result['success'] ? 'SUCCESS' : 'FAILED') . "\n";
    echo "Message: " . $result['message'] . "\n";

    if (!empty($result['bnr_rates'])) {
        echo "\nBNR Rates (RON-based):\n";
        foreach ($result['bnr_rates'] as $currency => $rate) {
            echo "  $currency: $rate\n";
        }
    }

    if (!empty($result['coefficients'])) {
        echo "\nCalculated Coefficients (EUR-based, commission: " . ($result['commission'] ?? 0) . "%):\n";
        foreach ($result['coefficients'] as $currency => $coefficient) {
            echo "  $currency: $coefficient\n";
        }
    }

    if (!empty($result['updates'])) {
        echo "\nUpdate Results:\n";
        foreach ($result['updates'] as $currency => $update) {
            if ($update['success']) {
                echo "  $currency: " . ($update['old_rate'] ?? '-') . " -> " . ($update['new_rate'] ?? '-') . "\n";
            } else {
                echo "  $currency: FAILED - " . ($update['error'] ?? 'Unknown error') . "\n";
            }
        }
    }

    exit;
}

// Any other mode - redirect to homepage
return [CONTROLLER_STATUS_REDIRECT, 'index.index'];

<?php
/**
 * Novoton Holidays - Exchange Rates Controller
 *
 * Admin controller for managing BNR exchange rate updates.
 * Supports manual updates and cron job execution.
 *
 * @package NovotonHolidays
 * @since 3.0.0
 */

use Tygh\Registry;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

// Check admin permissions
if (fn_allowed_for('MULTIVENDOR') || (defined('RESTRICTED_ADMIN') && RESTRICTED_ADMIN)) {
    return [CONTROLLER_STATUS_DENIED];
}

/**
 * Cron mode - called via system cron
 * URL: admin.php?dispatch=novoton_exchange_rates.cron&cron_password=XXX
 */
if ($mode == 'cron') {
    // Verify cron password
    $cron_password = Registry::get('addons.novoton_holidays.cron_access_key');

    if (empty($cron_password)) {
        echo "Error: Cron access key not configured in addon settings.\n";
        exit;
    }

    if (empty($_REQUEST['cron_password']) || $_REQUEST['cron_password'] !== $cron_password) {
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

/**
 * Manage mode - admin panel view
 */
if ($mode == 'manage') {
    // Get current exchange rate info
    $exchange_info = fn_novoton_get_exchange_rate_info();

    Tygh::$app['view']->assign('exchange_info', $exchange_info);

    $cron_password = Registry::get('addons.novoton_holidays.cron_access_key');
    Tygh::$app['view']->assign('cron_password', $cron_password);

    // Build cron URLs for display (frontend and admin)
    $cron_url_frontend = fn_url('novoton_exchange_rates.cron', 'C');
    $cron_url_frontend .= '&cron_password=' . $cron_password;
    Tygh::$app['view']->assign('cron_url_frontend', $cron_url_frontend);

    $cron_url_admin = fn_url('novoton_exchange_rates.cron', 'A');
    $cron_url_admin .= '&cron_password=' . $cron_password;
    Tygh::$app['view']->assign('cron_url_admin', $cron_url_admin);
}

/**
 * Update mode - manual trigger from admin panel
 */
if ($mode == 'update') {
    // Run exchange rate update
    $result = fn_novoton_update_exchange_rates(true);

    if ($result['success']) {
        fn_set_notification('N', __('notice'), __('novoton_holidays.exchange_rates_updated'));
    } else {
        fn_set_notification('E', __('error'), $result['message']);
    }

    return [CONTROLLER_STATUS_REDIRECT, 'novoton_exchange_rates.manage'];
}

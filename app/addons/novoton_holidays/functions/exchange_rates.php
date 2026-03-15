<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Exchange Rates Functions (Thin Wrapper)
 *
 * Delegates to travel_core's shared exchange rate functions.
 * Provides backwards-compatible function names and novoton-specific
 * sync log integration.
 *
 * @package NovotonHolidays
 * @since 3.5.0
 */

use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

/**
 * Update exchange rates from BNR using novoton's commission setting.
 *
 * Delegates to fn_travel_core_update_exchange_rates() and logs to novoton sync log.
 *
 * @param bool $return_details If true, returns detailed results
 * @return array|bool Results array or bool success
 */
function fn_novoton_holidays_update_exchange_rates($return_details = false): array|bool
{
    $commission = ConfigProvider::getCurrencyRiskCommission();

    $result = fn_travel_core_update_exchange_rates($commission, true);

    // Log to novoton-specific sync log
    if (is_array($result) && $result['success']) {
        db_query(
            "INSERT INTO ?:novoton_sync_log SET sync_date = ?s, sync_type = 'exchange_rates', status = 'completed', "
            . "notes = ?s",
            $result['timestamp'],
            json_encode([
                'coefficients' => $result['coefficients'],
                'commission' => $commission,
                'publishing_date' => $result['publishing_date'],
            ])
        );
    }

    return $return_details ? $result : ($result['success'] ?? false);
}

<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Exchange Rate Hook Handler
 *
 * Logs exchange rate updates from travel_core to the novoton_sync_log table.
 *
 * @package NovotonHolidays
 * @since   3.6.0
 */

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

/**
 * Hook handler: travel_core_exchange_rates_updated
 *
 * Logs the exchange rate update result to novoton's sync log table
 * so the admin panel can display "last updated" timestamps.
 *
 * @param array $result Full result array from fn_travel_core_update_exchange_rates()
 */
function fn_novoton_holidays_travel_core_exchange_rates_updated(array &$result): void
{
    if (empty($result['success'])) {
        return;
    }

    db_query(
        "INSERT INTO ?:novoton_sync_log SET sync_date = ?s, sync_type = 'exchange_rates', status = 'completed', "
        . "notes = ?s",
        $result['timestamp'],
        json_encode([
            'coefficients' => $result['coefficients'],
            'commission' => $result['commission'],
            'publishing_date' => $result['publishing_date'],
            'source' => 'travel_core_cron',
        ])
    );
}

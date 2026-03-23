<?php
declare(strict_types=1);
/**
 * Travel Core - Tools & Cron Backend Controller
 *
 * Provides admin interface for managing cron jobs and running
 * maintenance tasks like BNR exchange rate updates.
 *
 * @package TravelCore
 * @since   1.1.0
 */

use Tygh\Registry;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($mode === 'run_exchange_rates') {
        $commission = (float) Registry::get('addons.travel_core.currency_risk_commission');

        $result = fn_travel_core_update_exchange_rates($commission, true);

        if (!is_array($result)) {
            $result = ['success' => false, 'message' => 'No response from exchange rate service'];
        }

        if (!empty($result['success'])) {
            $parts = [];
            if (!empty($result['publishing_date'])) {
                $parts[] = 'BNR date: ' . $result['publishing_date'];
            }
            if (!empty($result['coefficients'])) {
                foreach ($result['coefficients'] as $cur => $coeff) {
                    $parts[] = "{$cur}: {$coeff}";
                }
            }
            $detail = !empty($parts) ? ' (' . implode(', ', $parts) . ')' : '';
            fn_set_notification('N', __('notice'), __('travel_core.exchange_rates_updated') . $detail);
        } else {
            fn_set_notification('E', __('error'), __('travel_core.exchange_rates_failed') . ': ' . ($result['message'] ?? 'Unknown error'));
        }

        return [CONTROLLER_STATUS_REDIRECT, 'travel_tools.manage'];
    }
}

if ($mode === 'manage') {
    $cron_key = Registry::get('addons.travel_core.cron_access_key');
    $base_url = Registry::get('config.http_location') . '/';

    $cron_jobs = [];

    $cron_jobs['exchange_rates'] = [
        'name'        => __('travel_core.cron_exchange_rates'),
        'description' => __('travel_core.cron_exchange_rates_desc'),
        'url'         => !empty($cron_key)
            ? $base_url . "index.php?dispatch=travel_cron.run&access_key={$cron_key}&cron_mode=exchange_rates"
            : '',
        'schedule'    => __('travel_core.cron_schedule_daily'),
        'cpanel'      => '5 13 * * *',
        'run_action'  => 'run_exchange_rates',
    ];

    Tygh::$app['view']->assign('cron_jobs', $cron_jobs);
    Tygh::$app['view']->assign('cron_key', $cron_key);
    Tygh::$app['view']->assign('base_url', $base_url);
}

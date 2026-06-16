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
use Tygh\Tygh;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($mode === 'run_exchange_rates') {
        $commission = TypeCoerce::toFloat(Registry::get('addons.travel_core.currency_risk_commission'));

        $result = fn_travel_core_update_exchange_rates($commission, true);

        if (!is_array($result)) {
            $result = ['success' => false, 'message' => 'No response from exchange rate service'];
        }

        if (!empty($result['success'])) {
            $parts = [];
            if (!empty($result['publishing_date'])) {
                $parts[] = 'BNR date: ' . TypeCoerce::toString($result['publishing_date']);
            }
            if (!empty($result['coefficients'])) {
                foreach (TypeCoerce::toStringMap($result['coefficients']) as $cur => $coeff) {
                    $coeffStr = TypeCoerce::toString($coeff);
                    $parts[] = "{$cur}: {$coeffStr}";
                }
            }
            $detail = !empty($parts) ? ' (' . implode(', ', $parts) . ')' : '';
            fn_set_notification('N', __('notice'), TypeCoerce::toString(__('travel_core.exchange_rates_updated')) . $detail);
        } else {
            fn_set_notification('E', __('error'), TypeCoerce::toString(__('travel_core.exchange_rates_failed')) . ': ' . TypeCoerce::toString($result['message'] ?? 'Unknown error'));
        }

        return [CONTROLLER_STATUS_REDIRECT, 'travel_tools.manage'];
    }
}

if ($mode === 'manage') {
    $cron_key = TypeCoerce::toString(Registry::get('addons.travel_core.cron_access_key'));
    $base_url = TypeCoerce::toString(Registry::get('config.http_location')) . '/';

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

    $view = Tygh::$app['view'];
    if ($view instanceof \Smarty) {
        $view->assign('cron_jobs', $cron_jobs);
        $view->assign('cron_key', $cron_key);
        $view->assign('base_url', $base_url);
    }
}

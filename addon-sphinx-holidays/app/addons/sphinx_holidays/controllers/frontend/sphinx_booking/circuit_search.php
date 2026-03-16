<?php
declare(strict_types=1);
/**
 * Sphinx Booking Controller — Circuit Search Mode
 *
 * Fetches circuit rates from the Sphinx API with optional filters.
 * Unlike hotel search (polling), circuits use a simple rates endpoint
 * with server-side pagination.
 *
 * @package SphinxHolidays
 * @since   1.1.0
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Tygh;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\TravelCore\Services\CommissionCalculator;

try {
    $api = Container::getApi();
    $view = Tygh::$app['view'];

    $destination_id = (int)($_REQUEST['destination_id'] ?? 0);
    $transport_type = trim($_REQUEST['transport_type'] ?? '');
    $month = trim($_REQUEST['month'] ?? '');
    $page = max(1, (int)($_REQUEST['page'] ?? 1));

    $searchParams = [
        'pagination' => ['page' => $page, 'per_page' => 20],
    ];

    if ($destination_id > 0) {
        $searchParams['destinatons'] = [$destination_id]; // API typo is intentional — matches spec
    }
    if (!empty($transport_type)) {
        $searchParams['transport_types'] = [$transport_type];
    }
    if (!empty($month)) {
        $searchParams['months'] = [$month];
    }

    $response = $api->getCircuitRates($searchParams);

    $allResults = $response['data'] ?? [];
    $meta = $response['meta'] ?? [];

    // Apply commission
    $commission = ConfigProvider::getCommission();
    $roundPrices = ConfigProvider::shouldRoundPrices();

    if ($commission > 0 && !empty($allResults)) {
        $calculator = new CommissionCalculator($commission, $roundPrices);
        foreach ($allResults as &$result) {
            if (isset($result['pricing']['selling_price'])) {
                $result['pricing']['original_price'] = $result['pricing']['selling_price'];
                $result['pricing']['selling_price'] = $calculator->apply((float)$result['pricing']['selling_price']);
            }
        }
        unset($result);
    }

    $view->assign('sphinx_circuit_results', $allResults);
    $view->assign('sphinx_circuit_meta', $meta);
    $view->assign('sphinx_circuit_params', [
        'destination_id' => $destination_id,
        'transport_type' => $transport_type,
        'month' => $month,
        'page' => $page,
        'currency' => ConfigProvider::getDefaultCurrency(),
    ]);

} catch (\Throwable $e) {
    fn_log_event('general', 'runtime', [
        'message' => 'Sphinx Circuit Search Error: ' . $e->getMessage(),
        'file'    => $e->getFile() . ':' . $e->getLine(),
    ]);
    fn_set_notification('E', __('error'),
        __('sphinx_holidays.search_error', ['[default]' => 'An error occurred while searching. Please try again later.']));
    Tygh::$app['view']->assign('sphinx_circuit_results', []);
}

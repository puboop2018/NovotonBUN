<?php
declare(strict_types=1);
/**
 * Sphinx Booking Controller — Experience Search Mode
 *
 * Fetches experience rates from the Sphinx API with optional filters.
 * Experiences use a simple paginated rates endpoint (no polling).
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
    $month = trim($_REQUEST['month'] ?? '');
    $from_date = trim($_REQUEST['from'] ?? '');
    $to_date = trim($_REQUEST['to'] ?? '');
    $page = max(1, (int)($_REQUEST['page'] ?? 1));

    $searchParams = [
        'pagination' => ['page' => $page, 'per_page' => 20],
    ];

    if ($destination_id > 0) {
        $searchParams['destinatons'] = [$destination_id]; // API typo is intentional — matches spec
    }
    if (!empty($month)) {
        $searchParams['months'] = [$month];
    }
    if (!empty($from_date)) {
        $searchParams['from'] = $from_date;
    }
    if (!empty($to_date)) {
        $searchParams['to'] = $to_date;
    }

    $response = $api->getExperienceRates($searchParams);

    $allResults = $response['data'] ?? [];
    $meta = $response['meta'] ?? [];

    // Apply commission
    $commission = ConfigProvider::getCommission();
    $roundPrices = ConfigProvider::shouldRoundPrices() ? 'Y' : 'N';

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

    $view->assign('sphinx_experience_results', $allResults);
    $view->assign('sphinx_experience_meta', $meta);
    $view->assign('sphinx_experience_params', [
        'destination_id' => $destination_id,
        'month' => $month,
        'from' => $from_date,
        'to' => $to_date,
        'page' => $page,
        'currency' => ConfigProvider::getDefaultCurrency(),
    ]);

} catch (\Throwable $e) {
    fn_log_event('general', 'runtime', [
        'message' => 'Sphinx Experience Search Error: ' . $e->getMessage(),
        'file'    => $e->getFile() . ':' . $e->getLine(),
    ]);
    fn_set_notification('E', __('error'),
        __('sphinx_holidays.search_error', ['[default]' => 'An error occurred while searching. Please try again later.']));
    Tygh::$app['view']->assign('sphinx_experience_results', []);
}

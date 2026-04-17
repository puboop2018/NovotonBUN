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
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;

try {
    $api = Container::getApi();
    $view = Tygh::$app['view'];

    $destination_id = RequestCoerce::int($_REQUEST, 'destination_id');
    $month = RequestCoerce::string($_REQUEST, 'month');
    $from_date = RequestCoerce::string($_REQUEST, 'from');
    $to_date = RequestCoerce::string($_REQUEST, 'to');
    $page = max(1, RequestCoerce::int($_REQUEST, 'page', 1));

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

    $allResults = TypeCoerce::toRowList($response['data'] ?? []);
    $meta = TypeCoerce::toStringMap($response['meta'] ?? null);

    // Apply commission
    $cartService = Container::getCartService();
    foreach ($allResults as &$result) {
        $resultPricing = TypeCoerce::toStringMap($result['pricing'] ?? null);
        if (isset($resultPricing['selling_price'])) {
            $resultPricing['original_price'] = $resultPricing['selling_price'];
            $resultPricing['selling_price'] = $cartService->applyCommission(TypeCoerce::toFloat($resultPricing['selling_price']));
            $result['pricing'] = $resultPricing;
        }
    }
    unset($result);

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

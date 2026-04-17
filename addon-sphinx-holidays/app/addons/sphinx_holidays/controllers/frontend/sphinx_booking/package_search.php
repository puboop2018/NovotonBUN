<?php
declare(strict_types=1);
/**
 * Sphinx Booking Controller — Package Search Mode
 *
 * Initiates a package search via SphinxApi::searchPackages(), then polls
 * for results using SphinxApi::getPackageResults() with cursor-based
 * pagination. Same polling pattern as hotel search.
 *
 * @package SphinxHolidays
 * @since   1.2.0
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Tygh;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\CacheService;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;

try {
    $api = Container::getApi();
    $view = Tygh::$app['view'];

    $departure_id = RequestCoerce::int($_REQUEST, 'departure_id');
    $destination_id = RequestCoerce::int($_REQUEST, 'destination_id');
    $departure_date = RequestCoerce::string($_REQUEST, 'departure_date');
    $nights = max(1, RequestCoerce::int($_REQUEST, 'nights', 7));
    $adults = max(1, RequestCoerce::int($_REQUEST, 'adults', 2));
    $children = max(0, RequestCoerce::int($_REQUEST, 'children'));
    $children_ages_str = RequestCoerce::string($_REQUEST, 'children_ages');
    $rooms = max(1, RequestCoerce::int($_REQUEST, 'rooms', 1));
    $transport = RequestCoerce::string($_REQUEST, 'transport');

    if (empty($departure_date) || empty($destination_id)) {
        fn_set_notification('W', __('warning'),
            __('sphinx_holidays.please_fill_required_fields', ['[default]' => 'Please fill in the required search fields.']));
        $view->assign('sphinx_package_results', []);
        return;
    }

    $children_ages = [];
    if (!empty($children_ages_str)) {
        $children_ages = array_map('intval', array_filter(explode(',', $children_ages_str), function($v) { return $v !== ''; }));
    }

    $occupancy = [];
    for ($r = 0; $r < $rooms; $r++) {
        $occupancy[] = ['adults' => $adults, 'children_ages' => $children_ages];
    }

    $searchParams = [
        'departure_date' => $departure_date,
        'nights' => $nights,
        'destination_id' => $destination_id,
        'occupancy' => $occupancy,
        'currency' => ConfigProvider::getDefaultCurrency(),
    ];

    if ($departure_id > 0) {
        $searchParams['departure_id'] = $departure_id;
    }

    if (!empty($transport)) {
        $searchParams['transport'] = [$transport];
    }

    $ignoreDomains = ConfigProvider::getIgnoreDomains();
    if (!empty($ignoreDomains)) {
        $searchParams['ignore_domains'] = $ignoreDomains;
    }

    // Check cache
    $cacheEnabled = ConfigProvider::isApiCacheEnabled();
    $cacheTtl = ConfigProvider::getCacheTtlSearch();
    $cacheKey = '';
    $allResults = [];
    $fromCache = false;

    if ($cacheEnabled && $cacheTtl > 0) {
        $cacheKey = CacheService::buildSearchKey(array_merge(['_type' => 'package'], $searchParams));
        $cached = CacheService::get($cacheKey);
        if ($cached !== null) {
            $allResults = TypeCoerce::toRowList($cached['results'] ?? []);
            $fromCache = true;
        }
    }

    if (!$fromCache) {
        $searchResponse = $api->searchPackages($searchParams);

        $searchId = TypeCoerce::toString($searchResponse['search_id'] ?? '');
        if (empty($searchId) && empty($searchResponse['cursor'])) {
            fn_set_notification('E', __('error'),
                __('sphinx_holidays.search_error', ['[default]' => 'Search failed. Please try again.']));
            $view->assign('sphinx_package_results', []);
            return;
        }

        // Poll for results using search_id + cursor
        $maxPolls = ConfigProvider::getSearchMaxPolls();
        $cursor = isset($searchResponse['cursor']) ? TypeCoerce::toString($searchResponse['cursor']) : null;
        $pollCount = 0;

        do {
            $pollCount++;

            $pollResponse = $api->getPackageResults($searchId, $cursor);
            if ($pollResponse === null) break;

            $pollData = TypeCoerce::toRowList($pollResponse['data'] ?? []);
            if (!empty($pollData)) {
                foreach ($pollData as $result) {
                    $allResults[] = $result;
                }
            }

            $cursor = isset($pollResponse['cursor']) ? TypeCoerce::toString($pollResponse['cursor']) : null;
            if ($cursor === null) break;

        } while ($pollCount < $maxPolls);

        // Cache raw results
        if ($cacheEnabled && $cacheTtl > 0 && !empty($allResults)) {
            CacheService::set($cacheKey, ['results' => $allResults], $cacheTtl);
        }
    }

    // Apply commission to pricing.selling_price
    $cartService = Container::getCartService();
    foreach ($allResults as &$result) {
        $resultPricing = TypeCoerce::toStringMap($result['pricing'] ?? null);
        if (isset($resultPricing['selling_price'])) {
            $resultPricing['original_selling_price'] = $resultPricing['selling_price'];
            $resultPricing['selling_price'] = $cartService->applyCommission(TypeCoerce::toFloat($resultPricing['selling_price']));
            $result['pricing'] = $resultPricing;
        }
    }
    unset($result);

    $view->assign('sphinx_package_results', $allResults);
    $view->assign('sphinx_package_params', [
        'departure_id' => $departure_id,
        'destination_id' => $destination_id,
        'departure_date' => $departure_date,
        'nights' => $nights,
        'adults' => $adults,
        'children' => $children,
        'children_ages' => $children_ages_str,
        'rooms' => $rooms,
        'transport' => $transport,
    ]);

} catch (\Throwable $e) {
    fn_log_event('general', 'runtime', [
        'message' => 'Sphinx Package Search Error: ' . $e->getMessage(),
        'file'    => $e->getFile() . ':' . $e->getLine(),
    ]);

    fn_set_notification('E', __('error'),
        __('sphinx_holidays.search_error', ['[default]' => 'An error occurred while searching. Please try again later.']));

    Tygh::$app['view']->assign('sphinx_package_results', []);
}

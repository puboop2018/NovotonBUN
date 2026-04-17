<?php
declare(strict_types=1);
/**
 * Sphinx Booking Controller — Search Mode
 *
 * Initiates a hotel search via SphinxApi::searchHotels() and returns
 * the search_id to the template. Actual results are fetched by
 * client-side JS polling sphinx_booking.search_poll, avoiding the
 * previous sleep-loop that blocked the PHP thread for up to 60s.
 *
 * Cached results (if any) are rendered inline for instant display.
 *
 * @package SphinxHolidays
 * @since   1.0.0
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

    $check_in = RequestCoerce::string($_REQUEST, 'check_in');
    $check_out = RequestCoerce::string($_REQUEST, 'check_out');
    $hotel_id = RequestCoerce::string($_REQUEST, 'hotel_id');
    $destination_id = RequestCoerce::int($_REQUEST, 'destination_id');
    $adults = max(1, RequestCoerce::int($_REQUEST, 'adults', 2));
    $children = max(0, RequestCoerce::int($_REQUEST, 'children'));
    $children_ages_str = RequestCoerce::string($_REQUEST, 'children_ages');
    $rooms = max(1, RequestCoerce::int($_REQUEST, 'rooms', 1));

    if (empty($check_out) && !empty($check_in)) {
        $nights = max(1, RequestCoerce::int($_REQUEST, 'nights', 7));
        $check_out = date('Y-m-d', (int) strtotime($check_in . " + {$nights} days"));
    }

    $templateParams = [
        'hotel_id' => $hotel_id,
        'destination_id' => $destination_id,
        'check_in' => $check_in,
        'check_out' => $check_out,
        'adults' => $adults,
        'children' => $children,
        'children_ages' => $children_ages_str,
        'rooms' => $rooms,
        'nights' => (strtotime($check_out ?: 'now') && strtotime($check_in ?: 'now'))
            ? (int) round((strtotime($check_out) - strtotime($check_in)) / 86400) : 0,
    ];

    // Render booking engine first — always needed
    $view->assign('booking_engine_html', fn_travel_core_render_booking_engine([
        'provider'        => 'sphinx',
        'search_dispatch' => 'sphinx_booking.search',
        'mode'            => 'search',
        'search_params'   => $templateParams,
    ]));
    $view->assign('sphinx_search_params', $templateParams);
    $view->assign('sphinx_search_results', []);
    $view->assign('sphinx_search_id', '');
    $view->assign('sphinx_search_status', 'idle');

    if (empty($check_in)) {
        fn_set_notification('W', __('warning'),
            __('sphinx_holidays.please_fill_required_fields',
                ['[default]' => 'Please fill in the required search fields.']));
        return;
    }

    $children_ages = [];
    if (!empty($children_ages_str)) {
        $children_ages = array_map(
            'intval',
            array_filter(explode(',', $children_ages_str), static fn($v) => $v !== '')
        );
    }

    $occupancy = [];
    for ($r = 0; $r < $rooms; $r++) {
        $occupancy[] = ['adults' => $adults, 'children_ages' => $children_ages];
    }

    $searchParams = [
        'check_in'  => $check_in,
        'check_out' => $check_out,
        'occupancy' => $occupancy,
        'currency'  => ConfigProvider::getDefaultCurrency(),
    ];

    if (!empty($hotel_id)) {
        $searchParams['hotel_id'] = $hotel_id;
    } elseif ($destination_id > 0) {
        $searchParams['destination_id'] = $destination_id;
    }

    $ignoreDomains = ConfigProvider::getIgnoreDomains();
    if (!empty($ignoreDomains)) {
        $searchParams['ignore_domains'] = $ignoreDomains;
    }

    // Check cache — if hit, render results inline (no polling needed)
    $cacheEnabled = ConfigProvider::isApiCacheEnabled();
    $cacheTtl = ConfigProvider::getCacheTtlSearch();

    if ($cacheEnabled && $cacheTtl > 0) {
        $cacheKey = CacheService::buildSearchKey($searchParams);
        $cached = CacheService::get($cacheKey);
        if ($cached !== null) {
            $cachedMap = TypeCoerce::toStringMap($cached);
            $view->assign('sphinx_search_results', TypeCoerce::toRowList($cachedMap['results'] ?? []));
            $view->assign('sphinx_search_id', TypeCoerce::toString($cachedMap['search_id'] ?? ''));
            $view->assign('sphinx_search_status', 'completed');
            return;
        }
    }

    // No cache — initiate async search, JS will poll for results
    $searchResponse = $api->searchHotels($searchParams);

    if (empty($searchResponse['search_id'])) {
        fn_log_event('general', 'runtime', [
            'message' => 'Sphinx searchHotels returned no search_id',
            'search_params' => $searchParams,
            'api_response' => is_array($searchResponse) ? json_encode($searchResponse) : TypeCoerce::toString($searchResponse),
        ]);
        fn_set_notification('E', __('error'),
            __('sphinx_holidays.search_error', ['[default]' => 'Search failed. Please try again.']));
        return;
    }

    // Store search params in session so search_poll can cache completed results
    Tygh::$app['session']['sphinx_search_' . $searchResponse['search_id']] = [
        'params'   => $searchParams,
        'cache_key' => $cacheEnabled && $cacheTtl > 0 ? CacheService::buildSearchKey($searchParams) : '',
        'cache_ttl' => $cacheTtl,
    ];

    $view->assign('sphinx_search_id', $searchResponse['search_id']);
    $view->assign('sphinx_search_status', 'pending');

} catch (\Throwable $e) {
    fn_log_event('general', 'runtime', [
        'message' => 'Sphinx Search Error: ' . $e->getMessage(),
        'file'    => $e->getFile() . ':' . $e->getLine(),
    ]);

    fn_set_notification('E', __('error'),
        __('sphinx_holidays.search_error',
            ['[default]' => 'An error occurred while searching. Please try again later.']));

    Tygh::$app['view']->assign('sphinx_search_results', []);
    Tygh::$app['view']->assign('sphinx_search_status', 'error');
}

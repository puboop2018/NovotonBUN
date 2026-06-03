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

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

use Tygh\Addons\SphinxHolidays\Helpers\SearchOfferNormalizer;
use Tygh\Addons\SphinxHolidays\Services\CacheService;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Tygh;

try {
    $api = Container::getApi();
    /** @var \Smarty $view */
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

    // Resolve the hotel record once when this is a product-page search
    // (hotel_id is set). Reused below for the destination_id and here for the
    // page heading so the results page shows which hotel is being searched
    // (mirrors the novoton results page).
    $hotelRow = $hotel_id !== ''
        ? Container::getHotelRepository()->getById($hotel_id)
        : null;

    $hotel_name = $hotelRow !== null ? TypeCoerce::toString($hotelRow['name'] ?? '') : '';
    $hotel_stars = $hotelRow !== null ? TypeCoerce::toString($hotelRow['classification'] ?? '') : '';
    $hotel_location = '';
    if ($hotelRow !== null) {
        $locationParts = array_filter(
            [
                TypeCoerce::toString($hotelRow['destination_name'] ?? ''),
                TypeCoerce::toString($hotelRow['country_name'] ?? ''),
            ],
            static fn (string $part): bool => $part !== '',
        );
        $hotel_location = implode(', ', $locationParts);
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
        'provider' => 'sphinx',
        'search_dispatch' => 'sphinx_booking.search',
        'mode' => 'search',
        'search_params' => $templateParams,
    ]));
    $view->assign('sphinx_search_params', $templateParams);
    $view->assign('sphinx_hotel_name', $hotel_name);
    $view->assign('sphinx_hotel_stars', $hotel_stars);
    $view->assign('sphinx_hotel_location', $hotel_location);
    $view->assign('sphinx_search_results', []);
    $view->assign('sphinx_search_id', '');
    $view->assign('sphinx_search_status', 'idle');

    if (empty($check_in)) {
        fn_set_notification(
            'W',
            __('warning'),
            __(
                'sphinx_holidays.please_fill_required_fields',
                ['[default]' => 'Please fill in the required search fields.'],
            ),
        );
        return;
    }

    $children_ages = [];
    if (!empty($children_ages_str)) {
        $children_ages = array_map(
            'intval',
            array_filter(explode(',', $children_ages_str), static fn ($v) => $v !== ''),
        );
    }

    $occupancy = [];
    for ($r = 0; $r < $rooms; $r++) {
        $occupancy[] = ['adults' => $adults, 'children_ages' => $children_ages];
    }

    $searchParams = [
        'check_in' => $check_in,
        'check_out' => $check_out,
        'occupancy' => $occupancy,
        'currency' => ConfigProvider::getDefaultCurrency(),
    ];

    // The Sphinx /hotels/search endpoint needs a destination_id to run a live
    // availability search — a hotel_ids-only query returns an empty result set.
    // The product page booking engine only knows the hotel_id, so take the
    // destination_id from the hotel row already fetched above.
    if ($destination_id <= 0 && $hotelRow !== null) {
        $destination_id = TypeCoerce::toInt($hotelRow['destination_id'] ?? 0);
    }

    if ($destination_id > 0) {
        $searchParams['destination_id'] = $destination_id;
    }
    if (!empty($hotel_id)) {
        $searchParams['hotel_ids'] = [$hotel_id];
    }

    $ignoreDomains = ConfigProvider::getIgnoreDomains();
    if (!empty($ignoreDomains)) {
        $searchParams['ignore_domains'] = $ignoreDomains;
    }

    // The Sphinx API's hotel_ids filter returns an EMPTY set when combined with
    // a destination_id (verified via cron_mode=diagnose_search: destination-only
    // returns the full destination, destination + hotel_ids returns 0). So when
    // we have a destination, query by destination alone and narrow back to this
    // hotel client-side (filter_hotel_id, below + in search_poll). hotel_ids is
    // kept in $searchParams purely to keep the cache key unique per hotel.
    $apiSearchParams = $searchParams;
    if ($destination_id > 0) {
        unset($apiSearchParams['hotel_ids']);
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
    $searchResponse = $api->searchHotels($apiSearchParams);

    // The search API returns an opaque polling token. Historically this was a
    // top-level `search_id`; the API now returns a `cursor` JWT instead (the
    // search_id is embedded inside it). Either value works as the token the JS
    // poller echoes back to sphinx_booking.search_poll.
    $searchToken = is_array($searchResponse)
        ? TypeCoerce::toString($searchResponse['cursor'] ?? $searchResponse['search_id'] ?? '')
        : '';

    if ($searchToken === '') {
        $httpClient = $api->getHttpClient();
        fn_log_event('general', 'runtime', [
            'message' => 'Sphinx searchHotels returned no search token (cursor/search_id)',
            'http_code' => $httpClient->getLastHttpCode(),
            'api_error' => $httpClient->getLastError(),
            'raw_response' => substr($httpClient->getLastResponseRaw() ?? '', 0, 500),
            'search_params' => $searchParams,
        ]);
        fn_set_notification(
            'E',
            __('error'),
            __('sphinx_holidays.search_error', ['[default]' => 'Search failed. Please try again.']),
        );
        return;
    }

    $searchIdStr = $searchToken;
    $initialStatus = TypeCoerce::toString($searchResponse['status'] ?? 'pending');
    $initialResults = TypeCoerce::toRowList($searchResponse['results'] ?? []);

    // Narrow to the requested hotel when this is a product-page (hotel_id)
    // search — searching by destination can return the whole destination.
    if ($hotel_id !== '' && !empty($initialResults)) {
        $initialResults = array_values(array_filter(
            $initialResults,
            static fn (array $r): bool => TypeCoerce::toString($r['hotel_id'] ?? $r['id'] ?? '') === $hotel_id,
        ));
    }

    // If the API returns final results synchronously, render inline and skip polling.
    if ($initialStatus === 'completed' && !empty($initialResults)) {
        // Flatten the nested API offer shape (pricing.selling_price,
        // meal_type_name, rooms[]) to the flat keys the template expects.
        $initialResults = SearchOfferNormalizer::flattenAll($initialResults);
        $cartService = Container::getCartService();
        foreach ($initialResults as &$result) {
            if (isset($result['price'])) {
                $result['original_price'] = $result['price'];
                $result['price'] = $cartService->applyCommission(TypeCoerce::toFloat($result['price']));
            }
        }
        unset($result);

        if ($cacheEnabled && $cacheTtl > 0) {
            CacheService::set(CacheService::buildSearchKey($searchParams), [
                'results' => $initialResults,
                'search_id' => $searchIdStr,
            ], $cacheTtl);
        }

        $view->assign('sphinx_search_results', $initialResults);
        $view->assign('sphinx_search_id', $searchIdStr);
        $view->assign('sphinx_search_status', 'completed');
        return;
    }

    // Diagnostic: log when the API immediately reports no availability
    // (helps differentiate "API has no offers" from "polling lost results").
    if ($initialStatus === 'completed' && empty($initialResults)) {
        fn_log_event('general', 'runtime', [
            'message' => 'Sphinx search returned completed with zero results',
            'search_params' => $searchParams,
            'api_response' => json_encode($searchResponse),
        ]);
    }

    // Store search params in session so search_poll can cache completed results
    /** @var array<string, mixed> $session */
    $session = &Tygh::$app['session'];
    $session['sphinx_search_' . $searchIdStr] = [
        'params' => $searchParams,
        'cache_key' => $cacheEnabled && $cacheTtl > 0 ? CacheService::buildSearchKey($searchParams) : '',
        'cache_ttl' => $cacheTtl,
        'filter_hotel_id' => $hotel_id,
    ];

    $view->assign('sphinx_search_id', $searchIdStr);
    $view->assign('sphinx_search_status', 'pending');
} catch (\Throwable $e) {
    fn_log_event('general', 'runtime', [
        'message' => 'Sphinx Search Error: ' . $e->getMessage(),
        'file' => $e->getFile() . ':' . $e->getLine(),
    ]);

    fn_set_notification(
        'E',
        __('error'),
        __(
            'sphinx_holidays.search_error',
            ['[default]' => 'An error occurred while searching. Please try again later.'],
        ),
    );

    /** @var \Smarty $errorView */
    $errorView = Tygh::$app['view'];
    $errorView->assign('sphinx_search_results', []);
    $errorView->assign('sphinx_search_status', 'error');
}

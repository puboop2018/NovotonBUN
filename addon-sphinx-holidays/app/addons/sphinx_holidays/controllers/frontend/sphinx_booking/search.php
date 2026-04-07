<?php
declare(strict_types=1);
/**
 * Sphinx Booking Controller — Search Mode
 *
 * Initiates a hotel search via SphinxApi::searchHotels(), then polls
 * for results using SphinxApi::getHotelResults() with cursor-based
 * pagination.
 *
 * @package SphinxHolidays
 * @since   1.0.0
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Tygh;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\CacheService;
try {
    $api = Container::getApi();
    $view = Tygh::$app['view'];

    $check_in = trim($_REQUEST['check_in'] ?? '');
    $check_out = trim($_REQUEST['check_out'] ?? '');
    $hotel_id = trim($_REQUEST['hotel_id'] ?? '');
    $destination_id = (int)($_REQUEST['destination_id'] ?? 0);
    $adults = max(1, (int)($_REQUEST['adults'] ?? 2));
    $children = max(0, (int)($_REQUEST['children'] ?? 0));
    $children_ages_str = trim($_REQUEST['children_ages'] ?? '');
    $rooms = max(1, (int)($_REQUEST['rooms'] ?? 1));

    if (empty($check_in)) {
        fn_set_notification('W', __('warning'),
            __('sphinx_holidays.please_fill_required_fields', ['[default]' => 'Please fill in the required search fields.']));
        $view->assign('sphinx_search_results', []);
        return;
    }

    if (empty($check_out)) {
        $nights = max(1, (int)($_REQUEST['nights'] ?? 7));
        $check_out = date('Y-m-d', strtotime($check_in . " + {$nights} days"));
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
        'check_in' => $check_in,
        'check_out' => $check_out,
        'occupancy' => $occupancy,
        'currency' => ConfigProvider::getDefaultCurrency(),
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

    // Check cache for identical search (short-lived, for UX — not a cache database)
    $cacheEnabled = ConfigProvider::isApiCacheEnabled();
    $cacheTtl = ConfigProvider::getCacheTtlSearch();
    $cacheKey = '';
    $allResults = [];
    $searchId = '';
    $fromCache = false;

    if ($cacheEnabled && $cacheTtl > 0) {
        $cacheKey = CacheService::buildSearchKey($searchParams);
        $cached = CacheService::get($cacheKey);
        if ($cached !== null) {
            $allResults = $cached['results'] ?? [];
            $searchId = $cached['search_id'] ?? '';
            $fromCache = true;
        }
    }

    if (!$fromCache) {
        $searchResponse = $api->searchHotels($searchParams);

        if (empty($searchResponse['search_id'])) {
            fn_set_notification('E', __('error'),
                __('sphinx_holidays.search_error', ['[default]' => 'Search failed. Please try again.']));
            $view->assign('sphinx_search_results', []);
            return;
        }

        $searchId = $searchResponse['search_id'];

        // Poll for results
        $pollInterval = ConfigProvider::getSearchPollInterval();
        $maxPolls = ConfigProvider::getSearchMaxPolls();
        $cursor = null;
        $pollCount = 0;

        $maxResults = 50; // Hard cap — Smarty 5 scope chain OOM at 200

        do {
            if ($pollCount > 0) {
                sleep($pollInterval);
            }
            $pollCount++;

            $pollResponse = $api->getHotelResults($searchId, $cursor);
            if ($pollResponse === null) break;

            if (!empty($pollResponse['results'])) {
                foreach ($pollResponse['results'] as $result) {
                    $allResults[] = $result;
                    if (count($allResults) >= $maxResults) break;
                }
            }

            if (count($allResults) >= $maxResults) break;

            $status = $pollResponse['status'] ?? 'completed';
            if ($status === 'completed') break;

            $cursor = $pollResponse['next_cursor'] ?? null;
            if ($cursor === null) break;

        } while ($pollCount < $maxPolls);

        // Cache results for identical future searches.
        // Strip to display-relevant fields to avoid caching multi-MB API payloads
        // (descriptions, facilities, images, terms) that exhaust memory.
        if ($cacheEnabled && $cacheTtl > 0 && !empty($allResults)) {
            $cacheFields = [
                'offer_id', 'hotel_id', 'product_id',
                'hotel_name', 'hotel_image', 'star_rating', 'destination',
                'room_name', 'room_type', 'board_name', 'board_type',
                'price', 'currency',
            ];
            $cacheResults = [];
            foreach ($allResults as $r) {
                $cr = [];
                foreach ($cacheFields as $f) {
                    if (isset($r[$f])) $cr[$f] = $r[$f];
                }
                // Preserve offers array (stripped) for commission recalculation
                if (!empty($r['offers'])) {
                    $cr['offers'] = array_map(function($o) use ($cacheFields) {
                        $so = [];
                        foreach ($cacheFields as $f) {
                            if (isset($o[$f])) $so[$f] = $o[$f];
                        }
                        return $so;
                    }, $r['offers']);
                }
                $cacheResults[] = $cr;
            }
            CacheService::set($cacheKey, [
                'results' => $cacheResults,
                'search_id' => $searchId,
            ], $cacheTtl);
            // Replace allResults with stripped version for downstream processing
            $allResults = $cacheResults;
        }
    }

    // Apply commission
    $commission = ConfigProvider::getCommission();
    $roundPrices = ConfigProvider::shouldRoundPrices();

    if ($commission > 0) {
        $calculator = new \Tygh\Addons\TravelCore\Services\CommissionCalculator($commission, $roundPrices);
        foreach ($allResults as &$result) {
            if (isset($result['price'])) {
                $result['original_price'] = $result['price'];
                $result['price'] = $calculator->apply((float)$result['price']);
            }
            if (!empty($result['offers'])) {
                foreach ($result['offers'] as &$offer) {
                    if (isset($offer['price'])) {
                        $offer['original_price'] = $offer['price'];
                        $offer['price'] = $calculator->apply((float)$offer['price']);
                    }
                }
                unset($offer);
            }
        }
        unset($result);
    }

    // Strip results to only the fields the template needs — raw API responses
    // can contain huge payloads (descriptions, facilities, images, terms) that
    // exhaust memory when Smarty serializes them for the template engine.
    $templateFields = [
        'offer_id', 'hotel_id', 'product_id',
        'hotel_name', 'hotel_image', 'star_rating', 'destination',
        'room_name', 'room_type', 'board_name', 'board_type',
        'price', 'original_price', 'currency',
    ];
    $slimResults = [];
    foreach ($allResults as $result) {
        $slim = [];
        foreach ($templateFields as $f) {
            if (isset($result[$f])) {
                $slim[$f] = $result[$f];
            }
        }
        $slimResults[] = $slim;
    }

    $view->assign('sphinx_search_results', $slimResults);
    $view->assign('sphinx_search_id', $searchId);
    $view->assign('sphinx_search_params', [
        'hotel_id' => $hotel_id,
        'destination_id' => $destination_id,
        'check_in' => $check_in,
        'check_out' => $check_out,
        'adults' => $adults,
        'children' => $children,
        'children_ages' => $children_ages_str,
        'rooms' => $rooms,
        'nights' => (strtotime($check_out) && strtotime($check_in)) ? (int)round((strtotime($check_out) - strtotime($check_in)) / 86400) : 0,
    ]);

} catch (\Throwable $e) {
    fn_log_event('general', 'runtime', [
        'message' => 'Sphinx Search Error: ' . $e->getMessage(),
        'file'    => $e->getFile() . ':' . $e->getLine(),
    ]);

    fn_set_notification('E', __('error'),
        __('sphinx_holidays.search_error', ['[default]' => 'An error occurred while searching. Please try again later.']));

    Tygh::$app['view']->assign('sphinx_search_results', []);
}

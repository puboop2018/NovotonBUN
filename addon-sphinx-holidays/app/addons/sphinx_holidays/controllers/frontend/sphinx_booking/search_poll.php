<?php
declare(strict_types=1);
/**
 * Sphinx Booking Controller — Search Poll Mode
 *
 * AJAX endpoint called by the search results page JS to fetch
 * incremental hotel search results. Replaces the previous
 * synchronous server-side polling loop.
 *
 * Request: GET sphinx_booking.search_poll?search_id=X&cursor=Y&nights=N
 * Response: JSON {status: pending|completed, results: [...], next_cursor: string|null}
 *
 * @package SphinxHolidays
 * @since   1.3.0
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Tygh;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\CacheService;
use Tygh\Addons\TravelCore\Services\CommissionCalculator;

header('Content-Type: application/json; charset=utf-8');

try {
    $searchId = trim($_REQUEST['search_id'] ?? '');
    $cursor = $_REQUEST['cursor'] ?? null;
    $finalize = !empty($_REQUEST['finalize']);

    if ($searchId === '') {
        echo json_encode(['status' => 'error', 'error' => 'Missing search_id']);
        exit;
    }

    $api = Container::getApi();
    $pollResponse = $api->getHotelResults($searchId, $cursor !== null && $cursor !== '' ? $cursor : null);

    if ($pollResponse === null) {
        echo json_encode(['status' => 'error', 'error' => 'API returned null']);
        exit;
    }

    $results = $pollResponse['results'] ?? [];
    $status = $pollResponse['status'] ?? 'completed';
    $nextCursor = $pollResponse['next_cursor'] ?? null;

    // Apply commission to each result
    $commission = ConfigProvider::getCommission();
    if ($commission > 0 && !empty($results)) {
        $calculator = new CommissionCalculator($commission, ConfigProvider::shouldRoundPrices() ? 'Y' : 'N');
        foreach ($results as &$result) {
            if (isset($result['price'])) {
                $result['original_price'] = $result['price'];
                $result['price'] = $calculator->apply((float) $result['price']);
            }
            if (!empty($result['offers'])) {
                foreach ($result['offers'] as &$offer) {
                    if (isset($offer['price'])) {
                        $offer['original_price'] = $offer['price'];
                        $offer['price'] = $calculator->apply((float) $offer['price']);
                    }
                }
                unset($offer);
            }
        }
        unset($result);
    }

    // Strip to display-relevant fields only (API payloads can be multi-MB)
    $templateFields = [
        'offer_id', 'hotel_id', 'product_id',
        'hotel_name', 'hotel_image', 'star_rating', 'destination',
        'room_name', 'room_type', 'board_name', 'board_type',
        'price', 'original_price', 'currency',
    ];
    $slimResults = [];
    foreach ($results as $result) {
        $slim = [];
        foreach ($templateFields as $f) {
            if (isset($result[$f])) {
                $slim[$f] = $result[$f];
            }
        }
        $slimResults[] = $slim;
    }

    // On completion, persist the full result set to cache for future searches
    if (($status === 'completed' || $finalize) && !empty($slimResults)) {
        $searchMeta = Tygh::$app['session']['sphinx_search_' . $searchId] ?? null;
        if ($searchMeta !== null && !empty($searchMeta['cache_key']) && !empty($searchMeta['cache_ttl'])) {
            // Accumulate results across multiple poll batches via session
            $cachedBatch = Tygh::$app['session']['sphinx_results_' . $searchId] ?? [];
            $cachedBatch = array_merge($cachedBatch, $slimResults);
            CacheService::set($searchMeta['cache_key'], [
                'results' => $cachedBatch,
                'search_id' => $searchId,
            ], (int) $searchMeta['cache_ttl']);
            unset(Tygh::$app['session']['sphinx_results_' . $searchId]);
            unset(Tygh::$app['session']['sphinx_search_' . $searchId]);
        }
    } elseif (!empty($slimResults)) {
        // Accumulate intermediate results in session for final caching
        $existing = Tygh::$app['session']['sphinx_results_' . $searchId] ?? [];
        Tygh::$app['session']['sphinx_results_' . $searchId] = array_merge($existing, $slimResults);
    }

    echo json_encode([
        'status'      => $status,
        'results'     => $slimResults,
        'next_cursor' => $nextCursor,
    ]);
    exit;

} catch (\Throwable $e) {
    fn_log_event('general', 'runtime', [
        'message' => 'Sphinx search_poll error: ' . $e->getMessage(),
        'file'    => $e->getFile() . ':' . $e->getLine(),
    ]);
    echo json_encode(['status' => 'error', 'error' => 'Internal error']);
    exit;
}

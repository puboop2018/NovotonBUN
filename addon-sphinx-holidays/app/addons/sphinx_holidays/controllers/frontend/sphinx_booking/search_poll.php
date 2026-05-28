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
use Tygh\Addons\SphinxHolidays\Services\CacheService;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;

header('Content-Type: application/json; charset=utf-8');

try {
    $searchId = RequestCoerce::string($_REQUEST, 'search_id');
    $cursor = RequestCoerce::string($_REQUEST, 'cursor');
    $finalize = RequestCoerce::bool($_REQUEST, 'finalize');

    if ($searchId === '') {
        echo json_encode(['status' => 'error', 'error' => 'Missing search_id']);
        exit;
    }

    $api = Container::getApi();
    $pollResponse = $api->getHotelResults($searchId, $cursor !== '' ? $cursor : null);

    if ($pollResponse === null) {
        echo json_encode(['status' => 'error', 'error' => 'API returned null']);
        exit;
    }

    $results = TypeCoerce::toRowList($pollResponse['results'] ?? []);
    $status = TypeCoerce::toString($pollResponse['status'] ?? 'completed');
    $nextCursor = isset($pollResponse['next_cursor']) ? TypeCoerce::toString($pollResponse['next_cursor']) : null;

    // Diagnostic: log when the poll completes with zero results, so the
    // CS-Cart event log shows whether the API genuinely has no availability
    // or returned an unexpected shape we failed to parse.
    if ($status === 'completed' && empty($results) && $cursor === '') {
        fn_log_event('general', 'runtime', [
            'message' => 'Sphinx search_poll completed with zero results',
            'search_id' => $searchId,
            'api_response' => json_encode($pollResponse),
        ]);
    }

    // Apply commission to each result
    $cartService = Container::getCartService();
    foreach ($results as &$result) {
        if (isset($result['price'])) {
            $result['original_price'] = $result['price'];
            $result['price'] = $cartService->applyCommission(TypeCoerce::toFloat($result['price']));
        }
        if (!empty($result['offers'])) {
            $offers = TypeCoerce::toRowList($result['offers']);
            foreach ($offers as &$offer) {
                if (isset($offer['price'])) {
                    $offer['original_price'] = $offer['price'];
                    $offer['price'] = $cartService->applyCommission(TypeCoerce::toFloat($offer['price']));
                }
            }
            unset($offer);
            $result['offers'] = $offers;
        }
    }
    unset($result);

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
        $searchMeta = TypeCoerce::toStringMap(Tygh::$app['session']['sphinx_search_' . $searchId] ?? null);
        if (!empty($searchMeta['cache_key']) && !empty($searchMeta['cache_ttl'])) {
            // Accumulate results across multiple poll batches via session
            $cachedBatch = TypeCoerce::toRowList(Tygh::$app['session']['sphinx_results_' . $searchId] ?? null);
            $cachedBatch = array_merge($cachedBatch, $slimResults);
            CacheService::set(TypeCoerce::toString($searchMeta['cache_key']), [
                'results' => $cachedBatch,
                'search_id' => $searchId,
            ], TypeCoerce::toInt($searchMeta['cache_ttl']));
            unset(Tygh::$app['session']['sphinx_results_' . $searchId]);
            unset(Tygh::$app['session']['sphinx_search_' . $searchId]);
        }
    } elseif (!empty($slimResults)) {
        // Accumulate intermediate results in session for final caching
        $existing = TypeCoerce::toRowList(Tygh::$app['session']['sphinx_results_' . $searchId] ?? null);
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

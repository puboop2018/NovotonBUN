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

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

use Tygh\Addons\SphinxHolidays\Helpers\SearchOfferNormalizer;
use Tygh\Addons\SphinxHolidays\Services\CacheService;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Tygh;

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
    // $searchId carries the opaque polling token from search.php (a `cursor` JWT
    // under the current API). Poll by cursor — use the evolving cursor the client
    // sends on follow-up polls, otherwise the initial token. Mirrors the proven
    // board-discovery poll loop (DiscoverBoardsCommand::pollResults).
    $pollResponse = $api->getHotelResults('', $cursor !== '' ? $cursor : $searchId);

    if ($pollResponse === null) {
        echo json_encode(['status' => 'error', 'error' => 'API returned null']);
        exit;
    }

    $results = TypeCoerce::toRowList($pollResponse['results'] ?? $pollResponse['data'] ?? []);
    $status = TypeCoerce::toString($pollResponse['status'] ?? 'completed');

    // Narrow to the requested hotel for product-page searches. search.php stores
    // the hotel_id in the search session meta; because we search by destination
    // the API can return the whole destination, so drop other hotels' offers
    // before commission/slimming. No-op when the API already filtered by hotel.
    $searchMeta = TypeCoerce::toStringMap(Tygh::$app['session']['sphinx_search_' . $searchId] ?? null);
    $filterHotelId = TypeCoerce::toString($searchMeta['filter_hotel_id'] ?? '');
    if ($filterHotelId !== '' && !empty($results)) {
        $results = array_values(array_filter(
            $results,
            // Match on hotel_id, falling back to id: the results endpoint is
            // not always consistent about which key carries the hotel id, and
            // matching the wrong key drops every offer for the hotel.
            static fn (array $r): bool => TypeCoerce::toString($r['hotel_id'] ?? $r['id'] ?? '') === $filterHotelId,
        ));
    }

    // The API paginates via a `cursor` token (older builds used `next_cursor`).
    $nextCursor = null;
    if (isset($pollResponse['next_cursor'])) {
        $nextCursor = TypeCoerce::toString($pollResponse['next_cursor']);
    } elseif (isset($pollResponse['cursor'])) {
        $nextCursor = TypeCoerce::toString($pollResponse['cursor']);
    }

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

    // The Sphinx search results endpoint returns a nested offer shape
    // (pricing.selling_price, meal_type_name, rooms[], destination_name).
    // Flatten each offer to the keys the commission step, slimming list and
    // template expect (price, currency, board_name, room_name, destination) —
    // otherwise every offer renders with a 0,00 price and blank room/board.
    $results = SearchOfferNormalizer::flattenAll($results);

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

    // Cache the hotel's offers as soon as we have any. The JS early-stops on the
    // first poll that returns this hotel's offers, and the API never reports
    // status='completed', so we must cache on the poll that produces them rather
    // than wait for a terminal status. $slimResults is already narrowed to this
    // hotel, so the cache holds exactly what is displayed. Never cache an empty
    // set, so a hotel with no availability is not cached as "no offers".
    $accumulated = TypeCoerce::toRowList(Tygh::$app['session']['sphinx_results_' . $searchId] ?? null);
    $accumulated = array_merge($accumulated, $slimResults);
    $endOfStream = $nextCursor === null || $nextCursor === '';

    if (!empty($accumulated) && !empty($searchMeta['cache_key']) && !empty($searchMeta['cache_ttl'])) {
        CacheService::set(TypeCoerce::toString($searchMeta['cache_key']), [
            'results' => $accumulated,
            'search_id' => $searchId,
        ], TypeCoerce::toInt($searchMeta['cache_ttl']));
    }

    if ($endOfStream || $finalize) {
        unset(Tygh::$app['session']['sphinx_results_' . $searchId]);
        unset(Tygh::$app['session']['sphinx_search_' . $searchId]);
    } else {
        Tygh::$app['session']['sphinx_results_' . $searchId] = $accumulated;
    }

    echo json_encode([
        'status' => $status,
        'results' => $slimResults,
        'next_cursor' => $nextCursor,
    ]);
    exit;
} catch (\Throwable $e) {
    fn_log_event('general', 'runtime', [
        'message' => 'Sphinx search_poll error: ' . $e->getMessage(),
        'file' => $e->getFile() . ':' . $e->getLine(),
    ]);
    echo json_encode(['status' => 'error', 'error' => 'Internal error']);
    exit;
}

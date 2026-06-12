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

use Tygh\Addons\SphinxHolidays\Helpers\OfferAvailability;
use Tygh\Addons\SphinxHolidays\Helpers\SearchMetrics;
use Tygh\Addons\SphinxHolidays\Helpers\SearchOfferNormalizer;
use Tygh\Addons\SphinxHolidays\Services\CacheService;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
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

    // P0b metrics: time this poll against the search start stamped by search.php
    // and track the poll index across requests (both live in the session meta).
    // docs/adr/0001-availability-early-render-and-metrics.md
    $startedAtMs = TypeCoerce::toInt($searchMeta['started_at_ms'] ?? 0);
    $pollIndex = TypeCoerce::toInt($searchMeta['poll_index'] ?? 0) + 1;
    $elapsedMs = $startedAtMs > 0 ? (int) round(microtime(true) * 1000) - $startedAtMs : 0;

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

    // Show only offers with immediate availability (confirmation=immediate).
    // Filter the raw offers before flatten/commission/slim/cache so downstream
    // consumers (and the cached set) contain only immediately-bookable offers.
    if (ConfigProvider::shouldRequireImmediateAvailability() && !empty($results)) {
        $beforeCount = count($results);
        $results = OfferAvailability::filterImmediate($results);
        if ($beforeCount !== count($results) && ConfigProvider::isDebugLogging()) {
            fn_log_event('general', 'runtime', [
                'message' => 'Sphinx search_poll filtered non-immediate offers',
                'search_id' => $searchId,
                'kept' => count($results),
                'dropped' => $beforeCount - count($results),
            ]);
        }
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
        'price', 'original_price', 'currency', 'confirmation',
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

    // Accumulate this hotel's offers across polls in the session (a per-user,
    // in-flight buffer). The cross-user cache is written ONLY when the stream
    // completes (cursor exhausted) or the client finalizes — never mid-stream —
    // so a concurrent visitor can never read a half-filled set as authoritative.
    // The client renders offers as they arrive but keeps polling to the end to
    // drive this completion. docs/adr/0001-availability-early-render-and-metrics.md
    $accumulated = TypeCoerce::toRowList(Tygh::$app['session']['sphinx_results_' . $searchId] ?? null);
    $priorCount = count($accumulated);
    $accumulated = array_merge($accumulated, $slimResults);
    $offerCount = count($accumulated);
    $endOfStream = $nextCursor === null || $nextCursor === '';
    $terminal = $endOfStream || $finalize;

    // P0b: first poll that produced this hotel's offers, and stream completion.
    if ($priorCount === 0 && $offerCount > 0) {
        SearchMetrics::record(SearchMetrics::EVENT_FIRST_OFFER, [
            'search_id' => $searchId,
            'hotel_id' => $filterHotelId,
            'elapsed_ms' => $elapsedMs,
            'poll' => $pollIndex,
            'offers' => $offerCount,
        ]);
    }
    if ($terminal) {
        SearchMetrics::record(SearchMetrics::EVENT_COMPLETE, [
            'search_id' => $searchId,
            'hotel_id' => $filterHotelId,
            'elapsed_ms' => $elapsedMs,
            'polls' => $pollIndex,
            'offers' => $offerCount,
        ]);
    }

    // Never cache an empty set, so a hotel with no availability is not cached as
    // "no offers". `complete` marks the entry as the full, authoritative set.
    if ($terminal && !empty($accumulated) && !empty($searchMeta['cache_key']) && !empty($searchMeta['cache_ttl'])) {
        CacheService::set(TypeCoerce::toString($searchMeta['cache_key']), [
            'results' => $accumulated,
            'search_id' => $searchId,
            'complete' => true,
        ], TypeCoerce::toInt($searchMeta['cache_ttl']));
    }

    if ($terminal) {
        unset(Tygh::$app['session']['sphinx_results_' . $searchId]);
        unset(Tygh::$app['session']['sphinx_search_' . $searchId]);
    } else {
        Tygh::$app['session']['sphinx_results_' . $searchId] = $accumulated;
        // Persist the incremented poll index for the next poll's metrics. Write
        // the whole meta back — the Session container returns values by copy, so
        // a nested write would not stick.
        $meta = Tygh::$app['session']['sphinx_search_' . $searchId] ?? [];
        if (is_array($meta)) {
            $meta['poll_index'] = $pollIndex;
            Tygh::$app['session']['sphinx_search_' . $searchId] = $meta;
        }
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

<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Api\SphinxNormalizer;
use Tygh\Addons\SphinxHolidays\Repository\HotelRepository;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\Container;

/**
 * Cron command: discover available board/meal types per hotel via live search.
 *
 * For each destination in sync targets, initiates a hotel search
 * (POST /api/v1/hotels/search) and polls results (GET /api/v1/hotels/results).
 * Each search result contains meal_type_name — these are normalized to canonical
 * board codes (AI, FB, HB, etc.) and stored in sphinx_hotels.boards_json.
 *
 * This is a one-time or periodic discovery job. Once boards are stored in
 * boards_json, they persist across hotel re-syncs and product creation.
 *
 * Uses the same sync targets as hotel sync (country codes, destination IDs)
 * from addon settings or CLI overrides.
 *
 * Usage:
 *   php cron.php access_key=KEY mode=discover_boards
 *   php cron.php access_key=KEY mode=discover_boards country=GR
 *   php cron.php access_key=KEY mode=discover_boards country=GR,BG
 */
class DiscoverBoardsCommand
{
    /** @var callable|null */
    private $outputCallback = null;

    /** Default search parameters for board discovery */
    private const SEARCH_NIGHTS = 7;
    private const SEARCH_ADULTS = 2;
    private const SEARCH_DAYS_AHEAD = 30;

    /** Polling configuration */
    private const POLL_INTERVAL_SECONDS = 3;
    private const POLL_MAX_ATTEMPTS = 20;

    /** Rate limiting */
    private const DELAY_BETWEEN_DESTINATIONS_MS = 500000; // 500ms

    public static function getDescription(): string
    {
        return 'Discover available board/meal types per hotel via live search API';
    }

    public function setOutputCallback(callable $callback): void
    {
        $this->outputCallback = $callback;
    }

    public function execute(array $params = []): array
    {
        $api = Container::getApi();
        $hotelRepo = new HotelRepository();
        $normalizer = new SphinxNormalizer();

        $startTime = microtime(true);

        // Resolve sync targets (CLI override or addon settings)
        $countryCodes = [];
        if (!empty($params['country'])) {
            $countryCodes = array_filter(array_map(function ($c) {
                return strtoupper(trim($c));
            }, explode(',', $params['country'])));
        }

        if (empty($countryCodes)) {
            $targets = ConfigProvider::getSelectedSyncTargets();
            $countryCodes = $targets['country_codes'];
        }

        $this->output('Sync targets: ' . (empty($countryCodes) ? 'ALL countries' : implode(', ', $countryCodes)));

        // Get unique destination_ids from sphinx_hotels for target countries
        $destinationIds = $hotelRepo->getDestinationIdsByCountry($countryCodes);
        $this->output('Destinations to search: ' . count($destinationIds));

        if (empty($destinationIds)) {
            $this->output('No destinations found. Run hotel sync first (mode=hotels).');
            return ['success' => true, 'stats' => ['destinations' => 0, 'hotels_updated' => 0]];
        }

        // Search parameters
        $checkIn = date('Y-m-d', strtotime('+' . self::SEARCH_DAYS_AHEAD . ' days'));
        $checkOut = date('Y-m-d', strtotime('+' . (self::SEARCH_DAYS_AHEAD + self::SEARCH_NIGHTS) . ' days'));
        $currency = ConfigProvider::getDefaultCurrency();

        $this->output("Search params: check_in={$checkIn}, check_out={$checkOut}, {$currency}, " . self::SEARCH_ADULTS . " adults");

        $stats = [
            'destinations_searched' => 0,
            'destinations_total'    => count($destinationIds),
            'destinations_empty'    => 0,
            'offers_found'          => 0,
            'hotels_updated'        => 0,
            'hotels_matched'        => 0,
            'unique_boards'         => [],
            'search_errors'         => 0,
        ];

        // Aggregate boards per hotel across all destinations
        /** @var array<string, string[]> hotel_id => array of canonical board codes */
        $boardsByHotel = [];

        foreach ($destinationIds as $destId) {
            $stats['destinations_searched']++;

            // Initiate search
            $searchResponse = $api->searchHotels([
                'destination_id' => (int) $destId,
                'check_in'       => $checkIn,
                'check_out'      => $checkOut,
                'occupancy'      => [['adults' => self::SEARCH_ADULTS, 'children_ages' => []]],
                'currency'       => $currency,
            ]);

            if ($searchResponse === null) {
                $stats['search_errors']++;
                if ($stats['search_errors'] <= 5) {
                    $this->output("  [WARN] Search init failed for destination {$destId}");
                }
                usleep(self::DELAY_BETWEEN_DESTINATIONS_MS);
                continue;
            }

            // The API returns a cursor for polling (not search_id)
            $cursor = $searchResponse['cursor'] ?? $searchResponse['search_id'] ?? null;
            if ($cursor === null) {
                $stats['search_errors']++;
                usleep(self::DELAY_BETWEEN_DESTINATIONS_MS);
                continue;
            }

            // Poll for results
            $destOffers = $this->pollResults($api, $cursor);

            if (empty($destOffers)) {
                $stats['destinations_empty']++;
                usleep(self::DELAY_BETWEEN_DESTINATIONS_MS);
                continue;
            }

            $stats['offers_found'] += count($destOffers);

            // Get known hotel_ids for this destination (to match search results)
            $knownHotels = $hotelRepo->getHotelIdsByDestination((int) $destId);

            // Extract and normalize meal_type_name per hotel
            foreach ($destOffers as $offer) {
                $hotelId = (string) ($offer['hotel_id'] ?? '');
                if ($hotelId === '') {
                    continue;
                }

                // Only process offers matching hotels we have in sphinx_hotels
                if (!isset($knownHotels[$hotelId])) {
                    continue;
                }

                $stats['hotels_matched']++;

                // Search results use 'meal_type_name' (confirmed by audit)
                $rawMeal = $offer['meal_type_name'] ?? $offer['meal_name'] ?? $offer['board_name'] ?? '';
                if ($rawMeal === '') {
                    continue;
                }

                $canonicalCode = $normalizer->normalizeBoardCode($rawMeal);
                if ($canonicalCode === null) {
                    continue;
                }

                if (!isset($boardsByHotel[$hotelId])) {
                    $boardsByHotel[$hotelId] = [];
                }
                if (!in_array($canonicalCode, $boardsByHotel[$hotelId], true)) {
                    $boardsByHotel[$hotelId][] = $canonicalCode;
                }

                $stats['unique_boards'][$canonicalCode] = ($stats['unique_boards'][$canonicalCode] ?? 0) + 1;
            }

            // Progress output every 10 destinations
            if ($stats['destinations_searched'] % 10 === 0) {
                $this->output("  Progress: {$stats['destinations_searched']}/{$stats['destinations_total']} destinations, "
                    . count($boardsByHotel) . " hotels with boards, {$stats['offers_found']} offers");
            }

            usleep(self::DELAY_BETWEEN_DESTINATIONS_MS);
        }

        // Batch update boards_json in sphinx_hotels
        if (!empty($boardsByHotel)) {
            $stats['hotels_updated'] = $hotelRepo->updateBoardsBatch($boardsByHotel);
        }

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        // Log to sphinx_sync_log
        db_query(
            "INSERT INTO ?:sphinx_sync_log (sync_type, status, items_total, items_synced, items_failed, error_message, sync_mode, duration_ms, started_at, completed_at)
             VALUES ('discover_boards', 'completed', ?i, ?i, ?i, '', 'full', ?i, NOW(), NOW())",
            $stats['destinations_total'],
            $stats['hotels_updated'],
            $stats['search_errors'],
            $durationMs
        );

        // Summary output
        $this->output('');
        $this->output('Board Discovery Summary:');
        $this->output("  Destinations searched: {$stats['destinations_searched']}/{$stats['destinations_total']}");
        $this->output("  Destinations with no results: {$stats['destinations_empty']}");
        $this->output("  Total search offers: {$stats['offers_found']}");
        $this->output("  Hotels matched to sphinx_hotels: {$stats['hotels_matched']}");
        $this->output("  Hotels updated (boards_json): {$stats['hotels_updated']}");
        $this->output("  Search errors: {$stats['search_errors']}");
        $this->output("  Duration: " . round($durationMs / 1000) . "s");

        if (!empty($stats['unique_boards'])) {
            arsort($stats['unique_boards']);
            $this->output('  Board distribution:');
            foreach ($stats['unique_boards'] as $code => $count) {
                $this->output("    {$code}: {$count} offers");
            }
        }

        return ['success' => true, 'stats' => $stats];
    }

    /**
     * Poll search results until completed or max attempts reached.
     *
     * The Sphinx API uses cursor-based polling:
     * - searchHotels() returns {"cursor": "jwt-token"}
     * - getHotelResults() polls with that cursor
     * - Response: {"data": [...offers], "cursor": "next-cursor-or-null"}
     *
     * @param \Tygh\Addons\SphinxHolidays\SphinxApi $api
     * @param string $cursor Initial cursor from searchHotels()
     * @return array All accumulated offers from polling
     */
    private function pollResults($api, string $cursor): array
    {
        $allResults = [];
        $currentCursor = $cursor;

        for ($poll = 0; $poll < self::POLL_MAX_ATTEMPTS; $poll++) {
            if ($poll > 0) {
                sleep(self::POLL_INTERVAL_SECONDS);
            }

            // SphinxApi::getHotelResults(searchId, cursor) — pass empty searchId,
            // cursor goes as query param: GET /api/v1/hotels/results?cursor=X
            $response = $api->getHotelResults('', $currentCursor);

            if ($response === null) {
                break;
            }

            $results = $response['data'] ?? $response['results'] ?? [];
            foreach ($results as $r) {
                $allResults[] = $r;
            }

            // Check completion status
            $status = $response['status'] ?? '';
            if ($status === 'completed' || $status === 'done') {
                break;
            }

            // Get next cursor for pagination
            $nextCursor = $response['cursor'] ?? $response['next_cursor'] ?? null;

            // Stop if no cursor and no results (search exhausted)
            if ($nextCursor === null && empty($results)) {
                break;
            }

            if ($nextCursor !== null) {
                $currentCursor = $nextCursor;
            }
            // If nextCursor is null but we got results, stop (final page)
            elseif (!empty($results)) {
                break;
            }
        }

        return $allResults;
    }

    private function output(string $message): void
    {
        if ($this->outputCallback !== null) {
            ($this->outputCallback)($message);
        }
    }
}

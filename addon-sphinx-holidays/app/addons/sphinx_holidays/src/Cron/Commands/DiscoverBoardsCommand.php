<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Api\SphinxNormalizer;
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
 * Supports batch processing with resume capability:
 * - Processes a configurable number of destinations per cron run
 * - Saves state to a JSON file after each destination
 * - Automatically resumes from where it left off on next run
 * - Respects time limits to avoid PHP timeout
 *
 * Usage:
 *   php cron.php access_key=KEY mode=discover_boards
 *   php cron.php access_key=KEY mode=discover_boards country=GR
 *   php cron.php access_key=KEY mode=discover_boards batch_size=20
 *   php cron.php access_key=KEY mode=discover_boards max_time=300
 *   php cron.php access_key=KEY mode=discover_boards unlimited=1
 *   php cron.php access_key=KEY mode=discover_boards status=1
 *   php cron.php access_key=KEY mode=discover_boards reset=1
 */
class DiscoverBoardsCommand extends AbstractSyncCommand
{
    use StatefulCommandTrait;

    /** State file name stored in DIR_CACHE */
    private const STATE_FILE_NAME = 'sphinx_discover_boards_state.json';

    /** Default search parameters for board discovery */
    private const SEARCH_NIGHTS = 7;
    private const SEARCH_ADULTS = 2;
    private const SEARCH_DAYS_AHEAD = 30;

    /** Polling configuration */
    private const POLL_INTERVAL_SECONDS = 3;
    private const POLL_MAX_ATTEMPTS = 20;

    /** Batch configuration */
    private const DEFAULT_MAX_TIME = 300;            // 5 minutes
    private const STALE_HOURS = 6;                   // clear abandoned state after 6h

    /** Rate limiting */
    private const DELAY_BETWEEN_DESTINATIONS_MS = 500000; // 500ms

    /** Default state structure */
    private const DEFAULT_STATE = [
        'status'          => 'idle',
        'started_at'      => null,
        'last_run_at'     => null,
        'total'           => 0,
        'processed'       => 0,
        'destination_ids' => [],
        'hotels_updated'  => 0,
        'offers_found'    => 0,
        'search_errors'   => 0,
        'boards_found'    => [],
        'country_codes'   => [],
    ];

    #[\Override]
    public static function getDescription(): string
    {
        return 'Discover available board/meal types per hotel via live search API (batched with resume)';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    #[\Override]
    public function execute(array $params = []): array
    {
        // Handle reset
        if (!empty($params['reset'])) {
            $this->clearState();
            $this->output('State cleared. Ready for fresh discovery run.');
            return ['success' => true, 'action' => 'reset'];
        }

        // Handle status check
        if (!empty($params['status'])) {
            return $this->showStatus();
        }

        // Load existing state
        $state = $this->loadState();

        // Check for in-progress state
        if ($state['status'] === 'in_progress') {
            if ($this->isStale($state)) {
                $this->output("Stale state detected (no activity since {$state['last_run_at']}). Clearing and starting fresh.");
                $this->clearState();
                $state = self::DEFAULT_STATE;
            } else {
                // Resume from where we left off
                $pct = $state['total'] > 0 ? round($state['processed'] / $state['total'] * 100, 1) : 0;
                $this->output("Resuming board discovery: {$state['processed']}/{$state['total']} ({$pct}%) done");
                return $this->processBatch($state, $params);
            }
        }

        // Fresh start: resolve country targets
        $countryCodes = $this->resolveCountryCodes($params);
        $this->output('Sync targets: ' . (empty($countryCodes) ? 'ALL countries' : implode(', ', $countryCodes)));

        // Get unique destination_ids from sphinx_hotels
        $hotelRepo = Container::getHotelRepository();
        $destinationIds = $hotelRepo->getDestinationIdsByCountry($countryCodes);

        if (empty($destinationIds)) {
            $this->output('No destinations found. Run hotel sync first (mode=hotels).');
            return ['success' => true, 'stats' => ['destinations' => 0, 'hotels_updated' => 0]];
        }

        $this->output('Starting board discovery: ' . count($destinationIds) . ' destinations');

        // Create initial state
        $state = [
            'status'          => 'in_progress',
            'started_at'      => date('Y-m-d H:i:s'),
            'last_run_at'     => date('Y-m-d H:i:s'),
            'total'           => count($destinationIds),
            'processed'       => 0,
            'destination_ids' => array_values($destinationIds),
            'hotels_updated'  => 0,
            'offers_found'    => 0,
            'search_errors'   => 0,
            'boards_found'    => [],
            'country_codes'   => $countryCodes,
        ];

        $this->saveState($state);

        return $this->processBatch($state, $params);
    }

    /**
     * Process a batch of destinations, respecting time limits.
     * Saves state after each destination for resume capability.
     * @param array<string, mixed> $state
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function processBatch(array $state, array $params): array
    {
        $maxTime = max(60, (int) ($params['max_time'] ?? self::DEFAULT_MAX_TIME));
        $unlimited = !empty($params['unlimited']);
        $debug = !empty($params['debug']);
        $startTime = time();

        $api = Container::getApi();
        $hotelRepo = Container::getHotelRepository();
        $normalizer = new SphinxNormalizer();

        // Search parameters
        $checkIn = date('Y-m-d', strtotime('+' . self::SEARCH_DAYS_AHEAD . ' days'));
        $checkOut = date('Y-m-d', strtotime('+' . (self::SEARCH_DAYS_AHEAD + self::SEARCH_NIGHTS) . ' days'));
        $currency = ConfigProvider::getDefaultCurrency();

        $offset = $state['processed'];
        $total = $state['total'];
        $processedThisRun = 0;

        while ($offset < $total) {
            // Check time limit (unless unlimited)
            if (!$unlimited && (time() - $startTime) > $maxTime) {
                $this->output('');
                $this->output("Time limit ({$maxTime}s) reached. Saving state for resume.");
                break;
            }

            $destId = $state['destination_ids'][$offset];

            // Initiate search for this destination
            $searchResponse = $api->searchHotels([
                'destination_id' => (int) $destId,
                'check_in'       => $checkIn,
                'check_out'      => $checkOut,
                'occupancy'      => [['adults' => self::SEARCH_ADULTS, 'children_ages' => []]],
                'currency'       => $currency,
            ]);

            if ($searchResponse === null) {
                $state['search_errors']++;
                if ($state['search_errors'] <= 5) {
                    $this->output("  [WARN] Search init failed for destination {$destId}");
                }
                $offset++;
                $state['processed'] = $offset;
                $state['last_run_at'] = date('Y-m-d H:i:s');
                $this->saveState($state);
                $processedThisRun++;
                usleep(self::DELAY_BETWEEN_DESTINATIONS_MS);
                continue;
            }

            $cursor = $searchResponse['cursor'] ?? $searchResponse['search_id'] ?? null;
            if ($cursor === null) {
                $state['search_errors']++;
                $offset++;
                $state['processed'] = $offset;
                $state['last_run_at'] = date('Y-m-d H:i:s');
                $this->saveState($state);
                $processedThisRun++;
                usleep(self::DELAY_BETWEEN_DESTINATIONS_MS);
                continue;
            }

            // Poll for results
            $destOffers = $this->pollResults($api, $cursor);

            if ($debug) {
                $this->output("  [DEBUG] dest={$destId}: search returned " . count($destOffers) . " offers");
            }

            if (!empty($destOffers)) {
                $state['offers_found'] += count($destOffers);

                // Get known hotel_ids for this destination
                $knownHotels = $hotelRepo->getHotelIdsByDestination((int) $destId);

                if ($debug) {
                    $this->output("  [DEBUG] dest={$destId}: " . count($knownHotels) . " known hotels in DB");
                    // Show first 3 offer hotel_ids vs first 3 known hotel_ids for comparison
                    $offerIds = array_unique(array_slice(array_column($destOffers, 'hotel_id'), 0, 5));
                    $knownIds = array_slice(array_keys($knownHotels), 0, 5);
                    $this->output("  [DEBUG] API offer hotel_ids (sample): " . json_encode($offerIds) . " (types: " . implode(',', array_map('gettype', $offerIds)) . ")");
                    $this->output("  [DEBUG] DB known hotel_ids (sample): " . json_encode($knownIds) . " (types: " . implode(',', array_map('gettype', $knownIds)) . ")");
                }

                // Extract and normalize boards, then update DB immediately per destination
                $boardsByHotel = [];
                $debugSkipNoId = 0;
                $debugSkipUnknown = 0;
                $debugSkipNoMeal = 0;
                $debugSkipNormalize = 0;
                foreach ($destOffers as $offer) {
                    $hotelId = (string) ($offer['hotel_id'] ?? '');
                    if ($hotelId === '' || !isset($knownHotels[$hotelId])) {
                        if ($hotelId === '') { $debugSkipNoId++; } else { $debugSkipUnknown++; }
                        continue;
                    }

                    $rawMeal = $offer['meal_type_name'] ?? $offer['meal_name'] ?? $offer['board_name'] ?? '';
                    if ($rawMeal === '') {
                        $debugSkipNoMeal++;
                        continue;
                    }

                    $canonicalCode = $normalizer->normalizeBoardCode($rawMeal);
                    if ($canonicalCode === null) {
                        $debugSkipNormalize++;
                        continue;
                    }

                    if (!isset($boardsByHotel[$hotelId])) {
                        $boardsByHotel[$hotelId] = [];
                    }
                    if (!in_array($canonicalCode, $boardsByHotel[$hotelId], true)) {
                        $boardsByHotel[$hotelId][] = $canonicalCode;
                    }

                    $state['boards_found'][$canonicalCode] = ($state['boards_found'][$canonicalCode] ?? 0) + 1;
                }

                if ($debug) {
                    $this->output("  [DEBUG] dest={$destId}: matched=" . count($boardsByHotel) . " hotels, skip_no_id={$debugSkipNoId}, skip_unknown={$debugSkipUnknown}, skip_no_meal={$debugSkipNoMeal}, skip_normalize={$debugSkipNormalize}");
                    if (!empty($boardsByHotel)) {
                        foreach (array_slice($boardsByHotel, 0, 3, true) as $hid => $codes) {
                            $this->output("  [DEBUG]   hotel {$hid} => " . json_encode($codes));
                        }
                    }
                }

                // Update DB immediately for this destination (not batched at the end)
                if (!empty($boardsByHotel)) {
                    $state['hotels_updated'] += $hotelRepo->updateBoardsBatch($boardsByHotel);
                }
            } elseif ($debug) {
                $this->output("  [DEBUG] dest={$destId}: 0 offers (empty search result)");
            }

            $offset++;
            $state['processed'] = $offset;
            $state['last_run_at'] = date('Y-m-d H:i:s');
            $processedThisRun++;

            // Save state after every destination (critical for resume)
            $this->saveState($state);

            // Progress output every 10 destinations
            if ($offset % 10 === 0 || $offset === $total) {
                $pct = round($offset / $total * 100, 1);
                $this->output("  {$offset}/{$total} ({$pct}%) — {$state['hotels_updated']} hotels updated");
            }

            usleep(self::DELAY_BETWEEN_DESTINATIONS_MS);
        }

        // Check if complete
        if ($offset >= $total) {
            return $this->completeSync($state);
        }

        // Still in progress — report remaining
        $remaining = $total - $offset;
        $elapsed = time() - $startTime;
        $this->output("Processed {$processedThisRun} destinations this run ({$elapsed}s).");
        $this->output("Run again to continue ({$remaining} destinations remaining).");

        return [
            'success'                    => true,
            'status'                     => 'in_progress',
            'total'                      => $total,
            'processed'                  => $offset,
            'remaining'                  => $remaining,
            'processed_this_run'         => $processedThisRun,
            'hotels_updated'             => $state['hotels_updated'],
        ];
    }

    /**
     * Mark sync as completed, log to sphinx_sync_log, clear state.
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function completeSync(array $state): array
    {
        $durationSeconds = !empty($state['started_at'])
            ? time() - strtotime($state['started_at'])
            : 0;

        // Log to sphinx_sync_log
        db_query(
            "INSERT INTO ?:sphinx_sync_log (sync_type, status, items_total, items_synced, items_failed, error_message, sync_mode, duration_ms, started_at, completed_at)
             VALUES ('discover_boards', 'completed', ?i, ?i, ?i, '', 'full', ?i, ?s, NOW())",
            $state['total'],
            $state['hotels_updated'],
            $state['search_errors'],
            $durationSeconds * 1000,
            $state['started_at']
        );

        // Summary output
        $this->output('');
        $this->output('Board Discovery Complete:');
        $this->output("  Destinations searched: {$state['processed']}/{$state['total']}");
        $this->output("  Total search offers: {$state['offers_found']}");
        $this->output("  Hotels updated (boards_json): {$state['hotels_updated']}");
        $this->output("  Search errors: {$state['search_errors']}");
        $this->output('  Duration: ' . $this->formatDuration($durationSeconds));

        if (!empty($state['boards_found'])) {
            arsort($state['boards_found']);
            $this->output('  Board distribution:');
            foreach ($state['boards_found'] as $code => $count) {
                $this->output("    {$code}: {$count} offers");
            }
        }

        // Report unrecognized board names (helps identify new aliases needed)
        $unknownBoards = SphinxNormalizer::getUnknownBoards();
        if (!empty($unknownBoards)) {
            arsort($unknownBoards);
            $this->output('  Unrecognized board names (consider adding aliases):');
            foreach (array_slice($unknownBoards, 0, 20) as $name => $count) {
                $this->output("    \"{$name}\": {$count} occurrences");
            }
            SphinxNormalizer::clearUnknownBoards();
        }

        // Clear state file
        $this->clearState();

        return [
            'success' => true,
            'status'  => 'completed',
            'stats'   => [
                'destinations_total' => $state['total'],
                'hotels_updated'     => $state['hotels_updated'],
                'offers_found'       => $state['offers_found'],
                'search_errors'      => $state['search_errors'],
                'boards_found'       => $state['boards_found'],
                'duration_seconds'   => $durationSeconds,
            ],
        ];
    }

    /**
     * Show current discovery progress without doing any API calls.
     * @return array<string, mixed>
     */
    private function showStatus(): array
    {
        $state = $this->loadState();

        if ($state['status'] === 'idle') {
            $this->output('Board Discovery Status: idle (no sync in progress)');

            // Check last completed run from sphinx_sync_log
            $lastRun = db_get_row(
                "SELECT * FROM ?:sphinx_sync_log WHERE sync_type = 'discover_boards' ORDER BY started_at DESC LIMIT 1"
            );
            if (!empty($lastRun)) {
                $this->output("  Last run: {$lastRun['started_at']} — {$lastRun['items_synced']} hotels updated");
            }

            return ['success' => true, 'status' => 'idle'];
        }

        $pct = $state['total'] > 0 ? round($state['processed'] / $state['total'] * 100, 1) : 0;
        $remaining = $state['total'] - $state['processed'];

        $this->output('Board Discovery Status:');
        $this->output("  Status: {$state['status']}");
        $this->output("  Progress: {$state['processed']}/{$state['total']} ({$pct}%)");
        $this->output("  Remaining: {$remaining} destinations");
        $this->output("  Hotels updated: {$state['hotels_updated']}");
        $this->output("  Offers found: {$state['offers_found']}");
        $this->output("  Search errors: {$state['search_errors']}");
        $this->output("  Countries: " . implode(', ', $state['country_codes'] ?? []));
        $this->output("  Started: {$state['started_at']}");
        $this->output("  Last activity: {$state['last_run_at']}");

        // ETA estimate
        if (!empty($state['started_at']) && $state['processed'] > 0) {
            $elapsed = time() - strtotime($state['started_at']);
            $rate = $state['processed'] / max(1, $elapsed);
            $etaSeconds = (int) ($remaining / max(0.001, $rate));
            $this->output('  ETA: ~' . $this->formatDuration($etaSeconds));
        }

        if ($this->isStale($state)) {
            $this->output('  WARNING: State appears stale (no activity for 6+ hours). Run with reset=1 to clear.');
        }

        return ['success' => true, 'status' => $state['status'], 'processed' => $state['processed'], 'total' => $state['total']];
    }

    /**
     * Poll search results until completed or max attempts reached.
     *
     * @param \Tygh\Addons\SphinxHolidays\SphinxApi $api
     * @return list<mixed>
     */
    private function pollResults($api, string $cursor): array
    {
        $allResults = [];
        $currentCursor = $cursor;

        for ($poll = 0; $poll < self::POLL_MAX_ATTEMPTS; $poll++) {
            if ($poll > 0) {
                sleep(self::POLL_INTERVAL_SECONDS);
            }

            $response = $api->getHotelResults('', $currentCursor);

            if ($response === null) {
                break;
            }

            $results = $response['data'] ?? $response['results'] ?? [];
            foreach ($results as $r) {
                $allResults[] = $r;
            }

            $status = $response['status'] ?? '';
            if ($status === 'completed' || $status === 'done') {
                break;
            }

            $nextCursor = $response['cursor'] ?? $response['next_cursor'] ?? null;

            if ($nextCursor === null && empty($results)) {
                break;
            }

            if ($nextCursor !== null) {
                $currentCursor = $nextCursor;
            } else {
                break;
            }
        }

        return $allResults;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $params
     * @return list<string>
     */
    private function resolveCountryCodes(array $params): array
    {
        if (!empty($params['country'])) {
            return array_filter(array_map(function ($c) {
                return strtoupper(trim($c));
            }, explode(',', $params['country'])));
        }

        return ConfigProvider::getSelectedCountryCodes();
    }

}

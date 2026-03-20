<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Api\SphinxNormalizer;
use Tygh\Addons\SphinxHolidays\Repository\HotelRepository;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\Container;

/**
 * Cron command: discover available board/meal types per hotel via cache API.
 *
 * Calls POST /api/v1/cache/hotels per destination to collect meal_name values,
 * normalizes them to canonical board codes (AI, FB, HB, etc.), and stores
 * the result in sphinx_hotels.boards_json.
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

    public static function getDescription(): string
    {
        return 'Discover available board/meal types per hotel via cache API';
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
        $this->output('Destinations to scan: ' . count($destinationIds));

        if (empty($destinationIds)) {
            $this->output('No destinations found. Run hotel sync first (mode=hotels).');
            return ['success' => true, 'stats' => ['destinations' => 0, 'hotels_updated' => 0]];
        }

        $stats = [
            'destinations_scanned' => 0,
            'destinations_total'   => count($destinationIds),
            'deals_found'          => 0,
            'hotels_updated'       => 0,
            'hotels_matched'       => 0,
            'unique_boards'        => [],
            'api_errors'           => 0,
        ];

        // Aggregate boards per hotel across all destinations
        /** @var array<string, string[]> hotel_id => array of canonical board codes */
        $boardsByHotel = [];

        foreach ($destinationIds as $destId) {
            $stats['destinations_scanned']++;

            $response = $api->cacheHotels(['destination_id' => (int) $destId]);

            if ($response === null) {
                $stats['api_errors']++;
                if ($stats['api_errors'] <= 5) {
                    $this->output("  [WARN] Cache API failed for destination {$destId}");
                }
                usleep(300000);
                continue;
            }

            $deals = $response['data'] ?? $response['results'] ?? $response['hotels'] ?? [];
            $stats['deals_found'] += count($deals);

            // Get known hotel_ids for this destination (to match cache deals)
            $knownHotels = $hotelRepo->getHotelIdsByDestination((int) $destId);

            foreach ($deals as $deal) {
                $dealHotelId = (string) ($deal['id'] ?? $deal['hotel_id'] ?? '');
                if ($dealHotelId === '') {
                    continue;
                }

                // Only process deals that match hotels we have in sphinx_hotels
                if (!isset($knownHotels[$dealHotelId])) {
                    continue;
                }

                $stats['hotels_matched']++;

                // Extract and normalize meal/board value
                $rawMeal = $deal['meal_name'] ?? $deal['board_name'] ?? $deal['board_type'] ?? '';
                if ($rawMeal === '') {
                    continue;
                }

                $canonicalCode = $normalizer->normalizeBoardCode($rawMeal);
                if ($canonicalCode === null) {
                    continue;
                }

                if (!isset($boardsByHotel[$dealHotelId])) {
                    $boardsByHotel[$dealHotelId] = [];
                }
                if (!in_array($canonicalCode, $boardsByHotel[$dealHotelId], true)) {
                    $boardsByHotel[$dealHotelId][] = $canonicalCode;
                }

                $stats['unique_boards'][$canonicalCode] = ($stats['unique_boards'][$canonicalCode] ?? 0) + 1;
            }

            // Progress output every 50 destinations
            if ($stats['destinations_scanned'] % 50 === 0) {
                $this->output("  Progress: {$stats['destinations_scanned']}/{$stats['destinations_total']} destinations, " . count($boardsByHotel) . " hotels with boards");
            }

            usleep(300000); // 300ms rate limit pause
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
            $stats['api_errors'],
            $durationMs
        );

        // Summary output
        $this->output('');
        $this->output('Board Discovery Summary:');
        $this->output("  Destinations scanned: {$stats['destinations_scanned']}/{$stats['destinations_total']}");
        $this->output("  Cache deals found: {$stats['deals_found']}");
        $this->output("  Hotels matched: {$stats['hotels_matched']}");
        $this->output("  Hotels updated (boards_json): {$stats['hotels_updated']}");
        $this->output("  API errors: {$stats['api_errors']}");
        $this->output("  Duration: {$durationMs}ms");

        if (!empty($stats['unique_boards'])) {
            arsort($stats['unique_boards']);
            $this->output('  Board distribution:');
            foreach ($stats['unique_boards'] as $code => $count) {
                $this->output("    {$code}: {$count} deals");
            }
        }

        return ['success' => true, 'stats' => $stats];
    }

    private function output(string $message): void
    {
        if ($this->outputCallback !== null) {
            ($this->outputCallback)($message);
        }
    }
}

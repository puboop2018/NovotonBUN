<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\Api\SphinxNormalizer;
use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Addons\SphinxHolidays\Repository\DestinationRepository;
use Tygh\Addons\SphinxHolidays\Repository\HotelRepository;

/**
 * Fetches hotels from the Sphinx API and syncs them into the local DB.
 *
 * Strategy: instead of fetching all 100k+ hotels at once, we fetch
 * per-destination — using the destination IDs from the selected countries
 * in the synced sphinx_destinations table. This keeps API calls manageable.
 *
 * Flow:
 *   1. Resolve selected country codes → destination IDs
 *   2. For each destination, paginate through getHotels()
 *   3. Normalize and filter each hotel
 *   4. Batch upsert into sphinx_hotels
 *   5. Mark stale hotels as inactive
 */
class HotelSyncService
{
    private SphinxApi $api;
    private HotelRepository $hotelRepo;
    private DestinationRepository $destRepo;
    private SphinxNormalizer $normalizer;

    /** @var callable|null */
    private $outputCallback = null;

    public function __construct(
        SphinxApi $api,
        HotelRepository $hotelRepo,
        DestinationRepository $destRepo
    ) {
        $this->api = $api;
        $this->hotelRepo = $hotelRepo;
        $this->destRepo = $destRepo;
        $this->normalizer = Container::getNormalizer();
    }

    public function setOutputCallback(callable $callback): void
    {
        $this->outputCallback = $callback;
    }

    /**
     * Run hotel sync filtered by country codes and/or specific destination IDs.
     *
     * @param string[] $countryCodes Country codes to sync (e.g. ['GR', 'BG'])
     * @param int[] $extraDestinationIds Specific destination IDs to include (region/city level targeting)
     * @param bool $fullSync Force full re-fetch (ignores updated_since)
     * @return array{success: bool, total: int, synced: int, skipped: int, failed: int, duration_ms: int, error: string, sync_mode: string}
     */
    public function sync(array $countryCodes = [], array $extraDestinationIds = [], bool $fullSync = false): array
    {
        $startMs = (int) (microtime(true) * 1000);
        $logId = $this->logStart('hotels');

        $stats = [
            'success'     => false,
            'total'       => 0,
            'synced'      => 0,
            'skipped'     => 0,
            'failed'      => 0,
            'duration_ms' => 0,
            'error'       => '',
            'sync_mode'   => 'full',
        ];

        try {
            if (empty($countryCodes) && empty($extraDestinationIds)) {
                $countryCodes = ConfigProvider::getSelectedCountryCodes();
                $extraDestinationIds = ConfigProvider::getAllowedDestinationIds();
            }

            if (empty($countryCodes) && empty($extraDestinationIds)) {
                $stats['error'] = 'No sync targets configured. Configure destinations in Sphinx Holidays > Whitelist.';
                $this->output('ERROR: ' . $stats['error']);
                $this->logComplete($logId, 'failed', $stats);
                return $stats;
            }

            // Determine sync mode
            $updatedSince = null;
            if (!$fullSync) {
                $lastSynced = $this->hotelRepo->getLastSyncedAt();
                if ($lastSynced !== null) {
                    $updatedSince = $lastSynced;
                    $stats['sync_mode'] = 'incremental';
                }
            }

            $labels = [];
            if (!empty($countryCodes)) {
                $labels[] = 'countries: ' . implode(', ', $countryCodes);
            }
            if (!empty($extraDestinationIds)) {
                $labels[] = 'destination IDs: ' . implode(', ', $extraDestinationIds);
            }
            $modeLabel = $updatedSince !== null ? "incremental since {$updatedSince}" : 'full';
            $this->output("Hotel sync starting ({$modeLabel}) for " . implode('; ', $labels));

            // Get destination IDs for selected countries (all types under those countries)
            $destinationIds = $this->resolveDestinationIds($countryCodes, $extraDestinationIds);

            if (empty($destinationIds)) {
                $stats['error'] = 'No destinations found for selected countries. Sync destinations first.';
                $this->output('ERROR: ' . $stats['error']);
                $this->logComplete($logId, 'failed', $stats);
                return $stats;
            }

            $this->output('Found ' . count($destinationIds) . ' destination(s) to sync hotels for');

            // Fetch and sync hotels per country
            foreach ($countryCodes as $countryCode) {
                $countryStats = $this->syncCountry($countryCode, $destinationIds[$countryCode] ?? [], $updatedSince);
                $stats['total'] += $countryStats['total'];
                $stats['synced'] += $countryStats['synced'];
                $stats['skipped'] += $countryStats['skipped'];
                $stats['failed'] += $countryStats['failed'];

                if (!empty($countryStats['error'])) {
                    $stats['error'] .= ($stats['error'] ? '; ' : '') . $countryStats['error'];
                }
            }

            $stats['success'] = ($stats['synced'] > 0 || $stats['total'] === 0);
            $this->output("Sync complete: {$stats['synced']}/{$stats['total']} hotels synced, {$stats['skipped']} skipped ({$stats['sync_mode']}).");

        } catch (\Throwable $e) {
            $stats['error'] = $e->getMessage();
            $this->output('EXCEPTION: ' . $e->getMessage());

            fn_log_event('general', 'runtime', [
                'message' => 'Sphinx hotel sync failed: ' . $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
        }

        $stats['duration_ms'] = (int) (microtime(true) * 1000) - $startMs;

        // Capture rate limit info from the HTTP client
        $httpClient = $this->api->getHttpClient();
        $stats['rate_limit'] = $httpClient->getRateLimitState();
        $stats['rate_limit_hits'] = $httpClient->getRateLimitHitCount();

        $this->logComplete($logId, $stats['success'] ? 'completed' : 'failed', $stats);

        return $stats;
    }

    /**
     * Sync hotels for a single country.
     *
     * Uses server-side destination_ids filtering to reduce API payload.
     * When updatedSince is set (incremental sync), stale detection is skipped.
     *
     * @param string $countryCode
     * @param int[] $destinationIds Destination IDs belonging to this country
     * @param string|null $updatedSince Only fetch hotels updated since this datetime
     */
    private function syncCountry(string $countryCode, array $destinationIds, ?string $updatedSince = null): array
    {
        $stats = ['total' => 0, 'synced' => 0, 'skipped' => 0, 'failed' => 0, 'error' => ''];

        if (empty($destinationIds)) {
            $this->output("  {$countryCode}: no destinations, skipping");
            return $stats;
        }

        $this->output("  {$countryCode}: syncing from " . count($destinationIds) . ' destination(s)...');

        $activeIds = [];
        $upsertBatchSize = 100;
        $perPage = 1000;

        // Chunk destination IDs to avoid URL length overflow.
        // 1887 IDs × ~25 chars each ≈ 47KB query string, which exceeds HTTP/2
        // frame limits (~16KB) and causes "Error in the HTTP2 framing layer".
        $destIdChunks = array_chunk($destinationIds, 200);

        foreach ($destIdChunks as $chunkIdx => $destIdChunk) {
            if (count($destIdChunks) > 1) {
                $this->output("    Destination chunk " . ($chunkIdx + 1) . '/' . count($destIdChunks) . ' (' . count($destIdChunk) . ' IDs)');
            }

            $page = 1;

            // Stream-and-upsert: fetch each page and upsert immediately
            while (true) {
                $response = $this->api->getHotels($page, $perPage, $updatedSince, $destIdChunk);

                if ($response === null) {
                    $stats['error'] = "API request failed on page {$page}: " . $this->api->getHttpClient()->getLastError();
                    $this->output('    ERROR: ' . $stats['error']);
                    break;
                }

                $items = $response['data'] ?? $response['items'] ?? $response;
                if (isset($response[0]) && !isset($response['data'])) {
                    $items = $response;
                }

                if (!is_array($items) || empty($items)) {
                    break;
                }

                $pageBatch = [];
                foreach ($items as $raw) {
                    $normalized = $this->normalizeHotel($raw);
                    if ($normalized === null) {
                        $stats['skipped']++;
                        continue;
                    }

                    // Safety filter: verify country_code matches
                    $hotelCountry = strtoupper($normalized['country_code']);
                    if ($hotelCountry !== '' && $hotelCountry !== $countryCode) {
                        $stats['skipped']++;
                        continue;
                    }

                    $pageBatch[] = $normalized;
                    $activeIds[] = $normalized['hotel_id'];
                    $stats['total']++;

                    if (count($pageBatch) >= $upsertBatchSize) {
                        $affected = $this->hotelRepo->upsertBatch($pageBatch);
                        $stats['synced'] += $affected;
                        $pageBatch = [];
                    }
                }

                if (!empty($pageBatch)) {
                    $affected = $this->hotelRepo->upsertBatch($pageBatch);
                    $stats['synced'] += $affected;
                }

                // Pagination: check for more pages
                $lastPage = $response['last_page'] ?? $response['meta']['last_page'] ?? null;
                $totalItems = $response['total'] ?? $response['meta']['total'] ?? null;

                if ($lastPage !== null && $page >= (int) $lastPage) {
                    break;
                }
                if ($totalItems !== null && ($stats['total'] + $stats['skipped']) >= (int) $totalItems) {
                    break;
                }
                if (count($items) < $perPage) {
                    break;
                }

                $page++;
            }
        }

        if ($stats['total'] === 0) {
            if ($updatedSince !== null) {
                $this->output("    {$countryCode}: no hotels updated since {$updatedSince}");
            } else {
                $this->output("    {$countryCode}: 0 hotels matched");
            }
            return $stats;
        }

        // Only mark stale hotels on full sync — incremental returns only changed items
        if ($updatedSince === null) {
            $inactive = $this->hotelRepo->markInactiveExcept($activeIds, $countryCode);
            if ($inactive > 0) {
                $this->output("    Marked {$inactive} stale hotel(s) as inactive");
            }
        }

        $this->output("    {$countryCode}: {$stats['synced']}/{$stats['total']} hotels synced");

        return $stats;
    }

    /**
     * Resolve destination IDs grouped by country code.
     *
     * @param string[] $countryCodes
     * @param int[] $extraDestIds Additional specific destination IDs (e.g. region/city)
     * @return array<string, int[]> Country code => [destination_id, ...]
     */
    private function resolveDestinationIds(array $countryCodes, array $extraDestIds = []): array
    {
        $result = [];

        // Resolve by country code
        foreach ($countryCodes as $code) {
            $ids = db_get_fields(
                "SELECT destination_id FROM ?:sphinx_destinations WHERE country_code = ?s",
                $code
            );

            if (!empty($ids)) {
                $result[$code] = array_map('intval', $ids);
            }
        }

        // Add specific destination IDs with their children
        if (!empty($extraDestIds)) {
            foreach ($extraDestIds as $destId) {
                $row = db_get_row(
                    "SELECT destination_id, country_code FROM ?:sphinx_destinations WHERE destination_id = ?i",
                    $destId
                );
                if ($row) {
                    $cc = $row['country_code'] ?: 'CUSTOM';
                    if (!isset($result[$cc])) {
                        $result[$cc] = [];
                    }
                    $result[$cc][] = (int) $row['destination_id'];

                    // Also include all children of this destination
                    $children = db_get_fields(
                        "SELECT destination_id FROM ?:sphinx_destinations WHERE parent_id = ?i",
                        $destId
                    );
                    foreach ($children as $childId) {
                        $result[$cc][] = (int) $childId;
                    }

                    $result[$cc] = array_unique($result[$cc]);
                }
            }
        }

        return $result;
    }

    /**
     * Normalize a raw API hotel into the DB column format.
     *
     * Sphinx static API returns: {id, destination_id, name, type, classification,
     * latitude, longitude, description, address, images, facilities, external_ids}
     */
    private function normalizeHotel(array $raw): ?array
    {
        $id = (string) ($raw['id'] ?? '');
        if ($id === '') {
            return null;
        }

        $name = (string) ($raw['name'] ?? '');
        if ($name === '') {
            return null;
        }

        $propertyType = $this->normalizer->normalizePropertyType(
            $raw['type'] ?? 'hotel'
        ) ?? 'hotel';

        $classification = (int) ($raw['classification'] ?? 0);
        if ($classification < 0 || $classification > 5) {
            $classification = 0;
        }

        return [
            'hotel_id'          => $id,
            'name'              => $name,
            'classification'    => $classification,
            'property_type'     => $propertyType,
            'destination_id'    => (int) ($raw['destination_id'] ?? 0),
            'destination_name'  => (string) ($raw['destination_name'] ?? ''),
            'region_id'         => (int) ($raw['region_id'] ?? 0),
            'region_name'       => (string) ($raw['region_name'] ?? ''),
            'country_code'      => strtoupper((string) ($raw['country_code'] ?? '')),
            'country_name'      => (string) ($raw['country_name'] ?? ''),
            'latitude'          => (float) ($raw['latitude'] ?? 0),
            'longitude'         => (float) ($raw['longitude'] ?? 0),
            'description'       => (string) ($raw['description'] ?? ''),
            'short_description' => (string) ($raw['short_description'] ?? ''),
            'image_url'         => (string) ($raw['images'][0]['url'] ?? ''),
            'facilities_json'   => !empty($raw['facilities']) ? json_encode($raw['facilities']) : '[]',
        ];
    }

    private function logStart(string $syncType): int
    {
        db_query(
            "INSERT INTO ?:sphinx_sync_log (sync_type, status, started_at) VALUES (?s, 'started', NOW())",
            $syncType
        );
        return (int) db_get_field("SELECT LAST_INSERT_ID()");
    }

    private function logComplete(int $logId, string $status, array $stats): void
    {
        if ($logId <= 0) {
            return;
        }

        db_query(
            "UPDATE ?:sphinx_sync_log SET
                status = ?s,
                items_total = ?i,
                items_synced = ?i,
                items_failed = ?i,
                error_message = ?s,
                duration_ms = ?i,
                rate_limit_hits = ?i,
                sync_mode = ?s,
                completed_at = NOW()
             WHERE log_id = ?i",
            $status,
            $stats['total'] ?? 0,
            $stats['synced'] ?? 0,
            $stats['failed'] ?? 0,
            $stats['error'] ?? '',
            $stats['duration_ms'] ?? 0,
            $stats['rate_limit_hits'] ?? 0,
            $stats['sync_mode'] ?? 'full',
            $logId
        );
    }

    private function output(string $message): void
    {
        if ($this->outputCallback !== null) {
            ($this->outputCallback)($message);
        }
    }
}

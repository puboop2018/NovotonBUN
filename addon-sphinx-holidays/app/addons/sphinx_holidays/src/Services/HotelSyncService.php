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
class HotelSyncService extends AbstractSyncService
{
    private const UPSERT_BATCH_SIZE = 100;
    private const DEST_CHUNK_SIZE = 200;
    private const PER_PAGE = 1000;

    private HotelRepository $hotelRepo;
    private DestinationRepository $destRepo;
    private SphinxNormalizer $normalizer;

    public function __construct(
        SphinxApi $api,
        HotelRepository $hotelRepo,
        DestinationRepository $destRepo
    ) {
        parent::__construct($api);
        $this->hotelRepo = $hotelRepo;
        $this->destRepo = $destRepo;
        $this->normalizer = Container::getNormalizer();
    }

    protected function getSyncType(): string
    {
        return 'hotels';
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
        return $this->runSync($fullSync, [
            'country_codes'    => $countryCodes,
            'destination_ids'  => $extraDestinationIds,
        ]);
    }

    protected function doSync(bool $fullSync, array $stats, array $context): array
    {
        $countryCodes = $context['country_codes'] ?? [];
        $extraDestinationIds = $context['destination_ids'] ?? [];

        if (empty($countryCodes) && empty($extraDestinationIds)) {
            $countryCodes = ConfigProvider::getSelectedCountryCodes();
            $extraDestinationIds = ConfigProvider::getAllowedDestinationIds();
        }

        if (empty($countryCodes) && empty($extraDestinationIds)) {
            $stats['error'] = 'No sync targets configured. Configure destinations in Sphinx Holidays > Whitelist.';
            $this->output('ERROR: ' . $stats['error']);
            return $stats;
        }

        $labels = [];
        if (!empty($countryCodes)) {
            $labels[] = 'countries: ' . implode(', ', $countryCodes);
        }
        if (!empty($extraDestinationIds)) {
            $labels[] = 'destination IDs: ' . implode(', ', $extraDestinationIds);
        }
        $this->output("Hotel sync starting for " . implode('; ', $labels));

        // Get destination IDs for selected countries (all types under those countries)
        $destinationIds = $this->resolveDestinationIds($countryCodes, $extraDestinationIds);

        if (empty($destinationIds)) {
            $stats['error'] = 'No destinations found for selected countries. Sync destinations first.';
            $this->output('ERROR: ' . $stats['error']);
            return $stats;
        }

        $this->output('Found ' . count($destinationIds) . ' destination(s) to sync hotels for');

        // Preload destination hierarchy for country/region/city resolution during sync
        if (!$this->destRepo->loadParentLookup()) {
            $this->output('WARNING: sphinx_destinations is empty — country/region enrichment will use sync context only. Run destination sync first for best results.');
        }

        // Fetch and sync hotels per country (with per-country incremental timestamps)
        foreach (array_keys($destinationIds) as $countryCode) {
            $updatedSince = null;
            if (!$fullSync) {
                $lastSynced = $this->hotelRepo->getLastSyncedAt($countryCode);
                if ($lastSynced !== null) {
                    $updatedSince = $lastSynced;
                }
            }
            $modeLabel = $updatedSince !== null ? "incremental since {$updatedSince}" : 'full';
            $this->output("  {$countryCode}: sync mode: {$modeLabel}");

            $countryStats = $this->syncCountry($countryCode, $destinationIds[$countryCode] ?? [], $updatedSince);
            $stats['total'] += $countryStats['total'];
            $stats['synced'] += $countryStats['synced'];
            $stats['skipped'] += $countryStats['skipped'];
            $stats['failed'] += $countryStats['failed'];

            if (!empty($countryStats['error'])) {
                $stats['error'] .= ($stats['error'] ? '; ' : '') . $countryStats['error'];
            }
        }

        // Set overall sync_mode label based on what actually happened
        $stats['sync_mode'] = $fullSync ? 'full' : 'per-country incremental';
        $stats['success'] = ($stats['synced'] > 0 || $stats['total'] === 0);
        $this->output("Sync complete: {$stats['synced']}/{$stats['total']} hotels synced, {$stats['skipped']} skipped.");

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

        // Record sync start time for stale detection (replaces $activeIds array)
        $syncStartedAt = date('Y-m-d H:i:s');

        // Chunk destination IDs to avoid URL length overflow.
        // Large ID lists create query strings that can exceed server limits.
        $destIdChunks = array_chunk($destinationIds, self::DEST_CHUNK_SIZE);

        foreach ($destIdChunks as $chunkIdx => $destIdChunk) {
            // Wait for circuit breaker cooldown before attempting next chunk
            $httpClient = $this->api->getHttpClient();
            if ($chunkIdx > 0 && $httpClient->isCircuitOpen()) {
                $waitSecs = $httpClient->getCircuitBreakerTimeout() + 5;
                $this->output("    Circuit breaker open. Waiting {$waitSecs}s before retry...");
                sleep($waitSecs);
                if ($httpClient->isCircuitOpen()) {
                    $this->output('    Circuit breaker still open after wait. Aborting remaining chunks.');
                    $stats['error'] = 'Circuit breaker open — API unavailable';
                    break;
                }
            }

            if (count($destIdChunks) > 1) {
                $this->output("    Destination chunk " . ($chunkIdx + 1) . '/' . count($destIdChunks) . ' (' . count($destIdChunk) . ' IDs)');
            }

            $page = 1;

            // Stream-and-upsert: fetch each page and upsert immediately
            while (true) {
                $response = $this->api->getHotels($page, self::PER_PAGE, $updatedSince, $destIdChunk);

                if ($response === null) {
                    $stats['error'] = "API request failed on page {$page}: " . $this->api->getHttpClient()->getLastError();
                    $this->output('    ERROR: ' . $stats['error']);
                    break;
                }

                $items = $this->extractItems($response);

                if (empty($items)) {
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
                    $stats['total']++;
                }

                // Batch-resolve country/region/city from destination hierarchy
                $pageBatch = $this->enrichFromHierarchy($pageBatch, $countryCode);

                // Upsert in sub-batches
                foreach (array_chunk($pageBatch, self::UPSERT_BATCH_SIZE) as $upsertChunk) {
                    $stats['synced'] += $this->hotelRepo->upsertBatch($upsertChunk);
                }

                if (!$this->hasMorePages($response, $page, self::PER_PAGE, $stats['total'] + $stats['skipped'])) {
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
        // Uses timestamp comparison instead of NOT IN (id_list) to avoid memory/SQL limits at scale
        if ($updatedSince === null) {
            $inactive = $this->hotelRepo->markInactiveBefore($syncStartedAt, $countryCode);
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
    private function resolveDestinationIds(array $countryCodes, array $allowedDestIds = []): array
    {
        $result = [];

        if (!empty($allowedDestIds)) {
            // Group the already-resolved whitelist destination IDs by country code.
            // These IDs come from ConfigProvider::getAllowedDestinationIds() which
            // correctly respects selection_type ('all' vs 'specific').
            $rows = db_get_array(
                "SELECT destination_id, country_code FROM ?:sphinx_destinations WHERE destination_id IN (?n)",
                $allowedDestIds
            );

            foreach ($rows as $row) {
                $cc = $row['country_code'] ?: 'CUSTOM';
                $result[$cc][] = (int) $row['destination_id'];
            }

            foreach ($result as $cc => $ids) {
                $result[$cc] = array_values(array_unique($ids));
            }
        } elseif (!empty($countryCodes)) {
            // Fallback: when called with explicit country codes (no whitelist IDs),
            // include all destinations for those countries.
            foreach ($countryCodes as $code) {
                $ids = db_get_fields(
                    "SELECT destination_id FROM ?:sphinx_destinations WHERE country_code = ?s",
                    $code
                );
                if (!empty($ids)) {
                    $result[$code] = array_map('intval', $ids);
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

        $name = html_entity_decode((string) ($raw['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
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

        // Detect adults-only from hotel name (API doesn't provide a dedicated field)
        // Matches: "Adults Only", "Adult Only", "+18", "+16", "(18+)", "(16+)"
        $isAdultsOnly = preg_match('/\badults?\s*only\b|\(\s*\+\s*1[68]\s*\)|\(\s*1[68]\s*\+\s*\)/i', $name) ? 'Y' : 'N';

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
            'description'       => html_entity_decode((string) ($raw['description'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'short_description' => html_entity_decode((string) ($raw['short_description'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'image_url'         => (string) ($raw['images'][0]['url'] ?? ''),
            'images_json'       => !empty($raw['images']) ? json_encode($raw['images']) : '[]',
            'facilities_json'   => !empty($raw['facilities']) ? json_encode($raw['facilities']) : '[]',
            'is_adults_only'    => $isAdultsOnly,
        ];
    }

    /**
     * Enrich a batch of normalized hotels with country/region data from the destination hierarchy.
     *
     * Uses the preloaded parentLookup (in-memory, no DB queries) to resolve country_code,
     * country_name, and region_name from each hotel's destination_id. Falls back to the
     * sync context $countryCode when destinations haven't been synced yet.
     *
     * @param array[] $hotels Normalized hotel rows
     * @param string $countryCode Sync context country code (fallback)
     * @return array[] Hotels with enriched country/region data
     */
    private function enrichFromHierarchy(array $hotels, string $countryCode): array
    {
        if (empty($hotels)) {
            return $hotels;
        }

        // Collect unique destination IDs for batch resolution
        $destIds = array_filter(array_unique(array_column($hotels, 'destination_id')));
        $hierarchyMap = !empty($destIds) ? $this->destRepo->resolveHierarchies($destIds) : [];

        foreach ($hotels as &$hotel) {
            $destId = (int) $hotel['destination_id'];
            $hierarchy = $hierarchyMap[$destId] ?? [];

            // Primary: derive from destination hierarchy
            if (!empty($hierarchy['country_code'])) {
                $hotel['country_code'] = $hierarchy['country_code'];
            }
            if (!empty($hierarchy['country'])) {
                $hotel['country_name'] = $hierarchy['country'];
            }
            if (!empty($hierarchy['region']) && $hotel['region_name'] === '') {
                $hotel['region_name'] = $hierarchy['region'];
            }
            if (!empty($hierarchy['region_id']) && (int) $hotel['region_id'] === 0) {
                $hotel['region_id'] = (int) $hierarchy['region_id'];
            }

            // Fallback: sync context country code (when destinations aren't synced yet)
            if ($hotel['country_code'] === '') {
                $hotel['country_code'] = $countryCode;
            }
        }
        unset($hotel);

        return $hotels;
    }
}

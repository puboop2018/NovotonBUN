<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\Api\SphinxNormalizer;
use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Addons\SphinxHolidays\Repository\DestinationRepository;
use Tygh\Addons\SphinxHolidays\Contracts\HotelSyncServiceInterface;
use Tygh\Addons\SphinxHolidays\Repository\HotelRepository;
use Tygh\Addons\TravelCore\Helpers\ValidationHelpers;

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
class HotelSyncService extends AbstractSyncService implements HotelSyncServiceInterface
{
    private const int UPSERT_BATCH_SIZE = 100;
    private const int DEST_CHUNK_SIZE = 200;
    private const int PER_PAGE = 1000;

    private readonly SphinxNormalizer $normalizer;

    public function __construct(
        SphinxApi $api,
        private readonly HotelRepository $hotelRepo,
        private readonly DestinationRepository $destRepo,
        ?SphinxNormalizer $normalizer = null,
    ) {
        parent::__construct($api);
        $this->normalizer = $normalizer ?? Container::getNormalizer();
    }

    #[\Override]
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
    #[\Override]
    public function sync(array $countryCodes = [], array $extraDestinationIds = [], bool $fullSync = false): array
    {
        return $this->runSync($fullSync, [
            'country_codes'    => $countryCodes,
            'destination_ids'  => $extraDestinationIds,
        ]);
    }

    /**
     * @param array<string, mixed> $stats
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    #[\Override]
    protected function doSync(bool $fullSync, array $stats, array $context): array
    {
        $countryCodes = is_array($context['country_codes'] ?? null) ? $context['country_codes'] : [];
        $extraDestinationIds = is_array($context['destination_ids'] ?? null) ? $context['destination_ids'] : [];

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
            $countryCodeStr = ValidationHelpers::toString($countryCode);
            $updatedSince = null;
            if (!$fullSync) {
                $lastSynced = $this->hotelRepo->getLastSyncedAt($countryCodeStr);
                if ($lastSynced !== null) {
                    $updatedSince = $lastSynced;
                }
            }
            $modeLabel = $updatedSince !== null ? "incremental since {$updatedSince}" : 'full';
            $this->output("  {$countryCodeStr}: sync mode: {$modeLabel}");

            /** @var array<string, mixed> $destIdsForCountry */
            $destIdsForCountry = is_array($destinationIds[$countryCode] ?? null) ? $destinationIds[$countryCode] : [];
            $countryStats = $this->syncCountry($countryCodeStr, $destIdsForCountry, $updatedSince);
            $stats['total'] = ValidationHelpers::toInt($stats['total'] ?? 0) + ValidationHelpers::toInt($countryStats['total'] ?? 0);
            $stats['synced'] = ValidationHelpers::toInt($stats['synced'] ?? 0) + ValidationHelpers::toInt($countryStats['synced'] ?? 0);
            $stats['skipped'] = ValidationHelpers::toInt($stats['skipped'] ?? 0) + ValidationHelpers::toInt($countryStats['skipped'] ?? 0);
            $stats['failed'] = ValidationHelpers::toInt($stats['failed'] ?? 0) + ValidationHelpers::toInt($countryStats['failed'] ?? 0);

            $cError = ValidationHelpers::toString($countryStats['error'] ?? '');
            if (!empty($cError)) {
                $currentError = ValidationHelpers::toString($stats['error'] ?? '');
                $stats['error'] = $currentError . ($currentError !== '' ? '; ' : '') . $cError;
            }
        }

        // Set overall sync_mode label based on what actually happened
        $stats['sync_mode'] = $fullSync ? 'full' : 'per-country incremental';
        $sSynced = ValidationHelpers::toInt($stats['synced'] ?? 0);
        $sTotal = ValidationHelpers::toInt($stats['total'] ?? 0);
        $sSkipped = ValidationHelpers::toInt($stats['skipped'] ?? 0);
        $stats['success'] = ($sSynced > 0 || $sTotal === 0);
        $this->output("Sync complete: {$sSynced}/{$sTotal} hotels synced, {$sSkipped} skipped.");

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
     * @return array<string, mixed>
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
                /** @var bool $stillOpen */
                $stillOpen = $httpClient->isCircuitOpen();
                if ($stillOpen) {
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
                    if (!is_array($raw)) {
                        continue;
                    }
                    $normalized = $this->normalizeHotel($raw);
                    if ($normalized === null) {
                        $stats['skipped'] = ValidationHelpers::toInt($stats['skipped'] ?? 0) + 1;
                        continue;
                    }

                    // Safety filter: verify country_code matches
                    $hotelCountry = strtoupper(ValidationHelpers::toString($normalized['country_code'] ?? ''));
                    if ($hotelCountry !== '' && $hotelCountry !== $countryCode) {
                        $stats['skipped'] = ValidationHelpers::toInt($stats['skipped'] ?? 0) + 1;
                        continue;
                    }

                    $pageBatch[] = $normalized;
                    $stats['total'] = ValidationHelpers::toInt($stats['total'] ?? 0) + 1;
                }

                // Batch-resolve country/region/city from destination hierarchy
                $pageBatch = $this->enrichFromHierarchy($pageBatch, $countryCode);

                // Upsert in sub-batches
                foreach (array_chunk($pageBatch, self::UPSERT_BATCH_SIZE) as $upsertChunk) {
                    $stats['synced'] = ValidationHelpers::toInt($stats['synced'] ?? 0) + $this->hotelRepo->upsertBatch($upsertChunk);
                }

                $totalPlusSkipped = ValidationHelpers::toInt($stats['total'] ?? 0) + ValidationHelpers::toInt($stats['skipped'] ?? 0);
                if (!$this->hasMorePages($response, $page, self::PER_PAGE, $totalPlusSkipped)) {
                    break;
                }

                $page++;
            }
        }

        if (ValidationHelpers::toInt($stats['total'] ?? 0) === 0) {
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
     * @param int[] $allowedDestIds Destination IDs already resolved from the whitelist
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
     * @param array<string, mixed> $raw
     * @return array<string, mixed>|null
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

        $address = $raw['address'] ?? [];

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
            'address'           => trim((string) ($address['street'] ?? '')),
            'phone'             => trim((string) ($address['phone'] ?? '')),
            'email'             => trim((string) ($address['email'] ?? '')),
            'website'           => trim((string) ($address['website'] ?? '')),
            'rating'            => isset($raw['rating']) ? (float) $raw['rating'] : null,
            'rating_count'      => isset($raw['rating_count']) ? (int) $raw['rating_count'] : null,
        ];
    }

    /**
     * Re-link existing CS-Cart products (with the configured prefix) back to sphinx_hotels.
     *
     * After a fresh addon reinstall the sphinx_hotels table is empty but CS-Cart
     * still has products with SPX* codes. This method fetches only those hotels
     * from the Sphinx API one-by-one and re-inserts them with the product link.
     *
     * @param callable|null $progressCallback fn(int $current, int $total, string $hotelId)
     * @return array{total: int, linked: int, skipped: int, not_found: int, errors: int}
     */
    public function relinkExistingProducts(?callable $progressCallback = null): array
    {
        $prefix = ConfigProvider::getProductCodePrefix();
        $prefixLen = strlen($prefix);

        $spxProducts = db_get_array(
            "SELECT product_id, product_code FROM ?:products WHERE product_code LIKE ?l",
            $prefix . '%'
        );

        $stats = ['total' => count($spxProducts), 'linked' => 0, 'skipped' => 0, 'not_found' => 0, 'errors' => 0];

        if (empty($spxProducts)) {
            return $stats;
        }

        foreach ($spxProducts as $i => $product) {
            $hotelId = substr($product['product_code'], $prefixLen);
            if ($progressCallback) {
                $progressCallback($i + 1, $stats['total'], $hotelId);
            }

            // Skip if already linked in sphinx_hotels
            $existing = $this->hotelRepo->getById($hotelId);
            if ($existing && !empty($existing['product_id'])) {
                $stats['skipped']++;
                continue;
            }

            // Fetch single hotel from API
            try {
                $raw = $this->api->getHotel($hotelId);
            } catch (\Throwable $e) {
                $stats['errors']++;
                continue;
            }

            if (empty($raw)) {
                $stats['not_found']++;
                continue;
            }

            // Unwrap if API returns {data: {...}}
            if (isset($raw['data']) && is_array($raw['data']) && !isset($raw['id'])) {
                $raw = $raw['data'];
            }

            $normalized = $this->normalizeHotel($raw);
            if ($normalized === null) {
                $stats['errors']++;
                continue;
            }

            $this->hotelRepo->upsertBatch([$normalized]);
            $this->hotelRepo->linkToProduct($hotelId, (int) $product['product_id']);
            $stats['linked']++;
        }

        return $stats;
    }

    /**
     * Enrich a batch of normalized hotels with country/region data from the destination hierarchy.
     *
     * Uses the preloaded parentLookup (in-memory, no DB queries) to resolve country_code,
     * country_name, and region_name from each hotel's destination_id. Falls back to the
     * sync context $countryCode when destinations haven't been synced yet.
     *
     * @param list<array<string, mixed>> $hotels Normalized hotel rows
     * @param string $countryCode Sync context country code (fallback)
     * @return list<array<string, mixed>> Hotels with enriched country/region data
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
            if (!empty($hierarchy['city']) && $hotel['destination_name'] === '') {
                $hotel['destination_name'] = $hierarchy['city'];
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

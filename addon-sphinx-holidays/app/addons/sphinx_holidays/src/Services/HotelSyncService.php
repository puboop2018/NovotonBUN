<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\Api\SphinxNormalizer;
use Tygh\Addons\SphinxHolidays\Contracts\HotelSyncServiceInterface;
use Tygh\Addons\SphinxHolidays\Helpers\OfferAvailability;
use Tygh\Addons\SphinxHolidays\Repository\DestinationRepository;
use Tygh\Addons\SphinxHolidays\Repository\HotelRepository;
use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
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

    // Availability probe parameters (mirror DiscoverBoardsCommand): a single
    // search window per destination, far enough out to have inventory loaded.
    private const int AVAILABILITY_NIGHTS = 7;
    private const int AVAILABILITY_ADULTS = 2;
    private const int AVAILABILITY_DAYS_AHEAD = 30;
    private const int AVAILABILITY_POLL_INTERVAL = 3;     // seconds between result polls
    private const int AVAILABILITY_POLL_DEADLINE = 60;    // hard cap per destination
    private const int AVAILABILITY_DEST_DELAY_US = 500000; // 500ms between destinations

    private readonly SphinxNormalizer $normalizer;

    /** Whether the availability gate runs this sync (resolved in doSync). */
    private bool $availabilityGateEnabled = true;

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
     * @param bool|null $availabilityGateOverride Force the availability gate on/off for this run (null = use setting)
     * @return array{success: bool, total: int, synced: int, skipped: int, failed: int, duration_ms: int, error: string, sync_mode: string}
     */
    #[\Override]
    public function sync(
        array $countryCodes = [],
        array $extraDestinationIds = [],
        bool $fullSync = false,
        ?bool $availabilityGateOverride = null,
    ): array {
        return $this->runSync($fullSync, [
            'country_codes' => $countryCodes,
            'destination_ids' => $extraDestinationIds,
            'availability_gate_override' => $availabilityGateOverride,
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
        $countryCodes = TypeCoerce::toStringList($context['country_codes'] ?? null);
        $extraDestinationIds = TypeCoerce::toIntList($context['destination_ids'] ?? null);

        // Availability gate: per-run override (cron &availability_gate=0|1) wins,
        // otherwise fall back to the addon setting (default Y).
        $gateOverride = $context['availability_gate_override'] ?? null;
        $this->availabilityGateEnabled = is_bool($gateOverride)
            ? $gateOverride
            : ConfigProvider::shouldRequireImmediateAvailability();

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
        $this->output('Hotel sync starting for ' . implode('; ', $labels));

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
        foreach ($destinationIds as $countryCodeStr => $destIdsForCountry) {
            $updatedSince = null;
            if (!$fullSync) {
                $lastSynced = $this->hotelRepo->getLastSyncedAt($countryCodeStr);
                if ($lastSynced !== null) {
                    $updatedSince = $lastSynced;
                }
            }
            $modeLabel = $updatedSince !== null ? "incremental since {$updatedSince}" : 'full';
            $this->output("  {$countryCodeStr}: sync mode: {$modeLabel}");

            $countryStats = $this->syncCountry($countryCodeStr, $destIdsForCountry, $updatedSince);
            $stats['total'] = ValidationHelpers::toInt($stats['total'] ?? 0) + ValidationHelpers::toInt($countryStats['total'] ?? 0);
            $stats['synced'] = ValidationHelpers::toInt($stats['synced'] ?? 0) + ValidationHelpers::toInt($countryStats['synced'] ?? 0);
            $stats['skipped'] = ValidationHelpers::toInt($stats['skipped'] ?? 0) + ValidationHelpers::toInt($countryStats['skipped'] ?? 0);
            $stats['failed'] = ValidationHelpers::toInt($stats['failed'] ?? 0) + ValidationHelpers::toInt($countryStats['failed'] ?? 0);

            foreach (['availability_probed', 'availability_gated', 'availability_cleared', 'availability_errors'] as $k) {
                $stats[$k] = ValidationHelpers::toInt($stats[$k] ?? 0) + ValidationHelpers::toInt($countryStats[$k] ?? 0);
            }

            $cError = ValidationHelpers::toString($countryStats['error'] ?? '');
            if (!empty($cError)) {
                $currentError = ValidationHelpers::toString($stats['error'] ?? '');
                $stats['error'] = $currentError . ($currentError !== '' ? '; ' : '') . $cError;
            }
        }

        // Set overall sync_mode label based on what actually happened
        $stats['sync_mode'] = $fullSync ? 'full' : 'per-country incremental';
        $sSynced = ValidationHelpers::toInt($stats['synced']);
        $sTotal = ValidationHelpers::toInt($stats['total']);
        $sSkipped = ValidationHelpers::toInt($stats['skipped']);
        $stats['success'] = ($sSynced > 0 || $sTotal === 0);
        $this->output("Sync complete: {$sSynced}/{$sTotal} hotels synced, {$sSkipped} skipped.");

        if (!$this->availabilityGateEnabled) {
            $this->output('Availability gate: disabled');
        } else {
            $this->output(sprintf(
                'Availability gate: %d gated, %d cleared (%d destination(s) probed, %d search error(s))',
                ValidationHelpers::toInt($stats['availability_gated'] ?? 0),
                ValidationHelpers::toInt($stats['availability_cleared'] ?? 0),
                ValidationHelpers::toInt($stats['availability_probed'] ?? 0),
                ValidationHelpers::toInt($stats['availability_errors'] ?? 0),
            ));
        }

        return $stats;
    }

    /**
     * Sync hotels for a single country.
     *
     * Uses server-side destination_ids filtering to reduce API payload.
     * When updatedSince is set (incremental sync), stale detection is skipped.
     *
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
                $this->output('    Destination chunk ' . ($chunkIdx + 1) . '/' . count($destIdChunks) . ' (' . count($destIdChunk) . ' IDs)');
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
                    $normalized = $this->normalizeHotel(TypeCoerce::toStringMap($raw));
                    if ($normalized === null) {
                        $stats['skipped']++;
                        continue;
                    }

                    // Safety filter: verify country_code matches
                    $hotelCountry = strtoupper(ValidationHelpers::toString($normalized['country_code'] ?? ''));
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

                $totalPlusSkipped = $stats['total'] + $stats['skipped'];
                if (!$this->hasMorePages($response, $page, self::PER_PAGE, $totalPlusSkipped)) {
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
            // Still run the availability gate: it clears no_availability from
            // hotels that became bookable even when no static data changed.
            return $this->applyAvailabilityGate($countryCode, $destinationIds, $stats);
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

        return $this->applyAvailabilityGate($countryCode, $destinationIds, $stats);
    }

    /**
     * Gate product-less hotels on immediate availability.
     *
     * For each destination with candidate hotels (no product yet, unflagged or
     * already flagged no_availability), runs one live search and marks hotels
     * with no immediate-confirmation offer as 'no_availability' (so
     * AddProductsCommand skips them), and clears that flag from hotels that have
     * become bookable again. Linked products and hotels skipped for other
     * reasons are never touched.
     *
     * Marking is scoped to destinations that were probed successfully, so an API
     * error never mass-flags hotels — only clearing (always safe) still applies.
     *
     * @param int[] $destinationIds
     * @param array<string, mixed> $stats
     * @return array<string, mixed>
     */
    private function applyAvailabilityGate(string $countryCode, array $destinationIds, array $stats): array
    {
        if (!$this->availabilityGateEnabled) {
            return $stats;
        }

        // Normalise to a unique list of positive int destination IDs.
        $destIds = [];
        foreach ($destinationIds as $d) {
            $di = ValidationHelpers::toInt($d);
            if ($di > 0) {
                $destIds[] = $di;
            }
        }
        $destIds = array_values(array_unique($destIds));
        if ($destIds === []) {
            return $stats;
        }

        $candidates = $this->hotelRepo->findAvailabilityGateCandidates($destIds);
        if ($candidates === []) {
            $this->output("    {$countryCode}: availability gate — no unlinked hotels to check");
            return $stats;
        }

        $this->output(sprintf(
            '    %s: availability gate — %d candidate hotel(s) across %d destination(s)',
            $countryCode,
            count($candidates),
            count($destIds),
        ));

        // Probe each destination once; collect the set of hotel IDs that have at
        // least one immediate-confirmation offer, and which destinations answered.
        $checkIn = date('Y-m-d', strtotime('+' . self::AVAILABILITY_DAYS_AHEAD . ' days'));
        $checkOut = date('Y-m-d', strtotime('+' . (self::AVAILABILITY_DAYS_AHEAD + self::AVAILABILITY_NIGHTS) . ' days'));
        $currency = ConfigProvider::getDefaultCurrency();
        $debug = ConfigProvider::isDebugLogging();

        /** @var array<string, true> $availableSet */
        $availableSet = [];
        /** @var array<int, true> $probedDestinations */
        $probedDestinations = [];
        $errors = 0;
        $httpClient = $this->api->getHttpClient();

        foreach ($destIds as $destId) {
            if ($httpClient->isCircuitOpen()) {
                $this->output('    Circuit breaker open — stopping availability probe early (unprobed hotels left unchanged)');
                break;
            }

            $searchResponse = $this->api->searchHotels([
                'destination_id' => $destId,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'occupancy' => [['adults' => self::AVAILABILITY_ADULTS, 'children_ages' => []]],
                'currency' => $currency,
            ]);

            if (!is_array($searchResponse)) {
                $errors++;
                if ($errors <= 5) {
                    $this->output("    [WARN] availability search failed for destination {$destId}");
                }
                usleep(self::AVAILABILITY_DEST_DELAY_US);
                continue;
            }

            // Search was accepted → destination counts as probed (zero offers is
            // a legitimate "no availability" answer, not an error).
            $probedDestinations[$destId] = true;

            // Inline results (synchronous completion) + cursor-polled results.
            $availableSet += OfferAvailability::collectImmediateHotelIds(
                TypeCoerce::toRowList($searchResponse['results'] ?? $searchResponse['data'] ?? []),
            );
            $cursor = TypeCoerce::toString($searchResponse['cursor'] ?? $searchResponse['search_id'] ?? '');
            if ($cursor !== '') {
                $availableSet += $this->pollImmediateHotelIds($cursor);
            }

            if ($debug) {
                $this->output("    [DEBUG] dest={$destId}: " . count($availableSet) . ' immediate hotel(s) so far');
            }

            usleep(self::AVAILABILITY_DEST_DELAY_US);
        }

        // Partition candidates into mark / clear operations.
        $toMark = [];
        $toClear = [];
        foreach ($candidates as $row) {
            $hid = TypeCoerce::toString($row['hotel_id'] ?? '');
            if ($hid === '') {
                continue;
            }
            $reason = TypeCoerce::toString($row['product_skip_reason'] ?? '');
            if (isset($availableSet[$hid])) {
                if ($reason === HotelRepository::SKIP_REASON_NO_AVAILABILITY) {
                    $toClear[] = $hid;
                }
                continue;
            }
            // Unavailable: only flag when this destination was actually probed
            // and the hotel carries no skip reason yet.
            $destId = ValidationHelpers::toInt($row['destination_id'] ?? 0);
            if ($reason === '' && isset($probedDestinations[$destId])) {
                $toMark[] = $hid;
            }
        }

        $marked = $this->hotelRepo->markSkippedBatch($toMark, HotelRepository::SKIP_REASON_NO_AVAILABILITY);
        $cleared = $this->hotelRepo->clearSkipReasonBatch($toClear, HotelRepository::SKIP_REASON_NO_AVAILABILITY);

        $stats['availability_probed'] = count($probedDestinations);
        $stats['availability_gated'] = $marked;
        $stats['availability_cleared'] = $cleared;
        $stats['availability_errors'] = $errors;

        $this->output(sprintf(
            '    %s: availability gate — %d marked no_availability, %d cleared, %d search error(s)',
            $countryCode,
            $marked,
            $cleared,
            $errors,
        ));

        return $stats;
    }

    /**
     * Poll a search cursor to completion, collecting hotel IDs with an
     * immediate-confirmation offer. Memory stays flat: each batch is reduced to
     * the hotel-id set instead of being accumulated.
     *
     * The Sphinx spec's only definitive terminal is a cursor:null page; empty
     * pages with a live cursor are normal and keep being polled until the
     * per-destination deadline.
     *
     * @return array<string, true>
     */
    private function pollImmediateHotelIds(string $cursorToken): array
    {
        /** @var array<string, true> $hotelIds */
        $hotelIds = [];
        $cursor = $cursorToken;
        $deadline = time() + self::AVAILABILITY_POLL_DEADLINE;

        while (time() < $deadline) {
            $response = $this->api->getHotelResults('', $cursor);
            if (!is_array($response)) {
                break;
            }

            $batch = TypeCoerce::toRowList($response['results'] ?? $response['data'] ?? []);
            if ($batch !== []) {
                $hotelIds += OfferAvailability::collectImmediateHotelIds($batch);
            }

            $nextCursor = TypeCoerce::toString($response['cursor'] ?? $response['next_cursor'] ?? '');
            if ($nextCursor === '') {
                break; // cursor:null → definitive end of search
            }
            $cursor = $nextCursor;

            if (time() < $deadline) {
                sleep(self::AVAILABILITY_POLL_INTERVAL);
            }
        }

        return $hotelIds;
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
            $rows = TypeCoerce::toRowList(db_get_array(
                'SELECT destination_id, country_code FROM ?:sphinx_destinations WHERE destination_id IN (?n)',
                $allowedDestIds,
            ));

            foreach ($rows as $row) {
                $cc = TypeCoerce::toString($row['country_code'] ?? '') ?: 'CUSTOM';
                $result[$cc][] = TypeCoerce::toInt($row['destination_id'] ?? 0);
            }

            foreach ($result as $cc => $ids) {
                $result[$cc] = array_values(array_unique($ids));
            }
        } elseif (!empty($countryCodes)) {
            // Fallback: when called with explicit country codes (no whitelist IDs),
            // include all destinations for those countries.
            foreach ($countryCodes as $code) {
                $ids = TypeCoerce::toIntList(db_get_fields(
                    'SELECT destination_id FROM ?:sphinx_destinations WHERE country_code = ?s',
                    $code,
                ));
                if ($ids !== []) {
                    $result[$code] = $ids;
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
        $id = TypeCoerce::toString($raw['id'] ?? '');
        if ($id === '') {
            return null;
        }

        $name = html_entity_decode(TypeCoerce::toString($raw['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($name === '') {
            return null;
        }

        $propertyType = $this->normalizer->normalizePropertyType(
            $raw['type'] ?? 'hotel',
        ) ?? 'hotel';

        $classification = TypeCoerce::toInt($raw['classification'] ?? 0);
        if ($classification < 0 || $classification > 5) {
            $classification = 0;
        }

        // Detect adults-only from hotel name (API doesn't provide a dedicated field)
        // Matches: "Adults Only", "Adult Only", "+18", "+16", "(18+)", "(16+)"
        $isAdultsOnly = preg_match('/\badults?\s*only\b|\(\s*\+\s*1[68]\s*\)|\(\s*1[68]\s*\+\s*\)/i', $name) === 1 ? 'Y' : 'N';

        $address = TypeCoerce::toStringMap($raw['address'] ?? []);
        $images = TypeCoerce::toRowList($raw['images'] ?? []);

        return [
            'hotel_id' => $id,
            'name' => $name,
            'classification' => $classification,
            'property_type' => $propertyType,
            'destination_id' => TypeCoerce::toInt($raw['destination_id'] ?? 0),
            'destination_name' => TypeCoerce::toString($raw['destination_name'] ?? ''),
            'region_id' => TypeCoerce::toInt($raw['region_id'] ?? 0),
            'region_name' => TypeCoerce::toString($raw['region_name'] ?? ''),
            'country_code' => strtoupper(TypeCoerce::toString($raw['country_code'] ?? '')),
            'country_name' => TypeCoerce::toString($raw['country_name'] ?? ''),
            'latitude' => TypeCoerce::toFloat($raw['latitude'] ?? 0),
            'longitude' => TypeCoerce::toFloat($raw['longitude'] ?? 0),
            'description' => html_entity_decode(TypeCoerce::toString($raw['description'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'short_description' => html_entity_decode(TypeCoerce::toString($raw['short_description'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'image_url' => TypeCoerce::toString($images[0]['url'] ?? ''),
            'images_json' => $images !== [] ? json_encode($images) : '[]',
            'facilities_json' => !empty($raw['facilities']) ? json_encode($raw['facilities']) : '[]',
            'is_adults_only' => $isAdultsOnly,
            'address' => trim(TypeCoerce::toString($address['street'] ?? '')),
            'phone' => trim(TypeCoerce::toString($address['phone'] ?? '')),
            'email' => trim(TypeCoerce::toString($address['email'] ?? '')),
            'website' => trim(TypeCoerce::toString($address['website'] ?? '')),
            'rating' => isset($raw['rating']) ? TypeCoerce::toFloat($raw['rating']) : null,
            'rating_count' => isset($raw['rating_count']) ? TypeCoerce::toInt($raw['rating_count']) : null,
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

        $spxProducts = TypeCoerce::toRowList(db_get_array(
            'SELECT product_id, product_code FROM ?:products WHERE product_code LIKE ?l',
            $prefix . '%',
        ));

        $stats = ['total' => count($spxProducts), 'linked' => 0, 'skipped' => 0, 'not_found' => 0, 'errors' => 0];

        if ($spxProducts === []) {
            return $stats;
        }

        foreach ($spxProducts as $i => $product) {
            $hotelId = substr(TypeCoerce::toString($product['product_code'] ?? ''), $prefixLen);
            if ($progressCallback !== null) {
                $progressCallback($i + 1, $stats['total'], $hotelId);
            }

            // Skip if already linked in sphinx_hotels
            $existing = $this->hotelRepo->findById($hotelId);
            if ($existing !== null && !empty($existing['product_id'])) {
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

            $normalized = $this->normalizeHotel(TypeCoerce::toStringMap($raw));
            if ($normalized === null) {
                $stats['errors']++;
                continue;
            }

            $this->hotelRepo->upsertBatch([$normalized]);
            $this->hotelRepo->linkToProduct($hotelId, TypeCoerce::toInt($product['product_id'] ?? 0));
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
        $destIds = [];
        foreach ($hotels as $hotelRow) {
            $di = TypeCoerce::toInt($hotelRow['destination_id'] ?? 0);
            if ($di > 0) {
                $destIds[$di] = $di;
            }
        }
        $hierarchyMap = $destIds !== [] ? $this->destRepo->resolveHierarchies(array_values($destIds)) : [];

        foreach ($hotels as &$hotel) {
            $destId = TypeCoerce::toInt($hotel['destination_id'] ?? 0);
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
            if (!empty($hierarchy['region_id']) && TypeCoerce::toInt($hotel['region_id'] ?? 0) === 0) {
                $hotel['region_id'] = TypeCoerce::toInt($hierarchy['region_id']);
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

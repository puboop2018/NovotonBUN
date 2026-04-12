<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Batched Hotel Info Sync (V2)
 *
 * Reimplementation of BatchedHotelInfoSync on top of the
 * AbstractBatchedSync template-method base class. Replaces ~860 lines
 * of bespoke state/retry/progress code with a ~430-line subclass that
 * contains only the per-hotel business logic and four private helpers
 * preserved verbatim from the legacy class.
 *
 * Shipped side-by-side with the legacy class — `BatchedSyncCommand`
 * is NOT yet pointed at V2 for the hotel_info mode. Swap happens in
 * a small follow-up commit (PR #9b).
 *
 * Architectural audit tracking: PR #9 of the roadmap — the last of
 * the three legacy Batched*Sync helpers to be migrated. Relies on the
 * hooks added to AbstractBatchedSync in PR #6b (preBatch, retry, CLI).
 *
 * Notable design choices
 * ----------------------
 * 1. curl_multi batch fetch:
 *    `$api->getHotelInfoBatch($batch)` runs in preBatch(), caching
 *    the result map in `$this->batchResults`. processItem() reads
 *    from that cache instead of fetching per-hotel — preserving the
 *    single-batched-fetch-per-page optimisation from the legacy class.
 *
 * 2. Retry-path fallback:
 *    The base class's retryFailedItems() bypasses preBatch() and calls
 *    processItem() directly for each error_id. When processItem() sees
 *    an empty cache for the current hotel, it calls
 *    `$this->preBatch([$hotelId])` to populate caches + batch result
 *    for a single item — effectively a single-hotel curl_multi call
 *    which is structurally identical to the legacy retry's dedicated
 *    single-hotel getHotelInfo fetch.
 *
 * 3. reconcileProductLinks():
 *    The legacy class's run() flow runs this before determineSyncType().
 *    The base class doesn't expose a pre-run hook, so V2 calls it from
 *    the top of determineSyncType() instead — which runs on fresh
 *    sync starts and is skipped on resume, exactly like the legacy.
 *
 * 4. Large-sync DB-pagination mode:
 *    Dropped. Legacy used DB pagination for hotel sets > 5000 to avoid
 *    a large state file. V2 stores every item in `state['item_ids']`;
 *    worst-case ~400 KB state file is acceptable and simplifies the
 *    class by ~100 LOC.
 *
 * @package NovotonHolidays
 * @since   3.8.0
 */

namespace Tygh\Addons\NovotonHolidays\Helpers;

use Tygh\Addons\NovotonHolidays\Api\AdultOnlyDetector;
use Tygh\Addons\NovotonHolidays\Exceptions\ApiException;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;

class BatchedHotelInfoSyncV2 extends AbstractBatchedSync
{
    /** Full-sync interval in seconds (from ConfigProvider). */
    private readonly int $fullSyncInterval;

    /** Adults-only detector (hotel-name-based classification). */
    private readonly AdultOnlyDetector $adultOnlyDetector;

    /**
     * Product code prefixes from config — e.g. `['NVT', 'NV']`.
     * Cached in constructor so preBatch() and processHotelInfo() don't
     * re-query ConfigProvider on every call.
     *
     * @var string[]
     */
    private readonly array $productCodePrefixes;

    /**
     * Per-batch cache: hotel_id → ['hotel_name' => string, 'product_id' => int|null].
     * Populated in preBatch(), consumed by processItem() + processHotelInfo().
     *
     * @var array<string, array<string, mixed>>
     */
    private array $hotelMap = [];

    /**
     * Per-batch cache: product_code → product_id.
     * Built from hotels with NULL product_id so processHotelInfo() can
     * auto-link them without per-hotel queries.
     *
     * @var array<string, int>
     */
    private array $productCodeMap = [];

    /**
     * Per-batch cache: hotel_id → SimpleXMLElement|false.
     * Populated by the curl_multi getHotelInfoBatch() call inside
     * preBatch(). processItem() reads from here instead of re-fetching.
     *
     * @var array<string, mixed>
     */
    private array $batchResults = [];

    public function __construct(
        ?StateManagerInterface $state = null,
        ?SyncLoggerInterface $logger = null,
    ) {
        parent::__construct($state, $logger);
        $this->fullSyncInterval = ConfigProvider::getSyncIntervalHotelInfo();
        $this->adultOnlyDetector = new AdultOnlyDetector();
        $this->productCodePrefixes = ConfigProvider::getProductCodePrefixes();
    }

    #[\Override]
    protected function getSyncName(): string
    {
        // Matches the legacy state file path:
        //   {cache_misc}/novoton/batch_hotelinfo_state.json
        return 'hotelinfo';
    }

    /**
     * Opt in to the base class's one-shot retry of error_ids at the
     * end of the main processing loop. Matches the legacy behaviour.
     */
    #[\Override]
    protected function shouldRetryFailedItems(): bool
    {
        return true;
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    protected function determineSyncType(array $options): string
    {
        // The legacy run() flow reconciles product links before deciding
        // the sync type. determineSyncType() is only called on fresh sync
        // starts (not on resume), which matches legacy semantics exactly.
        $this->reconcileProductLinks();

        if (!empty($options['force_full'])) {
            return 'full';
        }

        // Look only at completed *full* syncs (notes JSON contains sync_type: full).
        $lastFullSync = db_get_field(
            "SELECT MAX(sync_date) FROM ?:novoton_sync_log
             WHERE sync_type = 'hotelinfo' AND status = 'completed'
             AND notes LIKE '%\"sync_type\":\"full\"%'"
        );

        if (empty($lastFullSync)) {
            $this->logger->output("No previous full sync found. Starting full sync.");
            return 'full';
        }

        $timeSinceFull = time() - strtotime($lastFullSync);

        if ($timeSinceFull > $this->fullSyncInterval) {
            $days = round($timeSinceFull / 86400);
            $this->logger->output("Last full sync was {$days} days ago. Starting full sync.");
            return 'full';
        }

        // Incremental: last completed sync of any kind.
        $lastIncremental = db_get_field(
            "SELECT MAX(sync_date) FROM ?:novoton_sync_log
             WHERE sync_type = 'hotelinfo' AND status = 'completed'"
        );

        if (empty($lastIncremental)) {
            return 'full';
        }

        // More than 24 hours since the last completed sync → incremental.
        $timeSinceLast = time() - strtotime($lastIncremental);
        if ($timeSinceLast > 24 * 3600) {
            return 'incremental';
        }

        return 'none';
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    #[\Override]
    protected function getItemsToSync(string $syncType, array $options): array
    {
        $countries = $options['countries'] ?? ConfigProvider::getSelectedCountries();

        if ($syncType === 'full') {
            return db_get_fields(
                "SELECT hotel_id FROM ?:novoton_hotels
                 WHERE country IN (?a)
                 ORDER BY hotel_name",
                $countries
            );
        }

        // Incremental: changed hotels from offers_update API, unioned
        // with hotels that never had hotelinfo synced yet.
        return $this->getChangedHotelIds($countries);
    }

    /**
     * Pre-fetch EVERYTHING needed for a batch in a single place:
     *   - hotel_name + product_id per hotel  (one DB query)
     *   - product_code → product_id for unlinked hotels (one DB query)
     *   - hotel info XML per hotel, via curl_multi (one batched API call)
     *
     * Eliminates every N+1 the legacy class built up across its main
     * loop and per-item processing.
     *
     * @param array<int, string|int> $batch
     */
    #[\Override]
    protected function preBatch(array $batch): void
    {
        $this->hotelMap = [];
        $this->productCodeMap = [];
        $this->batchResults = [];

        if (empty($batch)) {
            return;
        }

        $hotelIds = array_values(array_map('strval', $batch));

        // 1. Hotel metadata (hotel_name, product_id) keyed by hotel_id.
        $this->hotelMap = db_get_hash_array(
            "SELECT hotel_id, hotel_name, product_id FROM ?:novoton_hotels WHERE hotel_id IN (?a)",
            'hotel_id',
            $hotelIds
        );

        // 2. Product code -> product_id map for unlinked hotels only.
        $codePatterns = [];
        foreach ($hotelIds as $hid) {
            if (empty($this->hotelMap[$hid]['product_id'])) {
                foreach ($this->productCodePrefixes as $pfx) {
                    $codePatterns[] = $pfx . $hid;
                }
            }
        }
        if (!empty($codePatterns)) {
            $this->productCodeMap = db_get_hash_single_array(
                "SELECT product_code, product_id FROM ?:products WHERE product_code IN (?a)",
                ['product_code', 'product_id'],
                $codePatterns
            );
        }

        // 3. curl_multi parallel fetch of hotel info for the whole batch.
        $this->batchResults = $this->getApi()->hotels()->getHotelInfoBatch($hotelIds);
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function processItem($itemId): array
    {
        $hotelId = (string) $itemId;

        // Retry-path fallback: the base class's retryFailedItems() calls
        // processItem() directly without invoking preBatch(), so our
        // caches may be empty for this item. Populate them for just
        // this hotel — effectively a single-hotel curl_multi call which
        // is structurally equivalent to the legacy retry's dedicated
        // getHotelInfo() call.
        if (!isset($this->hotelMap[$hotelId]) || !array_key_exists($hotelId, $this->batchResults)) {
            $this->preBatch([$hotelId]);
        }

        $hotelName = $this->hotelMap[$hotelId]['hotel_name'] ?? '?';
        $hotelInfo = $this->batchResults[$hotelId] ?? false;

        $this->logger->output("[{$hotelId}] {$hotelName} ... ", false);

        if (!$hotelInfo) {
            $this->logger->output("API returned empty");
            return ['success' => false, 'message' => 'api_returned_empty', 'data' => null];
        }

        try {
            $this->processHotelInfo(
                $hotelId,
                $hotelInfo,
                date('Y-m-d H:i:s'),
                $this->hotelMap,
                $this->productCodeMap,
                $this->productCodePrefixes,
            );
        } catch (ApiException $e) {
            $this->logger->output("ERROR: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => null];
        } catch (\Throwable $e) {
            $this->logger->output("ERROR: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => null];
        }

        $packagesCount = $this->countPackages($hotelInfo);
        $this->logger->output("OK ({$packagesCount} packages)");

        return [
            'success' => true,
            'message' => '',
            'data'    => ['packages_count' => $packagesCount],
        ];
    }

    // ════════════════════════════════════════════════════════════════════
    // Private helpers — preserved verbatim from the legacy class, with
    // only the array-argument list reformatted to fit typed parameters.
    // ════════════════════════════════════════════════════════════════════

    /**
     * Process hotel info from an API response.
     *
     * Writes hotel_data JSON, detects adults-only from the hotel name,
     * links unlinked products via the pre-fetched product_code_map, and
     * upserts every package in a single transactional batch INSERT.
     *
     * @param string                                  $hotelId         Hotel ID
     * @param mixed                                   $hotelInfo       SimpleXML hotel info from the API
     * @param string                                  $now             Current timestamp
     * @param array<string, array<string, mixed>>     $hotelMap        hotel_id → [hotel_name, product_id] map
     * @param array<string, int>                      $productCodeMap  product_code → product_id map
     * @param string[]                                $prefixes        Product code prefixes
     */
    private function processHotelInfo(
        string $hotelId,
        $hotelInfo,
        string $now,
        array $hotelMap = [],
        array $productCodeMap = [],
        array $prefixes = ['NVT'],
    ): void {
        $hotelDataJson = json_encode($hotelInfo);
        if ($hotelDataJson === false) {
            $hotelDataJson = null;
        }

        $update = [
            'hotelinfo_synced_at' => $now,
            'hotel_data'          => $hotelDataJson,
        ];

        // Detect adults-only from hotel name
        $hotelName = $hotelMap[$hotelId]['hotel_name'] ?? '';
        if ($hotelName !== '' && $this->adultOnlyDetector->detect($hotelName)) {
            $update['is_adults_only'] = 'Y';
        }

        // Link product if not already linked — use pre-fetched maps (no extra queries)
        $currentProductId = $hotelMap[$hotelId]['product_id'] ?? null;

        if (empty($currentProductId)) {
            foreach ($prefixes as $prefix) {
                $pid = $productCodeMap[$prefix . $hotelId] ?? null;
                if (!empty($pid)) {
                    $update['product_id'] = $pid;
                    break;
                }
            }
        }

        // Extract package_name
        $packageName = '';
        if (isset($hotelInfo->packages->PackageName)) {
            $packageName = (string) $hotelInfo->packages->PackageName;
        } elseif (isset($hotelInfo->packages->Package)) {
            $packageName = (string) $hotelInfo->packages->Package;
        }
        if ($packageName === '') {
            $pn = $hotelInfo->xpath('//PackageName');
            if (!empty($pn)) {
                $packageName = (string) $pn[0];
            }
        }
        if ($packageName !== '') {
            $update['package_name'] = $packageName;
        }

        // Extract and store packages (has_room_price is set exclusively by room_price check)
        $packages = $this->extractPackages($hotelInfo);
        $update['packages_count'] = count($packages);

        // Wrap hotel + packages update in a transaction for atomicity
        db_query("START TRANSACTION");
        try {
            // Update hotel record
            db_query("UPDATE ?:novoton_hotels SET ?u WHERE hotel_id = ?s", $update, $hotelId);

            // Batch INSERT packages (multi-row upsert instead of N individual queries)
            $validPackages = array_filter($packages, static fn(array $pkg): bool => !empty($pkg['IdCont']));
            if (!empty($validPackages)) {
                $values = [];
                $params = [];
                foreach ($validPackages as $pkg) {
                    $values[] = "(?s, ?s, ?s, NOW())";
                    $params[] = $hotelId;
                    $params[] = $pkg['IdCont'];
                    $params[] = $pkg['PackageName'];
                }
                $sql = "INSERT INTO ?:novoton_hotel_packages (hotel_id, package_id, package_name, created_at) VALUES "
                    . implode(', ', $values)
                    . " AS new_row ON DUPLICATE KEY UPDATE package_name = new_row.package_name";
                db_query($sql, ...$params);
            }

            db_query("COMMIT");
        } catch (\Throwable $e) {
            db_query("ROLLBACK");
            throw $e;
        }
    }

    /**
     * Extract packages from a SimpleXMLElement hotel info response.
     *
     * Handles both multiple <packages> siblings and nested <Package>
     * elements; dedupes by IdCont.
     *
     * Verbatim from legacy BatchedHotelInfoSync::extractPackages().
     *
     * @return array<int, array{IdCont: string, PackageName: string}>
     */
    private function extractPackages($hotelInfo): array
    {
        $packages = [];
        $seenIds = [];

        if (isset($hotelInfo->packages)) {
            foreach ($hotelInfo->packages as $pkg) {
                $idCont = (string) ($pkg->IdCont ?? '');
                if ($idCont !== '' && !isset($seenIds[$idCont])) {
                    $packages[] = [
                        'IdCont'      => $idCont,
                        'PackageName' => (string) ($pkg->PackageName ?? $pkg->Package ?? ''),
                    ];
                    $seenIds[$idCont] = true;
                }

                // Also check for nested <Package> elements within each <packages>
                if (isset($pkg->Package) && is_object($pkg->Package)) {
                    foreach ($pkg->Package as $nestedPkg) {
                        $nestedIdCont = (string) ($nestedPkg->IdCont ?? '');
                        if ($nestedIdCont !== '' && !isset($seenIds[$nestedIdCont])) {
                            $packages[] = [
                                'IdCont'      => $nestedIdCont,
                                'PackageName' => (string) ($nestedPkg->PackageName ?? ''),
                            ];
                            $seenIds[$nestedIdCont] = true;
                        }
                    }
                }
            }
        }

        return $packages;
    }

    private function countPackages($hotelInfo): int
    {
        return count($this->extractPackages($hotelInfo));
    }

    /**
     * Re-link hotels with NULL product_id whose CS-Cart product exists,
     * and clear stale product_id pointing to deleted products.
     *
     * Uses configured product_code_prefixes to match products. Called
     * once per fresh sync from the top of determineSyncType().
     *
     * Verbatim from legacy BatchedHotelInfoSync::reconcileProductLinks().
     */
    private function reconcileProductLinks(): void
    {
        $prefixes = $this->productCodePrefixes;

        // 1. Re-link: hotels with NULL product_id whose product exists
        $orphaned = db_get_fields(
            "SELECT hotel_id FROM ?:novoton_hotels WHERE product_id IS NULL OR product_id = 0"
        );

        $linked = 0;
        if (!empty($orphaned)) {
            // Build all possible product_codes in one go, then bulk-fetch
            $codePatterns = [];
            foreach ($orphaned as $hotelId) {
                foreach ($prefixes as $prefix) {
                    $codePatterns[] = $prefix . $hotelId;
                }
            }

            $productMap = [];
            if (!empty($codePatterns)) {
                $productMap = db_get_hash_single_array(
                    "SELECT product_code, product_id FROM ?:products WHERE product_code IN (?a)",
                    ['product_code', 'product_id'],
                    $codePatterns
                );
            }

            // Update matched hotels using the map (no per-hotel queries)
            foreach ($orphaned as $hotelId) {
                foreach ($prefixes as $prefix) {
                    $pid = $productMap[$prefix . $hotelId] ?? null;
                    if (!empty($pid)) {
                        db_query(
                            "UPDATE ?:novoton_hotels SET product_id = ?i WHERE hotel_id = ?s",
                            $pid, $hotelId
                        );
                        $linked++;
                        break;
                    }
                }
            }
        }

        // 2. Cleanup: clear product_id pointing to deleted products
        $cleaned = db_query(
            "UPDATE ?:novoton_hotels h
             LEFT JOIN ?:products p ON h.product_id = p.product_id
             SET h.product_id = NULL
             WHERE h.product_id > 0 AND p.product_id IS NULL"
        );

        if ($linked > 0 || $cleaned > 0) {
            $this->logger->output("Reconciliation: re-linked {$linked} hotels, cleared {$cleaned} stale references.");
        }
    }

    /**
     * Get changed hotel IDs from the offers_update API for the given
     * countries, unioned with hotels that never had hotelinfo synced yet.
     *
     * Verbatim from legacy BatchedHotelInfoSync::getChangedHotelIds().
     *
     * @param string[] $countries
     * @return string[]
     */
    private function getChangedHotelIds(array $countries): array
    {
        $api = $this->getApi();

        // Get last sync date
        $lastSync = db_get_field(
            "SELECT MAX(sync_date) FROM ?:novoton_sync_log
             WHERE sync_type = 'hotelinfo' AND status = 'completed'"
        );

        if (empty($lastSync)) {
            // No previous sync - should do full sync instead
            return [];
        }

        $datetimeParam = date('Y-m-d\TH:i:s', strtotime($lastSync));
        $this->logger->output("Checking offers_update since: {$datetimeParam}");

        $changedIds = [];

        foreach ($countries as $country) {
            $this->logger->output("Checking {$country}...");

            try {
                $response = $api->destinations()->getOffersUpdate($datetimeParam, $country);

                if ($response && isset($response->Offer)) {
                    $offers = is_array($response->Offer) ? $response->Offer : [$response->Offer];
                    foreach ($offers as $offer) {
                        $hid = (string) ($offer->IdHotel ?? '');
                        if ($hid !== '') {
                            $changedIds[$hid] = true;
                        }
                    }
                    $this->logger->output("  Found " . count($offers) . " changed offers");
                } else {
                    $this->logger->output("  No changes");
                }
            } catch (ApiException $e) {
                $this->logger->output("  Error: " . $e->getMessage());
            }
        }

        // Also include hotels that never had hotelinfo synced
        $unsynced = db_get_fields(
            "SELECT hotel_id FROM ?:novoton_hotels
             WHERE country IN (?a) AND hotelinfo_synced_at IS NULL",
            $countries
        );

        if (!empty($unsynced)) {
            $this->logger->output("Also adding " . count($unsynced) . " hotels that never had hotelinfo synced.");
            foreach ($unsynced as $id) {
                $changedIds[$id] = true;
            }
        }

        return array_keys($changedIds);
    }
}
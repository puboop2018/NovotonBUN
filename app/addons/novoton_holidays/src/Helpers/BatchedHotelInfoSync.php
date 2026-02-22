<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Batched Hotel Info Sync
 *
 * Handles hotel info synchronization with:
 * - Resume capability (saves state between runs)
 * - Full sync mode (all hotels, every 6 months)
 * - Incremental sync mode (only new/changed hotels from offers_update API)
 *
 * Usage:
 *   // CLI or Cron - runs in batch mode, resumes automatically
 *   $sync = new BatchedHotelInfoSync();
 *   $result = $sync->run();
 *
 *   // Force full sync
 *   $sync->run(['force_full' => true]);
 *
 *   // Check status only
 *   $status = $sync->getStatus();
 *
 * @package NovotonHolidays
 * @since 2.9.0
 */

namespace Tygh\Addons\NovotonHolidays\Helpers;

use Tygh\Registry;
use Tygh\Addons\NovotonHolidays\NovotonApi;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Exceptions\ApiException;

class BatchedHotelInfoSync
{
    /**
     * State file path
     */
    private string $state_file;

    /**
     * Number of hotels to process per batch
     */
    private int $batch_size = 100;

    /**
     * Maximum execution time per run (seconds)
     */
    private int $max_execution_time = 300; // 5 minutes

    /**
     * Unlimited mode - no time limit (for CLI usage)
     */
    private bool $unlimited = false;

    /**
     * Full sync interval (seconds) - 6 months
     */
    private int $full_sync_interval = 180 * 24 * 3600; // 180 days

    /**
     * API instance
     */
    private ?NovotonApi $api = null;

    /**
     * Output callback for logging
     */
    private $output_callback = null;

    /**
     * Constructor
     */
    public function __construct()
    {
        $cache_dir = Registry::get('config.dir.cache_misc') ?? (DIR_ROOT . '/var/cache/');
        $this->state_file = $cache_dir . 'novoton/batch_hotelinfo_state.json';

        // Ensure directory exists
        $dir = dirname($this->state_file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    /**
     * Set output callback for logging
     */
    public function setOutputCallback(callable $callback): void
    {
        $this->output_callback = $callback;
    }

    /**
     * Set batch size
     */
    public function setBatchSize(int $size): void
    {
        $this->batch_size = max(10, min(500, $size));
    }

    /**
     * Set max execution time
     */
    public function setMaxExecutionTime(int $seconds): void
    {
        $this->max_execution_time = max(60, min(3600, $seconds));
    }

    /**
     * Set unlimited mode (no time limit - for CLI usage)
     */
    public function setUnlimited(bool $unlimited): void
    {
        $this->unlimited = $unlimited;
    }

    /**
     * Get API instance
     */
    private function getApi(): NovotonApi
    {
        if ($this->api === null) {
            $src_dir = Registry::get('config.dir.addons') . 'novoton_holidays/src/';
            if (file_exists($src_dir . 'NovotonApi.php')) {
                require_once($src_dir . 'NovotonApi.php');
            }
            $this->api = new NovotonApi();
        }
        return $this->api;
    }

    /**
     * Main entry point - run sync
     *
     * @param array $options Options: force_full, reset, countries
     * @return array Result with status, processed, total, etc.
     */
    public function run(array $options = []): array
    {
        $start_time = time();

        // Handle reset option
        if (!empty($options['reset'])) {
            $this->clearState();
            $this->output("State reset. Ready for new sync.");
            return ['status' => 'reset'];
        }

        // Check for active job to resume
        $state = $this->loadState();

        if (!empty($state) && $state['status'] === 'in_progress') {
            $this->output("Resuming {$state['sync_type']} sync...");
            $this->output("Progress: {$state['processed']}/{$state['total']} (" .
                round($state['processed'] / max(1, $state['total']) * 100, 1) . "%)");
            return $this->resumeSync($state, $start_time);
        }

        // Reconcile: re-link hotels with NULL product_id whose products exist
        $this->reconcileProductLinks();

        // Determine sync type needed
        $sync_type = $this->determineSyncType($options);

        if ($sync_type === 'none') {
            $this->output("No sync needed at this time.");
            return ['status' => 'skipped', 'reason' => 'No sync needed'];
        }

        $this->output("Starting {$sync_type} sync...");

        // Get hotel IDs to sync
        $hotel_ids = $this->getHotelIdsToSync($sync_type, $options);

        if (empty($hotel_ids)) {
            $this->output("No hotels to sync.");
            return ['status' => 'skipped', 'reason' => 'No hotels found'];
        }

        $this->output("Found " . count($hotel_ids) . " hotels to sync.");

        // Create new state — store only IDs (needed for resume ordering)
        // For large sets (>5000), store only count and use DB pagination
        $store_ids = count($hotel_ids) <= 5000;

        $state = [
            'sync_type' => $sync_type,
            'status' => 'in_progress',
            'started_at' => date('Y-m-d H:i:s'),
            'last_run_at' => date('Y-m-d H:i:s'),
            'hotel_ids' => $store_ids ? $hotel_ids : [],
            'use_db_pagination' => !$store_ids,
            'total' => count($hotel_ids),
            'processed' => 0,
            'synced' => 0,
            'errors' => 0,
            'error_ids' => [],
            'countries' => $options['countries'] ?? $this->getConfiguredCountries(),
        ];

        $this->saveState($state);

        return $this->resumeSync($state, $start_time);
    }

    /**
     * Resume an in-progress sync
     */
    private function resumeSync(array $state, int $start_time): array
    {
        $api = $this->getApi();
        $processed_this_run = 0;
        $synced_this_run = 0;
        $errors_this_run = 0;
        $offset = $state['processed'];
        $now = date('Y-m-d H:i:s');

        while ($offset < $state['total']) {
            // Check time and memory limits (skip if unlimited mode)
            if (!$this->unlimited) {
                $elapsed = time() - $start_time;
                if ($elapsed > $this->max_execution_time) {
                    $this->output("\nTime limit reached ({$elapsed}s). Saving state for resume.");
                    break;
                }
                if ($this->isMemoryLimitReached()) {
                    $mem = round(memory_get_usage(true) / 1024 / 1024, 1);
                    $this->output("\nMemory limit approaching ({$mem}MB). Saving state for resume.");
                    break;
                }
            }

            // Get next batch — from state array or DB pagination
            if (!empty($state['hotel_ids'])) {
                $batch = array_slice($state['hotel_ids'], $offset, $this->batch_size);
            } else {
                // DB pagination mode for large hotel sets
                $countries = $state['countries'] ?? $this->getConfiguredCountries();
                $batch = db_get_fields(
                    "SELECT hotel_id FROM ?:novoton_hotels WHERE country IN (?a) ORDER BY hotel_name LIMIT ?i OFFSET ?i",
                    $countries, $this->batch_size, $offset
                );
            }

            if (empty($batch)) {
                break; // No more items (DB pagination exhausted)
            }

            // Pre-fetch hotel names and product_ids for entire batch (eliminates N+1)
            $hotel_map = db_get_hash_array(
                "SELECT hotel_id, hotel_name, product_id FROM ?:novoton_hotels WHERE hotel_id IN (?a)",
                'hotel_id', $batch
            );

            // Pre-fetch product_code -> product_id map for unlinked hotels
            $prefixes = ConfigProvider::getProductCodePrefixes();

            $code_patterns = [];
            foreach ($batch as $hid) {
                if (empty($hotel_map[$hid]['product_id'])) {
                    foreach ($prefixes as $pfx) {
                        $code_patterns[] = $pfx . $hid;
                    }
                }
            }
            $product_code_map = [];
            if (!empty($code_patterns)) {
                $product_code_map = db_get_hash_single_array(
                    "SELECT product_code, product_id FROM ?:products WHERE product_code IN (?a)",
                    ['product_code', 'product_id'], $code_patterns
                );
            }

            // Fetch all hotel info in parallel using curl_multi
            $batch_results = $api->getHotelInfoBatch($batch);

            foreach ($batch as $hotel_id) {
                // Check time and memory limits within batch (skip if unlimited mode)
                if (!$this->unlimited) {
                    if ((time() - $start_time) > $this->max_execution_time || $this->isMemoryLimitReached()) {
                        break 2; // Exit both loops
                    }
                }

                $hotel_name = $hotel_map[$hotel_id]['hotel_name'] ?? '?';
                $hotel_info = $batch_results[$hotel_id] ?? false;

                $this->output("[{$hotel_id}] " . ($hotel_name ?: '?') . " ... ", false);

                $itemStart = hrtime(true);
                try {
                    if (!$hotel_info) {
                        $this->output("API returned empty");
                        $state['errors']++;
                        $state['error_ids'][] = $hotel_id;
                        $errors_this_run++;
                    } else {
                        // Process hotel info — pass pre-fetched maps to avoid N+1
                        $this->processHotelInfo($hotel_id, $hotel_info, $now, $hotel_map, $product_code_map, $prefixes);
                        $state['synced']++;
                        $synced_this_run++;

                        // Extract info for output
                        $packages_count = $this->countPackages($hotel_info);
                        $this->output("OK ({$packages_count} packages)");
                    }
                } catch (ApiException $e) {
                    $this->output("ERROR: " . $e->getMessage());
                    $state['errors']++;
                    $state['error_ids'][] = $hotel_id;
                    $errors_this_run++;
                }

                // Warn about slow items
                $itemDurationMs = (int)((hrtime(true) - $itemStart) / 1_000_000);
                if ($itemDurationMs > 30000) {
                    $secs = round($itemDurationMs / 1000, 1);
                    $this->output("Warning: hotel {$hotel_id} took {$secs}s");
                }

                $offset++;
                $processed_this_run++;
            }

            // Update state after each batch
            $state['processed'] = $offset;
            $state['last_run_at'] = date('Y-m-d H:i:s');
            $this->saveState($state);

            // Progress output
            $percent = round($offset / max(1, $state['total']) * 100, 1);
            $this->output("--- Progress: {$offset}/{$state['total']} ({$percent}%) ---");
        }

        // Check if complete — retry failed items before finishing
        if ($offset >= $state['total']) {
            // Retry failed items (max 1 pass, with backoff)
            if (!empty($state['error_ids']) && empty($state['retry_done'])) {
                $retry_ids = array_unique($state['error_ids']);
                $this->output("\nRetrying " . count($retry_ids) . " failed hotels...");
                $recovered = 0;
                $recovered_ids = [];
                foreach ($retry_ids as $retry_id) {
                    if (!$this->unlimited && (time() - $start_time) > $this->max_execution_time) {
                        break;
                    }
                    usleep(500000); // 500ms backoff for retries
                    try {
                        $hotel_info = $api->getHotelInfo($retry_id);
                        if ($hotel_info) {
                            $this->processHotelInfo($retry_id, $hotel_info, $now);
                            $recovered++;
                            $recovered_ids[] = $retry_id;
                            $state['synced']++;
                            $state['errors']--;
                            $this->output("  [{$retry_id}] retry OK");
                        }
                    } catch (ApiException $e) {
                        $this->output("  [{$retry_id}] retry failed: " . $e->getMessage());
                    }
                }
                $state['error_ids'] = array_values(array_diff($state['error_ids'], $recovered_ids));
                $state['retry_done'] = true;
                $this->saveState($state);
                if ($recovered > 0) {
                    $this->output("Recovered {$recovered} hotels on retry.");
                }
            }

            $this->completeSync($state);

            return [
                'status' => 'completed',
                'sync_type' => $state['sync_type'],
                'total' => $state['total'],
                'synced' => $state['synced'],
                'errors' => $state['errors'],
                'duration' => time() - strtotime($state['started_at']),
            ];
        }

        // Still in progress — save state in case break 2 skipped in-loop save
        $state['processed'] = $offset;
        $state['last_run_at'] = date('Y-m-d H:i:s');
        $this->saveState($state);

        $remaining = $state['total'] - $offset;
        $runs_remaining = ceil($remaining / ($processed_this_run ?: $this->batch_size));

        return [
            'status' => 'in_progress',
            'sync_type' => $state['sync_type'],
            'total' => $state['total'],
            'processed' => $offset,
            'remaining' => $remaining,
            'synced_this_run' => $synced_this_run,
            'errors_this_run' => $errors_this_run,
            'estimated_runs_remaining' => $runs_remaining,
        ];
    }

    /**
     * Process hotel info from API response
     *
     * @param string $hotel_id Hotel ID
     * @param mixed $hotel_info SimpleXML hotel info
     * @param string $now Current timestamp
     * @param array $hotel_map Pre-fetched hotel_id => [hotel_name, product_id] map
     * @param array $product_code_map Pre-fetched product_code => product_id map
     * @param array $prefixes Product code prefixes
     */
    private function processHotelInfo(string $hotel_id, $hotel_info, string $now, array $hotel_map = [], array $product_code_map = [], array $prefixes = ['NVT']): void
    {
        $hotel_data_json = json_encode($hotel_info);
        if ($hotel_data_json === false) {
            $hotel_data_json = null;
        }

        $update = [
            'hotelinfo_synced_at' => $now,
            'hotel_data' => $hotel_data_json,
        ];

        // Link product if not already linked — use pre-fetched maps (no extra queries)
        $current_product_id = $hotel_map[$hotel_id]['product_id'] ?? null;

        if (empty($current_product_id)) {
            foreach ($prefixes as $prefix) {
                $pid = $product_code_map[$prefix . $hotel_id] ?? null;
                if (!empty($pid)) {
                    $update['product_id'] = $pid;
                    break;
                }
            }
        }

        // Extract package_name
        $package_name = '';
        if (isset($hotel_info->packages->PackageName)) {
            $package_name = (string)$hotel_info->packages->PackageName;
        } elseif (isset($hotel_info->packages->Package)) {
            $package_name = (string)$hotel_info->packages->Package;
        }
        if (empty($package_name)) {
            $pn = $hotel_info->xpath('//PackageName');
            if (!empty($pn)) {
                $package_name = (string)$pn[0];
            }
        }
        if (!empty($package_name)) {
            $update['package_name'] = $package_name;
        }

        // Extract and store packages
        $packages = $this->extractPackages($hotel_info);
        $update['packages_count'] = count($packages);
        $update['has_prices'] = count($packages) > 0 ? 'Y' : 'N';

        // Wrap hotel + packages update in a transaction for atomicity
        db_query("START TRANSACTION");
        try {
            // Update hotel record
            db_query("UPDATE ?:novoton_hotels SET ?u WHERE hotel_id = ?s", $update, $hotel_id);

            // Batch INSERT packages (multi-row upsert instead of N individual queries)
            $valid_packages = array_filter($packages, fn($pkg) => !empty($pkg['IdCont']));
            if (!empty($valid_packages)) {
                $values = [];
                $params = [];
                foreach ($valid_packages as $pkg) {
                    $values[] = "(?s, ?s, ?s, NOW())";
                    $params[] = $hotel_id;
                    $params[] = $pkg['IdCont'];
                    $params[] = $pkg['PackageName'];
                }
                $sql = "INSERT INTO ?:novoton_hotel_packages (hotel_id, package_id, package_name, created_at) VALUES "
                    . implode(', ', $values)
                    . " ON DUPLICATE KEY UPDATE package_name = VALUES(package_name)";
                call_user_func_array('db_query', array_merge([$sql], $params));
            }

            db_query("COMMIT");
        } catch (\Exception $e) {
            db_query("ROLLBACK");
            throw $e;
        }
    }

    /**
     * Extract packages from hotel info
     */
    private function extractPackages($hotel_info): array
    {
        $packages = [];
        $seen_ids = [];

        // Handle multiple <packages> elements at the same level
        // XML structure: <hotel><packages>...</packages><packages>...</packages></hotel>
        // In SimpleXML, iterating over $hotel_info->packages yields all <packages> siblings
        if (isset($hotel_info->packages)) {
            foreach ($hotel_info->packages as $pkg) {
                $idCont = (string)($pkg->IdCont ?? '');
                if (!empty($idCont) && !isset($seen_ids[$idCont])) {
                    $packages[] = [
                        'IdCont' => $idCont,
                        'PackageName' => (string)($pkg->PackageName ?? $pkg->Package ?? ''),
                    ];
                    $seen_ids[$idCont] = true;
                }

                // Also check for nested <Package> elements within each <packages>
                if (isset($pkg->Package) && is_object($pkg->Package)) {
                    foreach ($pkg->Package as $nestedPkg) {
                        $nestedIdCont = (string)($nestedPkg->IdCont ?? '');
                        if (!empty($nestedIdCont) && !isset($seen_ids[$nestedIdCont])) {
                            $packages[] = [
                                'IdCont' => $nestedIdCont,
                                'PackageName' => (string)($nestedPkg->PackageName ?? ''),
                            ];
                            $seen_ids[$nestedIdCont] = true;
                        }
                    }
                }
            }
        }

        return $packages;
    }

    /**
     * Count packages in hotel info
     */
    private function countPackages($hotel_info): int
    {
        return count($this->extractPackages($hotel_info));
    }

    /**
     * Complete the sync and log results
     */
    private function completeSync(array $state): void
    {
        $duration = time() - strtotime($state['started_at']);

        // Log to sync_log table
        db_query(
            "INSERT INTO ?:novoton_sync_log SET
             sync_type = 'hotelinfo',
             sync_date = NOW(),
             products_total = ?i,
             products_updated = ?i,
             products_failed = ?i,
             duration_seconds = ?i,
             status = 'completed',
             notes = ?s",
            $state['total'],
            $state['synced'],
            $state['errors'],
            $duration,
            json_encode([
                'sync_type' => $state['sync_type'],
                'countries' => $state['countries'] ?? [],
            ])
        );

        // Clear state file
        $this->clearState();

        $this->output("\n========================================");
        $this->output("SYNC COMPLETED");
        $this->output("========================================");
        $this->output("Type: {$state['sync_type']}");
        $this->output("Total: {$state['total']}");
        $this->output("Synced: {$state['synced']}");
        $this->output("Errors: {$state['errors']}");
        $this->output("Duration: " . $this->formatDuration($duration));
        $this->output("========================================");
    }

    /**
     * Re-link hotels that have NULL product_id but whose CS-Cart product exists.
     * Uses configured product_code_prefixes to match products.
     * Also clears stale product_id pointing to deleted products.
     */
    private function reconcileProductLinks(): void
    {
        $prefixes = ConfigProvider::getProductCodePrefixes();

        // 1. Re-link: hotels with NULL product_id whose product exists (bulk approach)
        $orphaned = db_get_fields(
            "SELECT hotel_id FROM ?:novoton_hotels WHERE product_id IS NULL OR product_id = 0"
        );

        $linked = 0;
        if (!empty($orphaned)) {
            // Build all possible product_codes in one go, then bulk-fetch
            $code_patterns = [];
            foreach ($orphaned as $hotel_id) {
                foreach ($prefixes as $prefix) {
                    $code_patterns[] = $prefix . $hotel_id;
                }
            }

            $product_map = [];
            if (!empty($code_patterns)) {
                $product_map = db_get_hash_single_array(
                    "SELECT product_code, product_id FROM ?:products WHERE product_code IN (?a)",
                    ['product_code', 'product_id'], $code_patterns
                );
            }

            // Now update matched hotels using the map (no per-hotel queries)
            foreach ($orphaned as $hotel_id) {
                foreach ($prefixes as $prefix) {
                    $pid = $product_map[$prefix . $hotel_id] ?? null;
                    if (!empty($pid)) {
                        db_query("UPDATE ?:novoton_hotels SET product_id = ?i WHERE hotel_id = ?s",
                            $pid, $hotel_id);
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
            $this->output("Reconciliation: re-linked {$linked} hotels, cleared {$cleaned} stale references.");
        }
    }

    /**
     * Determine what type of sync is needed
     */
    private function determineSyncType(array $options): string
    {
        // Force full sync requested
        if (!empty($options['force_full'])) {
            return 'full';
        }

        // Check last full sync date
        $last_full_sync = db_get_field(
            "SELECT MAX(sync_date) FROM ?:novoton_sync_log
             WHERE sync_type = 'hotelinfo' AND status = 'completed'
             AND notes LIKE '%\"sync_type\":\"full\"%'"
        );

        // Never done full sync OR more than 6 months ago
        if (empty($last_full_sync)) {
            $this->output("No previous full sync found. Starting full sync.");
            return 'full';
        }

        $last_full_time = strtotime($last_full_sync);
        $time_since_full = time() - $last_full_time;

        if ($time_since_full > $this->full_sync_interval) {
            $this->output("Last full sync was " . round($time_since_full / 86400) . " days ago. Starting full sync.");
            return 'full';
        }

        // Check for incremental sync
        $last_incremental = db_get_field(
            "SELECT MAX(sync_date) FROM ?:novoton_sync_log
             WHERE sync_type = 'hotelinfo' AND status = 'completed'"
        );

        if (empty($last_incremental)) {
            return 'full';
        }

        // More than 24 hours since last sync - do incremental
        $time_since_last = time() - strtotime($last_incremental);
        if ($time_since_last > 24 * 3600) {
            return 'incremental';
        }

        return 'none';
    }

    /**
     * Get hotel IDs to sync based on sync type
     */
    private function getHotelIdsToSync(string $sync_type, array $options): array
    {
        $countries = $options['countries'] ?? $this->getConfiguredCountries();

        if ($sync_type === 'full') {
            // Full sync - all hotels in configured countries
            return db_get_fields(
                "SELECT hotel_id FROM ?:novoton_hotels
                 WHERE country IN (?a)
                 ORDER BY hotel_name",
                $countries
            );
        }

        // Incremental sync - get changed hotels from offers_update API
        return $this->getChangedHotelIds($countries);
    }

    /**
     * Get changed hotel IDs from offers_update API
     */
    private function getChangedHotelIds(array $countries): array
    {
        $api = $this->getApi();

        // Get last sync date
        $last_sync = db_get_field(
            "SELECT MAX(sync_date) FROM ?:novoton_sync_log
             WHERE sync_type = 'hotelinfo' AND status = 'completed'"
        );

        if (empty($last_sync)) {
            // No previous sync - should do full sync instead
            return [];
        }

        $datetime_param = date('Y-m-d\TH:i:s', strtotime($last_sync));
        $this->output("Checking offers_update since: {$datetime_param}");

        $changed_ids = [];

        foreach ($countries as $country) {
            $this->output("Checking {$country}...");

            try {
                $response = $api->getOffersUpdate($datetime_param, $country);

                if ($response && isset($response->Offer)) {
                    $offers = is_array($response->Offer) ? $response->Offer : [$response->Offer];
                    foreach ($offers as $offer) {
                        $hid = (string)($offer->IdHotel ?? '');
                        if (!empty($hid)) {
                            $changed_ids[$hid] = true;
                        }
                    }
                    $this->output("  Found " . count($offers) . " changed offers");
                } else {
                    $this->output("  No changes");
                }
            } catch (ApiException $e) {
                $this->output("  Error: " . $e->getMessage());
            }
        }

        // Also include hotels that never had hotelinfo synced
        $unsynced = db_get_fields(
            "SELECT hotel_id FROM ?:novoton_hotels
             WHERE country IN (?a) AND hotelinfo_synced_at IS NULL",
            $countries
        );

        if (!empty($unsynced)) {
            $this->output("Also adding " . count($unsynced) . " hotels that never had hotelinfo synced.");
            foreach ($unsynced as $id) {
                $changed_ids[$id] = true;
            }
        }

        return array_keys($changed_ids);
    }

    /**
     * Get configured countries — delegates to ConfigProvider::getSelectedCountries()
     */
    private function getConfiguredCountries(): array
    {
        return ConfigProvider::getSelectedCountries();
    }

    /**
     * Get current sync status
     */
    public function getStatus(): array
    {
        $state = $this->loadState();

        if (empty($state)) {
            // Check last completed sync
            $last_sync = db_get_row(
                "SELECT * FROM ?:novoton_sync_log
                 WHERE sync_type = 'hotelinfo' AND status = 'completed'
                 ORDER BY sync_date DESC LIMIT 1"
            );

            return [
                'status' => 'idle',
                'last_sync' => $last_sync['sync_date'] ?? null,
                'last_sync_type' => $this->extractSyncTypeFromNotes($last_sync['notes'] ?? ''),
                'last_total' => $last_sync['products_total'] ?? 0,
            ];
        }

        $percent = round($state['processed'] / max(1, $state['total']) * 100, 1);
        $remaining = $state['total'] - $state['processed'];
        $elapsed = time() - strtotime($state['started_at']);

        // Estimate time remaining
        $rate = $state['processed'] / max(1, $elapsed); // hotels per second
        $eta_seconds = $rate > 0 ? $remaining / $rate : 0;

        return [
            'status' => 'in_progress',
            'sync_type' => $state['sync_type'],
            'started_at' => $state['started_at'],
            'last_run_at' => $state['last_run_at'],
            'total' => $state['total'],
            'processed' => $state['processed'],
            'remaining' => $remaining,
            'percent' => $percent,
            'synced' => $state['synced'],
            'errors' => $state['errors'],
            'elapsed' => $this->formatDuration($elapsed),
            'eta' => $this->formatDuration((int)$eta_seconds),
        ];
    }

    /**
     * Extract sync type from notes JSON
     */
    private function extractSyncTypeFromNotes(string $notes): string
    {
        if (empty($notes)) {
            return 'unknown';
        }
        $data = json_decode($notes, true);
        return $data['sync_type'] ?? 'unknown';
    }

    /**
     * Format duration in human readable format
     */
    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }
        if ($seconds < 3600) {
            $m = floor($seconds / 60);
            $s = $seconds % 60;
            return "{$m}m {$s}s";
        }
        $h = floor($seconds / 3600);
        $m = floor(($seconds % 3600) / 60);
        return "{$h}h {$m}m";
    }

    /**
     * Load state from file
     */
    private function loadState(): array
    {
        if (file_exists($this->state_file)) {
            $content = file_get_contents($this->state_file);
            $state = json_decode($content, true);
            if (is_array($state)) {
                return $state;
            }
        }
        return [];
    }

    /**
     * Save state to file
     */
    private function saveState(array $state): void
    {
        file_put_contents($this->state_file, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * Clear state file
     */
    private function clearState(): void
    {
        if (file_exists($this->state_file)) {
            unlink($this->state_file);
        }
    }

    /**
     * Output helper that handles newlines
     */
    private function output(string $message, bool $newline = true): void
    {
        if ($this->output_callback) {
            call_user_func($this->output_callback, $message . ($newline ? "\n" : ""));
        } else {
            echo $message . ($newline ? "\n" : "");
            flush();
        }
    }

    /**
     * Check if memory usage is approaching the PHP memory_limit.
     * Uses 85% threshold to allow time for state save before OOM.
     */
    private function isMemoryLimitReached(): bool
    {
        $limit = ini_get('memory_limit');
        if ($limit === '-1' || $limit === false) {
            return false;
        }
        $limit = trim($limit);
        $bytes = (int)$limit;
        $unit = strtolower(substr($limit, -1));
        switch ($unit) {
            case 'g': $bytes *= 1024; // fall through
            case 'm': $bytes *= 1024; // fall through
            case 'k': $bytes *= 1024;
        }
        return memory_get_usage(true) > (int)($bytes * 0.85);
    }
}

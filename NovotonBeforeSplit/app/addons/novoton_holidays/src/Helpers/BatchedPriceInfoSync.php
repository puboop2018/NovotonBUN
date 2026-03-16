<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Batched Price Info Sync
 *
 * Handles price info synchronization with:
 * - Resume capability (saves state between runs)
 * - Full sync mode (all packages)
 * - Incremental sync mode (only stale packages)
 *
 * Usage:
 *   $sync = new BatchedPriceInfoSync();
 *   $result = $sync->run();
 *
 *   // Force full sync
 *   $sync->run(['force_full' => true]);
 *
 *   // Check status
 *   $status = $sync->getStatus();
 *
 * @package NovotonHolidays
 * @since 2.9.0
 */

namespace Tygh\Addons\NovotonHolidays\Helpers;

use Tygh\Registry;
use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\NovotonApi;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Exceptions\ApiException;

class BatchedPriceInfoSync
{
    use OutputWriterTrait;
    use SyncStateTrait;

    /**
     * State file path
     */
    private string $state_file;

    private int $batch_size;
    private int $max_execution_time;
    private bool $unlimited = false;
    private int $stale_hours = 24;
    private int $full_sync_interval;
    private ?NovotonApi $api = null;

    public function __construct()
    {
        $cache_dir = Registry::get('config.dir.cache_misc') ?? (DIR_ROOT . '/var/cache/');
        $this->state_file = $cache_dir . 'novoton/batch_priceinfo_state.json';

        // Ensure directory exists
        $dir = dirname($this->state_file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Load configurable values from addon settings
        $this->batch_size = ConfigProvider::getCronBatchSize();
        $this->max_execution_time = ConfigProvider::getCronMaxExecutionTime();
        $this->full_sync_interval = ConfigProvider::getSyncIntervalPriceInfo();

        // CLI has no execution time limit — skip artificial batching
        if (PHP_SAPI === 'cli') {
            $this->unlimited = true;
        }
    }

    /**
     * Set batch size
     */
    public function setBatchSize(int $size): void
    {
        $this->batch_size = max(10, min(200, $size));
    }

    /**
     * Set max execution time
     */
    public function setMaxExecutionTime(int $seconds): void
    {
        $this->max_execution_time = max(60, min(3600, $seconds));
    }

    /**
     * Set stale threshold in hours
     */
    public function setStaleHours(int $hours): void
    {
        $this->stale_hours = max(1, min(168, $hours)); // 1 hour to 7 days
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
     * @param array $options Options: force_full, reset, stale_hours
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

        // Override stale hours if provided
        if (!empty($options['stale_hours'])) {
            $this->setStaleHours((int)($options['stale_hours']));
        }

        // Check for active job to resume
        $state = $this->loadState();

        if (!empty($state) && $state['status'] === 'in_progress') {
            // Detect stale state: if no activity for 6+ hours, the previous
            // process likely died. Clear and start fresh.
            if ($this->isStateStale($state)) {
                $age = $this->stateAgeDescription($state);
                $this->output("Stale state detected ({$age} since last activity). Clearing and starting fresh.");
                $this->clearState();
                $state = [];
            } else {
                $this->output("Resuming {$state['sync_type']} sync...");
                $this->output("Progress: {$state['processed']}/{$state['total']} (" .
                    round($state['processed'] / max(1, $state['total']) * 100, 1) . "%)");
                return $this->resumeSync($state, $start_time);
            }
        }

        // Determine sync type needed
        $sync_type = $this->determineSyncType($options);

        if ($sync_type === 'none') {
            $this->output("No sync needed at this time.");
            return ['status' => 'skipped', 'reason' => 'No sync needed'];
        }

        $this->output("Starting {$sync_type} price info sync...");

        // Get packages to sync
        $packages = $this->getPackagesToSync($sync_type, $options);

        if (empty($packages)) {
            $this->output("No packages to sync.");
            return ['status' => 'skipped', 'reason' => 'No packages found'];
        }

        $this->output("Found " . count($packages) . " packages to sync.");

        // Store only minimal identifiers (hotel_id + package_id) to keep state file small
        $package_keys = array_map(function ($p) {
            return ['hotel_id' => $p['hotel_id'], 'package_id' => $p['package_id']];
        }, $packages);

        // Create new state
        $state = [
            'sync_type' => $sync_type,
            'status' => 'in_progress',
            'started_at' => date('Y-m-d H:i:s'),
            'last_run_at' => date('Y-m-d H:i:s'),
            'packages' => $package_keys,
            'total' => count($packages),
            'processed' => 0,
            'synced' => 0,
            'errors' => 0,
            'error_ids' => [],
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

            // Get next batch
            $batch = array_slice($state['packages'], $offset, $this->batch_size);

            if (empty($batch)) {
                break;
            }

            // Pre-fetch package_names for the batch to avoid N+1
            $pkg_name_map = [];
            if (!empty($batch)) {
                // Build OR conditions for batch lookup
                $where_parts = [];
                $where_params = [];
                foreach ($batch as $pkg) {
                    $where_parts[] = "(hotel_id = ?s AND package_id = ?s)";
                    $where_params[] = $pkg['hotel_id'];
                    $where_params[] = $pkg['package_id'];
                }
                $rows = call_user_func_array('db_get_array', array_merge(
                    ["SELECT hotel_id, package_id, package_name FROM ?:novoton_hotel_packages WHERE " . implode(' OR ', $where_parts)],
                    $where_params
                ));
                foreach ($rows as $r) {
                    $pkg_name_map[$r['hotel_id'] . '|' . $r['package_id']] = $r['package_name'];
                }
            }

            foreach ($batch as $pkg) {
                // Check time and memory limits within batch (skip if unlimited mode)
                if (!$this->unlimited) {
                    if ((time() - $start_time) > $this->max_execution_time || $this->isMemoryLimitReached()) {
                        break 2; // Exit both loops
                    }
                }

                $hotel_id = $pkg['hotel_id'];
                $package_id = $pkg['package_id'];
                $package_name = $pkg_name_map[$hotel_id . '|' . $package_id]
                    ?? $pkg['package_name']
                    ?? '';

                if (empty($package_name) || $package_name === '?') {
                    $this->output("[{$hotel_id}/{$package_id}] SKIP (no package_name)");
                    $offset++;
                    continue;
                }

                $this->output("[{$hotel_id}/{$package_id}] {$package_name} ... ", false);

                try {
                    // API requires PackageName, not package_id (IdCont)
                    $priceinfo = $api->getPriceInfo($hotel_id, $package_name);

                    if (!$priceinfo) {
                        $this->output("API returned empty");
                        $state['errors']++;
                        $state['error_ids'][] = "{$hotel_id}/{$package_id}";
                        $errors_this_run++;
                    } else {
                        // Process price info
                        $seasons_count = $this->processPriceInfo($hotel_id, $package_id, $priceinfo, $now);
                        $state['synced']++;
                        $synced_this_run++;
                        $this->output("OK ({$seasons_count} seasons)");
                    }
                } catch (ApiException $e) {
                    $this->output("ERROR: " . $e->getMessage());
                    $state['errors']++;
                    $state['error_ids'][] = "{$hotel_id}/{$package_id}";
                    $errors_this_run++;
                }

                $offset++;
                $processed_this_run++;

                // Small delay to avoid API rate limits
                usleep(Constants::API_DELAY_NORMAL);
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
            if (!empty($state['error_ids']) && empty($state['retry_done'])) {
                $retry_ids = array_unique($state['error_ids']);
                $this->output("\nRetrying " . count($retry_ids) . " failed packages...");
                $recovered = 0;
                foreach ($retry_ids as $retry_key) {
                    if (!$this->unlimited && (time() - $start_time) > $this->max_execution_time) {
                        break;
                    }
                    usleep(Constants::API_DELAY_BACKOFF);
                    $parts = explode('/', $retry_key, 2);
                    if (count($parts) !== 2) continue;
                    [$r_hotel_id, $r_package_id] = $parts;
                    // Lookup package_name from DB
                    $r_package_name = db_get_field(
                        "SELECT package_name FROM ?:novoton_hotel_packages WHERE hotel_id = ?s AND package_id = ?s",
                        $r_hotel_id, $r_package_id
                    );
                    if (empty($r_package_name)) continue;
                    try {
                        $priceinfo = $api->getPriceInfo($r_hotel_id, $r_package_name);
                        if ($priceinfo) {
                            $this->processPriceInfo($r_hotel_id, $r_package_id, $priceinfo, $now);
                            $recovered++;
                            $state['synced']++;
                            $state['errors']--;
                            $this->output("  [{$retry_key}] retry OK");
                        }
                    } catch (ApiException $e) {
                        $this->output("  [{$retry_key}] retry failed: " . $e->getMessage());
                    }
                }
                $state['retry_done'] = true;
                $this->saveState($state);
                if ($recovered > 0) {
                    $this->output("Recovered {$recovered} packages on retry.");
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
     * Process price info from API response.
     *
     * Stores raw priceinfo JSON and flags the package for recomputation
     * by the compute_prices cron. Does NOT compute min_price, seasons_count
     * or has_early_booking inline — that's the cron's job.
     */
    private function processPriceInfo(string $hotel_id, string $package_id, $priceinfo, string $now): int
    {
        // Count seasons for the return value (lightweight, used for output only)
        $seasons_count = 0;
        if (isset($priceinfo->seasons)) {
            foreach ($priceinfo->seasons as $season) {
                $seasons_count++;
            }
        }

        // Convert SimpleXML to array for reliable JSON encoding.
        // json_encode(SimpleXMLElement) can lose attributes and mishandle repeated siblings.
        $priceinfo_array = self::simpleXmlToArray($priceinfo);
        $priceinfo_json = json_encode($priceinfo_array);

        if ($priceinfo_json === false || $priceinfo_json === 'null') {
            // Fallback to direct encode if conversion failed
            $priceinfo_json = json_encode($priceinfo);
        }

        db_query(
            "UPDATE ?:novoton_hotel_packages SET
             priceinfo_data = ?s,
             needs_price_compute = 'Y',
             synced_at = ?s
             WHERE hotel_id = ?s AND package_id = ?s",
            $priceinfo_json,
            $now,
            $hotel_id,
            $package_id
        );

        return $seasons_count;
    }

    /**
     * Reliably convert SimpleXMLElement to associative array.
     * Handles repeated siblings (same-named elements) as arrays,
     * preserves text content, and includes attributes.
     */
    private static function simpleXmlToArray($xml): array
    {
        if (!($xml instanceof \SimpleXMLElement)) {
            return [];
        }

        $result = [];

        // Include attributes
        foreach ($xml->attributes() as $attrName => $attrValue) {
            $result['@' . $attrName] = (string)$attrValue;
        }

        // Process child elements
        foreach ($xml->children() as $name => $child) {
            $value = ($child->count() > 0) ? self::simpleXmlToArray($child) : (string)$child;

            // Handle repeated siblings: convert to array
            if (isset($result[$name])) {
                if (!is_array($result[$name]) || !isset($result[$name][0])) {
                    $result[$name] = [$result[$name]];
                }
                $result[$name][] = $value;
            } else {
                $result[$name] = $value;
            }
        }

        return $result;
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
             sync_type = 'sync_priceinfo',
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
            json_encode(['sync_type' => $state['sync_type']])
        );

        // Clear state file
        $this->clearState();

        $this->output("\n========================================");
        $this->output("PRICE INFO SYNC COMPLETED");
        $this->output("========================================");
        $this->output("Type: {$state['sync_type']}");
        $this->output("Total: {$state['total']}");
        $this->output("Synced: {$state['synced']}");
        $this->output("Errors: {$state['errors']}");
        $this->output("Duration: " . $this->formatDuration($duration));
        $this->output("========================================");
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
             WHERE sync_type = 'sync_priceinfo' AND status = 'completed'
             AND notes LIKE '%\"sync_type\":\"full\"%'"
        );

        // Never done full sync OR more than 7 days ago
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

        // Check for incremental sync (stale packages)
        $stale_count = db_get_field(
            "SELECT COUNT(*) FROM ?:novoton_hotel_packages
             WHERE synced_at IS NULL OR synced_at < DATE_SUB(NOW(), INTERVAL ?i HOUR)",
            $this->stale_hours
        );

        if ($stale_count > 0) {
            $this->output("Found {$stale_count} stale packages (older than {$this->stale_hours}h).");
            return 'incremental';
        }

        return 'none';
    }

    /**
     * Get packages to sync based on sync type
     */
    private function getPackagesToSync(string $sync_type, array $options): array
    {
        $countries = $this->getConfiguredCountries();

        if ($sync_type === 'full') {
            // Full sync - all packages
            return db_get_array(
                "SELECT p.hotel_id, p.package_id, p.package_name, h.hotel_name
                 FROM ?:novoton_hotel_packages p
                 JOIN ?:novoton_hotels h ON p.hotel_id = h.hotel_id
                 WHERE h.country IN (?a)
                 ORDER BY h.hotel_name, p.package_name",
                $countries
            );
        }

        // Incremental sync - only stale packages
        return db_get_array(
            "SELECT p.hotel_id, p.package_id, p.package_name, h.hotel_name
             FROM ?:novoton_hotel_packages p
             JOIN ?:novoton_hotels h ON p.hotel_id = h.hotel_id
             WHERE h.country IN (?a)
             AND (p.synced_at IS NULL OR p.synced_at < DATE_SUB(NOW(), INTERVAL ?i HOUR))
             ORDER BY p.synced_at ASC, h.hotel_name",
            $countries,
            $this->stale_hours
        );
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
                 WHERE sync_type = 'sync_priceinfo' AND status = 'completed'
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
        $rate = $state['processed'] / max(1, $elapsed); // packages per second
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

}

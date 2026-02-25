<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Batched Hotel Facilities Sync
 *
 * Syncs per-hotel facility assignments in batches with resume capability.
 * Each hotel requires 1 API call (function 27: getHotelFacilities).
 *
 * Usage:
 *   $sync = new BatchedHotelFacilitiesSync();
 *   $result = $sync->run();
 *
 *   // Force full sync
 *   $sync->run(['force_full' => true]);
 *
 *   // Check status only
 *   $status = $sync->getStatus();
 *
 * @package NovotonHolidays
 * @since 3.4.0
 */

namespace Tygh\Addons\NovotonHolidays\Helpers;

use Tygh\Registry;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;

class BatchedHotelFacilitiesSync
{
    use OutputWriterTrait;

    private string $state_file;
    private int $batch_size = 100;
    private int $max_execution_time = 300; // 5 minutes
    private bool $unlimited = false;

    /** Full sync interval — 30 days (facilities change infrequently) */
    private int $full_sync_interval = 30 * 24 * 3600;

    /** Delay between API calls in microseconds (100ms) */
    private int $api_delay_us = 100000;

    public function __construct()
    {
        $cache_dir = Registry::get('config.dir.cache_misc') ?? (DIR_ROOT . '/var/cache/');
        $this->state_file = $cache_dir . 'novoton/batch_hotel_facilities_state.json';

        $dir = dirname($this->state_file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // CLI has no execution time limit — skip artificial batching
        if (PHP_SAPI === 'cli') {
            $this->unlimited = true;
        }
    }

    public function setBatchSize(int $size): void
    {
        $this->batch_size = max(10, min(500, $size));
    }

    public function setMaxExecutionTime(int $seconds): void
    {
        $this->max_execution_time = max(60, min(3600, $seconds));
    }

    public function setUnlimited(bool $unlimited): void
    {
        $this->unlimited = $unlimited;
    }

    /**
     * Main entry point — run sync
     *
     * @param array $options Options: force_full, reset
     * @return array Result with status, processed, total, etc.
     */
    public function run(array $options = []): array
    {
        $start_time = time();

        if (!empty($options['reset'])) {
            $this->clearState();
            $this->output("State reset. Ready for new sync.");
            return ['status' => 'reset'];
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
                $this->output("Resuming hotel facilities sync...");
                $this->output("Progress: {$state['processed']}/{$state['total']} (" .
                    round($state['processed'] / max(1, $state['total']) * 100, 1) . "%)");
                return $this->resumeSync($state, $start_time);
            }
        }

        // Determine sync type
        $sync_type = $this->determineSyncType($options);

        if ($sync_type === 'none') {
            $this->output("No sync needed at this time.");
            return ['status' => 'skipped', 'reason' => 'No sync needed'];
        }

        $this->output("Starting {$sync_type} hotel facilities sync...");

        $hotel_ids = $this->getHotelIdsToSync($sync_type);

        if (empty($hotel_ids)) {
            $this->output("No hotels to sync.");
            return ['status' => 'skipped', 'reason' => 'No hotels found'];
        }

        $this->output("Found " . count($hotel_ids) . " hotels to sync.");

        // Store IDs in state only for small sets; use DB pagination for large ones
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
            'countries' => ConfigProvider::getSelectedCountries(),
        ];

        $this->saveState($state);

        return $this->resumeSync($state, $start_time);
    }

    /**
     * Resume an in-progress sync
     */
    private function resumeSync(array $state, int $start_time): array
    {
        $synced_this_run = 0;
        $errors_this_run = 0;
        $processed_this_run = 0;
        $offset = $state['processed'];

        while ($offset < $state['total']) {
            // Check time and memory limits
            if (!$this->unlimited) {
                if ((time() - $start_time) > $this->max_execution_time) {
                    $this->output("\nTime limit reached. Saving state for resume.");
                    break;
                }
                if ($this->isMemoryLimitReached()) {
                    $this->output("\nMemory limit approaching. Saving state for resume.");
                    break;
                }
            }

            // Get next batch
            if (!empty($state['hotel_ids'])) {
                $batch = array_slice($state['hotel_ids'], $offset, $this->batch_size);
            } else {
                $countries = $state['countries'] ?? ConfigProvider::getSelectedCountries();
                $batch = db_get_fields(
                    "SELECT hotel_id FROM ?:novoton_hotels WHERE country IN (?a) ORDER BY hotel_name LIMIT ?i OFFSET ?i",
                    $countries, $this->batch_size, $offset
                );
            }

            if (empty($batch)) {
                break;
            }

            // Pre-fetch hotel names for output
            $hotel_names = db_get_hash_single_array(
                "SELECT hotel_id, hotel_name FROM ?:novoton_hotels WHERE hotel_id IN (?a)",
                ['hotel_id', 'hotel_name'], $batch
            );

            foreach ($batch as $hotel_id) {
                // Check limits within batch
                if (!$this->unlimited) {
                    if ((time() - $start_time) > $this->max_execution_time || $this->isMemoryLimitReached()) {
                        break 2;
                    }
                }

                $hotel_name = $hotel_names[$hotel_id] ?? '?';
                $this->output("[{$hotel_id}] {$hotel_name} ... ", false);

                try {
                    $result = fn_novoton_holidays_sync_hotel_facilities($hotel_id);

                    if ($result) {
                        $count = (int) db_get_field(
                            "SELECT COUNT(*) FROM ?:novoton_hotel_facilities WHERE hotel_id = ?s",
                            $hotel_id
                        );
                        $this->output("OK ({$count} facilities)");
                        $state['synced']++;
                        $synced_this_run++;
                    } else {
                        $this->output("EMPTY/FAILED");
                        $state['errors']++;
                        $state['error_ids'][] = $hotel_id;
                        $errors_this_run++;
                    }
                } catch (\Exception $e) {
                    $this->output("ERROR: " . $e->getMessage());
                    $state['errors']++;
                    $state['error_ids'][] = $hotel_id;
                    $errors_this_run++;
                }

                $offset++;
                $processed_this_run++;

                // Delay between API calls to avoid hammering
                usleep($this->api_delay_us);
            }

            // Save state after each batch
            $state['processed'] = $offset;
            $state['last_run_at'] = date('Y-m-d H:i:s');
            $this->saveState($state);

            $percent = round($offset / max(1, $state['total']) * 100, 1);
            $this->output("--- Progress: {$offset}/{$state['total']} ({$percent}%) ---");
        }

        // Check if complete — retry failed items
        if ($offset >= $state['total']) {
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
                        if (fn_novoton_holidays_sync_hotel_facilities($retry_id)) {
                            $recovered++;
                            $recovered_ids[] = $retry_id;
                            $state['synced']++;
                            $state['errors']--;
                            $this->output("  [{$retry_id}] retry OK");
                        }
                    } catch (\Exception $e) {
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

        // Still in progress
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

    private function completeSync(array $state): void
    {
        $duration = time() - strtotime($state['started_at']);

        db_query(
            "INSERT INTO ?:novoton_sync_log SET
             sync_type = 'hotel_facilities',
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

        $this->clearState();

        $this->output("\n========================================");
        $this->output("HOTEL FACILITIES SYNC COMPLETED");
        $this->output("========================================");
        $this->output("Type: {$state['sync_type']}");
        $this->output("Total: {$state['total']}");
        $this->output("Synced: {$state['synced']}");
        $this->output("Errors: {$state['errors']}");
        $this->output("Duration: " . $this->formatDuration($duration));
        $this->output("========================================");
    }

    private function determineSyncType(array $options): string
    {
        if (!empty($options['force_full'])) {
            return 'full';
        }

        $last_sync = db_get_field(
            "SELECT MAX(sync_date) FROM ?:novoton_sync_log
             WHERE sync_type = 'hotel_facilities' AND status = 'completed'"
        );

        if (empty($last_sync)) {
            $this->output("No previous hotel facilities sync found. Starting full sync.");
            return 'full';
        }

        $time_since = time() - strtotime($last_sync);

        if ($time_since > $this->full_sync_interval) {
            $this->output("Last sync was " . round($time_since / 86400) . " days ago. Starting full sync.");
            return 'full';
        }

        // Check for hotels that never had facilities synced
        $countries = ConfigProvider::getSelectedCountries();
        $unsynced = (int) db_get_field(
            "SELECT COUNT(*) FROM ?:novoton_hotels h
             LEFT JOIN ?:novoton_hotel_facilities hf ON h.hotel_id = hf.hotel_id
             WHERE h.country IN (?a) AND hf.hotel_id IS NULL",
            $countries
        );

        if ($unsynced > 0) {
            $this->output("{$unsynced} hotels have no facilities. Starting incremental sync.");
            return 'incremental';
        }

        return 'none';
    }

    private function getHotelIdsToSync(string $sync_type): array
    {
        $countries = ConfigProvider::getSelectedCountries();

        if ($sync_type === 'full') {
            return db_get_fields(
                "SELECT hotel_id FROM ?:novoton_hotels
                 WHERE country IN (?a)
                 ORDER BY hotel_name",
                $countries
            );
        }

        // Incremental — only hotels without any facilities
        return db_get_fields(
            "SELECT h.hotel_id FROM ?:novoton_hotels h
             LEFT JOIN ?:novoton_hotel_facilities hf ON h.hotel_id = hf.hotel_id
             WHERE h.country IN (?a) AND hf.hotel_id IS NULL
             ORDER BY h.hotel_name",
            $countries
        );
    }

    /**
     * Get current sync status
     */
    public function getStatus(): array
    {
        $state = $this->loadState();

        if (empty($state)) {
            $last_sync = db_get_row(
                "SELECT * FROM ?:novoton_sync_log
                 WHERE sync_type = 'hotel_facilities' AND status = 'completed'
                 ORDER BY sync_date DESC LIMIT 1"
            );

            return [
                'status' => 'idle',
                'last_sync' => $last_sync['sync_date'] ?? null,
                'last_total' => $last_sync['products_total'] ?? 0,
            ];
        }

        $percent = round($state['processed'] / max(1, $state['total']) * 100, 1);
        $remaining = $state['total'] - $state['processed'];
        $elapsed = time() - strtotime($state['started_at']);
        $rate = $state['processed'] / max(1, $elapsed);
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

    private function saveState(array $state): void
    {
        file_put_contents($this->state_file, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * Clear state file and auxiliary files (.bak, .lock, .tmp)
     */
    private function clearState(): void
    {
        foreach (['', '.bak', '.lock', '.tmp'] as $suffix) {
            $file = $this->state_file . $suffix;
            if (file_exists($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * Check if an in-progress state is stale (no activity for 6+ hours).
     */
    private function isStateStale(array $state, int $maxAgeHours = 6): bool
    {
        $lastRun = $state['last_run_at'] ?? $state['started_at'] ?? null;
        if ($lastRun === null) {
            return true;
        }
        return (time() - strtotime($lastRun)) > ($maxAgeHours * 3600);
    }

    /**
     * Human-readable description of state age.
     */
    private function stateAgeDescription(array $state): string
    {
        $lastRun = $state['last_run_at'] ?? $state['started_at'] ?? null;
        if ($lastRun === null) {
            return 'unknown age';
        }
        $hours = round((time() - strtotime($lastRun)) / 3600, 1);
        return "{$hours}h";
    }

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

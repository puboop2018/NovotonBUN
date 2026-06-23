<?php

declare(strict_types=1);

/**
 * State Manager Interface
 *
 * Contract for state persistence in batched sync operations.
 * Supports save/load, progress tracking, batch retrieval, and locking.
 *
 * @package NovotonHolidays
 * @since 3.5.0
 */

namespace Tygh\Addons\NovotonHolidays\Helpers;

interface StateManagerInterface
{
    /**
     * Load state from file.
     *
     * @return array<string, mixed> State array
     */
    public function load(): array;

    /**
     * Save state to file with locking.
     *
     * @param array<string, mixed> $state State array
     * @return bool Success
     */
    public function save(array $state): bool;

    /**
     * Clear state file.
     *
     * @return bool Success
     */
    public function clear(): bool;

    /**
     * Check if a sync is in progress.
     */
    public function isInProgress(): bool;

    /**
     * Start a new sync.
     *
     * @param string $syncType Type of sync (e.g., 'full', 'incremental')
     * @param array<int|string, mixed> $itemIds Items to process (a flat list of ids)
     * @param array<string, mixed> $metadata Additional metadata
     * @return array<string, mixed> New state
     */
    public function start(string $syncType, array $itemIds, array $metadata = []): array;

    /**
     * Update progress counters.
     *
     * @param list<string> $errorIds
     * @return array<string, mixed> Updated state
     */
    public function updateProgress(int $processed, int $synced, int $errors, array $errorIds = []): array;

    /**
     * Increment counters.
     *
     * @return array<string, mixed> Updated state
     */
    public function increment(int $processed = 0, int $synced = 0, int $errors = 0, ?string $errorId = null): array;

    /**
     * Mark sync as completed.
     *
     * @return array<string, mixed> Final state
     */
    public function complete(): array;

    /**
     * Get current status with calculated fields.
     *
     * @return array<string, mixed> Status with percent, eta, etc.
     */
    public function getStatus(): array;

    /**
     * Get the next batch of item IDs to process.
     *
     * @return list<mixed> Array of item IDs
     */
    public function getNextBatch(int $batchSize): array;

    /**
     * Check if sync should be resumed.
     */
    public function shouldResume(): bool;

    /**
     * Check if the current in-progress state is stale (abandoned).
     *
     * A state is stale when last_run_at is older than the given threshold,
     * meaning the process that was running the sync has likely died.
     *
     * @param int $maxAgeHours Maximum age in hours before state is considered stale
     * @return bool True if state exists, is in_progress, and is stale
     */
    public function isStale(int $maxAgeHours = 6): bool;

    /**
     * Acquire an exclusive lock.
     *
     * @param int $timeout Seconds to wait for lock
     * @return bool Success
     */
    public function acquireLock(int $timeout = 5): bool;

    /**
     * Release the lock.
     */
    public function releaseLock(): void;

    /**
     * Get state file path.
     */
    public function getStateFilePath(): string;
}

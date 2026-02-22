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
     * @return array State array
     */
    public function load(): array;

    /**
     * Save state to file with locking.
     *
     * @param array $state State array
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
     *
     * @return bool
     */
    public function isInProgress(): bool;

    /**
     * Start a new sync.
     *
     * @param string $syncType Type of sync (e.g., 'full', 'incremental')
     * @param array  $itemIds  Items to process
     * @param array  $metadata Additional metadata
     * @return array New state
     */
    public function start(string $syncType, array $itemIds, array $metadata = []): array;

    /**
     * Update progress counters.
     *
     * @param int   $processed
     * @param int   $synced
     * @param int   $errors
     * @param array $errorIds
     * @return array Updated state
     */
    public function updateProgress(int $processed, int $synced, int $errors, array $errorIds = []): array;

    /**
     * Increment counters.
     *
     * @param int         $processed
     * @param int         $synced
     * @param int         $errors
     * @param string|null $errorId
     * @return array Updated state
     */
    public function increment(int $processed = 0, int $synced = 0, int $errors = 0, ?string $errorId = null): array;

    /**
     * Mark sync as completed.
     *
     * @return array Final state
     */
    public function complete(): array;

    /**
     * Get current status with calculated fields.
     *
     * @return array Status with percent, eta, etc.
     */
    public function getStatus(): array;

    /**
     * Get the next batch of item IDs to process.
     *
     * @param int $batchSize
     * @return array Array of item IDs
     */
    public function getNextBatch(int $batchSize): array;

    /**
     * Check if sync should be resumed.
     *
     * @return bool
     */
    public function shouldResume(): bool;

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
     *
     * @return string
     */
    public function getStateFilePath(): string;
}

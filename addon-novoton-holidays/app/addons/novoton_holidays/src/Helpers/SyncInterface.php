<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Sync Interface
 *
 * Defines a unified interface for all sync operations.
 * Implementations provide consistent behavior for:
 * - Batched processing with resume
 * - Progress tracking
 * - Logging and reporting
 *
 * @package NovotonHolidays
 * @since 3.1.0
 */

namespace Tygh\Addons\NovotonHolidays\Helpers;

interface SyncInterface
{
    /**
     * Run the sync operation
     *
     * @param array<string, mixed> $options Options for the sync (e.g., force_full, reset)
     * @return array<string, mixed> Result with status, counts, and other info
     */
    public function run(array $options = []): array;

    /**
     * Get current status of the sync
     *
     * @return array<string, mixed> Status info including progress if in progress
     */
    public function getStatus(): array;

    /**
     * Set batch size for processing
     *
     * @param int $size Number of items per batch
     */
    public function setBatchSize(int $size): void;

    /**
     * Set maximum execution time per run
     *
     * @param int $seconds Maximum seconds
     */
    public function setMaxExecutionTime(int $seconds): void;

    /**
     * Set unlimited mode (no time limit)
     *
     * @param bool $unlimited
     */
    public function setUnlimited(bool $unlimited): void;

    /**
     * Set output callback for logger.
     *
     * @param callable $callback
     */
    public function setOutputCallback(callable $callback): void;
}
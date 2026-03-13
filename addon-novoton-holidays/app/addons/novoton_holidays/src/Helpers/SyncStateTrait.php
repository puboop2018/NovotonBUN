<?php
declare(strict_types=1);
/**
 * Shared state-file management for legacy batched sync classes.
 *
 * Extracts the identical loadState/saveState/clearState/isStateStale/
 * stateAgeDescription/formatDuration/isMemoryLimitReached methods that
 * were copy-pasted across BatchedHotelInfoSync, BatchedPriceInfoSync,
 * and BatchedHotelFacilitiesSync.
 *
 * Classes using this trait MUST declare:
 *   private string $state_file;
 *
 * @package NovotonHolidays
 * @since   3.6.0
 */

namespace Tygh\Addons\NovotonHolidays\Helpers;

trait SyncStateTrait
{
    // Requires: private string $state_file  (set by the using class)

    /**
     * Load state from JSON file.
     *
     * @return array State array, or empty array if no active state
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
     * Persist state to JSON file.
     */
    private function saveState(array $state): void
    {
        $dir = dirname($this->state_file);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($this->state_file, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * Delete the state file.
     */
    private function clearState(): void
    {
        if (file_exists($this->state_file)) {
            unlink($this->state_file);
        }
    }

    /**
     * Check if an in-progress state is stale (no activity for $maxAgeHours).
     *
     * A stale state means the previous sync process likely died (OOM, crash,
     * server restart). Callers should clear the state and start fresh.
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
     * Human-readable description of how long ago the state was last active.
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

    /**
     * Format a duration in seconds as a human-readable string.
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
     * Check if memory usage is approaching the PHP memory_limit.
     *
     * Returns true when current usage exceeds 85% of the limit,
     * giving the process time to save state before OOM.
     */
    private function isMemoryLimitReached(): bool
    {
        $limit = ini_get('memory_limit');
        if ($limit === '-1' || $limit === false) {
            return false;
        }
        $limit = trim($limit);
        $bytes = (int) $limit;
        $unit  = strtolower(substr($limit, -1));
        switch ($unit) {
            case 'g': $bytes *= 1024; // fall through
            case 'm': $bytes *= 1024; // fall through
            case 'k': $bytes *= 1024;
        }
        return memory_get_usage(true) > (int) ($bytes * 0.85);
    }
}

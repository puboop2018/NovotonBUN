<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\SphinxApi;

/**
 * Base class for Sphinx sync services.
 *
 * Provides the Template Method pattern for sync orchestration:
 * timer, logging, stats, exception handling, and rate limit capture
 * are handled here. Concrete services implement doSync() with their
 * entity-specific logic.
 */
abstract class AbstractSyncService
{
    protected SphinxApi $api;

    private ?\Closure $outputCallback = null;

    public function __construct(SphinxApi $api)
    {
        $this->api = $api;
    }

    public function setOutputCallback(\Closure $callback): void
    {
        $this->outputCallback = $callback;
    }

    /**
     * Template method: orchestrates the full sync lifecycle.
     *
     * @param bool $fullSync Force full re-fetch (ignores updated_since)
     * @param array<string, mixed> $context Additional parameters for the concrete service
     * @return array{success: bool, total: int, synced: int, skipped: int, failed: int, duration_ms: int, error: string, sync_mode: string}
     */
    protected function runSync(bool $fullSync, array $context = []): array
    {
        $startMs = (int) (microtime(true) * 1000);
        $logId = $this->logStart($this->getSyncType());

        $stats = [
            'success' => false,
            'total' => 0,
            'synced' => 0,
            'skipped' => 0,
            'failed' => 0,
            'duration_ms' => 0,
            'error' => '',
            'sync_mode' => 'full',
        ];

        try {
            $stats = $this->doSync($fullSync, $stats, $context);
        } catch (\Throwable $e) {
            $stats['error'] = $e->getMessage();
            $this->output('EXCEPTION: ' . $e->getMessage());

            fn_log_event('general', 'runtime', [
                'message' => "Sphinx {$this->getSyncType()} sync failed: " . $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        $stats['duration_ms'] = (int) (microtime(true) * 1000) - $startMs;

        // Capture rate limit info from the HTTP client
        $httpClient = $this->api->getHttpClient();
        $stats['rate_limit'] = $httpClient->getRateLimitState();
        $stats['rate_limit_hits'] = $httpClient->getRateLimitHitCount();

        $this->logComplete($logId, $stats['success'] ? 'completed' : 'failed', $stats);

        /** @var array{success: bool, total: int, synced: int, skipped: int, failed: int, duration_ms: int, error: string, sync_mode: string} $stats */
        return $stats;
    }

    /**
     * Return the sync type identifier (e.g. 'hotels', 'destinations').
     * Used for sync log entries.
     */
    abstract protected function getSyncType(): string;

    /**
     * Core sync logic — each service implements its own flow.
     *
     * @param bool $fullSync Whether this is a full or incremental sync
     * @param array<string, mixed> $stats Initialized stats array to populate
     * @param array<string, mixed> $context Additional parameters (country codes, destination IDs, etc.)
     * @return array<string, mixed> The populated stats array
     */
    abstract protected function doSync(bool $fullSync, array $stats, array $context): array;

    /**
     * Extract items array from a paginated API response.
     *
     * Handles Sphinx API's inconsistent response wrappers:
     * {'data': [...]} or {'items': [...]} or bare [...].
     * @param array<int|string, mixed> $response
     * @return list<mixed>
     */
    protected function extractItems(array $response): array
    {
        $items = $response['data'] ?? $response['items'] ?? $response;

        if (isset($response[0]) && !isset($response['data'])) {
            $items = $response;
        }

        return is_array($items) ? array_values($items) : [];
    }

    /**
     * Check if there are more pages to fetch.
     *
     * @param array<string, mixed> $response Full API response (with pagination metadata)
     * @param int $currentPage Current page number
     * @param int $perPage Items per page
     * @param int $fetchedSoFar Total items fetched across all pages
     */
    protected function hasMorePages(array $response, int $currentPage, int $perPage, int $fetchedSoFar): bool
    {
        $lastPage = $response['last_page'] ?? $response['meta']['last_page'] ?? null;
        if ($lastPage !== null && $currentPage >= (int) $lastPage) {
            return false;
        }

        $totalItems = $response['total'] ?? $response['meta']['total'] ?? null;
        if ($totalItems !== null && $fetchedSoFar >= (int) $totalItems) {
            return false;
        }

        $pageItems = $this->extractItems($response);
        if (count($pageItems) < $perPage) {
            return false;
        }

        return true;
    }

    protected function output(string $message): void
    {
        if ($this->outputCallback !== null) {
            ($this->outputCallback)($message);
        }
    }

    private function logStart(string $syncType): int
    {
        db_query(
            "INSERT INTO ?:sphinx_sync_log (sync_type, status, started_at) VALUES (?s, 'started', NOW())",
            $syncType,
        );

        return (int) db_get_field('SELECT LAST_INSERT_ID()');
    }

    /**
     * @param array<string, mixed> $stats
     */
    private function logComplete(int $logId, string $status, array $stats): void
    {
        if ($logId <= 0) {
            return;
        }

        db_query(
            'UPDATE ?:sphinx_sync_log SET
                status = ?s,
                items_total = ?i,
                items_synced = ?i,
                items_failed = ?i,
                error_message = ?s,
                duration_ms = ?i,
                rate_limit_hits = ?i,
                sync_mode = ?s,
                completed_at = NOW()
             WHERE log_id = ?i',
            $status,
            $stats['total'] ?? 0,
            $stats['synced'] ?? 0,
            $stats['failed'] ?? 0,
            $stats['error'] ?? '',
            $stats['duration_ms'] ?? 0,
            $stats['rate_limit_hits'] ?? 0,
            $stats['sync_mode'] ?? 'full',
            $logId,
        );
    }
}

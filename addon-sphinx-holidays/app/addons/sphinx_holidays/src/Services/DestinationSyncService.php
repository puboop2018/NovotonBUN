<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Addons\SphinxHolidays\Repository\DestinationRepository;

/**
 * Fetches destinations from the Sphinx API and syncs them into the local DB.
 *
 * Handles pagination, normalization, and sync-log tracking.
 */
class DestinationSyncService
{
    private SphinxApi $api;
    private DestinationRepository $repository;

    /** @var callable|null Output callback for progress messages */
    private $outputCallback = null;

    public function __construct(SphinxApi $api, DestinationRepository $repository)
    {
        $this->api = $api;
        $this->repository = $repository;
    }

    /**
     * Set a callback for progress output (used by cron commands).
     */
    public function setOutputCallback(callable $callback): void
    {
        $this->outputCallback = $callback;
    }

    /**
     * Run a destination sync (incremental by default, full if forced or first run).
     *
     * @param bool $fullSync Force full re-fetch (ignores updated_since)
     * @return array{success: bool, total: int, synced: int, failed: int, duration_ms: int, error: string, sync_mode: string}
     */
    public function sync(bool $fullSync = false): array
    {
        $startMs = (int) (microtime(true) * 1000);
        $logId = $this->logStart('destinations');

        $stats = [
            'success' => false,
            'total' => 0,
            'synced' => 0,
            'failed' => 0,
            'duration_ms' => 0,
            'error' => '',
            'sync_mode' => 'full',
        ];

        try {
            // Determine sync mode: incremental (updated_since) or full
            $updatedSince = null;
            if (!$fullSync) {
                $lastSynced = $this->repository->getLastSyncedAt();
                if ($lastSynced !== null) {
                    $updatedSince = $lastSynced;
                    $stats['sync_mode'] = 'incremental';
                }
            }

            if ($updatedSince !== null) {
                $this->output("Starting incremental destination sync (since {$updatedSince})...");
            } else {
                $this->output('Starting full destination sync...');
            }

            $page = 1;
            $perPage = 1000;
            $batchSize = 100;

            // Stream-and-upsert: fetch each page and upsert immediately
            // instead of accumulating all destinations in memory.
            while (true) {
                $this->output("Fetching page {$page}...");

                $response = $this->api->getDestinations($page, $perPage, $updatedSince);

                if ($response === null) {
                    $httpClient = $this->api->getHttpClient();
                    $stats['error'] = 'API request failed: ' . $httpClient->getLastError();
                    $this->output('ERROR: ' . $stats['error']);
                    break;
                }

                // Sphinx API returns paginated data — extract the items array
                $items = $response['data'] ?? $response['items'] ?? $response;

                // If response is the top-level array itself (no wrapper)
                if (isset($response[0]) && !isset($response['data'])) {
                    $items = $response;
                }

                if (!is_array($items) || empty($items)) {
                    // No more pages
                    break;
                }

                // Normalize and upsert this page's items immediately
                $pageBatch = [];
                foreach ($items as $item) {
                    $normalized = $this->normalizeDestination($item);
                    if ($normalized !== null) {
                        $pageBatch[] = $normalized;
                        $stats['total']++;

                        // Flush batch when full
                        if (count($pageBatch) >= $batchSize) {
                            $affected = $this->repository->upsertBatch($pageBatch);
                            $stats['synced'] += $affected;
                            $pageBatch = [];
                        }
                    }
                }

                // Flush remaining items from this page
                if (!empty($pageBatch)) {
                    $affected = $this->repository->upsertBatch($pageBatch);
                    $stats['synced'] += $affected;
                }

                $this->output("  Page {$page}: " . count($items) . ' items fetched, ' . $stats['total'] . ' total so far');

                // Check if there are more pages
                $lastPage = $response['last_page'] ?? $response['meta']['last_page'] ?? null;
                $totalItems = $response['total'] ?? $response['meta']['total'] ?? null;

                if ($lastPage !== null && $page >= (int) $lastPage) {
                    break;
                }
                if ($totalItems !== null && $stats['total'] >= (int) $totalItems) {
                    break;
                }
                if (count($items) < $perPage) {
                    // Partial page = last page
                    break;
                }

                $page++;
            }

            if ($stats['total'] === 0 && !empty($stats['error'])) {
                $this->logComplete($logId, 'failed', $stats);
                return $stats;
            }

            if ($stats['total'] === 0 && $updatedSince !== null) {
                $this->output('No destinations updated since last sync. Everything is up to date.');
                $stats['success'] = true;
                $stats['synced'] = 0;
            } else {
                $stats['failed'] = $stats['total'] - $stats['synced'];
                $stats['success'] = true;

                // Build full_path breadcrumbs for disambiguation (chunked)
                $this->output('Building destination breadcrumb paths...');
                $pathsUpdated = $this->repository->buildFullPaths();
                $this->output("Updated {$pathsUpdated} full_path breadcrumbs.");
            }

            $this->output("Sync complete: {$stats['synced']}/{$stats['total']} destinations synced ({$stats['sync_mode']}).");
        } catch (\Throwable $e) {
            $stats['error'] = $e->getMessage();
            $this->output('EXCEPTION: ' . $e->getMessage());

            fn_log_event('general', 'runtime', [
                'message' => 'Sphinx destination sync failed: ' . $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        $stats['duration_ms'] = (int) (microtime(true) * 1000) - $startMs;

        // Capture rate limit info from the HTTP client
        $httpClient = $this->api->getHttpClient();
        $stats['rate_limit'] = $httpClient->getRateLimitState();
        $stats['rate_limit_hits'] = $httpClient->getRateLimitHitCount();

        $this->logComplete($logId, $stats['success'] ? 'completed' : 'failed', $stats);

        return $stats;
    }

    /**
     * Normalize a raw API destination into the DB column format.
     */
    private function normalizeDestination(array $raw): ?array
    {
        $id = (int) ($raw['id'] ?? $raw['destination_id'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        // Determine destination type from API data
        $type = strtolower((string) ($raw['type'] ?? $raw['destination_type'] ?? 'destination'));

        // Map common API type values to our canonical types
        $typeMap = [
            'continent' => 'continent',
            'country'   => 'country',
            'region'    => 'region',
            'city'      => 'city',
            'resort'    => 'destination',
            'area'      => 'region',
            'zone'      => 'region',
        ];
        $type = $typeMap[$type] ?? $type;

        return [
            'destination_id' => $id,
            'name'           => (string) ($raw['name'] ?? $raw['title'] ?? ''),
            'type'           => $type,
            'parent_id'      => (int) ($raw['parent_id'] ?? $raw['parent'] ?? 0),
            'country_code'   => (string) ($raw['country_code'] ?? $raw['iso'] ?? $raw['iso_code'] ?? ''),
            'geoname_id'     => (int) ($raw['geoname_id'] ?? $raw['geonames_id'] ?? 0),
            'latitude'       => (float) ($raw['latitude'] ?? $raw['lat'] ?? 0),
            'longitude'      => (float) ($raw['longitude'] ?? $raw['lng'] ?? $raw['lon'] ?? 0),
            'hotel_count'    => (int) ($raw['hotel_count'] ?? $raw['hotels_count'] ?? 0),
        ];
    }

    /**
     * Write a sync log entry (started).
     */
    private function logStart(string $syncType): int
    {
        db_query(
            "INSERT INTO ?:sphinx_sync_log (sync_type, status, started_at) VALUES (?s, 'started', NOW())",
            $syncType
        );

        return (int) db_get_field("SELECT LAST_INSERT_ID()");
    }

    /**
     * Update a sync log entry (completed/failed).
     */
    private function logComplete(int $logId, string $status, array $stats): void
    {
        if ($logId <= 0) {
            return;
        }

        db_query(
            "UPDATE ?:sphinx_sync_log SET
                status = ?s,
                items_total = ?i,
                items_synced = ?i,
                items_failed = ?i,
                error_message = ?s,
                duration_ms = ?i,
                rate_limit_hits = ?i,
                sync_mode = ?s,
                completed_at = NOW()
             WHERE log_id = ?i",
            $status,
            $stats['total'] ?? 0,
            $stats['synced'] ?? 0,
            $stats['failed'] ?? 0,
            $stats['error'] ?? '',
            $stats['duration_ms'] ?? 0,
            $stats['rate_limit_hits'] ?? 0,
            $stats['sync_mode'] ?? 'full',
            $logId
        );
    }

    /**
     * Output a progress message.
     */
    private function output(string $message): void
    {
        if ($this->outputCallback !== null) {
            ($this->outputCallback)($message);
        }
    }
}

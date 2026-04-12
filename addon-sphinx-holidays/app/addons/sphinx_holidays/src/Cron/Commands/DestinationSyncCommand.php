<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Services\Container;

/**
 * Cron command: sync destinations from Sphinx API.
 *
 * Supports batch processing with resume capability (like Novoton's batched sync):
 * - Saves state to a JSON file after each API page
 * - Automatically resumes from where it left off on next run
 * - Stale state (>6h no activity) is cleared and restarted
 *
 * Usage:
 *   php cron.php access_key=KEY mode=destinations
 *   php cron.php access_key=KEY mode=destinations full=1       (force full re-sync)
 *   php cron.php access_key=KEY mode=destinations status=1     (check progress)
 *   php cron.php access_key=KEY mode=destinations reset=1      (clear state, start fresh)
 */
class DestinationSyncCommand extends AbstractSyncCommand
{
    use StatefulCommandTrait;

    private const STATE_FILE_NAME = 'sphinx_destination_sync_state.json';
    private const STALE_HOURS = 0.5; // 30 minutes — full sync takes ~7 min
    private const DEFAULT_STATE = [
        'status'       => 'idle',
        'started_at'   => null,
        'last_run_at'  => null,
        'sync_mode'    => 'full',
        'next_page'    => 1,
        'total'        => 0,
        'synced'       => 0,
        'skipped'      => 0,
        'failed'       => 0,
        'error'        => '',
        'full_sync'    => true,
        'updated_since' => null,
    ];

    #[\Override]
    public static function getDescription(): string
    {
        return 'Sync destinations (countries, regions, cities) from Sphinx API — with resume support';
    }

    /**
     * Execute the destination sync.
     *
     * @param array $params CLI parameters:
     *   'full'   => 1  Force full re-sync
     *   'status' => 1  Show progress without running
     *   'reset'  => 1  Clear state, start fresh
     * @return array{success: bool, stats: array}
     */
    #[\Override]
    public function execute(array $params = []): array
    {
        // Handle reset
        if (!empty($params['reset'])) {
            $this->clearState();
            $this->output('Destination sync state cleared. Ready for fresh sync.');
            return ['success' => true, 'stats' => ['action' => 'reset']];
        }

        // Handle status check
        if (!empty($params['status'])) {
            return $this->showStatus();
        }

        // Load existing state
        $state = $this->loadState();

        if ($state['status'] === 'in_progress') {
            if ($this->isStale($state)) {
                $this->output("Stale state detected (no activity since {$state['last_run_at']}). Clearing and starting fresh.");
                $this->clearState();
                $state = self::DEFAULT_STATE;
            } else {
                // Resume from where we left off
                $this->output("Resuming destination sync from page {$state['next_page']} ({$state['synced']} synced so far)...");
                return $this->runWithState($state, $params);
            }
        }

        // Fresh start
        $fullSync = !empty($params['full']);
        $state = self::DEFAULT_STATE;
        $state['status'] = 'in_progress';
        $state['started_at'] = date('Y-m-d H:i:s');
        $state['last_run_at'] = date('Y-m-d H:i:s');
        $state['full_sync'] = $fullSync;
        $state['sync_mode'] = 'full';

        // Determine incremental vs full
        if (!$fullSync) {
            $repository = Container::getDestinationRepository();
            $lastSynced = $repository->getLastSyncedAt();
            if ($lastSynced !== null) {
                $state['updated_since'] = $lastSynced;
                $state['sync_mode'] = 'incremental';
            }
        }

        $this->saveState($state);

        if ($state['updated_since'] !== null) {
            $this->output("Starting incremental destination sync (since {$state['updated_since']})...");
        } else {
            $this->output('Starting full destination sync...');
        }

        return $this->runWithState($state, $params);
    }

    /**
     * Process pages from the API, saving state after each page.
     * Can be interrupted (browser close, timeout) and resumed on next call.
     */
    private function runWithState(array $state, array $params): array
    {
        $api = Container::getApi();
        $repository = Container::getDestinationRepository();

        $perPage = 1000;
        $upsertBatchSize = 100;
        $page = $state['next_page'];
        $updatedSince = $state['updated_since'];
        $pagesThisRun = 0;

        while (true) {
            $this->output("Fetching page {$page}...");

            $response = $api->getDestinations($page, $perPage, $updatedSince);

            if ($response === null) {
                $state['error'] = 'API request failed: ' . $api->getHttpClient()->getLastError();
                $this->output('ERROR: ' . $state['error']);
                $state['last_run_at'] = date('Y-m-d H:i:s');
                $this->saveState($state);
                break;
            }

            $items = $this->extractItems($response);

            if (empty($items)) {
                break;
            }

            // Log first item's keys on first page of this session
            if ($pagesThisRun === 0 && !empty($items[0]) && is_array($items[0])) {
                $this->output('  API fields: ' . implode(', ', array_keys($items[0])));
            }

            // Normalize and upsert
            $pageBatch = [];
            foreach ($items as $item) {
                $normalized = $this->normalizeDestination($item);
                if ($normalized !== null) {
                    $pageBatch[] = $normalized;
                    $state['total']++;

                    if (count($pageBatch) >= $upsertBatchSize) {
                        $state['synced'] += $repository->upsertBatch($pageBatch);
                        $pageBatch = [];
                    }
                } else {
                    $state['skipped']++;
                }
            }

            if (!empty($pageBatch)) {
                $state['synced'] += $repository->upsertBatch($pageBatch);
            }

            $this->output("  Page {$page}: " . count($items) . " items, {$state['total']} total accepted, {$state['synced']} synced");

            $hasMore = $this->hasMorePages($response, $page, $perPage, $state['total']);

            $page++;
            $pagesThisRun++;

            // Save state after every page (critical for resume)
            $state['next_page'] = $page;
            $state['last_run_at'] = date('Y-m-d H:i:s');
            $this->saveState($state);

            if (!$hasMore) {
                break;
            }
        }

        // If no error occurred, all pages were fetched — sync is complete
        if ($state['error'] === '') {
            return $this->completeSync($state, $repository);
        }

        // Error occurred — save state for resume
        $this->output("Destination sync interrupted. Run again to resume from page {$state['next_page']}.");
        $this->output("Progress: {$state['synced']}/{$state['total']} synced.");

        return [
            'success' => false,
            'stats' => [
                'status'   => 'in_progress',
                'total'    => $state['total'],
                'synced'   => $state['synced'],
                'skipped'  => $state['skipped'],
                'error'    => $state['error'],
                'next_page' => $state['next_page'],
            ],
        ];
    }

    /**
     * Mark sync as completed, build breadcrumbs, log, clear state.
     */
    private function completeSync(array $state, $repository): array
    {
        if ($state['total'] === 0 && $state['updated_since'] !== null) {
            $this->output('No destinations updated since last sync. Everything is up to date.');
        } else {
            $state['failed'] = $state['total'] - $state['synced'];

            // Build full_path breadcrumbs
            $this->output('Building destination breadcrumb paths...');
            $pathsUpdated = $repository->buildFullPaths();
            $this->output("Updated {$pathsUpdated} full_path breadcrumbs.");
        }

        $this->output("Sync complete: {$state['synced']}/{$state['total']} destinations synced ({$state['sync_mode']}).");

        // Clear state file — sync is done
        $this->clearState();

        $stats = [
            'success'     => true,
            'total'       => $state['total'],
            'synced'      => $state['synced'],
            'skipped'     => $state['skipped'],
            'failed'      => $state['failed'],
            'error'       => '',
            'sync_mode'   => $state['sync_mode'],
            'duration_ms' => !empty($state['started_at'])
                ? (int) ((microtime(true) * 1000) - (strtotime($state['started_at']) * 1000))
                : 0,
        ];

        $this->outputRateLimitSummary($stats);

        return $this->wrapResult($stats);
    }

    /**
     * Show current sync progress without doing any API calls.
     */
    private function showStatus(): array
    {
        $state = $this->loadState();

        if ($state['status'] === 'idle') {
            $this->output('Destination Sync Status: idle (no sync in progress)');

            $lastRun = db_get_row(
                "SELECT * FROM ?:sphinx_sync_log WHERE sync_type = 'destinations' ORDER BY started_at DESC LIMIT 1"
            );
            if (!empty($lastRun)) {
                $this->output("  Last completed: {$lastRun['started_at']} — {$lastRun['items_synced']}/{$lastRun['items_total']} synced");
            }

            return ['success' => true, 'stats' => ['status' => 'idle']];
        }

        $this->output('Destination Sync Status:');
        $this->output("  Status: {$state['status']}");
        $this->output("  Mode: {$state['sync_mode']}");
        $this->output("  Next page: {$state['next_page']}");
        $this->output("  Synced: {$state['synced']}/{$state['total']}");
        $this->output("  Skipped: {$state['skipped']}");
        $this->output("  Started: {$state['started_at']}");
        $this->output("  Last activity: {$state['last_run_at']}");

        if (!empty($state['error'])) {
            $this->output("  Last error: {$state['error']}");
        }

        if ($this->isStale($state)) {
            $this->output('  WARNING: State appears stale (no activity for 6+ hours). Run with reset=1 to clear.');
        }

        // ETA estimate
        if (!empty($state['started_at']) && $state['synced'] > 0 && $state['total'] > 0) {
            $elapsed = time() - strtotime($state['started_at']);
            $this->output('  Elapsed: ' . $this->formatDuration($elapsed));
        }

        return ['success' => true, 'stats' => ['status' => $state['status'], 'synced' => $state['synced'], 'total' => $state['total']]];
    }

    /**
     * Normalize a raw API destination into the DB column format.
     * (Duplicated from DestinationSyncService to keep command self-contained)
     */
    private function normalizeDestination(array $raw): ?array
    {
        $id = (int) ($raw['id'] ?? $raw['destination_id'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        $name = (string) ($raw['name'] ?? $raw['title'] ?? $raw['label'] ?? '');

        if ($name === '' && isset($raw['translations'])) {
            $translations = $raw['translations'];
            $name = (string) ($translations['en'] ?? $translations['en_US'] ?? reset($translations) ?? '');
        }
        if ($name === '' && isset($raw['names'])) {
            $names = $raw['names'];
            $name = (string) ($names['en'] ?? $names['en_US'] ?? reset($names) ?? '');
        }
        if ($name === '' && isset($raw['name_en'])) {
            $name = (string) $raw['name_en'];
        }

        if (trim($name) === '') {
            return null;
        }

        $type = strtolower((string) ($raw['type'] ?? $raw['destination_type'] ?? 'destination'));
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
            'name'           => trim($name),
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
     * Extract items array from a paginated API response.
     */
    private function extractItems(array $response): array
    {
        $items = $response['data'] ?? $response['items'] ?? $response;

        if (isset($response[0]) && !isset($response['data'])) {
            $items = $response;
        }

        return is_array($items) ? $items : [];
    }

    /**
     * Check if there are more pages to fetch.
     */
    private function hasMorePages(array $response, int $currentPage, int $perPage, int $fetchedSoFar): bool
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

    protected function output(string $message, bool $addNewline = true): void
    {
        if ($this->outputCallback !== null) {
            ($this->outputCallback)($message, $addNewline);
        }
    }
}

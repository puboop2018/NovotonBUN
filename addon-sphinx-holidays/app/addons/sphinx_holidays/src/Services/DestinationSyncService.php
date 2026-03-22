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
class DestinationSyncService extends AbstractSyncService
{
    private const UPSERT_BATCH_SIZE = 100;
    private const PER_PAGE = 1000;

    private DestinationRepository $repository;

    public function __construct(SphinxApi $api, DestinationRepository $repository)
    {
        parent::__construct($api);
        $this->repository = $repository;
    }

    protected function getSyncType(): string
    {
        return 'destinations';
    }

    /**
     * Run a destination sync (incremental by default, full if forced or first run).
     *
     * @param bool $fullSync Force full re-fetch (ignores updated_since)
     * @return array{success: bool, total: int, synced: int, failed: int, duration_ms: int, error: string, sync_mode: string}
     */
    public function sync(bool $fullSync = false): array
    {
        return $this->runSync($fullSync);
    }

    protected function doSync(bool $fullSync, array $stats, array $context): array
    {
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
        $skipped = 0;
        $debuggedKeys = false;

        // Stream-and-upsert: fetch each page and upsert immediately
        // instead of accumulating all destinations in memory.
        while (true) {
            $this->output("Fetching page {$page}...");

            $response = $this->api->getDestinations($page, self::PER_PAGE, $updatedSince);

            if ($response === null) {
                $stats['error'] = 'API request failed: ' . $this->api->getHttpClient()->getLastError();
                $this->output('ERROR: ' . $stats['error']);
                break;
            }

            $items = $this->extractItems($response);

            if (empty($items)) {
                break;
            }

            // Log first item's keys on first page (helps debug field name mismatches)
            if (!$debuggedKeys && !empty($items[0]) && is_array($items[0])) {
                $this->output('  API fields: ' . implode(', ', array_keys($items[0])));
                $debuggedKeys = true;
            }

            // Normalize and upsert this page's items immediately
            $pageBatch = [];
            foreach ($items as $item) {
                $normalized = $this->normalizeDestination($item);
                if ($normalized !== null) {
                    $pageBatch[] = $normalized;
                    $stats['total']++;

                    if (count($pageBatch) >= self::UPSERT_BATCH_SIZE) {
                        $stats['synced'] += $this->repository->upsertBatch($pageBatch);
                        $pageBatch = [];
                    }
                } else {
                    $skipped++;
                }
            }

            if (!empty($pageBatch)) {
                $stats['synced'] += $this->repository->upsertBatch($pageBatch);
            }

            $skipMsg = $skipped > 0 ? ", {$skipped} skipped (no name/id)" : '';
            $this->output("  Page {$page}: " . count($items) . " items fetched, {$stats['total']} accepted{$skipMsg}");

            if (!$this->hasMorePages($response, $page, self::PER_PAGE, $stats['total'])) {
                break;
            }

            $page++;
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

        if ($skipped > 0) {
            $this->output("{$skipped} destination(s) skipped (empty name or invalid ID).");
        }
        $this->output("Sync complete: {$stats['synced']}/{$stats['total']} destinations synced ({$stats['sync_mode']}).");

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

        // Extract name — try multiple field names the API might use
        $name = (string) ($raw['name'] ?? $raw['title'] ?? $raw['label'] ?? '');

        // Some APIs nest the name inside a translations/localized object
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

        // Skip destinations with no name — they are unusable for disambiguation
        if (trim($name) === '') {
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
}

<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\Contracts\DestinationSyncServiceInterface;
use Tygh\Addons\SphinxHolidays\Repository\DestinationRepository;
use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Helpers\ValidationHelpers;

/**
 * Fetches destinations from the Sphinx API and syncs them into the local DB.
 *
 * Handles pagination, normalization, and sync-log tracking.
 */
class DestinationSyncService extends AbstractSyncService implements DestinationSyncServiceInterface
{
    private const int UPSERT_BATCH_SIZE = 100;
    private const int PER_PAGE = 1000;

    public function __construct(
        SphinxApi $api,
        private readonly DestinationRepository $repository,
    ) {
        parent::__construct($api);
    }

    #[\Override]
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
    #[\Override]
    public function sync(bool $fullSync = false): array
    {
        return $this->runSync($fullSync);
    }

    /**
     * @param array<string, mixed> $stats
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    #[\Override]
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
        $skippedDetails = [];
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
                if (!is_array($item)) {
                    continue;
                }
                $normalized = $this->normalizeDestination(TypeCoerce::toStringMap($item));
                if ($normalized !== null) {
                    $pageBatch[] = $normalized;
                    $stats['total'] = ValidationHelpers::toInt($stats['total'] ?? 0) + 1;

                    if (count($pageBatch) >= self::UPSERT_BATCH_SIZE) {
                        $stats['synced'] = ValidationHelpers::toInt($stats['synced'] ?? 0) + $this->repository->upsertBatch($pageBatch);
                        $pageBatch = [];
                    }
                } else {
                    $skipped++;
                    $rawId = ValidationHelpers::toString($item['id'] ?? $item['destination_id'] ?? '?');
                    $rawName = ValidationHelpers::toString($item['name'] ?? $item['title'] ?? $item['label'] ?? '(none)');
                    $rawType = ValidationHelpers::toString($item['type'] ?? $item['destination_type'] ?? '?');
                    $rawParent = ValidationHelpers::toString($item['parent_id'] ?? $item['parent'] ?? '?');
                    $reason = ValidationHelpers::toInt($item['id'] ?? $item['destination_id'] ?? 0) <= 0
                        ? 'invalid ID (0 or negative)'
                        : 'empty name';
                    $detail = "id={$rawId}, name=\"{$rawName}\", type={$rawType}, parent_id={$rawParent}, reason={$reason}";
                    $skippedDetails[] = $detail;
                    $this->output("  SKIP #{$skipped}: {$detail}");
                }
            }

            if (!empty($pageBatch)) {
                $stats['synced'] = ValidationHelpers::toInt($stats['synced'] ?? 0) + $this->repository->upsertBatch($pageBatch);
            }

            $sTotal = ValidationHelpers::toInt($stats['total'] ?? 0);
            $skipMsg = $skipped > 0 ? ", {$skipped} skipped (no name/id)" : '';
            $this->output("  Page {$page}: " . count($items) . " items fetched, {$sTotal} accepted{$skipMsg}");

            if (!$this->hasMorePages($response, $page, self::PER_PAGE, $sTotal)) {
                break;
            }

            $page++;
        }

        $sTotal = ValidationHelpers::toInt($stats['total'] ?? 0);
        $sSynced = ValidationHelpers::toInt($stats['synced'] ?? 0);

        if ($sTotal === 0 && $updatedSince !== null) {
            $this->output('No destinations updated since last sync. Everything is up to date.');
            $stats['success'] = true;
            $stats['synced'] = 0;
        } else {
            $stats['failed'] = $sTotal - $sSynced;
            $stats['success'] = true;

            // Build full_path breadcrumbs for disambiguation (chunked)
            $this->output('Building destination breadcrumb paths...');
            $pathsUpdated = $this->repository->buildFullPaths();
            $this->output("Updated {$pathsUpdated} full_path breadcrumbs.");
        }

        if ($skipped > 0) {
            $this->output('');
            $this->output("=== SKIPPED DESTINATIONS SUMMARY ({$skipped} total) ===");
            foreach ($skippedDetails as $i => $detail) {
                $this->output('  ' . ($i + 1) . '. ' . $detail);
            }
            $this->output('=== END SKIPPED SUMMARY ===');
            $this->output('');
        }
        $sSyncMode = ValidationHelpers::toString($stats['sync_mode'] ?? 'full');
        $this->output("Sync complete: {$sSynced}/{$sTotal} destinations synced ({$sSyncMode}).");

        return $stats;
    }

    /**
     * Normalize a raw API destination into the DB column format.
     * @param array<string, mixed> $raw
     * @return array<string, mixed>|null
     */
    private function normalizeDestination(array $raw): ?array
    {
        $id = ValidationHelpers::toInt($raw['id'] ?? $raw['destination_id'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        // Extract name — try multiple field names the API might use
        $name = ValidationHelpers::toString($raw['name'] ?? $raw['title'] ?? $raw['label'] ?? '');

        // Some APIs nest the name inside a translations/localized object
        if ($name === '' && isset($raw['translations']) && is_array($raw['translations'])) {
            $translations = $raw['translations'];
            $first = reset($translations);
            $name = ValidationHelpers::toString($translations['en'] ?? $translations['en_US'] ?? $first ?? '');
        }
        if ($name === '' && isset($raw['names']) && is_array($raw['names'])) {
            $names = $raw['names'];
            $first = reset($names);
            $name = ValidationHelpers::toString($names['en'] ?? $names['en_US'] ?? $first ?? '');
        }
        if ($name === '' && isset($raw['name_en'])) {
            $name = ValidationHelpers::toString($raw['name_en']);
        }

        // Skip destinations with no name — they are unusable for disambiguation
        if (trim($name) === '') {
            return null;
        }

        // Determine destination type from API data
        $type = strtolower(ValidationHelpers::toString($raw['type'] ?? $raw['destination_type'] ?? 'destination'));

        // Map common API type values to our canonical types
        $typeMap = [
            'continent' => 'continent',
            'country' => 'country',
            'region' => 'region',
            'city' => 'city',
            'resort' => 'destination',
            'area' => 'region',
            'zone' => 'region',
        ];
        $type = $typeMap[$type] ?? $type;

        return [
            'destination_id' => $id,
            'name' => trim($name),
            'type' => $type,
            'parent_id' => ValidationHelpers::toInt($raw['parent_id'] ?? $raw['parent'] ?? 0),
            'country_code' => ValidationHelpers::toString($raw['country_code'] ?? $raw['iso'] ?? $raw['iso_code'] ?? ''),
            'geoname_id' => ValidationHelpers::toInt($raw['geoname_id'] ?? $raw['geonames_id'] ?? 0),
            'latitude' => ValidationHelpers::toFloat($raw['latitude'] ?? $raw['lat'] ?? 0),
            'longitude' => ValidationHelpers::toFloat($raw['longitude'] ?? $raw['lng'] ?? $raw['lon'] ?? 0),
            'hotel_count' => ValidationHelpers::toInt($raw['hotel_count'] ?? $raw['hotels_count'] ?? 0),
        ];
    }
}

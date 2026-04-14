<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\Contracts\CircuitSyncServiceInterface;
use Tygh\Addons\SphinxHolidays\Repository\CircuitRepository;
use Tygh\Addons\SphinxHolidays\SphinxApi;

/**
 * Fetches circuits from the Sphinx static API and syncs them into the local DB.
 *
 * Simpler than HotelSyncService — circuits are a flat catalog without
 * country-based partitioning.
 *
 * @since 1.2.0
 */
class CircuitSyncService extends AbstractSyncService implements CircuitSyncServiceInterface
{
    private const int UPSERT_BATCH_SIZE = 100;
    private const int PER_PAGE = 1000;

    public function __construct(SphinxApi $api)
    {
        parent::__construct($api);
    }

    #[\Override]
    protected function getSyncType(): string
    {
        return 'circuits';
    }

    /**
     * Run circuit sync from static API.
     *
     * @return array{success: bool, total: int, synced: int, failed: int, duration_ms: int, error: string}
     */
    #[\Override]
    public function sync(): array
    {
        return $this->runSync(true);
    }

    /**
     * @param array<string, mixed> $stats
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    #[\Override]
    protected function doSync(bool $fullSync, array $stats, array $context): array
    {
        $allowedDestIds = ConfigProvider::getAllowedDestinationIds();
        if (empty($allowedDestIds)) {
            $stats['error'] = 'No sync targets configured. Configure destinations in Sphinx Holidays > Whitelist.';
            $this->output('ERROR: ' . $stats['error']);
            return $stats;
        }

        $this->output('Circuit sync starting (filtering by ' . count($allowedDestIds) . ' allowed destinations)...');

        $allCircuits = [];
        $filtered = 0;
        $page = 1;

        while (true) {
            $response = $this->api->getCircuits($page, self::PER_PAGE);
            if ($response === null) {
                $stats['error'] = 'API request failed on page ' . $page;
                break;
            }

            $items = $this->extractItems($response);
            if (empty($items)) {
                break;
            }

            foreach ($items as $raw) {
                $normalized = $this->normalizeCircuit($raw);
                if ($normalized === null) {
                    $stats['failed']++;
                    continue;
                }

                // Client-side filtering: skip circuits outside sync targets
                $circuitDestIds = !empty($normalized['destination_ids'])
                    ? json_decode($normalized['destination_ids'], true) ?: []
                    : [];
                if (!empty($circuitDestIds) && empty(array_intersect($circuitDestIds, $allowedDestIds))) {
                    $filtered++;
                    continue;
                }

                $allCircuits[] = $normalized;
                $stats['total']++;
            }

            if (!$this->hasMorePages($response, $page, self::PER_PAGE, $stats['total'] + $stats['failed'] + $filtered)) {
                break;
            }
            $page++;
        }

        if (!empty($allCircuits)) {
            $this->output("Upserting {$stats['total']} circuits...");
            $batches = array_chunk($allCircuits, self::UPSERT_BATCH_SIZE);
            foreach ($batches as $batch) {
                $stats['synced'] += $this->upsertBatch($batch);
            }
        }

        $stats['success'] = true;
        $filterMsg = $filtered > 0 ? ", {$filtered} filtered (outside sync targets)" : '';
        $this->output("Circuit sync complete: {$stats['synced']}/{$stats['total']} synced, {$stats['failed']} failed{$filterMsg}.");

        return $stats;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>|null
     */
    private function normalizeCircuit(array $raw): ?array
    {
        $id = (int) ($raw['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        $name = (string) ($raw['name'] ?? $raw['title'] ?? '');
        if ($name === '') {
            return null;
        }

        $destinations = $raw['destinations'] ?? $raw['destinatons'] ?? [];
        $destIds = [];
        $destNames = [];
        foreach ($destinations as $dest) {
            if (isset($dest['id'])) {
                $destIds[] = (int) $dest['id'];
            }
            if (isset($dest['name'])) {
                $destNames[] = $dest['name'];
            }
        }

        $departures = $raw['departures'] ?? [];
        $depIds = [];
        $depNames = [];
        foreach ($departures as $dep) {
            if (isset($dep['id'])) {
                $depIds[] = (int) $dep['id'];
            }
            if (isset($dep['name'])) {
                $depNames[] = $dep['name'];
            }
        }

        return [
            'circuit_id' => $id,
            'name' => $name,
            'summary' => (string) ($raw['summary'] ?? ''),
            'description' => (string) ($raw['description'] ?? ''),
            'duration_days' => (int) ($raw['duration']['days'] ?? $raw['duration_days'] ?? 0),
            'duration_nights' => (int) ($raw['duration']['nights'] ?? $raw['duration_nights'] ?? 0),
            'transport_type' => (string) ($raw['transport_type'] ?? ''),
            'destination_ids' => !empty($destIds) ? json_encode($destIds) : null,
            'destination_names' => !empty($destNames) ? implode(', ', $destNames) : null,
            'departure_ids' => !empty($depIds) ? json_encode($depIds) : null,
            'departure_names' => !empty($depNames) ? implode(', ', $depNames) : null,
            'image_url' => (string) ($raw['image'] ?? $raw['image_url'] ?? ''),
            'itinerary_json' => !empty($raw['itinerary']) ? json_encode($raw['itinerary']) : null,
            'tags_json' => !empty($raw['tags']) ? json_encode($raw['tags']) : null,
            'min_price' => isset($raw['pricing']['selling_price']) ? (float) $raw['pricing']['selling_price'] : null,
            'currency' => (string) ($raw['pricing']['currency'] ?? 'EUR'),
            'sync_status' => 'active',
            'last_synced_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param list<array<string, mixed>> $batch
     */
    private function upsertBatch(array $batch): int
    {
        $repo = new CircuitRepository();
        $affected = 0;
        foreach ($batch as $row) {
            $repo->upsert((int) $row['circuit_id'], $row);
            $affected++;
        }
        return $affected;
    }
}

<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\SphinxApi;

/**
 * Fetches circuits from the Sphinx static API and syncs them into the local DB.
 *
 * Simpler than HotelSyncService — circuits are a flat catalog without
 * country-based partitioning.
 *
 * @package SphinxHolidays
 * @since   1.2.0
 */
class CircuitSyncService
{
    private SphinxApi $api;

    /** @var callable|null */
    private $outputCallback = null;

    public function __construct(SphinxApi $api)
    {
        $this->api = $api;
    }

    public function setOutputCallback(callable $callback): void
    {
        $this->outputCallback = $callback;
    }

    /**
     * Run circuit sync from static API.
     *
     * @return array{success: bool, total: int, synced: int, failed: int, duration_ms: int, error: string}
     */
    public function sync(): array
    {
        $startMs = (int)(microtime(true) * 1000);
        $logId = $this->logStart('circuits');

        $stats = [
            'success' => false, 'total' => 0, 'synced' => 0,
            'failed' => 0, 'duration_ms' => 0, 'error' => '',
        ];

        try {
            $allowedDestIds = ConfigProvider::getAllowedDestinationIds();
            if (empty($allowedDestIds)) {
                $stats['error'] = 'No sync targets configured. Configure destinations in Sphinx Holidays > Whitelist.';
                $this->output('ERROR: ' . $stats['error']);
                $this->logComplete($logId, 'failed', $stats);
                return $stats;
            }

            $this->output('Circuit sync starting (filtering by ' . count($allowedDestIds) . ' allowed destinations)...');

            $allCircuits = [];
            $filtered = 0;
            $page = 1;
            $perPage = 1000;

            while (true) {
                $response = $this->api->getCircuits($page, $perPage);
                if ($response === null) {
                    $stats['error'] = 'API request failed on page ' . $page;
                    break;
                }

                $items = $response['data'] ?? $response;
                if (!is_array($items) || empty($items)) {
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

                $lastPage = $response['meta']['last_page'] ?? $response['last_page'] ?? null;
                if ($lastPage !== null && $page >= (int)$lastPage) break;
                if (count($items) < $perPage) break;
                $page++;
            }

            if (!empty($allCircuits)) {
                $this->output("Upserting {$stats['total']} circuits...");
                $batches = array_chunk($allCircuits, 100);
                foreach ($batches as $batch) {
                    $stats['synced'] += $this->upsertBatch($batch);
                }
            }

            $stats['success'] = true;
            $filterMsg = $filtered > 0 ? ", {$filtered} filtered (outside sync targets)" : '';
            $this->output("Circuit sync complete: {$stats['synced']}/{$stats['total']} synced, {$stats['failed']} failed{$filterMsg}.");

        } catch (\Throwable $e) {
            $stats['error'] = $e->getMessage();
            $this->output('EXCEPTION: ' . $e->getMessage());
            fn_log_event('general', 'runtime', [
                'message' => 'Sphinx circuit sync failed: ' . $e->getMessage(),
            ]);
        }

        $stats['duration_ms'] = (int)(microtime(true) * 1000) - $startMs;
        $this->logComplete($logId, $stats['success'] ? 'completed' : 'failed', $stats);

        return $stats;
    }

    private function normalizeCircuit(array $raw): ?array
    {
        $id = (int)($raw['id'] ?? 0);
        if ($id <= 0) return null;

        $name = (string)($raw['name'] ?? $raw['title'] ?? '');
        if ($name === '') return null;

        $destinations = $raw['destinations'] ?? $raw['destinatons'] ?? [];
        $destIds = [];
        $destNames = [];
        foreach ($destinations as $dest) {
            if (isset($dest['id'])) $destIds[] = (int)$dest['id'];
            if (isset($dest['name'])) $destNames[] = $dest['name'];
        }

        $departures = $raw['departures'] ?? [];
        $depIds = [];
        $depNames = [];
        foreach ($departures as $dep) {
            if (isset($dep['id'])) $depIds[] = (int)$dep['id'];
            if (isset($dep['name'])) $depNames[] = $dep['name'];
        }

        return [
            'circuit_id'        => $id,
            'name'              => $name,
            'summary'           => (string)($raw['summary'] ?? ''),
            'description'       => (string)($raw['description'] ?? ''),
            'duration_days'     => (int)($raw['duration']['days'] ?? $raw['duration_days'] ?? 0),
            'duration_nights'   => (int)($raw['duration']['nights'] ?? $raw['duration_nights'] ?? 0),
            'transport_type'    => (string)($raw['transport_type'] ?? ''),
            'destination_ids'   => !empty($destIds) ? json_encode($destIds) : null,
            'destination_names' => !empty($destNames) ? implode(', ', $destNames) : null,
            'departure_ids'     => !empty($depIds) ? json_encode($depIds) : null,
            'departure_names'   => !empty($depNames) ? implode(', ', $depNames) : null,
            'image_url'         => (string)($raw['image'] ?? $raw['image_url'] ?? ''),
            'itinerary_json'    => !empty($raw['itinerary']) ? json_encode($raw['itinerary']) : null,
            'tags_json'         => !empty($raw['tags']) ? json_encode($raw['tags']) : null,
            'min_price'         => isset($raw['pricing']['selling_price']) ? (float)$raw['pricing']['selling_price'] : null,
            'currency'          => (string)($raw['pricing']['currency'] ?? 'EUR'),
            'sync_status'       => 'active',
            'last_synced_at'    => date('Y-m-d H:i:s'),
        ];
    }

    private function upsertBatch(array $batch): int
    {
        $affected = 0;
        foreach ($batch as $row) {
            $existing = db_get_field("SELECT circuit_id FROM ?:sphinx_circuits WHERE circuit_id = ?i", $row['circuit_id']);
            if ($existing) {
                db_query("UPDATE ?:sphinx_circuits SET ?u WHERE circuit_id = ?i", $row, $row['circuit_id']);
            } else {
                db_query("INSERT INTO ?:sphinx_circuits ?e", $row);
            }
            $affected++;
        }
        return $affected;
    }

    private function logStart(string $syncType): int
    {
        db_query("INSERT INTO ?:sphinx_sync_log (sync_type, status, started_at) VALUES (?s, 'started', NOW())", $syncType);
        return (int)db_get_field("SELECT LAST_INSERT_ID()");
    }

    private function logComplete(int $logId, string $status, array $stats): void
    {
        if ($logId <= 0) return;
        db_query(
            "UPDATE ?:sphinx_sync_log SET status = ?s, items_total = ?i, items_synced = ?i, items_failed = ?i, error_message = ?s, duration_ms = ?i, completed_at = NOW() WHERE log_id = ?i",
            $status, $stats['total'] ?? 0, $stats['synced'] ?? 0, $stats['failed'] ?? 0, $stats['error'] ?? '', $stats['duration_ms'] ?? 0, $logId
        );
    }

    private function output(string $message): void
    {
        if ($this->outputCallback !== null) {
            ($this->outputCallback)($message);
        }
    }
}

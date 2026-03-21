<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\SphinxApi;

/**
 * Fetches package routes from the Sphinx static API and syncs them into the local DB.
 *
 * Package routes represent flight/bus connections between departure and arrival
 * destinations with available dates and durations.
 *
 * @package SphinxHolidays
 * @since   1.3.0
 */
class PackageRouteSyncService
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
     * Run package route sync from static API.
     *
     * @param array $departureIds  Optional departure ID filter
     * @param array $destinationIds Optional destination ID filter
     * @return array{success: bool, total: int, synced: int, failed: int, duration_ms: int, error: string}
     */
    public function sync(array $departureIds = [], array $destinationIds = []): array
    {
        $startMs = (int)(microtime(true) * 1000);
        $logId = $this->logStart('package_routes');

        $stats = [
            'success' => false, 'total' => 0, 'synced' => 0,
            'failed' => 0, 'duration_ms' => 0, 'error' => '',
        ];

        try {
            $allowedDestIds = ConfigProvider::getAllowedDestinationIds();
            if (empty($allowedDestIds)) {
                $stats['error'] = 'No sync targets configured. Set selected_destinations in Sphinx addon settings.';
                $this->output('ERROR: ' . $stats['error']);
                $this->logComplete($logId, 'failed', $stats);
                return $stats;
            }

            $this->output('Package route sync starting (filtering by ' . count($allowedDestIds) . ' allowed destinations)...');

            $allRoutes = [];
            $filtered = 0;
            $page = 1;
            $perPage = 1000;

            while (true) {
                $this->output("Fetching page {$page}...");
                $response = $this->api->getPackageRoutes($page, $perPage);

                if ($response === null) {
                    $stats['error'] = 'API request failed on page ' . $page;
                    break;
                }

                $items = $response['data'] ?? $response;
                if (!is_array($items) || empty($items)) {
                    break;
                }

                foreach ($items as $raw) {
                    $normalized = $this->normalizeRoute($raw);
                    if ($normalized === null) {
                        $stats['failed']++;
                        continue;
                    }

                    // Client-side filtering: skip routes whose arrival destination is outside sync targets
                    if (!in_array($normalized['arrival_id'], $allowedDestIds, true)) {
                        $filtered++;
                        continue;
                    }

                    $allRoutes[] = $normalized;
                    $stats['total']++;
                }

                $lastPage = $response['meta']['last_page'] ?? $response['last_page'] ?? null;
                if ($lastPage !== null && $page >= (int)$lastPage) break;
                if (count($items) < $perPage) break;
                $page++;
            }

            if (!empty($allRoutes)) {
                $this->output("Upserting {$stats['total']} routes...");
                $batches = array_chunk($allRoutes, 100);
                foreach ($batches as $batch) {
                    $stats['synced'] += $this->upsertBatch($batch);
                }
            }

            $stats['success'] = empty($stats['error']);
            $filterMsg = $filtered > 0 ? ", {$filtered} filtered (outside sync targets)" : '';
            $this->output("Package route sync complete: {$stats['synced']}/{$stats['total']} synced, {$stats['failed']} failed{$filterMsg}.");

        } catch (\Throwable $e) {
            $stats['error'] = $e->getMessage();
            $this->output('EXCEPTION: ' . $e->getMessage());
            fn_log_event('general', 'runtime', [
                'message' => 'Sphinx package route sync failed: ' . $e->getMessage(),
            ]);
        }

        $stats['duration_ms'] = (int)(microtime(true) * 1000) - $startMs;
        $this->logComplete($logId, $stats['success'] ? 'completed' : 'failed', $stats);

        return $stats;
    }

    /**
     * Normalize a raw API route into DB columns.
     *
     * API response shape:
     * {
     *   "type": "flight",
     *   "departure": {"id": 197128, "name": "Timisoara", "iata_code": "TSR"},
     *   "arrival": {"id": 87819, "name": "Corfu", "iata_code": "CFU"},
     *   "dates": ["2024-07-02", "2024-07-09"],
     *   "duration": 7
     * }
     */
    private function normalizeRoute(array $raw): ?array
    {
        $departure = $raw['departure'] ?? [];
        $arrival = $raw['arrival'] ?? [];

        $departureId = (int)($departure['id'] ?? 0);
        $arrivalId = (int)($arrival['id'] ?? 0);
        if ($departureId <= 0 || $arrivalId <= 0) return null;

        $transportType = strtolower((string)($raw['type'] ?? 'flight'));
        if (!in_array($transportType, ['flight', 'bus'], true)) {
            $transportType = 'flight';
        }

        $dates = $raw['dates'] ?? [];
        if (!is_array($dates)) $dates = [];

        return [
            'transport_type'  => $transportType,
            'departure_id'    => $departureId,
            'departure_name'  => (string)($departure['name'] ?? ''),
            'departure_iata'  => (string)($departure['iata_code'] ?? '') ?: null,
            'arrival_id'      => $arrivalId,
            'arrival_name'    => (string)($arrival['name'] ?? ''),
            'arrival_iata'    => (string)($arrival['iata_code'] ?? '') ?: null,
            'available_dates' => json_encode($dates),
            'duration'        => (int)($raw['duration'] ?? 0),
            'last_synced_at'  => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Upsert routes using the unique key (transport_type, departure_id, arrival_id, duration).
     */
    private function upsertBatch(array $batch): int
    {
        $affected = 0;
        foreach ($batch as $row) {
            $existing = db_get_field(
                "SELECT route_id FROM ?:sphinx_package_routes WHERE transport_type = ?s AND departure_id = ?i AND arrival_id = ?i AND duration = ?i",
                $row['transport_type'], $row['departure_id'], $row['arrival_id'], $row['duration']
            );

            if ($existing) {
                db_query("UPDATE ?:sphinx_package_routes SET ?u WHERE route_id = ?i", $row, (int)$existing);
            } else {
                db_query("INSERT INTO ?:sphinx_package_routes ?e", $row);
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

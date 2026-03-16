<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\SphinxApi;

/**
 * Fetches experiences from the Sphinx static API and syncs them into the local DB.
 *
 * @package SphinxHolidays
 * @since   1.2.0
 */
class ExperienceSyncService
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
     * Run experience sync from static API.
     *
     * @return array{success: bool, total: int, synced: int, failed: int, duration_ms: int, error: string}
     */
    public function sync(): array
    {
        $startMs = (int)(microtime(true) * 1000);
        $logId = $this->logStart('experiences');

        $stats = [
            'success' => false, 'total' => 0, 'synced' => 0,
            'failed' => 0, 'duration_ms' => 0, 'error' => '',
        ];

        try {
            $this->output('Experience sync starting...');

            $allExperiences = [];
            $page = 1;
            $perPage = 1000;

            while (true) {
                $response = $this->api->getExperiences($page, $perPage);
                if ($response === null) {
                    $stats['error'] = 'API request failed on page ' . $page;
                    break;
                }

                $items = $response['data'] ?? $response;
                if (!is_array($items) || empty($items)) {
                    break;
                }

                foreach ($items as $raw) {
                    $normalized = $this->normalizeExperience($raw);
                    if ($normalized === null) {
                        $stats['failed']++;
                        continue;
                    }
                    $allExperiences[] = $normalized;
                    $stats['total']++;
                }

                $lastPage = $response['meta']['last_page'] ?? $response['last_page'] ?? null;
                if ($lastPage !== null && $page >= (int)$lastPage) break;
                if (count($items) < $perPage) break;
                $page++;
            }

            if (!empty($allExperiences)) {
                $this->output("Upserting {$stats['total']} experiences...");
                $batches = array_chunk($allExperiences, 100);
                foreach ($batches as $batch) {
                    $stats['synced'] += $this->upsertBatch($batch);
                }
            }

            $stats['success'] = true;
            $this->output("Experience sync complete: {$stats['synced']}/{$stats['total']} synced, {$stats['failed']} failed.");

        } catch (\Throwable $e) {
            $stats['error'] = $e->getMessage();
            $this->output('EXCEPTION: ' . $e->getMessage());
            fn_log_event('general', 'runtime', [
                'message' => 'Sphinx experience sync failed: ' . $e->getMessage(),
            ]);
        }

        $stats['duration_ms'] = (int)(microtime(true) * 1000) - $startMs;
        $this->logComplete($logId, $stats['success'] ? 'completed' : 'failed', $stats);

        return $stats;
    }

    private function normalizeExperience(array $raw): ?array
    {
        $id = (int)($raw['id'] ?? 0);
        if ($id <= 0) return null;

        $name = (string)($raw['name'] ?? $raw['title'] ?? '');
        if ($name === '') return null;

        $destinations = $raw['destinations'] ?? [];
        $destIds = [];
        $destNames = [];
        foreach ($destinations as $dest) {
            if (isset($dest['id'])) $destIds[] = (int)$dest['id'];
            if (isset($dest['name'])) $destNames[] = $dest['name'];
        }

        $durationHours = 0;
        $durationDays = 0;
        if (isset($raw['duration'])) {
            if (is_array($raw['duration'])) {
                $durationHours = (int)($raw['duration']['hours'] ?? 0);
                $durationDays = (int)($raw['duration']['days'] ?? 0);
            } else {
                $durationHours = (int)$raw['duration'];
            }
        }

        return [
            'experience_id'      => $id,
            'name'               => $name,
            'summary'            => (string)($raw['summary'] ?? ''),
            'description'        => (string)($raw['description'] ?? ''),
            'duration_hours'     => $durationHours,
            'duration_days'      => $durationDays,
            'destination_ids'    => !empty($destIds) ? json_encode($destIds) : null,
            'destination_names'  => !empty($destNames) ? implode(', ', $destNames) : null,
            'pickup_points_json' => !empty($raw['pickup_points']) ? json_encode($raw['pickup_points']) : null,
            'image_url'          => (string)($raw['image'] ?? $raw['image_url'] ?? ''),
            'highlights_json'    => !empty($raw['highlights']) ? json_encode($raw['highlights']) : null,
            'features_json'      => !empty($raw['features']) ? json_encode($raw['features']) : null,
            'tags_json'          => !empty($raw['tags']) ? json_encode($raw['tags']) : null,
            'min_price'          => isset($raw['pricing']['selling_price']) ? (float)$raw['pricing']['selling_price'] : null,
            'currency'           => (string)($raw['pricing']['currency'] ?? 'EUR'),
            'sync_status'        => 'active',
            'last_synced_at'     => date('Y-m-d H:i:s'),
        ];
    }

    private function upsertBatch(array $batch): int
    {
        $affected = 0;
        foreach ($batch as $row) {
            $existing = db_get_field("SELECT experience_id FROM ?:sphinx_experiences WHERE experience_id = ?i", $row['experience_id']);
            if ($existing) {
                db_query("UPDATE ?:sphinx_experiences SET ?u WHERE experience_id = ?i", $row, $row['experience_id']);
            } else {
                db_query("INSERT INTO ?:sphinx_experiences ?e", $row);
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

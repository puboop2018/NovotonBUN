<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\Repository\ExperienceRepository;
use Tygh\Addons\SphinxHolidays\SphinxApi;

/**
 * Fetches experiences from the Sphinx static API and syncs them into the local DB.
 *
 * @since 1.2.0
 */
class ExperienceSyncService extends AbstractSyncService
{
    private const UPSERT_BATCH_SIZE = 100;
    private const PER_PAGE = 1000;

    public function __construct(SphinxApi $api)
    {
        parent::__construct($api);
    }

    protected function getSyncType(): string
    {
        return 'experiences';
    }

    /**
     * Run experience sync from static API.
     *
     * @return array{success: bool, total: int, synced: int, failed: int, duration_ms: int, error: string}
     */
    public function sync(): array
    {
        return $this->runSync(true);
    }

    protected function doSync(bool $fullSync, array $stats, array $context): array
    {
        $allowedDestIds = ConfigProvider::getAllowedDestinationIds();
        if (empty($allowedDestIds)) {
            $stats['error'] = 'No sync targets configured. Configure destinations in Sphinx Holidays > Whitelist.';
            $this->output('ERROR: ' . $stats['error']);
            return $stats;
        }

        $this->output('Experience sync starting (filtering by ' . count($allowedDestIds) . ' allowed destinations)...');

        $allExperiences = [];
        $filtered = 0;
        $page = 1;

        while (true) {
            $response = $this->api->getExperiences($page, self::PER_PAGE);
            if ($response === null) {
                $stats['error'] = 'API request failed on page ' . $page;
                break;
            }

            $items = $this->extractItems($response);
            if (empty($items)) {
                break;
            }

            foreach ($items as $raw) {
                $normalized = $this->normalizeExperience($raw);
                if ($normalized === null) {
                    $stats['failed']++;
                    continue;
                }

                // Client-side filtering: skip experiences outside sync targets
                $expDestIds = !empty($normalized['destination_ids'])
                    ? json_decode($normalized['destination_ids'], true) ?: []
                    : [];
                if (!empty($expDestIds) && empty(array_intersect($expDestIds, $allowedDestIds))) {
                    $filtered++;
                    continue;
                }

                $allExperiences[] = $normalized;
                $stats['total']++;
            }

            if (!$this->hasMorePages($response, $page, self::PER_PAGE, $stats['total'] + $stats['failed'] + $filtered)) {
                break;
            }
            $page++;
        }

        if (!empty($allExperiences)) {
            $this->output("Upserting {$stats['total']} experiences...");
            $batches = array_chunk($allExperiences, self::UPSERT_BATCH_SIZE);
            foreach ($batches as $batch) {
                $stats['synced'] += $this->upsertBatch($batch);
            }
        }

        $stats['success'] = true;
        $filterMsg = $filtered > 0 ? ", {$filtered} filtered (outside sync targets)" : '';
        $this->output("Experience sync complete: {$stats['synced']}/{$stats['total']} synced, {$stats['failed']} failed{$filterMsg}.");

        return $stats;
    }

    private function normalizeExperience(array $raw): ?array
    {
        $id = (int) ($raw['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        $name = (string) ($raw['name'] ?? $raw['title'] ?? '');
        if ($name === '') {
            return null;
        }

        $destinations = $raw['destinations'] ?? [];
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

        $durationHours = 0;
        $durationDays = 0;
        if (isset($raw['duration'])) {
            if (is_array($raw['duration'])) {
                $durationHours = (int) ($raw['duration']['hours'] ?? 0);
                $durationDays = (int) ($raw['duration']['days'] ?? 0);
            } else {
                $durationHours = (int) $raw['duration'];
            }
        }

        return [
            'experience_id'      => $id,
            'name'               => $name,
            'summary'            => (string) ($raw['summary'] ?? ''),
            'description'        => (string) ($raw['description'] ?? ''),
            'duration_hours'     => $durationHours,
            'duration_days'      => $durationDays,
            'destination_ids'    => !empty($destIds) ? json_encode($destIds) : null,
            'destination_names'  => !empty($destNames) ? implode(', ', $destNames) : null,
            'pickup_points_json' => !empty($raw['pickup_points']) ? json_encode($raw['pickup_points']) : null,
            'image_url'          => (string) ($raw['image'] ?? $raw['image_url'] ?? ''),
            'highlights_json'    => !empty($raw['highlights']) ? json_encode($raw['highlights']) : null,
            'features_json'      => !empty($raw['features']) ? json_encode($raw['features']) : null,
            'tags_json'          => !empty($raw['tags']) ? json_encode($raw['tags']) : null,
            'min_price'          => isset($raw['pricing']['selling_price']) ? (float) $raw['pricing']['selling_price'] : null,
            'currency'           => (string) ($raw['pricing']['currency'] ?? 'EUR'),
            'sync_status'        => 'active',
            'last_synced_at'     => date('Y-m-d H:i:s'),
        ];
    }

    private function upsertBatch(array $batch): int
    {
        $repo = new ExperienceRepository();
        $affected = 0;
        foreach ($batch as $row) {
            $repo->upsert((int) $row['experience_id'], $row);
            $affected++;
        }
        return $affected;
    }
}

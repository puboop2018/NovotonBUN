<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\Contracts\ExperienceSyncServiceInterface;
use Tygh\Addons\SphinxHolidays\Repository\ExperienceRepository;
use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Fetches experiences from the Sphinx static API and syncs them into the local DB.
 *
 * @since 1.2.0
 */
class ExperienceSyncService extends AbstractSyncService implements ExperienceSyncServiceInterface
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
        return 'experiences';
    }

    /**
     * Run experience sync from static API.
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
                $normalized = $this->normalizeExperience(TypeCoerce::toStringMap($raw));
                if ($normalized === null) {
                    $stats['failed'] = TypeCoerce::toInt($stats['failed']) + 1;
                    continue;
                }

                // Client-side filtering: skip experiences outside sync targets
                $expDestIds = !empty($normalized['destination_ids'])
                    ? TypeCoerce::toIntList(json_decode(TypeCoerce::toString($normalized['destination_ids']), true) ?: [])
                    : [];
                if (!empty($expDestIds) && empty(array_intersect($expDestIds, $allowedDestIds))) {
                    $filtered++;
                    continue;
                }

                $allExperiences[] = $normalized;
                $stats['total'] = TypeCoerce::toInt($stats['total']) + 1;
            }

            if (!$this->hasMorePages($response, $page, self::PER_PAGE, TypeCoerce::toInt($stats['total']) + TypeCoerce::toInt($stats['failed']) + $filtered)) {
                break;
            }
            $page++;
        }

        if (!empty($allExperiences)) {
            $total = TypeCoerce::toInt($stats['total']);
            $this->output("Upserting {$total} experiences...");
            $batches = array_chunk($allExperiences, self::UPSERT_BATCH_SIZE);
            foreach ($batches as $batch) {
                $stats['synced'] = TypeCoerce::toInt($stats['synced']) + $this->upsertBatch($batch);
            }
        }

        $stats['success'] = true;
        $filterMsg = $filtered > 0 ? ", {$filtered} filtered (outside sync targets)" : '';
        $synced = TypeCoerce::toInt($stats['synced']);
        $total = TypeCoerce::toInt($stats['total']);
        $failed = TypeCoerce::toInt($stats['failed']);
        $this->output("Experience sync complete: {$synced}/{$total} synced, {$failed} failed{$filterMsg}.");

        return $stats;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>|null
     */
    private function normalizeExperience(array $raw): ?array
    {
        $id = TypeCoerce::toInt($raw['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        $name = TypeCoerce::toString($raw['name'] ?? $raw['title'] ?? '');
        if ($name === '') {
            return null;
        }

        $destinations = $raw['destinations'] ?? [];
        $destIds = [];
        $destNames = [];
        foreach (TypeCoerce::toRowList($destinations) as $dest) {
            if (isset($dest['id'])) {
                $destIds[] = TypeCoerce::toInt($dest['id']);
            }
            if (isset($dest['name'])) {
                $destNames[] = TypeCoerce::toString($dest['name']);
            }
        }

        $durationHours = 0;
        $durationDays = 0;
        if (isset($raw['duration'])) {
            if (is_array($raw['duration'])) {
                $durationHours = TypeCoerce::toInt($raw['duration']['hours'] ?? 0);
                $durationDays = TypeCoerce::toInt($raw['duration']['days'] ?? 0);
            } else {
                $durationHours = TypeCoerce::toInt($raw['duration']);
            }
        }

        $pricing = TypeCoerce::toStringMap($raw['pricing'] ?? []);

        return [
            'experience_id' => $id,
            'name' => $name,
            'summary' => TypeCoerce::toString($raw['summary'] ?? ''),
            'description' => TypeCoerce::toString($raw['description'] ?? ''),
            'duration_hours' => $durationHours,
            'duration_days' => $durationDays,
            'destination_ids' => !empty($destIds) ? json_encode($destIds) : null,
            'destination_names' => !empty($destNames) ? implode(', ', $destNames) : null,
            'pickup_points_json' => !empty($raw['pickup_points']) ? json_encode($raw['pickup_points']) : null,
            'image_url' => TypeCoerce::toString($raw['image'] ?? $raw['image_url'] ?? ''),
            'highlights_json' => !empty($raw['highlights']) ? json_encode($raw['highlights']) : null,
            'features_json' => !empty($raw['features']) ? json_encode($raw['features']) : null,
            'tags_json' => !empty($raw['tags']) ? json_encode($raw['tags']) : null,
            'min_price' => isset($pricing['selling_price']) ? TypeCoerce::toFloat($pricing['selling_price']) : null,
            'currency' => TypeCoerce::toString($pricing['currency'] ?? 'EUR'),
            'sync_status' => 'active',
            'last_synced_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param list<array<string, mixed>> $batch
     */
    private function upsertBatch(array $batch): int
    {
        $repo = new ExperienceRepository();
        $affected = 0;
        foreach ($batch as $row) {
            $repo->upsert(TypeCoerce::toInt($row['experience_id']), $row);
            $affected++;
        }
        return $affected;
    }
}

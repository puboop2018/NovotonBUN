<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Cron command: back-fill empty region/city columns on sphinx_hotels.
 *
 * The static hotels API carries NO region/city fields — during sync they are
 * synthesized from the sphinx_destinations parent tree. Hotels synced while
 * that tree was empty/partial were stored with NULL region_name /
 * destination_name, and because the hotel sync is incremental
 * (updated_since), those rows are never revisited — which is why the admin
 * Hotels grid shows blank Region and City/Resort columns. This command
 * repairs the stored rows in place from the (now-synced) destination tree;
 * no API calls are made.
 *
 * Usage:
 *   cron_mode=backfill_hotel_locations
 *
 * Idempotent and safe to re-run: only rows still missing a value are
 * scanned, and only the empty columns are written.
 */
class BackfillHotelLocationsCommand extends AbstractSyncCommand
{
    /**
     * @return list<string>
     */
    #[\Override]
    public static function getModes(): array
    {
        return ['backfill_hotel_locations'];
    }

    private const int CHUNK = 500;

    /** How many unresolved examples to include in the report. */
    private const int SAMPLE_LIMIT = 10;

    #[\Override]
    public static function getDescription(): string
    {
        return 'Back-fill empty Region/City columns on hotels from the destination tree (no API calls)';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    #[\Override]
    public function execute(array $params = []): array
    {
        $destRepo = Container::getDestinationRepository();
        if (!$destRepo->loadParentLookup()) {
            $this->output('sphinx_destinations is EMPTY — run cron_mode=destinations first, then re-run this backfill.');
            return ['success' => false, 'error' => 'destination tree empty'];
        }

        $scanned = 0;
        $updated = 0;
        $noDestinationId = 0;
        $unresolved = 0;
        $samples = [];
        $lastId = '';

        while (true) {
            $rows = TypeCoerce::toRowList(db_get_array(
                "SELECT hotel_id, name, destination_id, destination_name, region_id, region_name
                 FROM ?:sphinx_hotels
                 WHERE (region_name IS NULL OR region_name = ''
                        OR destination_name IS NULL OR destination_name = '')
                   AND hotel_id > ?s
                 ORDER BY hotel_id
                 LIMIT ?i",
                $lastId,
                self::CHUNK,
            ));
            if ($rows === []) {
                break;
            }
            $scanned += count($rows);
            $lastId = TypeCoerce::toString($rows[count($rows) - 1]['hotel_id'] ?? '');

            $destIds = [];
            foreach ($rows as $row) {
                $di = TypeCoerce::toInt($row['destination_id'] ?? 0);
                if ($di > 0) {
                    $destIds[$di] = $di;
                }
            }
            $hierarchyMap = $destIds !== [] ? $destRepo->resolveHierarchies(array_values($destIds)) : [];

            foreach ($rows as $row) {
                $hotelId = TypeCoerce::toString($row['hotel_id'] ?? '');
                $destId = TypeCoerce::toInt($row['destination_id'] ?? 0);

                if ($destId <= 0) {
                    $noDestinationId++;
                    $this->collectSample($samples, $row, 'no destination_id on the hotel row');
                    continue;
                }

                $hierarchy = $hierarchyMap[$destId] ?? [];
                $treeCity = TypeCoerce::toString($hierarchy['city'] ?? '');
                $treeRegion = TypeCoerce::toString($hierarchy['region'] ?? '');
                $treeRegionId = TypeCoerce::toInt($hierarchy['region_id'] ?? 0);

                $set = [];
                if ($treeCity !== '' && TypeCoerce::toString($row['destination_name'] ?? '') === '') {
                    $set['destination_name'] = $treeCity;
                }
                if ($treeRegion !== '' && TypeCoerce::toString($row['region_name'] ?? '') === '') {
                    $set['region_name'] = $treeRegion;
                }
                if ($treeRegionId > 0 && TypeCoerce::toInt($row['region_id'] ?? 0) === 0) {
                    $set['region_id'] = $treeRegionId;
                }

                if ($set === []) {
                    $unresolved++;
                    $this->collectSample(
                        $samples,
                        $row,
                        "destination_id={$destId} not in the tree, or its parent chain names no region/city",
                    );
                    continue;
                }

                db_query('UPDATE ?:sphinx_hotels SET ?u WHERE hotel_id = ?s', $set, $hotelId);
                $updated++;
            }
        }

        if ($scanned === 0) {
            $this->output('No hotels with empty Region/City found. Nothing to do.');
            return ['success' => true, 'stats' => ['scanned' => 0, 'updated' => 0]];
        }

        $this->output("Scanned {$scanned} hotel(s) with empty Region/City.");
        $this->output("Updated: {$updated}");
        if ($noDestinationId > 0) {
            $this->output("Skipped — no destination_id on the row: {$noDestinationId} (only a full hotel re-sync can repair these)");
        }
        if ($unresolved > 0) {
            $this->output("Skipped — destination tree could not name a region/city: {$unresolved}");
        }
        foreach ($samples as $sample) {
            $this->output('  - ' . $sample);
        }

        return [
            'success' => true,
            'stats' => [
                'scanned' => $scanned,
                'updated' => $updated,
                'no_destination_id' => $noDestinationId,
                'unresolved' => $unresolved,
            ],
        ];
    }

    /**
     * @param list<string> $samples
     * @param array<string, mixed> $row
     */
    private function collectSample(array &$samples, array $row, string $reason): void
    {
        if (count($samples) >= self::SAMPLE_LIMIT) {
            return;
        }
        $samples[] = sprintf(
            '[%s] %s — %s',
            TypeCoerce::toString($row['hotel_id'] ?? ''),
            TypeCoerce::toString($row['name'] ?? ''),
            $reason,
        );
    }
}

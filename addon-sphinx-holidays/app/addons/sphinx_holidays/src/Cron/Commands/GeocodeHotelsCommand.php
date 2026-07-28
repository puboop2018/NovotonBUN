<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\HotelLocationResolver;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\Geocoding\GeocodeBacklogRunner;
use Tygh\Addons\TravelCore\Services\Geocoding\NominatimClient;
use Tygh\Addons\TravelCore\Services\Geocoding\ReverseGeocodeResult;
use Tygh\Addons\TravelCore\Services\TravelCoreConfig;

/**
 * Cron command: fill the last rung of the City/Region ladder from coordinates.
 *
 * HotelLocationResolver resolves City from the destination tree and the API
 * address, and Region from the tree — but a hotel whose destination_id points
 * at a COUNTRY (Adalya Port Hotel → 233 "Turkey") gets nothing from the tree,
 * and its address city may be a region string in disguise. This command asks
 * OSM Nominatim what is actually at the hotel's coordinates, stores the answer
 * in geo_city / geo_region / street_address, then re-runs the resolver so the
 * stored City/Region reflect the new rung immediately.
 *
 * Pacing (1 req/s, per the OSM policy) belongs to travel_core's shared
 * GeocodeBacklogRunner — the limit is per application, so novoton and sphinx
 * must not each invent their own.
 *
 * Usage:
 *   cron_mode=geocode_hotels
 *   cron_mode=geocode_hotels&limit=200
 *   cron_mode=geocode_hotels&force=1     — clear all stamps and start over
 *   cron_mode=geocode_hotels&status=1    — report the backlog, call nothing
 */
class GeocodeHotelsCommand extends AbstractSyncCommand
{
    private const int DEFAULT_LIMIT = 100;

    /**
     * @return list<string>
     */
    #[\Override]
    public static function getModes(): array
    {
        return ['geocode_hotels'];
    }

    #[\Override]
    public static function getDescription(): string
    {
        return 'Reverse-geocode hotel coordinates (OSM Nominatim) to fill the City/Region ladder\'s last rung';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    #[\Override]
    public function execute(array $params = []): array
    {
        if (!empty($params['status'])) {
            $this->output('Geocode backlog: ' . $this->countCandidates() . ' hotel(s) with coordinates and no stamp.');

            return ['success' => true, 'stats' => ['remaining' => $this->countCandidates()]];
        }

        if (!TravelCoreConfig::isGeocodingEnabled()) {
            $this->output('Geocoding is disabled — enable it in Travel Core settings first.');

            return ['success' => false, 'error' => 'Geocoding disabled in settings'];
        }

        $contactEmail = TravelCoreConfig::getGeocodingContactEmail();
        if ($contactEmail === '') {
            $this->output('Set the geocoding contact email in Travel Core settings first — the Nominatim usage policy requires identifying the application.');

            return ['success' => false, 'error' => 'Missing geocoding contact email'];
        }

        if (!empty($params['force'])) {
            db_query('UPDATE ?:sphinx_hotels SET geocoded_at = NULL');
            $this->output('force=1: cleared all geocode stamps — full re-geocode.');
        }

        $limit = TypeCoerce::toInt($params['limit'] ?? self::DEFAULT_LIMIT);
        if ($limit <= 0) {
            $limit = self::DEFAULT_LIMIT;
        }

        $candidates = $this->findCandidates($limit);
        if ($candidates === []) {
            $this->output('Nothing to geocode — every hotel with coordinates is already stamped.');

            return ['success' => true, 'stats' => ['found' => 0, 'empty' => 0, 'remaining' => 0]];
        }

        $endpoint = TravelCoreConfig::getGeocodingEndpoint();
        $this->output('Reverse geocoding ' . count($candidates) . " hotel(s) via {$endpoint} (1 req/s)...");
        $this->output('');

        $runner = new GeocodeBacklogRunner(
            new NominatimClient(null, $endpoint, $contactEmail, 'SphinxHolidays/1.0'),
            null,
            $this->output(...),
        );

        $stats = $runner->run($candidates, $this->persist(...));

        $remaining = $this->countCandidates();
        $this->output('');
        $this->output("Done: {$stats['found']} located, {$stats['empty']} with nothing found. Remaining backlog: {$remaining}.");
        $this->output('Re-resolving stored City/Region for the geocoded hotels...');
        $reResolved = $this->reResolveLocations();
        $this->output("Locations refreshed: {$reResolved}");

        return [
            'success' => !$stats['aborted'],
            'stats' => [
                'found' => $stats['found'],
                'empty' => $stats['empty'],
                'remaining' => $remaining,
                're_resolved' => $reResolved,
                'aborted' => $stats['aborted'],
            ],
        ];
    }

    /**
     * Hotels with usable coordinates that have never been stamped. Ordered so
     * the rows the ladder cannot otherwise answer (no city, or a city resting
     * on a fallback rung) are geocoded first.
     *
     * @return list<array<string, mixed>>
     */
    private function findCandidates(int $limit): array
    {
        return TypeCoerce::toRowList(db_get_array(
            "SELECT hotel_id AS id, latitude AS lat, longitude AS lng, name AS label
             FROM ?:sphinx_hotels
             WHERE geocoded_at IS NULL
               AND latitude IS NOT NULL AND longitude IS NOT NULL
               AND latitude <> 0 AND longitude <> 0
             ORDER BY
                CASE
                    WHEN destination_name IS NULL OR destination_name = '' THEN 0
                    WHEN region_name IS NULL OR region_name = '' THEN 1
                    WHEN location_source IN ('address', 'geocode') THEN 2
                    ELSE 3
                END,
                hotel_id
             LIMIT ?i",
            $limit,
        ));
    }

    private function countCandidates(): int
    {
        return TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:sphinx_hotels
             WHERE geocoded_at IS NULL
               AND latitude IS NOT NULL AND longitude IS NOT NULL
               AND latitude <> 0 AND longitude <> 0',
        ));
    }

    /**
     * Store one lookup. The stamp is written even when nothing was found, so
     * the hotel is not retried on every run.
     */
    private function persist(string $hotelId, ?ReverseGeocodeResult $result): void
    {
        db_query(
            'UPDATE ?:sphinx_hotels SET geo_city = ?s, geo_region = ?s, street_address = ?s, geocoded_at = NOW()
             WHERE hotel_id = ?s',
            $result === null ? '' : $result->city,
            $result === null ? '' : $result->region,
            $result === null ? '' : $result->street,
            $hotelId,
        );
    }

    /**
     * Re-run the ladder for hotels that now carry geocoded values, so the
     * stored City/Region (and their provenance) reflect the new rung without
     * waiting for the next full sync.
     */
    private function reResolveLocations(): int
    {
        $destRepo = Container::getDestinationRepository();
        $destRepo->loadParentLookup();

        $rows = TypeCoerce::toRowList(db_get_array(
            "SELECT hotel_id, destination_id, destination_name, region_id, region_name,
                    address_city, geo_city, geo_region, location_source
             FROM ?:sphinx_hotels
             WHERE geocoded_at IS NOT NULL
               AND (geo_city <> '' OR geo_region <> '')
               AND (location_source IS NULL OR location_source IN ('address', 'geocode')
                    OR destination_name IS NULL OR destination_name = ''
                    OR region_name IS NULL OR region_name = '')",
        ));
        if ($rows === []) {
            return 0;
        }

        $destIds = [];
        foreach ($rows as $row) {
            $di = TypeCoerce::toInt($row['destination_id'] ?? 0);
            if ($di > 0) {
                $destIds[$di] = $di;
            }
        }
        $hierarchyMap = $destIds !== [] ? $destRepo->resolveHierarchies(array_values($destIds)) : [];

        $updated = 0;
        foreach ($rows as $row) {
            $destId = TypeCoerce::toInt($row['destination_id'] ?? 0);
            $resolved = HotelLocationResolver::resolve($row, $hierarchyMap[$destId] ?? []);

            $set = [];
            if ($resolved['city'] !== '' && $resolved['city'] !== TypeCoerce::toString($row['destination_name'] ?? '')) {
                $set['destination_name'] = $resolved['city'];
            }
            if ($resolved['region'] !== '' && $resolved['region'] !== TypeCoerce::toString($row['region_name'] ?? '')) {
                $set['region_name'] = $resolved['region'];
            }
            $source = HotelLocationResolver::rowSource($resolved);
            if ($source !== TypeCoerce::toString($row['location_source'] ?? '')) {
                $set['location_source'] = $source;
            }

            if ($set === []) {
                continue;
            }

            db_query(
                'UPDATE ?:sphinx_hotels SET ?u WHERE hotel_id = ?s',
                $set,
                TypeCoerce::toString($row['hotel_id'] ?? ''),
            );
            $updated++;
        }

        return $updated;
    }
}

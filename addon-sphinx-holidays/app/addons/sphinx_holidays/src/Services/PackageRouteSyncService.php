<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\Repository\PackageRouteRepository;
use Tygh\Addons\SphinxHolidays\SphinxApi;

/**
 * Fetches package routes from the Sphinx static API and syncs them into the local DB.
 *
 * Package routes represent flight/bus connections between departure and arrival
 * destinations with available dates and durations.
 *
 * @since 1.3.0
 */
class PackageRouteSyncService extends AbstractSyncService
{
    private const UPSERT_BATCH_SIZE = 100;
    private const PER_PAGE = 1000;

    /** @var array<int, string> In-memory cache: destination_id → country_code */
    private array $countryCodeCache = [];

    public function __construct(SphinxApi $api)
    {
        parent::__construct($api);
    }

    #[\Override]
    protected function getSyncType(): string
    {
        return 'package_routes';
    }

    /**
     * Run package route sync from static API.
     *
     * @param array<string, mixed> $departureIds  Optional departure ID filter
     * @param array<string, mixed> $destinationIds Optional destination ID filter
     * @return array{success: bool, total: int, synced: int, failed: int, duration_ms: int, error: string}
     */
    public function sync(array $departureIds = [], array $destinationIds = []): array
    {
        return $this->runSync(true, [
            'departure_ids'   => $departureIds,
            'destination_ids' => $destinationIds,
        ]);
    }

    /**
     * @param array<string, mixed> $stats
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    #[\Override]
    protected function doSync(bool $fullSync, array $stats, array $context): array
    {
        // Package routes use region/resort-level arrival IDs that may not appear
        // in the city-level whitelist. Filter by country code instead.
        $allowedCountryCodes = ConfigProvider::getSelectedCountryCodes();
        if (empty($allowedCountryCodes)) {
            $stats['error'] = 'No sync targets configured. Configure destinations in Sphinx Holidays > Whitelist.';
            $this->output('ERROR: ' . $stats['error']);
            return $stats;
        }

        $this->output('Package route sync starting (filtering by countries: ' . implode(', ', $allowedCountryCodes) . ')...');

        $allRoutes = [];
        $filtered = 0;
        $page = 1;

        while (true) {
            $this->output("Fetching page {$page}...");
            $response = $this->api->getPackageRoutes($page, self::PER_PAGE);

            if ($response === null) {
                $stats['error'] = 'API request failed on page ' . $page;
                break;
            }

            $items = $this->extractItems($response);
            if (empty($items)) {
                break;
            }

            foreach ($items as $raw) {
                $normalized = $this->normalizeRoute($raw);
                if ($normalized === null) {
                    $stats['failed']++;
                    continue;
                }

                // Filter by country: resolve arrival destination's country code
                $arrivalCountry = $this->resolveCountryCode($normalized['arrival_id']);
                if ($arrivalCountry === '' || !in_array($arrivalCountry, $allowedCountryCodes, true)) {
                    $filtered++;
                    continue;
                }

                $allRoutes[] = $normalized;
                $stats['total']++;
            }

            if (!$this->hasMorePages($response, $page, self::PER_PAGE, $stats['total'] + $stats['failed'] + $filtered)) {
                break;
            }
            $page++;
        }

        if (!empty($allRoutes)) {
            $this->output("Upserting {$stats['total']} routes...");
            $batches = array_chunk($allRoutes, self::UPSERT_BATCH_SIZE);
            foreach ($batches as $batch) {
                $stats['synced'] += $this->upsertBatch($batch);
            }
        }

        $stats['success'] = empty($stats['error']);
        $filterMsg = $filtered > 0 ? ", {$filtered} filtered (outside sync targets)" : '';
        $this->output("Package route sync complete: {$stats['synced']}/{$stats['total']} synced, {$stats['failed']} failed{$filterMsg}.");

        return $stats;
    }

    /**
     * Normalize a raw API route into DB columns.
     * @param array<string, mixed> $raw
     * @return array<string, mixed>|null
     */
    private function normalizeRoute(array $raw): ?array
    {
        $departure = $raw['departure'] ?? [];
        $arrival = $raw['arrival'] ?? [];

        $departureId = (int) ($departure['id'] ?? 0);
        $arrivalId = (int) ($arrival['id'] ?? 0);
        if ($departureId <= 0 || $arrivalId <= 0) {
            return null;
        }

        $transportType = strtolower((string) ($raw['type'] ?? 'flight'));
        if (!in_array($transportType, ['flight', 'bus'], true)) {
            $transportType = 'flight';
        }

        $dates = $raw['dates'] ?? [];
        if (!is_array($dates)) {
            $dates = [];
        }

        return [
            'transport_type'  => $transportType,
            'departure_id'    => $departureId,
            'departure_name'  => (string) ($departure['name'] ?? ''),
            'departure_iata'  => (string) ($departure['iata_code'] ?? '') ?: null,
            'arrival_id'      => $arrivalId,
            'arrival_name'    => (string) ($arrival['name'] ?? ''),
            'arrival_iata'    => (string) ($arrival['iata_code'] ?? '') ?: null,
            'available_dates' => json_encode($dates),
            'duration'        => (int) ($raw['duration'] ?? 0),
            'last_synced_at'  => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Resolve a destination ID to its country code via the sphinx_destinations table.
     * Results are cached in memory (package routes have a small set of unique arrival IDs).
     */
    private function resolveCountryCode(int $destinationId): string
    {
        if (!isset($this->countryCodeCache[$destinationId])) {
            $repo = new PackageRouteRepository();
            $this->countryCodeCache[$destinationId] = $repo->getCountryCodeForDestination($destinationId);
        }
        return $this->countryCodeCache[$destinationId];
    }

    /**
     * Upsert routes using the unique key (transport_type, departure_id, arrival_id, duration).
     * @param array<string, mixed> $batch
     */
    private function upsertBatch(array $batch): int
    {
        $repo = new PackageRouteRepository();
        $affected = 0;
        foreach ($batch as $row) {
            $repo->upsert($row);
            $affected++;
        }
        return $affected;
    }
}

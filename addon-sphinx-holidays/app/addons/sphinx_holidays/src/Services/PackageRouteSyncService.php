<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\Repository\PackageRouteRepository;
use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

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
    private const int UPSERT_BATCH_SIZE = 100;
    private const int PER_PAGE = 1000;

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
     * @param list<int> $departureIds Optional departure ID filter
     * @param list<int> $destinationIds Optional destination ID filter
     * @return array{success: bool, total: int, synced: int, skipped: int, failed: int, sync_mode: string, duration_ms: int, error: string}
     */
    public function sync(array $departureIds = [], array $destinationIds = []): array
    {
        return $this->runSync(true, [
            'departure_ids' => $departureIds,
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
                $normalized = $this->normalizeRoute(TypeCoerce::toStringMap($raw));
                if ($normalized === null) {
                    $stats['failed'] = TypeCoerce::toInt($stats['failed'] ?? 0) + 1;
                    continue;
                }

                // Filter by country: resolve arrival destination's country code
                $arrivalCountry = $this->resolveCountryCode(TypeCoerce::toInt($normalized['arrival_id']));
                if ($arrivalCountry === '' || !in_array($arrivalCountry, $allowedCountryCodes, true)) {
                    $filtered++;
                    continue;
                }

                $allRoutes[] = $normalized;
                $stats['total'] = TypeCoerce::toInt($stats['total'] ?? 0) + 1;
            }

            if (!$this->hasMorePages($response, $page, self::PER_PAGE, TypeCoerce::toInt($stats['total'] ?? 0) + TypeCoerce::toInt($stats['failed'] ?? 0) + $filtered)) {
                break;
            }
            $page++;
        }

        $totalRoutes = TypeCoerce::toInt($stats['total'] ?? 0);
        if (!empty($allRoutes)) {
            $this->output("Upserting {$totalRoutes} routes...");
            $batches = array_chunk($allRoutes, self::UPSERT_BATCH_SIZE);
            foreach ($batches as $batch) {
                $stats['synced'] = TypeCoerce::toInt($stats['synced'] ?? 0) + $this->upsertBatch($batch);
            }
        }

        $stats['success'] = empty($stats['error']);
        $syncedRoutes = TypeCoerce::toInt($stats['synced'] ?? 0);
        $failedRoutes = TypeCoerce::toInt($stats['failed'] ?? 0);
        $filterMsg = $filtered > 0 ? ", {$filtered} filtered (outside sync targets)" : '';
        $this->output("Package route sync complete: {$syncedRoutes}/{$totalRoutes} synced, {$failedRoutes} failed{$filterMsg}.");

        return $stats;
    }

    /**
     * Normalize a raw API route into DB columns.
     * @param array<string, mixed> $raw
     * @return array<string, mixed>|null
     */
    private function normalizeRoute(array $raw): ?array
    {
        $departure = TypeCoerce::toStringMap($raw['departure'] ?? []);
        $arrival = TypeCoerce::toStringMap($raw['arrival'] ?? []);

        $departureId = TypeCoerce::toInt($departure['id'] ?? 0);
        $arrivalId = TypeCoerce::toInt($arrival['id'] ?? 0);
        if ($departureId <= 0 || $arrivalId <= 0) {
            return null;
        }

        $transportType = strtolower(TypeCoerce::toString($raw['type'] ?? 'flight'));
        if (!in_array($transportType, ['flight', 'bus'], true)) {
            $transportType = 'flight';
        }

        $dates = $raw['dates'] ?? [];
        if (!is_array($dates)) {
            $dates = [];
        }

        return [
            'transport_type' => $transportType,
            'departure_id' => $departureId,
            'departure_name' => TypeCoerce::toString($departure['name'] ?? ''),
            'departure_iata' => TypeCoerce::toString($departure['iata_code'] ?? '') ?: null,
            'arrival_id' => $arrivalId,
            'arrival_name' => TypeCoerce::toString($arrival['name'] ?? ''),
            'arrival_iata' => TypeCoerce::toString($arrival['iata_code'] ?? '') ?: null,
            'available_dates' => json_encode($dates),
            'duration' => TypeCoerce::toInt($raw['duration'] ?? 0),
            'last_synced_at' => date('Y-m-d H:i:s'),
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
     * @param list<array<string, mixed>> $batch
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

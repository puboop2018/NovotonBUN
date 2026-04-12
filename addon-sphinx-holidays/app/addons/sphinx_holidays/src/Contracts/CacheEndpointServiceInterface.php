<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Contracts;

/**
 * Contract for the Sphinx cache-endpoint service.
 *
 * Wraps the Sphinx cache API endpoints (hotels, packages) with
 * commission application and local cache storage for widget display.
 */
interface CacheEndpointServiceInterface
{
    /**
     * Get hotel deals with commission applied.
     *
     * @param array $filters {destination_id?: int, stars?: int, limit?: int, sort_by?: string}
     * @return array Normalized deal entries with commission-applied prices
     */
    public function getHotelDeals(array $filters = []): array;

    /**
     * Get package deals with commission applied.
     *
     * @param array $filters {destination_id?: int, type?: string, limit?: int}
     * @return array Normalized deal entries with commission-applied prices
     */
    public function getPackageDeals(array $filters = []): array;

    /**
     * Refresh all cached deals (called by cron).
     *
     * @return array{hotels_count: int, packages_count: int, errors: int}
     */
    public function refreshAll(): array;
}

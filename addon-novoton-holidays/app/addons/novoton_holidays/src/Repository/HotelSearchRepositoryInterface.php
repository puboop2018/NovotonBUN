<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Hotel Search Repository Contract
 *
 * Focused contract for read-side hotel queries: listing, filtering,
 * pagination, sync-selection, and staleness-based retrieval.
 *
 * Extracted from HotelRepository to unwind the 49-method god-object
 * (architectural audit: PR #3). Callers that only need to *find*
 * hotels should type-hint this interface instead of the full
 * HotelRepositoryInterface (which still delegates here for backward
 * compatibility).
 *
 * @package NovotonHolidays
 * @since   3.7.0
 */

namespace Tygh\Addons\NovotonHolidays\Repository;

interface HotelSearchRepositoryInterface
{
    /** Find all hotels matching optional filters, with pagination (listing columns only). */
    public function findAll(array $filters = [], int $limit = 0, int $offset = 0): array;

    /** Alias for findAll — kept for backward compat with the admin listing callers. */
    public function findAllForListing(array $filters = [], int $limit = 0, int $offset = 0): array;

    /** Find every hotel in a country (listing columns only). */
    public function findByCountry(string $country): array;

    /** Alias for findByCountry — kept for backward compat. */
    public function findByCountryForListing(string $country): array;

    /**
     * Find hotels in a country, indexed by hotel_id.
     *
     * @return array<string, array>
     */
    public function findByCountryIndexed(string $country): array;

    /** Find hotels by country with optional limit (minimal columns). */
    public function findByCountryWithLimit(string $country, int $limit = 0): array;

    /** Find hotels that have no package records linked in novoton_hotel_packages. */
    public function findWithoutPackages(int $limit = 0): array;

    /**
     * Find hotels that have prices but no linked CS-Cart product.
     *
     * @param string[] $excludeResorts
     */
    public function findUnlinkedWithPrices(string $country, array $excludeResorts = [], int $limit = 0): array;

    /** Find unlinked hotels for the "view hotels to add" admin page. */
    public function findUnlinkedForAdmin(string $country, string $filter = 'prices', int $limit = 500): array;

    /** Find hotels whose last price check is older than $daysStale. */
    public function findNeedingPriceCheck(int $daysStale = 7, int $limit = 100): array;

    /** Find hotels that have prices but a stale last price check. */
    public function findNeedingPriceUpdate(int $staleHours = 24, int $limit = 100): array;

    /** Find hotels with linked products, ordered by stalest price check first. */
    public function findWithProductsSortedByStaleness(int $limit = 50): array;

    /** Find hotels with active prices for CSV export. */
    public function findWithPricesForExport(string $country): array;

    /**
     * Find hotels for bulk import (supports all-columns projection).
     *
     * @param string[] $selectedResorts
     */
    public function findForImport(string $country, string $importMode = 'new_only', array $selectedResorts = [], int $limit = 0): array;

    /**
     * Find hotel IDs that have priceinfo data in their packages.
     *
     * @return string[]
     */
    public function findIdsWithPriceinfoData(): array;

    /** Find hotels linked to products (for SEO bulk apply), paginated. */
    public function findLinkedForSeo(int $offset, int $batch): array;

    /** Find hotels that have priceinfo data (joined with packages), for price comparison listing. */
    public function findWithPriceinfoData(int $limit = 200): array;

    /** Count hotels matching optional filters (same filter shape as findAll). */
    public function count(array $filters = []): int;
}

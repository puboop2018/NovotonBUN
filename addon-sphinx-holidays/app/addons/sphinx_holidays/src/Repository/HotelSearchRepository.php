<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;

/**
 * Name/filter search reads over the sphinx_hotels table: the paged admin
 * listing (getFiltered), the general name search, and the lightweight
 * autocomplete search.
 *
 * Extracted from HotelRepository, which mixed these listing reads with the core
 * CRUD + sync-write surface. Behaviour is preserved verbatim; HotelRepository
 * keeps the public methods as thin delegations so its callers are unchanged.
 * The shared listing-column definitions live in HotelListingColumnsTrait.
 */
class HotelSearchRepository
{
    use RowNarrowingTrait;
    use HotelListingColumnsTrait;

    /**
     * Get hotels with optional filters.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function getFiltered(
        string $countryCode = '',
        int $destinationId = 0,
        int $regionId = 0,
        string $syncStatus = '',
        string $query = '',
        int $page = 1,
        int $perPage = 50,
    ): array {
        $condition = '';

        if ($countryCode !== '') {
            $condition .= db_quote(' AND h.country_code = ?s', $countryCode);
        }
        if ($destinationId > 0) {
            $condition .= db_quote(' AND h.destination_id = ?i', $destinationId);
        }
        if ($regionId > 0) {
            $condition .= db_quote(' AND h.region_id = ?i', $regionId);
        }
        if ($syncStatus !== '') {
            $condition .= db_quote(' AND h.sync_status = ?s', $syncStatus);
        }
        if ($query !== '') {
            $escaped = addcslashes($query, '%_\\');
            $condition .= db_quote(' AND h.name LIKE ?l', '%' . $escaped . '%');
        }

        $total = TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:sphinx_hotels h WHERE 1 ?p',
            $condition,
        ));

        $offset = ($page - 1) * $perPage;

        $cols = $this->aliasedListingColumns();
        $items = self::asRowList(db_get_array(
            "SELECT {$cols} FROM ?:sphinx_hotels h
             WHERE 1 ?p
             ORDER BY h.country_code ASC, h.name ASC
             LIMIT ?i, ?i",
            $condition,
            $offset,
            $perPage,
        ));

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Search hotels by name (excludes large JSON/TEXT columns).
     * @return list<array<string, mixed>>
     */
    public function search(string $query, int $limit = 20): array
    {
        $query = trim($query);
        $escaped = addcslashes($query, '%_\\');
        return self::asRowList(db_get_array(
            'SELECT ' . $this->listingColumns() . ' FROM ?:sphinx_hotels WHERE name LIKE ?l ORDER BY country_code ASC, name ASC LIMIT ?i',
            '%' . $escaped . '%',
            $limit,
        ));
    }

    /**
     * Lightweight hotel name search for AJAX autocomplete.
     * Returns only the columns needed for the Select2 dropdown display.
     * @return list<array<string, mixed>>
     */
    public function searchByName(string $query, int $limit = 20): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $escaped = addcslashes($query, '%_\\');
        return self::asRowList(db_get_array(
            'SELECT hotel_id, name, classification, country_code, destination_name
             FROM ?:sphinx_hotels
             WHERE name LIKE ?l
             ORDER BY country_code ASC, name ASC
             LIMIT ?i',
            '%' . $escaped . '%',
            $limit,
        ));
    }
}

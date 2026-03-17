<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Repository;

/**
 * Repository for sphinx_hotels table operations.
 *
 * Provides type-safe read/write access with batch upsert support.
 */
class HotelRepository
{
    /**
     * Upsert a batch of hotels (INSERT ... ON DUPLICATE KEY UPDATE).
     *
     * @param array $hotels Array of hotel rows
     * @return int Number of rows affected
     */
    public function upsertBatch(array $hotels): int
    {
        if (empty($hotels)) {
            return 0;
        }

        $affected = 0;
        foreach ($hotels as $hotel) {
            $hotelId = (string) ($hotel['hotel_id'] ?? '');
            if ($hotelId === '') {
                continue;
            }

            db_query(
                "INSERT INTO ?:sphinx_hotels
                    (hotel_id, name, classification, property_type,
                     destination_id, destination_name, region_id, region_name,
                     country_code, country_name, latitude, longitude,
                     description, short_description, image_url,
                     facilities_json,
                     sync_status, last_synced_at)
                 VALUES (?s, ?s, ?i, ?s,
                     ?i, ?s, ?i, ?s,
                     ?s, ?s, ?d, ?d,
                     ?s, ?s, ?s,
                     ?s,
                     'active', ?s)
                 ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    classification = VALUES(classification),
                    property_type = VALUES(property_type),
                    destination_id = VALUES(destination_id),
                    destination_name = VALUES(destination_name),
                    region_id = VALUES(region_id),
                    region_name = VALUES(region_name),
                    country_code = VALUES(country_code),
                    country_name = VALUES(country_name),
                    latitude = VALUES(latitude),
                    longitude = VALUES(longitude),
                    description = VALUES(description),
                    short_description = VALUES(short_description),
                    image_url = VALUES(image_url),
                    facilities_json = VALUES(facilities_json),
                    sync_status = 'active',
                    last_synced_at = VALUES(last_synced_at)",
                $hotelId,
                (string) ($hotel['name'] ?? ''),
                (int) ($hotel['classification'] ?? 0),
                (string) ($hotel['property_type'] ?? 'hotel'),
                (int) ($hotel['destination_id'] ?? 0),
                (string) ($hotel['destination_name'] ?? ''),
                (int) ($hotel['region_id'] ?? 0),
                (string) ($hotel['region_name'] ?? ''),
                (string) ($hotel['country_code'] ?? ''),
                (string) ($hotel['country_name'] ?? ''),
                (float) ($hotel['latitude'] ?? 0),
                (float) ($hotel['longitude'] ?? 0),
                (string) ($hotel['description'] ?? ''),
                (string) ($hotel['short_description'] ?? ''),
                (string) ($hotel['image_url'] ?? ''),
                $hotel['facilities_json'] ?? null,
                date('Y-m-d H:i:s')
            );

            $affected++;
        }

        return $affected;
    }

    /**
     * Get hotels with optional filters.
     *
     * @return array{items: array, total: int}
     */
    public function getFiltered(
        string $countryCode = '',
        int $destinationId = 0,
        int $regionId = 0,
        string $syncStatus = '',
        string $query = '',
        int $page = 1,
        int $perPage = 50
    ): array {
        $condition = '';

        if ($countryCode !== '') {
            $condition .= db_quote(" AND h.country_code = ?s", $countryCode);
        }
        if ($destinationId > 0) {
            $condition .= db_quote(" AND h.destination_id = ?i", $destinationId);
        }
        if ($regionId > 0) {
            $condition .= db_quote(" AND h.region_id = ?i", $regionId);
        }
        if ($syncStatus !== '') {
            $condition .= db_quote(" AND h.sync_status = ?s", $syncStatus);
        }
        if ($query !== '') {
            $escaped = addcslashes($query, '%_\\');
            $condition .= db_quote(" AND h.name LIKE ?l", '%' . $escaped . '%');
        }

        $total = (int) db_get_field(
            "SELECT COUNT(*) FROM ?:sphinx_hotels h WHERE 1 ?p",
            $condition
        );

        $offset = ($page - 1) * $perPage;

        $items = db_get_array(
            "SELECT h.* FROM ?:sphinx_hotels h
             WHERE 1 ?p
             ORDER BY h.country_code ASC, h.name ASC
             LIMIT ?i, ?i",
            $condition, $offset, $perPage
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Get a single hotel by ID.
     */
    public function getById(string $hotelId): ?array
    {
        $row = db_get_row(
            "SELECT * FROM ?:sphinx_hotels WHERE hotel_id = ?s",
            $hotelId
        );

        return $row ?: null;
    }

    /**
     * Get hotels by destination ID.
     */
    public function getByDestination(int $destinationId): array
    {
        return db_get_array(
            "SELECT * FROM ?:sphinx_hotels WHERE destination_id = ?i ORDER BY name ASC",
            $destinationId
        );
    }

    /**
     * Get hotel counts grouped by country code.
     *
     * @return array<string, int>
     */
    public function getCountsByCountry(): array
    {
        $rows = db_get_array(
            "SELECT country_code, COUNT(*) as cnt FROM ?:sphinx_hotels WHERE sync_status = 'active' GROUP BY country_code ORDER BY cnt DESC"
        );

        $counts = [];
        foreach ($rows as $row) {
            $code = $row['country_code'] ?: 'unknown';
            $counts[$code] = (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * Get distinct country codes from synced hotels.
     *
     * @return string[]
     */
    public function getDistinctCountries(): array
    {
        return db_get_fields(
            "SELECT DISTINCT country_code FROM ?:sphinx_hotels WHERE country_code != '' ORDER BY country_code"
        );
    }

    /**
     * Get total number of active hotels.
     */
    public function getTotal(): int
    {
        return (int) db_get_field("SELECT COUNT(*) FROM ?:sphinx_hotels WHERE sync_status = 'active'");
    }

    /**
     * Get last hotel sync timestamp.
     */
    public function getLastSyncedAt(): ?string
    {
        $val = db_get_field("SELECT MAX(last_synced_at) FROM ?:sphinx_hotels");
        return $val ?: null;
    }

    /**
     * Search hotels by name.
     */
    public function search(string $query, int $limit = 20): array
    {
        $escaped = addcslashes($query, '%_\\');
        return db_get_array(
            "SELECT * FROM ?:sphinx_hotels WHERE name LIKE ?l ORDER BY country_code ASC, name ASC LIMIT ?i",
            '%' . $escaped . '%',
            $limit
        );
    }

    /**
     * Link a hotel to a CS-Cart product.
     */
    public function linkToProduct(string $hotelId, int $productId): void
    {
        db_query(
            "UPDATE ?:sphinx_hotels SET product_id = ?i WHERE hotel_id = ?s",
            $productId, $hotelId
        );
    }

    /**
     * Get unlinked hotels (no product_id) with optional country filter.
     *
     * @return array List of hotel rows without linked products
     */
    public function findUnlinked(string $countryCode = '', int $limit = 0): array
    {
        $condition = '';
        if ($countryCode !== '') {
            $condition .= db_quote(" AND h.country_code = ?s", $countryCode);
        }

        $limitClause = $limit > 0 ? db_quote(" LIMIT ?i", $limit) : '';

        return db_get_array(
            "SELECT h.* FROM ?:sphinx_hotels h
             WHERE h.sync_status = 'active' AND (h.product_id IS NULL OR h.product_id = 0) ?p
             ORDER BY h.country_code ASC, h.name ASC ?p",
            $condition, $limitClause
        );
    }

    /**
     * Count hotels that have a linked CS-Cart product.
     */
    public function countLinked(): int
    {
        return (int) db_get_field(
            "SELECT COUNT(*) FROM ?:sphinx_hotels WHERE product_id IS NOT NULL AND product_id > 0 AND sync_status = 'active'"
        );
    }

    /**
     * Mark hotels as inactive if not in the provided ID list (for a given country).
     * Used after sync to detect hotels removed from the API.
     *
     * @param string[] $activeIds Hotel IDs that are still active
     * @param string $countryCode Country code to scope the update
     * @return int Number of rows marked inactive
     */
    public function markInactiveExcept(array $activeIds, string $countryCode): int
    {
        if (empty($activeIds) || $countryCode === '') {
            return 0;
        }

        // Build a safe IN clause
        $placeholders = implode(',', array_fill(0, count($activeIds), '?s'));
        $params = array_merge([$countryCode], $activeIds);

        $result = db_query(
            "UPDATE ?:sphinx_hotels SET sync_status = 'inactive'
             WHERE country_code = ?s AND sync_status = 'active' AND hotel_id NOT IN ($placeholders)",
            ...$params
        );

        return (int) db_affected_rows();
    }
}

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
     * Core columns for hotel listing (excludes large JSON/TEXT columns).
     */
    private const LISTING_COLUMNS = 'hotel_id, product_id, name, classification, property_type,
        destination_id, destination_name, region_id, region_name,
        country_code, country_name, latitude, longitude,
        image_url, is_recommended, is_adults_only, rating, rating_count,
        sync_status, last_synced_at, created_at, updated_at';

    private const STATUS_ACTIVE = 'active';
    private const STATUS_INACTIVE = 'inactive';
    private const STATUS_ERROR = 'error';

    private const VALID_STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_ERROR];

    /**
     * Get LISTING_COLUMNS prefixed with a table alias.
     */
    private function aliasedListingColumns(string $alias = 'h'): string
    {
        return preg_replace('/\b(\w+)\b/', $alias . '.$1', self::LISTING_COLUMNS);
    }

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
                     images_json, facilities_json, is_adults_only,
                     sync_status, last_synced_at)
                 VALUES (?s, ?s, ?i, ?s,
                     ?i, ?s, ?i, ?s,
                     ?s, ?s, ?d, ?d,
                     ?s, ?s, ?s,
                     ?s, ?s, ?s,
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
                    images_json = VALUES(images_json),
                    facilities_json = VALUES(facilities_json),
                    is_adults_only = VALUES(is_adults_only),
                    sync_status = 'active',
                    last_synced_at = VALUES(last_synced_at),
                    product_skip_reason = IF(
                        destination_name != VALUES(destination_name)
                        OR country_name != VALUES(country_name)
                        OR country_code != VALUES(country_code),
                        NULL, product_skip_reason
                    ),
                    product_needs_update = IF(
                        product_id IS NOT NULL AND product_id > 0 AND (
                            name != VALUES(name)
                            OR description != VALUES(description)
                            OR short_description != VALUES(short_description)
                            OR classification != VALUES(classification)
                            OR image_url != VALUES(image_url)
                        ),
                        'Y', product_needs_update
                    )",
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
                $hotel['images_json'] ?? '[]',
                $hotel['facilities_json'] ?? '[]',
                (string) ($hotel['is_adults_only'] ?? 'N'),
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

        $cols = $this->aliasedListingColumns();
        $items = db_get_array(
            "SELECT {$cols} FROM ?:sphinx_hotels h
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
     * Get hotels by destination ID (excludes large JSON/TEXT columns).
     */
    public function getByDestination(int $destinationId): array
    {
        return db_get_array(
            "SELECT " . self::LISTING_COLUMNS . " FROM ?:sphinx_hotels WHERE destination_id = ?i ORDER BY name ASC",
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
     * Get last hotel sync timestamp, optionally per country.
     */
    public function getLastSyncedAt(?string $countryCode = null): ?string
    {
        if ($countryCode !== null) {
            $val = db_get_field(
                "SELECT MAX(last_synced_at) FROM ?:sphinx_hotels WHERE country_code = ?s",
                $countryCode
            );
        } else {
            $val = db_get_field("SELECT MAX(last_synced_at) FROM ?:sphinx_hotels");
        }
        return $val ?: null;
    }

    /**
     * Search hotels by name (excludes large JSON/TEXT columns).
     */
    public function search(string $query, int $limit = 20): array
    {
        $escaped = addcslashes($query, '%_\\');
        return db_get_array(
            "SELECT " . self::LISTING_COLUMNS . " FROM ?:sphinx_hotels WHERE name LIKE ?l ORDER BY country_code ASC, name ASC LIMIT ?i",
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
        $cols = $this->aliasedListingColumns();

        // Product creation needs TEXT/JSON columns excluded from LISTING_COLUMNS
        $extraCols = ', h.description, h.short_description, h.facilities_json, h.boards_json';

        return db_get_array(
            "SELECT {$cols}{$extraCols} FROM ?:sphinx_hotels h
             WHERE h.sync_status = 'active'
               AND (h.product_id IS NULL OR h.product_id = 0)
               AND h.product_skip_reason IS NULL ?p
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
     * Get distinct destination_ids for active hotels, filtered by country codes.
     *
     * @param string[] $countryCodes Country codes to filter (e.g. ['GR', 'BG'])
     * @return int[] Distinct destination IDs
     */
    public function getDestinationIdsByCountry(array $countryCodes): array
    {
        if (empty($countryCodes)) {
            return db_get_fields(
                "SELECT DISTINCT destination_id FROM ?:sphinx_hotels WHERE sync_status = 'active' AND destination_id > 0"
            );
        }

        $placeholders = implode(',', array_fill(0, count($countryCodes), '?s'));
        return db_get_fields(
            "SELECT DISTINCT destination_id FROM ?:sphinx_hotels WHERE sync_status = 'active' AND destination_id > 0 AND country_code IN ($placeholders)",
            ...$countryCodes
        );
    }

    /**
     * Get hotel_id → hotel_id map for a given destination (for matching cache deals to hotels).
     *
     * @return array<string, string> hotel_id => hotel_id
     */
    public function getHotelIdsByDestination(int $destinationId): array
    {
        return db_get_hash_single_array(
            "SELECT hotel_id, hotel_id FROM ?:sphinx_hotels WHERE destination_id = ?i AND sync_status = 'active'",
            ['hotel_id', 'hotel_id'],
            $destinationId
        );
    }

    /**
     * Update boards_json for a batch of hotels.
     *
     * @param array<string, string[]> $boardsByHotel hotel_id => array of canonical board codes
     * @return int Number of hotels updated
     */
    public function updateBoardsBatch(array $boardsByHotel): int
    {
        $updated = 0;
        foreach ($boardsByHotel as $hotelId => $boards) {
            $json = !empty($boards) ? (json_encode(array_values(array_unique($boards))) ?: '[]') : '[]';
            db_query(
                "UPDATE ?:sphinx_hotels SET boards_json = ?s WHERE hotel_id = ?s",
                $json, (string) $hotelId
            );
            $updated++;
        }
        return $updated;
    }

    /**
     * Get hotels that have boards_json AND a linked product.
     *
     * Returns all fields needed by SphinxFeatureAssigner::assignAll().
     *
     * @param string $countryCode Optional country filter
     * @param int $limit Max rows (0 = unlimited)
     * @param int $offset Starting offset for pagination
     * @return array List of hotel rows
     */
    public function findWithBoardsAndProduct(string $countryCode = '', int $limit = 0, int $offset = 0): array
    {
        $condition = " AND h.boards_json IS NOT NULL AND h.product_id IS NOT NULL AND h.product_id > 0";
        if ($countryCode !== '') {
            $condition .= db_quote(" AND h.country_code = ?s", $countryCode);
        }

        $limitClause = '';
        if ($limit > 0) {
            $limitClause = db_quote(" LIMIT ?i, ?i", $offset, $limit);
        } elseif ($offset > 0) {
            // MySQL max BIGINT UNSIGNED — effectively "no limit, offset only"
            $limitClause = db_quote(" LIMIT ?i, 18446744073709551615", $offset);
        }

        return db_get_array(
            "SELECT h.hotel_id, h.product_id, h.boards_json, h.name,
                    h.classification, h.property_type, h.destination_name,
                    h.facilities_json, h.country_code
             FROM ?:sphinx_hotels h
             WHERE h.sync_status = 'active' ?p
             ORDER BY h.hotel_id ASC ?p",
            $condition, $limitClause
        );
    }

    /**
     * Count hotels that have boards_json AND a linked product.
     */
    public function countWithBoardsAndProduct(string $countryCode = ''): int
    {
        $condition = " AND h.boards_json IS NOT NULL AND h.product_id IS NOT NULL AND h.product_id > 0";
        if ($countryCode !== '') {
            $condition .= db_quote(" AND h.country_code = ?s", $countryCode);
        }

        return (int) db_get_field(
            "SELECT COUNT(*) FROM ?:sphinx_hotels h
             WHERE h.sync_status = 'active' ?p",
            $condition
        );
    }

    /**
     * Mark hotels as inactive if they weren't touched since the given timestamp.
     *
     * Replaces markInactiveExcept() for scalability: uses a single indexed WHERE clause
     * instead of NOT IN (100k IDs). Works because upsertBatch() sets last_synced_at = NOW()
     * on every hotel it touches, so any hotel with last_synced_at < $syncStartedAt was not
     * in the API response and is therefore stale.
     *
     * @param string $syncStartedAt Timestamp recorded before sync started (Y-m-d H:i:s)
     * @param string $countryCode Country code to scope the update
     * @return int Number of rows marked inactive
     */
    public function markInactiveBefore(string $syncStartedAt, string $countryCode): int
    {
        if ($countryCode === '') {
            return 0;
        }

        return (int) db_query(
            "UPDATE ?:sphinx_hotels SET sync_status = 'inactive'
             WHERE country_code = ?s AND sync_status = 'active' AND last_synced_at < ?s",
            $countryCode, $syncStartedAt
        );
    }

    /**
     * Mark a hotel as skipped for product creation with a reason.
     *
     * Skipped hotels are excluded from findUnlinked() on subsequent runs.
     * Reset with resetSkipped() to retry.
     */
    public function markSkipped(string $hotelId, string $reason): void
    {
        db_query(
            "UPDATE ?:sphinx_hotels SET product_skip_reason = ?s WHERE hotel_id = ?s",
            $reason, $hotelId
        );
    }

    /**
     * Reset skip reason for hotels, making them eligible for product creation again.
     *
     * @return int Number of hotels reset
     */
    public function resetSkipped(string $countryCode = '', string $reason = ''): int
    {
        $condition = '';
        if ($countryCode !== '') {
            $condition .= db_quote(" AND country_code = ?s", $countryCode);
        }
        if ($reason !== '') {
            $condition .= db_quote(" AND product_skip_reason = ?s", $reason);
        }

        return (int) db_query(
            "UPDATE ?:sphinx_hotels SET product_skip_reason = NULL
             WHERE product_skip_reason IS NOT NULL ?p",
            $condition
        );
    }

    /**
     * Count hotels that have been skipped for product creation.
     */
    public function countSkipped(string $reason = ''): int
    {
        $condition = '';
        if ($reason !== '') {
            $condition = db_quote(" AND product_skip_reason = ?s", $reason);
        }
        return (int) db_get_field(
            "SELECT COUNT(*) FROM ?:sphinx_hotels WHERE product_skip_reason IS NOT NULL ?p",
            $condition
        );
    }

    /**
     * Bulk update sync_status for a list of hotel IDs.
     *
     * @param string[] $hotelIds Hotel IDs to update
     * @param string $status Target status (active, inactive, error)
     * @return int Number of rows affected
     */
    public function bulkUpdateStatus(array $hotelIds, string $status): int
    {
        if (empty($hotelIds) || !in_array($status, self::VALID_STATUSES, true)) {
            return 0;
        }

        return (int) db_query(
            "UPDATE ?:sphinx_hotels SET sync_status = ?s WHERE hotel_id IN (?a)",
            $status,
            $hotelIds
        );
    }

    /**
     * Bulk delete hotels by ID.
     *
     * @param string[] $hotelIds Hotel IDs to delete
     * @return int Number of rows deleted
     */
    public function bulkDelete(array $hotelIds): int
    {
        if (empty($hotelIds)) {
            return 0;
        }

        return (int) db_query(
            "DELETE FROM ?:sphinx_hotels WHERE hotel_id IN (?a)",
            $hotelIds
        );
    }

    /**
     * Get distinct classification values present in the data.
     *
     * @return int[]
     */
    public function getDistinctClassifications(): array
    {
        return array_map(
            'intval',
            db_get_fields(
                "SELECT DISTINCT classification FROM ?:sphinx_hotels WHERE classification IS NOT NULL ORDER BY classification"
            )
        );
    }

    /**
     * Get distinct property_type values present in the data.
     *
     * @return string[]
     */
    public function getDistinctPropertyTypes(): array
    {
        return db_get_fields(
            "SELECT DISTINCT property_type FROM ?:sphinx_hotels WHERE property_type IS NOT NULL AND property_type != '' ORDER BY property_type"
        );
    }
}

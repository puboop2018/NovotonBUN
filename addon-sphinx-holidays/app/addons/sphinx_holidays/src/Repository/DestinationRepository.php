<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Repository;

/**
 * Repository for sphinx_destinations table operations.
 *
 * Provides type-safe read/write access with batch upsert support.
 */
class DestinationRepository
{
    /**
     * Upsert a batch of destinations (INSERT ... ON DUPLICATE KEY UPDATE).
     *
     * @param array $destinations Array of destination rows, each with keys matching DB columns
     * @return int Number of rows affected
     */
    public function upsertBatch(array $destinations): int
    {
        if (empty($destinations)) {
            return 0;
        }

        $affected = 0;
        foreach ($destinations as $dest) {
            $id = (int) ($dest['destination_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            db_query(
                "INSERT INTO ?:sphinx_destinations
                    (destination_id, name, type, parent_id, country_code, geoname_id, latitude, longitude, hotel_count, last_synced_at)
                 VALUES (?i, ?s, ?s, ?i, ?s, ?i, ?d, ?d, ?i, ?s)
                 ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    type = VALUES(type),
                    parent_id = VALUES(parent_id),
                    country_code = VALUES(country_code),
                    geoname_id = VALUES(geoname_id),
                    latitude = VALUES(latitude),
                    longitude = VALUES(longitude),
                    hotel_count = VALUES(hotel_count),
                    last_synced_at = VALUES(last_synced_at)",
                $id,
                (string) ($dest['name'] ?? ''),
                (string) ($dest['type'] ?? 'destination'),
                (int) ($dest['parent_id'] ?? 0),
                (string) ($dest['country_code'] ?? ''),
                (int) ($dest['geoname_id'] ?? 0),
                (float) ($dest['latitude'] ?? 0),
                (float) ($dest['longitude'] ?? 0),
                (int) ($dest['hotel_count'] ?? 0),
                date('Y-m-d H:i:s')
            );

            $affected++;
        }

        return $affected;
    }

    /**
     * Get all destinations, optionally filtered by type.
     *
     * @param string $type Filter by type (continent, country, region, city, destination)
     * @param int $parentId Filter by parent_id
     * @param int $page Page number (1-based)
     * @param int $perPage Items per page
     * @return array{items: array, total: int}
     */
    public function getFiltered(string $type = '', int $parentId = 0, int $page = 1, int $perPage = 50): array
    {
        $condition = '';

        if (!empty($type)) {
            $condition .= db_quote(" AND d.type = ?s", $type);
        }

        if ($parentId > 0) {
            $condition .= db_quote(" AND d.parent_id = ?i", $parentId);
        }

        $total = (int) db_get_field(
            "SELECT COUNT(*) FROM ?:sphinx_destinations d WHERE 1 ?p",
            $condition
        );

        $offset = ($page - 1) * $perPage;

        $items = db_get_array(
            "SELECT d.* FROM ?:sphinx_destinations d
             WHERE 1 ?p
             ORDER BY d.type ASC, d.name ASC
             LIMIT ?i, ?i",
            $condition, $offset, $perPage
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Get a single destination by ID.
     */
    public function getById(int $id): ?array
    {
        $row = db_get_row(
            "SELECT * FROM ?:sphinx_destinations WHERE destination_id = ?i",
            $id
        );

        return $row ?: null;
    }

    /**
     * Get children of a parent destination.
     */
    public function getChildren(int $parentId): array
    {
        return db_get_array(
            "SELECT * FROM ?:sphinx_destinations WHERE parent_id = ?i ORDER BY name ASC",
            $parentId
        );
    }

    /**
     * Get all countries (type = 'country'), ordered by name.
     */
    public function getCountries(): array
    {
        return db_get_array(
            "SELECT * FROM ?:sphinx_destinations WHERE type = 'country' ORDER BY name ASC"
        );
    }

    /**
     * Get destination counts by type.
     *
     * @return array<string, int> e.g. ['continent' => 5, 'country' => 120, ...]
     */
    public function getCountsByType(): array
    {
        $rows = db_get_array(
            "SELECT type, COUNT(*) as cnt FROM ?:sphinx_destinations GROUP BY type ORDER BY type"
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['type']] = (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * Get total number of destinations.
     */
    public function getTotal(): int
    {
        return (int) db_get_field("SELECT COUNT(*) FROM ?:sphinx_destinations");
    }

    /**
     * Get last sync timestamp.
     */
    public function getLastSyncedAt(): ?string
    {
        $val = db_get_field(
            "SELECT MAX(last_synced_at) FROM ?:sphinx_destinations"
        );

        return $val ?: null;
    }

    /**
     * Delete all destinations (used before full re-sync if needed).
     */
    public function truncate(): void
    {
        db_query("DELETE FROM ?:sphinx_destinations");
    }

    /**
     * Get regions for a country (direct children of the country destination).
     *
     * @param string $countryCode ISO country code (e.g. 'GR')
     * @return array Regions with destination_id, name, type, hotel_count
     */
    public function getRegionsByCountry(string $countryCode): array
    {
        $countryId = db_get_field(
            "SELECT destination_id FROM ?:sphinx_destinations WHERE country_code = ?s AND type = 'country' LIMIT 1",
            $countryCode
        );

        if (!$countryId) {
            return [];
        }

        return db_get_array(
            "SELECT destination_id, name, type, hotel_count FROM ?:sphinx_destinations
             WHERE parent_id = ?i ORDER BY name",
            (int) $countryId
        );
    }

    /**
     * Get cities/resorts under a parent destination (region or country).
     *
     * @param int $parentId Parent destination ID
     * @return array Cities with destination_id, name, type, hotel_count
     */
    public function getCitiesByParent(int $parentId): array
    {
        return db_get_array(
            "SELECT destination_id, name, type, hotel_count FROM ?:sphinx_destinations
             WHERE parent_id = ?i ORDER BY name",
            $parentId
        );
    }

    /**
     * Find destinations by exact name (case-insensitive).
     *
     * Returns all matches ordered by hierarchy (region > city > destination)
     * so the caller can pick the most relevant one for disambiguation.
     *
     * @param string $name Destination name (e.g. "Crete", "Athens")
     * @return array All matching destination rows
     */
    public function findByExactName(string $name): array
    {
        return db_get_array(
            "SELECT * FROM ?:sphinx_destinations
             WHERE LOWER(name) = ?s
             ORDER BY FIELD(type, 'continent', 'country', 'region', 'city', 'destination') ASC",
            mb_strtolower($name)
        );
    }

    /**
     * Search destinations by name.
     *
     * @param string $query Search term
     * @param int $limit Max results
     */
    public function search(string $query, int $limit = 20): array
    {
        $escaped = addcslashes($query, '%_\\');

        return db_get_array(
            "SELECT * FROM ?:sphinx_destinations WHERE name LIKE ?l ORDER BY type ASC, name ASC LIMIT ?i",
            '%' . $escaped . '%',
            $limit
        );
    }
}

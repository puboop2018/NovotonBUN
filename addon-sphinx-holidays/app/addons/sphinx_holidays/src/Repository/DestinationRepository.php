<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Repository;

use Tygh\Addons\TravelCore\Helpers\ValidationHelpers;

/**
 * Repository for sphinx_destinations table operations.
 *
 * Provides type-safe read/write access with batch upsert support.
 */
class DestinationRepository
{
    /** Destination types that map to the "city" level in the hierarchy. */
    private const array CITY_LEVEL_TYPES = ['city', 'destination', 'resort'];

    /**
     * Lightweight parent lookup cache: id → [name, type, parent_id].
     * Loaded once via loadParentLookup(), reused across resolveHierarchies() calls.
     *
     * @var array<int, array<string, mixed>>|null
     */
    private ?array $parentLookup = null;

    /**
     * Upsert a batch of destinations (INSERT ... ON DUPLICATE KEY UPDATE).
     *
     * @param list<array<string, mixed>> $destinations Array of destination rows, each with keys matching DB columns
     * @return int Number of rows affected
     */
    public function upsertBatch(array $destinations): int
    {
        if (empty($destinations)) {
            return 0;
        }

        $affected = 0;
        foreach ($destinations as $dest) {
            if (!is_array($dest)) {
                continue;
            }
            $id = ValidationHelpers::toInt($dest['destination_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            db_query(
                'INSERT INTO ?:sphinx_destinations
                    (destination_id, name, type, parent_id, country_code, geoname_id, latitude, longitude, hotel_count, last_synced_at)
                 VALUES (?i, ?s, ?s, ?i, ?s, ?i, ?d, ?d, ?i, ?s)
                 AS new_row
                 ON DUPLICATE KEY UPDATE
                    name = new_row.name,
                    type = new_row.type,
                    parent_id = new_row.parent_id,
                    country_code = new_row.country_code,
                    geoname_id = new_row.geoname_id,
                    latitude = new_row.latitude,
                    longitude = new_row.longitude,
                    hotel_count = new_row.hotel_count,
                    last_synced_at = new_row.last_synced_at',
                $id,
                ValidationHelpers::toString($dest['name'] ?? ''),
                ValidationHelpers::toString($dest['type'] ?? 'destination'),
                ValidationHelpers::toInt($dest['parent_id'] ?? 0),
                ValidationHelpers::toString($dest['country_code'] ?? ''),
                ValidationHelpers::toInt($dest['geoname_id'] ?? 0),
                ValidationHelpers::toFloat($dest['latitude'] ?? 0),
                ValidationHelpers::toFloat($dest['longitude'] ?? 0),
                ValidationHelpers::toInt($dest['hotel_count'] ?? 0),
                date('Y-m-d H:i:s'),
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
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function getFiltered(string $type = '', int $parentId = 0, int $page = 1, int $perPage = 50): array
    {
        $condition = '';

        if (!empty($type)) {
            $condition .= db_quote(' AND d.type = ?s', $type);
        }

        if ($parentId > 0) {
            $condition .= db_quote(' AND d.parent_id = ?i', $parentId);
        }

        $total = ValidationHelpers::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:sphinx_destinations d WHERE 1 ?p',
            $condition,
        ));

        $offset = ($page - 1) * $perPage;

        $items = db_get_array(
            'SELECT d.* FROM ?:sphinx_destinations d
             WHERE 1 ?p
             ORDER BY d.type ASC, d.name ASC
             LIMIT ?i, ?i',
            $condition,
            $offset,
            $perPage,
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Get a single destination by ID.
     * @return array<string, mixed>|null
     */
    public function getById(int $id): ?array
    {
        /** @var array<string, mixed>|null $row */
        $row = db_get_row(
            'SELECT * FROM ?:sphinx_destinations WHERE destination_id = ?i',
            $id,
        );

        return (is_array($row) && !empty($row)) ? $row : null;
    }

    /**
     * Get children of a parent destination.
     * @return array<string, mixed>
     */
    public function getChildren(int $parentId): array
    {
        return db_get_array(
            'SELECT * FROM ?:sphinx_destinations WHERE parent_id = ?i ORDER BY name ASC',
            $parentId,
        );
    }

    /**
     * Get all countries (type = 'country'), ordered by name.
     * @return array<string, mixed>
     */
    public function getCountries(): array
    {
        return db_get_array(
            "SELECT * FROM ?:sphinx_destinations WHERE type = 'country' ORDER BY name ASC",
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
            'SELECT type, COUNT(*) as cnt FROM ?:sphinx_destinations GROUP BY type ORDER BY type',
        );

        $counts = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $counts[ValidationHelpers::toString($row['type'] ?? '')] = ValidationHelpers::toInt($row['cnt'] ?? 0);
            }
        }

        return $counts;
    }

    /**
     * Get total number of destinations.
     */
    public function getTotal(): int
    {
        return ValidationHelpers::toInt(db_get_field('SELECT COUNT(*) FROM ?:sphinx_destinations'));
    }

    /**
     * Get last sync timestamp.
     */
    public function getLastSyncedAt(): ?string
    {
        $val = db_get_field(
            'SELECT MAX(last_synced_at) FROM ?:sphinx_destinations',
        );

        return is_string($val) && $val !== '' ? $val : null;
    }

    /**
     * Delete all destinations (used before full re-sync if needed).
     */
    public function truncate(): void
    {
        db_query('DELETE FROM ?:sphinx_destinations');
    }

    /**
     * Get regions for a country (direct children of the country destination).
     *
     * @param string $countryCode ISO country code (e.g. 'GR')
     * @return array<string, mixed> Regions with destination_id, name, type, hotel_count
     */
    public function getRegionsByCountry(string $countryCode): array
    {
        $countryId = ValidationHelpers::toInt(db_get_field(
            "SELECT destination_id FROM ?:sphinx_destinations WHERE country_code = ?s AND type = 'country' LIMIT 1",
            $countryCode,
        ));

        if ($countryId <= 0) {
            return [];
        }

        return db_get_array(
            'SELECT destination_id, name, type, hotel_count FROM ?:sphinx_destinations
             WHERE parent_id = ?i ORDER BY name',
            $countryId,
        );
    }

    /**
     * Get cities/resorts under a parent destination (region or country).
     *
     * @param int $parentId Parent destination ID
     * @return array<string, mixed> Cities with destination_id, name, type, hotel_count
     */
    public function getCitiesByParent(int $parentId): array
    {
        return db_get_array(
            'SELECT destination_id, name, type, hotel_count FROM ?:sphinx_destinations
             WHERE parent_id = ?i ORDER BY name',
            $parentId,
        );
    }

    /**
     * Find destinations by exact name (case-insensitive).
     *
     * Returns all matches ordered by hierarchy (region > city > destination)
     * so the caller can pick the most relevant one for disambiguation.
     *
     * @param string $name Destination name (e.g. "Crete", "Athens")
     * @return array<string, mixed> All matching destination rows
     */
    public function findByExactName(string $name): array
    {
        return db_get_array(
            "SELECT * FROM ?:sphinx_destinations
             WHERE LOWER(name) = ?s
             ORDER BY FIELD(type, 'continent', 'country', 'region', 'city', 'destination') ASC",
            mb_strtolower($name),
        );
    }

    /**
     * Search destinations by name.
     *
     * @param string $query Search term
     * @param int $limit Max results
     * @return array<string, mixed>
     */
    public function search(string $query, int $limit = 20): array
    {
        $escaped = addcslashes($query, '%_\\');

        return db_get_array(
            'SELECT * FROM ?:sphinx_destinations WHERE name LIKE ?l ORDER BY type ASC, name ASC LIMIT ?i',
            '%' . $escaped . '%',
            $limit,
        );
    }

    /**
     * Load destination rows in chunks, keyed by destination_id.
     *
     * Generic chunk-loader for building in-memory lookups without loading
     * all 200k+ rows in a single query. ~80 bytes/row × 200k ≈ 16 MB.
     *
     * @param array<string> $columns Columns to select (must include destination_id)
     * @param callable $mapper fn(array $row): array — transforms each row into the stored value
     * @return array<int, array<string, mixed>> Keyed by destination_id
     */
    private function loadChunked(array $columns, callable $mapper): array
    {
        $lookup = [];
        $chunkSize = 10000;
        $offset = 0;
        $cols = implode(', ', $columns);

        while (true) {
            $chunk = db_get_array(
                "SELECT {$cols} FROM ?:sphinx_destinations ORDER BY destination_id LIMIT ?i, ?i",
                $offset,
                $chunkSize,
            );

            if (empty($chunk)) {
                break;
            }

            foreach ($chunk as $row) {
                $lookup[(int) $row['destination_id']] = $mapper($row);
            }

            if (count($chunk) < $chunkSize) {
                break;
            }
            $offset += $chunkSize;
        }

        return $lookup;
    }

    /**
     * Build full_path breadcrumbs for all destinations by walking parent_id chains.
     *
     * Generates paths like "Athens, Attica, Greece, Europe" for disambiguation.
     * Called after destination sync completes.
     *
     * @return int Number of paths updated
     */
    public function buildFullPaths(): int
    {
        $parentLookup = $this->loadChunked(
            ['destination_id', 'name', 'parent_id'],
            static fn (array $row): array => [
                'name' => $row['name'],
                'parent_id' => (int) $row['parent_id'],
            ],
        );

        if (empty($parentLookup)) {
            return 0;
        }

        // Process updates in chunks: walk chains and update full_path
        $updated = 0;
        $ids = array_keys($parentLookup);
        $idChunks = array_chunk($ids, 500);

        foreach ($idChunks as $idBatch) {
            foreach ($idBatch as $id) {
                $row = $parentLookup[$id];
                $segments = [$row['name']];
                $currentId = $row['parent_id'];
                $visited = [$id => true]; // cycle protection

                // Walk up the parent chain (max depth 5)
                while ($currentId > 0 && isset($parentLookup[$currentId]) && !isset($visited[$currentId])) {
                    $visited[$currentId] = true;
                    $segments[] = $parentLookup[$currentId]['name'];
                    $currentId = $parentLookup[$currentId]['parent_id'];
                }

                $fullPath = implode(', ', $segments);
                db_query(
                    'UPDATE ?:sphinx_destinations SET full_path = ?s WHERE destination_id = ?i',
                    $fullPath,
                    $id,
                );
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Get all continents with country count.
     *
     * @return array<string, mixed> Continents with destination_id, name, type, country_count
     */
    public function getContinents(): array
    {
        return db_get_array(
            "SELECT d.destination_id, d.name, d.type,
                    (SELECT COUNT(*) FROM ?:sphinx_destinations c WHERE c.parent_id = d.destination_id) AS country_count
             FROM ?:sphinx_destinations d
             WHERE d.type = 'continent'
             ORDER BY d.name ASC",
        );
    }

    /**
     * Load the full destination parent lookup into memory.
     *
     * Call once before processing batches; reused by resolveHierarchies().
     *
     * @return bool True if lookup has data, false if sphinx_destinations is empty
     */
    public function loadParentLookup(): bool
    {
        if ($this->parentLookup !== null) {
            return !empty($this->parentLookup);
        }

        $this->parentLookup = $this->loadChunked(
            ['destination_id', 'name', 'type', 'parent_id', 'country_code'],
            static fn (array $row): array => [
                'name' => $row['name'],
                'type' => $row['type'],
                'parent_id' => (int) $row['parent_id'],
                'country_code' => $row['country_code'] ?? '',
            ],
        );

        return !empty($this->parentLookup);
    }

    /**
     * Resolve destination hierarchies for a set of destination IDs.
     *
     * Walks each destination's parent_id chain to extract city, region, and country names.
     * Requires loadParentLookup() to have been called first.
     *
     * @param array<int> $destinationIds List of destination IDs to resolve
     * @return array<int, array{city: string, region: string, region_id: int, country: string, country_code: string}> Keyed by destination_id
     */
    public function resolveHierarchies(array $destinationIds): array
    {
        if (empty($destinationIds) || empty($this->parentLookup)) {
            return [];
        }

        $result = [];
        foreach ($destinationIds as $destId) {
            $destId = (int) $destId;
            $hierarchy = ['city' => '', 'region' => '', 'region_id' => 0, 'country' => '', 'country_code' => ''];

            $currentId = $destId;
            $visited = [];

            // Walk up parent chain (max depth ~5: destination → city → region → country → continent)
            while ($currentId > 0 && isset($this->parentLookup[$currentId]) && !isset($visited[$currentId])) {
                $visited[$currentId] = true;
                $node = $this->parentLookup[$currentId];
                $type = $node['type'];

                if (in_array($type, self::CITY_LEVEL_TYPES, true)) {
                    // First city-level node wins (the hotel's own destination)
                    if ($hierarchy['city'] === '') {
                        $hierarchy['city'] = $node['name'];
                    }
                } elseif ($type === 'region') {
                    if ($hierarchy['region'] === '') {
                        $hierarchy['region'] = $node['name'];
                        $hierarchy['region_id'] = $currentId;
                    }
                } elseif ($type === 'country') {
                    $hierarchy['country'] = $node['name'];
                    $hierarchy['country_code'] = $node['country_code'];
                }

                $currentId = (int) $node['parent_id'];
            }

            $result[$destId] = $hierarchy;
        }

        return $result;
    }

    /**
     * Get the country_code for a destination by its ID.
     *
     * @return string|null Country code or null if not found
     */
    public function getCountryCodeById(int $destinationId): ?string
    {
        $code = db_get_field(
            'SELECT country_code FROM ?:sphinx_destinations WHERE destination_id = ?i',
            $destinationId,
        );
        return ($code !== false && $code !== '') ? (string) $code : null;
    }

    /**
     * Count city-level destinations (city, destination) for a given country code.
     *
     * @param string $countryCode ISO country code
     */
    public function countCitiesByCountry(string $countryCode): int
    {
        return (int) db_get_field(
            "SELECT COUNT(*) FROM ?:sphinx_destinations WHERE country_code = ?s AND type IN ('city','destination')",
            $countryCode,
        );
    }

    /**
     * Find destinations by name or full_path prefix.
     *
     * "Athens" matches by name. "Athens, Greece" matches via full_path prefix,
     * enabling unambiguous disambiguation (the "Athens Problem").
     *
     * @param string $query Destination name or partial full_path
     * @return array<string, mixed> Matching destination rows, ordered by hierarchy level
     */
    public function findByNameOrPath(string $query): array
    {
        $lower = mb_strtolower(trim($query));

        return db_get_array(
            "SELECT * FROM ?:sphinx_destinations
             WHERE LOWER(name) = ?s OR LOWER(full_path) LIKE ?l
             ORDER BY FIELD(type, 'continent', 'country', 'region', 'city', 'destination') ASC",
            $lower,
            $lower . '%',
        );
    }
}

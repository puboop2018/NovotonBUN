<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Hotel Repository
 *
 * Centralized database access for hotel data.
 * V3 Architecture: Uses novoton_hotel_packages for package data.
 *
 * @package NovotonHolidays
 * @since 3.0.0
 */

namespace Tygh\Addons\NovotonHolidays\Repository;

class HotelRepository implements HotelRepositoryInterface
{
    /**
     * Find hotel by ID
     */
    public function findById(string $hotel_id): ?array
    {
        $hotel = db_get_row("SELECT * FROM ?:novoton_hotels WHERE hotel_id = ?s", $hotel_id);
        return $hotel ?: null;
    }

    /**
     * Find hotel by product ID
     */
    public function findByProductId(int $product_id): ?array
    {
        $hotel = db_get_row("SELECT * FROM ?:novoton_hotels WHERE product_id = ?i", $product_id);
        return $hotel ?: null;
    }

    /**
     * Get hotel ID by product ID
     */
    public function getHotelIdByProduct(int $product_id): ?string
    {
        $hotel_id = db_get_field("SELECT hotel_id FROM ?:novoton_hotels WHERE product_id = ?i", $product_id);
        return $hotel_id ?: null;
    }

    /**
     * Core columns for hotel listing (excludes large hotel_data JSON)
     */
    private const LISTING_COLUMNS = 'hotel_id, product_id, hotel_name, city, region, country,
        hotel_type, star_rating, latitude, longitude, has_prices, packages_count,
        hotelinfo_synced_at, hotel_list_synced_at, created_at, updated_at';

    /**
     * Find all hotels with optional filters
     */
    public function findAll(array $filters = [], int $limit = 0, int $offset = 0): array
    {
        $where = $this->buildWhereClause($filters);
        $limit_clause = $limit > 0 ? db_quote(" LIMIT ?i, ?i", $offset, $limit) : '';

        return db_get_array("SELECT * FROM ?:novoton_hotels {$where} ORDER BY hotel_name {$limit_clause}");
    }

    /**
     * Find all hotels for listing (excludes large hotel_data JSON)
     * Use this for admin lists, exports, etc. where full data isn't needed
     */
    public function findAllForListing(array $filters = [], int $limit = 0, int $offset = 0): array
    {
        $where = $this->buildWhereClause($filters);
        $limit_clause = $limit > 0 ? db_quote(" LIMIT ?i, ?i", $offset, $limit) : '';

        return db_get_array("SELECT " . self::LISTING_COLUMNS . " FROM ?:novoton_hotels {$where} ORDER BY hotel_name {$limit_clause}");
    }

    /**
     * Find hotels by country
     */
    public function findByCountry(string $country): array
    {
        return db_get_array("SELECT * FROM ?:novoton_hotels WHERE country = ?s ORDER BY hotel_name", $country);
    }

    /**
     * Find hotels by country for listing (excludes large hotel_data JSON)
     */
    public function findByCountryForListing(string $country): array
    {
        return db_get_array(
            "SELECT " . self::LISTING_COLUMNS . " FROM ?:novoton_hotels WHERE country = ?s ORDER BY hotel_name",
            $country
        );
    }

    /**
     * Get basic hotel info by ID (excludes large hotel_data JSON)
     */
    public function findBasicById(string $hotel_id): ?array
    {
        $hotel = db_get_row(
            "SELECT " . self::LISTING_COLUMNS . " FROM ?:novoton_hotels WHERE hotel_id = ?s",
            $hotel_id
        );
        return $hotel ?: null;
    }

    /**
     * Find hotels without packages (V3: checks novoton_hotel_packages table)
     */
    public function findWithoutPackages(int $limit = 0): array
    {
        $limit_clause = $limit > 0 ? db_quote(" LIMIT ?i", $limit) : '';

        return db_get_array(
            "SELECT h.* FROM ?:novoton_hotels h
             LEFT JOIN ?:novoton_hotel_packages p ON h.hotel_id = p.hotel_id
             WHERE p.id IS NULL
             ORDER BY h.hotel_name {$limit_clause}"
        );
    }

    /**
     * Count hotels with optional filters
     */
    public function count(array $filters = []): int
    {
        $where = $this->buildWhereClause($filters);
        return (int) db_get_field("SELECT COUNT(*) FROM ?:novoton_hotels {$where}");
    }

    /**
     * Count hotels without packages by country (V3: checks novoton_hotel_packages table)
     */
    public function countWithoutPackagesByCountry(): array
    {
        return db_get_hash_single_array(
            "SELECT h.country, COUNT(*) as cnt FROM ?:novoton_hotels h
             LEFT JOIN ?:novoton_hotel_packages p ON h.hotel_id = p.hotel_id
             WHERE p.id IS NULL
             GROUP BY h.country ORDER BY cnt DESC",
            ['country', 'cnt']
        );
    }

    /**
     * Check if hotel exists
     */
    public function exists(string $hotel_id): bool
    {
        return (bool) db_get_field("SELECT 1 FROM ?:novoton_hotels WHERE hotel_id = ?s", $hotel_id);
    }

    /**
     * Save hotel (insert or update)
     */
    public function save(string $hotel_id, array $data): bool
    {
        if ($this->exists($hotel_id)) {
            return $this->update($hotel_id, $data);
        }
        $data['hotel_id'] = $hotel_id;
        return $this->insert($data);
    }

    /**
     * Insert new hotel
     */
    public function insert(array $data): bool
    {
        return (bool) db_query("INSERT INTO ?:novoton_hotels ?e", $data);
    }

    /**
     * Update hotel
     */
    public function update(string $hotel_id, array $data): bool
    {
        return (bool) db_query("UPDATE ?:novoton_hotels SET ?u WHERE hotel_id = ?s", $data, $hotel_id);
    }

    /**
     * Link hotel to product
     */
    public function linkToProduct(string $hotel_id, int $product_id): bool
    {
        return $this->update($hotel_id, ['product_id' => $product_id]);
    }

    /**
     * Delete hotel
     */
    public function delete(string $hotel_id): bool
    {
        // Delete related data first
        db_query("DELETE FROM ?:novoton_hotel_facilities WHERE hotel_id = ?s", $hotel_id);
        db_query("DELETE FROM ?:novoton_hotel_packages WHERE hotel_id = ?s", $hotel_id);
        return (bool) db_query("DELETE FROM ?:novoton_hotels WHERE hotel_id = ?s", $hotel_id);
    }

    /**
     * Get distinct countries
     */
    public function getCountries(): array
    {
        return db_get_fields("SELECT DISTINCT country FROM ?:novoton_hotels WHERE country != '' ORDER BY country");
    }

    /**
     * Get distinct resorts (= city) for a country
     */
    public function getResorts(string $country = ''): array
    {
        if (!empty($country)) {
            return db_get_fields(
                "SELECT DISTINCT city FROM ?:novoton_hotels WHERE country = ?s AND city != '' ORDER BY city",
                $country
            );
        }
        return db_get_fields("SELECT DISTINCT city FROM ?:novoton_hotels WHERE city != '' ORDER BY city");
    }

    /**
     * Get packages for a hotel (V3) - full data including priceinfo_data
     */
    public function getPackages(string $hotel_id): array
    {
        return db_get_array(
            "SELECT * FROM ?:novoton_hotel_packages WHERE hotel_id = ?s ORDER BY package_name",
            $hotel_id
        );
    }

    /**
     * Get packages for listing (V3) - excludes large priceinfo_data JSON
     */
    public function getPackagesForListing(string $hotel_id): array
    {
        return db_get_array(
            "SELECT id, hotel_id, package_id, package_name, seasons_count, has_early_booking,
                    min_price, currency, synced_at, created_at, updated_at
             FROM ?:novoton_hotel_packages WHERE hotel_id = ?s ORDER BY package_name",
            $hotel_id
        );
    }

    /**
     * Save package (V3)
     */
    public function savePackage(string $hotel_id, string $package_id, array $data): bool
    {
        $data['hotel_id'] = $hotel_id;
        $data['package_id'] = $package_id;

        $exists = db_get_field(
            "SELECT id FROM ?:novoton_hotel_packages WHERE hotel_id = ?s AND package_id = ?s",
            $hotel_id,
            $package_id
        );

        if ($exists) {
            return (bool) db_query(
                "UPDATE ?:novoton_hotel_packages SET ?u WHERE hotel_id = ?s AND package_id = ?s",
                $data,
                $hotel_id,
                $package_id
            );
        }

        return (bool) db_query("INSERT INTO ?:novoton_hotel_packages ?e", $data);
    }

    /**
     * Unlink a hotel from its CS-Cart product (set product_id = NULL).
     *
     * Used when a product is deleted — the hotel record stays, only the link is removed.
     */
    public function unlinkProduct(int $product_id): bool
    {
        return (bool) db_query("UPDATE ?:novoton_hotels SET product_id = NULL WHERE product_id = ?i", $product_id);
    }

    /**
     * Get location data (city, region, country) for multiple hotels in one query.
     *
     * @param array $hotel_ids
     * @return array<string, array{hotel_id: string, city: string, region: string, country: string}>
     *         Keyed by hotel_id
     */
    public function getLocationsByIds(array $hotel_ids): array
    {
        if (empty($hotel_ids)) {
            return [];
        }
        $rows = db_get_array(
            "SELECT hotel_id, city, region, country FROM ?:novoton_hotels WHERE hotel_id IN (?a)",
            $hotel_ids
        );
        $result = [];
        foreach ($rows as $row) {
            $result[$row['hotel_id']] = $row;
        }
        return $result;
    }

    /**
     * Get the latest priceinfo_data JSON for a hotel (from novoton_hotel_packages).
     */
    public function getLatestPriceinfoData(string $hotel_id): ?string
    {
        $data = db_get_field(
            "SELECT priceinfo_data FROM ?:novoton_hotel_packages
             WHERE hotel_id = ?s AND priceinfo_data IS NOT NULL
             ORDER BY synced_at DESC LIMIT 1",
            $hotel_id
        );
        return $data ?: null;
    }

    /**
     * Get the latest synced_at timestamp for a hotel's packages.
     */
    public function getLatestPackageSyncedAt(string $hotel_id): ?string
    {
        $ts = db_get_field(
            "SELECT MAX(synced_at) FROM ?:novoton_hotel_packages WHERE hotel_id = ?s",
            $hotel_id
        );
        return $ts ?: null;
    }

    /**
     * Link a hotel to a CS-Cart product.
     */
    public function linkProduct(string $hotel_id, int $product_id): bool
    {
        return (bool) db_query(
            "UPDATE ?:novoton_hotels SET product_id = ?i WHERE hotel_id = ?s",
            $product_id,
            $hotel_id
        );
    }

    /**
     * Insert or update a hotel record (upsert).
     */
    public function upsert(array $data): bool
    {
        return (bool) db_query("INSERT INTO ?:novoton_hotels ?e ON DUPLICATE KEY UPDATE ?u", $data, $data);
    }

    /**
     * Find hotels that have prices but no linked CS-Cart product.
     *
     * @param string $country         Country filter
     * @param array  $excludeResorts  Cities to exclude
     * @param int    $limit           0 = no limit
     */
    public function findUnlinkedWithPrices(string $country, array $excludeResorts = [], int $limit = 0): array
    {
        $query = "SELECT * FROM ?:novoton_hotels
                  WHERE has_prices = 'Y' AND country = ?s
                  AND (product_id IS NULL OR product_id = 0)";
        $params = [$country];

        if (!empty($excludeResorts)) {
            $query .= " AND (city NOT IN (?a) OR city IS NULL)";
            $params[] = $excludeResorts;
        }

        $query .= " ORDER BY hotel_name";
        if ($limit > 0) {
            $query .= " LIMIT ?i";
            $params[] = $limit;
        }

        return db_get_array($query, ...$params);
    }

    /**
     * Build WHERE clause from filters
     */
    private function buildWhereClause(array $filters): string
    {
        $conditions = [];

        if (!empty($filters['country'])) {
            $conditions[] = db_quote("country = ?s", $filters['country']);
        }
        if (!empty($filters['city'])) {
            $conditions[] = db_quote("city = ?s", $filters['city']);
        }
        if (!empty($filters['resort'])) {
            $conditions[] = db_quote("city = ?s", $filters['resort']);
        }
        if (!empty($filters['has_prices'])) {
            $conditions[] = db_quote("has_prices = ?s", $filters['has_prices']);
        }
        if (!empty($filters['has_product'])) {
            $conditions[] = "product_id > 0";
        }
        if (!empty($filters['no_packages'])) {
            $conditions[] = "packages_count = 0";
        }
        if (!empty($filters['has_packages'])) {
            $conditions[] = "packages_count > 0";
        }
        if (!empty($filters['stars'])) {
            $conditions[] = db_quote("star_rating = ?i", (int) $filters['stars']);
        }
        if (!empty($filters['star_rating'])) {
            $conditions[] = db_quote("star_rating = ?i", (int) $filters['star_rating']);
        }

        return !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
    }
}

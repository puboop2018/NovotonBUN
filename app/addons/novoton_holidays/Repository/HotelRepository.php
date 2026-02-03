<?php
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

class HotelRepository
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
     * Find all hotels with optional filters
     */
    public function findAll(array $filters = [], int $limit = 0, int $offset = 0): array
    {
        $where = $this->buildWhereClause($filters);
        $limit_clause = $limit > 0 ? db_quote(" LIMIT ?i, ?i", $offset, $limit) : '';

        return db_get_array("SELECT * FROM ?:novoton_hotels {$where} ORDER BY hotel_name {$limit_clause}");
    }

    /**
     * Find hotels by country
     */
    public function findByCountry(string $country): array
    {
        return db_get_array("SELECT * FROM ?:novoton_hotels WHERE country = ?s ORDER BY hotel_name", $country);
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
     * Get packages for a hotel (V3)
     */
    public function getPackages(string $hotel_id): array
    {
        return db_get_array(
            "SELECT * FROM ?:novoton_hotel_packages WHERE hotel_id = ?s ORDER BY package_name",
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
            $conditions[] = "has_prices = 'N'";
        }
        if (!empty($filters['has_packages'])) {
            $conditions[] = "has_prices = 'Y'";
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

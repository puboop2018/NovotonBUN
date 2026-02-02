<?php
/**
 * Novoton Holidays - Hotel Repository
 * 
 * Centralized database access for hotel data.
 * 
 * @package NovotonHolidays
 * @since 2.8.0
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
     * Find hotels without packages data
     */
    public function findWithoutPackages(int $limit = 0): array
    {
        $limit_clause = $limit > 0 ? db_quote(" LIMIT ?i", $limit) : '';
        
        return db_get_array(
            "SELECT * FROM ?:novoton_hotels 
             WHERE packages_data IS NULL OR packages_data = '' OR packages_data = '[]' OR packages_data = 'null'
             ORDER BY hotel_name {$limit_clause}"
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
     * Count hotels without packages by country
     */
    public function countWithoutPackagesByCountry(): array
    {
        return db_get_hash_single_array(
            "SELECT country, COUNT(*) as cnt FROM ?:novoton_hotels 
             WHERE packages_data IS NULL OR packages_data = '' OR packages_data = '[]' OR packages_data = 'null'
             GROUP BY country ORDER BY cnt DESC",
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
     * Update packages data
     */
    public function updatePackages(string $hotel_id, array $packages, array $rooms = [], array $boards = []): bool
    {
        $data = [
            'packages_data' => json_encode($packages),
            'rooms_data' => json_encode($rooms),
            'boards_data' => json_encode($boards),
            'has_prices' => !empty($packages) ? 'Y' : 'N',
            'last_price_check' => date('Y-m-d H:i:s')
        ];
        
        return $this->update($hotel_id, $data);
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
        // Delete related facilities first
        db_query("DELETE FROM ?:novoton_hotel_facilities WHERE hotel_id = ?s", $hotel_id);
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
            $conditions[] = "(packages_data IS NULL OR packages_data = '' OR packages_data = '[]')";
        }
        if (!empty($filters['has_packages'])) {
            $conditions[] = "(packages_data IS NOT NULL AND packages_data != '' AND packages_data != '[]')";
        }
        if (!empty($filters['stars'])) {
            $conditions[] = db_quote("hotel_type = ?s", $filters['stars'] . '*');
        }
        
        return !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
    }
}

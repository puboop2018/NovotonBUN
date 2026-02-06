<?php
/**
 * Novoton Holidays - Database Iterator Helper
 *
 * Provides PHP Generator functions for memory-efficient iteration
 * over large database result sets. Uses chunked queries to avoid
 * loading all records into memory at once.
 *
 * Usage:
 *   $iterator = new DatabaseIterator();
 *   foreach ($iterator->iterateHotels(['country' => 'BULGARIA']) as $hotel) {
 *       // Process each hotel - only one row in memory at a time
 *   }
 *
 * @package NovotonHolidays
 * @since 2.9.0
 */

namespace Tygh\Addons\NovotonHolidays\Helpers;

class DatabaseIterator
{
    /**
     * Default chunk size for batch processing
     */
    private const DEFAULT_CHUNK_SIZE = 100;

    /**
     * Iterate over hotels with optional filters
     *
     * @param array $filters Filter conditions (country, has_prices, etc.)
     * @param int $chunk_size Number of records to fetch per batch
     * @return \Generator Yields one hotel row at a time
     */
    public function iterateHotels(array $filters = [], int $chunk_size = self::DEFAULT_CHUNK_SIZE): \Generator
    {
        $offset = 0;

        while (true) {
            $hotels = $this->fetchHotelChunk($filters, $chunk_size, $offset);

            if (empty($hotels)) {
                break;
            }

            foreach ($hotels as $hotel) {
                yield $hotel;
            }

            // If we got fewer than chunk_size, we've reached the end
            if (count($hotels) < $chunk_size) {
                break;
            }

            $offset += $chunk_size;
        }
    }

    /**
     * Iterate over hotel IDs only (more memory efficient)
     *
     * @param array $filters Filter conditions
     * @param int $chunk_size Number of IDs to fetch per batch
     * @return \Generator Yields one hotel_id at a time
     */
    public function iterateHotelIds(array $filters = [], int $chunk_size = self::DEFAULT_CHUNK_SIZE): \Generator
    {
        $offset = 0;

        // Build WHERE clause
        $where_parts = [];
        $params = [];

        if (!empty($filters['country'])) {
            if (is_array($filters['country'])) {
                $where_parts[] = "country IN (?a)";
                $params[] = $filters['country'];
            } else {
                $where_parts[] = "country = ?s";
                $params[] = $filters['country'];
            }
        }

        if (!empty($filters['has_prices'])) {
            $where_parts[] = "has_prices = ?s";
            $params[] = $filters['has_prices'];
        }

        if (!empty($filters['no_product'])) {
            $where_parts[] = "(product_id IS NULL OR product_id = 0)";
        }

        if (!empty($filters['has_product'])) {
            $where_parts[] = "product_id IS NOT NULL AND product_id > 0";
        }

        if (!empty($filters['no_hotelinfo'])) {
            $where_parts[] = "hotelinfo_synced_at IS NULL";
        }

        $where = !empty($where_parts) ? 'WHERE ' . implode(' AND ', $where_parts) : '';

        while (true) {
            $query = "SELECT hotel_id FROM ?:novoton_hotels {$where} ORDER BY hotel_name LIMIT ?i OFFSET ?i";
            $query_params = array_merge($params, [$chunk_size, $offset]);

            $ids = db_get_fields($query, ...$query_params);

            if (empty($ids)) {
                break;
            }

            foreach ($ids as $hotel_id) {
                yield $hotel_id;
            }

            if (count($ids) < $chunk_size) {
                break;
            }

            $offset += $chunk_size;
        }
    }

    /**
     * Iterate over hotel packages
     *
     * @param array $filters Filter conditions
     * @param int $chunk_size Number of packages to fetch per batch
     * @return \Generator Yields one package row at a time
     */
    public function iteratePackages(array $filters = [], int $chunk_size = self::DEFAULT_CHUNK_SIZE): \Generator
    {
        $offset = 0;

        // Build WHERE clause
        $where_parts = [];
        $params = [];

        if (!empty($filters['hotel_id'])) {
            $where_parts[] = "p.hotel_id = ?s";
            $params[] = $filters['hotel_id'];
        }

        if (!empty($filters['countries'])) {
            $where_parts[] = "h.country IN (?a)";
            $params[] = $filters['countries'];
        }

        if (!empty($filters['no_priceinfo'])) {
            $where_parts[] = "(p.priceinfo_data IS NULL OR p.priceinfo_data = '')";
        }

        if (!empty($filters['stale'])) {
            // Packages not synced in last 24 hours
            $where_parts[] = "(p.synced_at IS NULL OR p.synced_at < DATE_SUB(NOW(), INTERVAL 24 HOUR))";
        }

        $where = !empty($where_parts) ? 'WHERE ' . implode(' AND ', $where_parts) : '';

        while (true) {
            $query = "SELECT p.hotel_id, p.package_id, p.package_name, h.hotel_name, h.country
                      FROM ?:novoton_hotel_packages p
                      JOIN ?:novoton_hotels h ON p.hotel_id = h.hotel_id
                      {$where}
                      ORDER BY h.hotel_name, p.package_name
                      LIMIT ?i OFFSET ?i";
            $query_params = array_merge($params, [$chunk_size, $offset]);

            $packages = db_get_array($query, ...$query_params);

            if (empty($packages)) {
                break;
            }

            foreach ($packages as $package) {
                yield $package;
            }

            if (count($packages) < $chunk_size) {
                break;
            }

            $offset += $chunk_size;
        }
    }

    /**
     * Iterate over bookings
     *
     * @param array $filters Filter conditions (status, novoton_status, etc.)
     * @param int $chunk_size Number of bookings to fetch per batch
     * @return \Generator Yields one booking row at a time
     */
    public function iterateBookings(array $filters = [], int $chunk_size = self::DEFAULT_CHUNK_SIZE): \Generator
    {
        $offset = 0;

        // Build WHERE clause
        $where_parts = [];
        $params = [];

        if (!empty($filters['status'])) {
            if (is_array($filters['status'])) {
                $where_parts[] = "status IN (?a)";
                $params[] = $filters['status'];
            } else {
                $where_parts[] = "status = ?s";
                $params[] = $filters['status'];
            }
        }

        if (!empty($filters['novoton_status'])) {
            $where_parts[] = "novoton_status = ?s";
            $params[] = $filters['novoton_status'];
        }

        $where = !empty($where_parts) ? 'WHERE ' . implode(' AND ', $where_parts) : '';

        while (true) {
            $query = "SELECT * FROM ?:novoton_bookings {$where} ORDER BY created_at DESC LIMIT ?i OFFSET ?i";
            $query_params = array_merge($params, [$chunk_size, $offset]);

            $bookings = db_get_array($query, ...$query_params);

            if (empty($bookings)) {
                break;
            }

            foreach ($bookings as $booking) {
                yield $booking;
            }

            if (count($bookings) < $chunk_size) {
                break;
            }

            $offset += $chunk_size;
        }
    }

    /**
     * Iterate over sync logs
     *
     * @param string $type Optional sync type filter
     * @param int $chunk_size Number of logs to fetch per batch
     * @return \Generator Yields one log row at a time
     */
    public function iterateSyncLogs(string $type = '', int $chunk_size = self::DEFAULT_CHUNK_SIZE): \Generator
    {
        $offset = 0;

        $where = '';
        $params = [];

        if (!empty($type)) {
            $where = 'WHERE sync_type = ?s';
            $params[] = $type;
        }

        while (true) {
            $query = "SELECT * FROM ?:novoton_sync_log {$where} ORDER BY sync_date DESC LIMIT ?i OFFSET ?i";
            $query_params = array_merge($params, [$chunk_size, $offset]);

            $logs = db_get_array($query, ...$query_params);

            if (empty($logs)) {
                break;
            }

            foreach ($logs as $log) {
                yield $log;
            }

            if (count($logs) < $chunk_size) {
                break;
            }

            $offset += $chunk_size;
        }
    }

    /**
     * Generic query iterator - iterate over any query results
     *
     * @param string $query SQL query with LIMIT ?i OFFSET ?i placeholders at the end
     * @param array $params Query parameters (before limit/offset)
     * @param int $chunk_size Number of rows to fetch per batch
     * @return \Generator Yields one row at a time
     */
    public function iterateQuery(string $query, array $params = [], int $chunk_size = self::DEFAULT_CHUNK_SIZE): \Generator
    {
        $offset = 0;

        while (true) {
            $query_params = array_merge($params, [$chunk_size, $offset]);
            $rows = db_get_array($query, ...$query_params);

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                yield $row;
            }

            if (count($rows) < $chunk_size) {
                break;
            }

            $offset += $chunk_size;
        }
    }

    /**
     * Count total items matching filters
     *
     * @param string $table Table name (without prefix)
     * @param array $filters Filter conditions
     * @return int Total count
     */
    public function countItems(string $table, array $filters = []): int
    {
        $where_parts = [];
        $params = [];

        foreach ($filters as $key => $value) {
            if (is_array($value)) {
                $where_parts[] = "{$key} IN (?a)";
                $params[] = $value;
            } else {
                $where_parts[] = "{$key} = ?s";
                $params[] = $value;
            }
        }

        $where = !empty($where_parts) ? 'WHERE ' . implode(' AND ', $where_parts) : '';

        return (int) db_get_field("SELECT COUNT(*) FROM ?:{$table} {$where}", ...$params);
    }

    /**
     * Fetch a chunk of hotels
     */
    private function fetchHotelChunk(array $filters, int $limit, int $offset): array
    {
        // Build WHERE clause
        $where_parts = [];
        $params = [];

        if (!empty($filters['country'])) {
            if (is_array($filters['country'])) {
                $where_parts[] = "country IN (?a)";
                $params[] = $filters['country'];
            } else {
                $where_parts[] = "country = ?s";
                $params[] = $filters['country'];
            }
        }

        if (!empty($filters['has_prices'])) {
            $where_parts[] = "has_prices = ?s";
            $params[] = $filters['has_prices'];
        }

        if (!empty($filters['no_product'])) {
            $where_parts[] = "(product_id IS NULL OR product_id = 0)";
        }

        $where = !empty($where_parts) ? 'WHERE ' . implode(' AND ', $where_parts) : '';

        // Exclude large JSON columns for listing efficiency
        $query = "SELECT hotel_id, hotel_name, city, region, country, hotel_type,
                         star_rating, has_prices, product_id, packages_count,
                         hotelinfo_synced_at, created_at, updated_at
                  FROM ?:novoton_hotels
                  {$where}
                  ORDER BY hotel_name
                  LIMIT ?i OFFSET ?i";

        $query_params = array_merge($params, [$limit, $offset]);

        return db_get_array($query, ...$query_params);
    }
}

<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Database Helper
 *
 * Optimized database operations:
 * - Batch inserts/updates (reduce N+1 queries)
 * - Upsert operations (INSERT ... ON DUPLICATE KEY UPDATE)
 * - Bulk lookups with caching
 *
 * Injectable: Use DatabaseHelper::getInstance() or inject via constructor.
 * Testable: Use DatabaseHelper::setInstance($mockHelper) in tests.
 *
 * @package NovotonHolidays
 * @since 3.1.0
 */

namespace Tygh\Addons\NovotonHolidays\Helpers;

use Tygh\Addons\NovotonHolidays\Services\ConfigService;

class DatabaseHelper
{
    /**
     * Singleton instance (replaceable for testing)
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Lookup cache for product codes -> product IDs
     * @var array
     */
    private array $productCodeCache = [];

    /**
     * Lookup cache for hotel IDs -> hotel data
     * @var array
     */
    private array $hotelCache = [];

    /**
     * Get the singleton instance.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Replace the singleton instance (for testing / DI).
     */
    public static function setInstance(?self $instance): void
    {
        self::$instance = $instance;
    }

    /**
     * Batch update hotels has_prices flag after room_price API checks
     *
     * Used after calling room_price API to mark which hotels have available prices.
     * Updates the has_prices column (Y/N) and last_price_check timestamp.
     *
     * @param array $withPrices Array of hotel_ids that have prices (set has_prices = 'Y')
     * @param array $withoutPrices Array of hotel_ids without prices (set has_prices = 'N')
     * @return int Number of rows updated
     */
    public static function batchUpdateHasPricesFlag(array $withPrices, array $withoutPrices): int
    {
        $updated = 0;

        if (!empty($withPrices)) {
            $updated += (int) \db_query(
                "UPDATE ?:novoton_hotels
                 SET has_prices = 'Y', last_price_check = NOW()
                 WHERE hotel_id IN (?a)",
                $withPrices
            );
        }

        if (!empty($withoutPrices)) {
            $updated += (int) \db_query(
                "UPDATE ?:novoton_hotels
                 SET has_prices = 'N', last_price_check = NOW()
                 WHERE hotel_id IN (?a)",
                $withoutPrices
            );
        }

        return $updated;
    }

    /**
     * Bulk lookup: Get existing product IDs for multiple hotel IDs
     *
     * @param array $hotelIds Array of hotel IDs
     * @return array Map of hotel_id => product_id
     */
    public static function getProductIdsByHotelIds(array $hotelIds): array
    {
        if (empty($hotelIds)) {
            return [];
        }

        // Generate product codes
        $productCodes = array_map(function ($id) {
            return ConfigService::PRODUCT_CODE_PREFIX . $id;
        }, $hotelIds);

        // Single query to get all existing products
        $results = \db_get_hash_array(
            "SELECT product_code, product_id
             FROM ?:products
             WHERE product_code IN (?a)",
            'product_code',
            $productCodes
        );

        // Map back to hotel IDs
        $map = [];
        foreach ($hotelIds as $hotelId) {
            $code = ConfigService::PRODUCT_CODE_PREFIX . $hotelId;
            if (isset($results[$code])) {
                $map[$hotelId] = $results[$code]['product_id'];
            }
        }

        return $map;
    }

    /**
     * Bulk lookup: Check which hotel IDs already exist in database
     *
     * @param array $hotelIds Array of hotel IDs to check
     * @return array Array of existing hotel IDs
     */
    public static function getExistingHotelIds(array $hotelIds): array
    {
        if (empty($hotelIds)) {
            return [];
        }

        return \db_get_fields(
            "SELECT hotel_id FROM ?:novoton_hotels WHERE hotel_id IN (?a)",
            $hotelIds
        );
    }

    /**
     * Bulk upsert hotels (INSERT ... ON DUPLICATE KEY UPDATE)
     *
     * @param array $hotels Array of hotel data arrays
     * @return array ['inserted' => int, 'updated' => int]
     */
    public static function upsertHotels(array $hotels): array
    {
        if (empty($hotels)) {
            return ['inserted' => 0, 'updated' => 0];
        }

        $now = date('Y-m-d H:i:s');
        $inserted = 0;
        $updated = 0;

        foreach ($hotels as $hotel) {
            if (empty($hotel['hotel_id'])) {
                continue;
            }

            $hotel['updated_at'] = $now;

            $affected = (int) \db_query(
                "INSERT INTO ?:novoton_hotels ?e
                 ON DUPLICATE KEY UPDATE ?u",
                array_merge($hotel, ['created_at' => $now]),
                $hotel
            );

            if ($affected === 1) {
                $inserted++;
            } elseif ($affected >= 2) {
                $updated++;
            }
        }

        return ['inserted' => $inserted, 'updated' => $updated];
    }

    /**
     * Upsert hotel packages (batch)
     *
     * @param string $hotelId Hotel ID
     * @param array $packages Array of package data
     * @return int Number of packages upserted
     */
    public static function upsertHotelPackages(string $hotelId, array $packages): int
    {
        if (empty($packages)) {
            return 0;
        }

        $count = 0;

        foreach ($packages as $pkg) {
            $packageId = $pkg['IdCont'] ?? $pkg['package_id'] ?? '';
            $packageName = $pkg['PackageName'] ?? $pkg['package_name'] ?? '';

            if (empty($packageId)) {
                continue;
            }

            \db_query(
                "INSERT INTO ?:novoton_hotel_packages
                 (hotel_id, package_id, package_name, created_at)
                 VALUES (?s, ?s, ?s, NOW())
                 ON DUPLICATE KEY UPDATE package_name = ?s, updated_at = NOW()",
                $hotelId,
                $packageId,
                $packageName,
                $packageName
            );

            $count++;
        }

        return $count;
    }

    /**
     * Link products to hotels in batch
     *
     * @param array $links Array of ['hotel_id' => x, 'product_id' => y]
     * @return int Number of links created
     */
    public static function linkProductsToHotels(array $links): int
    {
        if (empty($links)) {
            return 0;
        }

        $count = 0;

        foreach ($links as $link) {
            if (empty($link['hotel_id']) || empty($link['product_id'])) {
                continue;
            }

            // Reject invalid product_ids — must be a positive integer
            $pid = intval($link['product_id']);
            if ($pid <= 0) {
                continue;
            }

            \db_query(
                "UPDATE ?:novoton_hotels SET product_id = ?i WHERE hotel_id = ?s",
                $pid,
                $link['hotel_id']
            );

            $count++;
        }

        return $count;
    }

    /**
     * Get hotels for sync with optimized field selection
     *
     * @param array $conditions Array of conditions ['key' => value]
     * @param int $limit Limit
     * @param array $fields Fields to select (empty = minimal set for sync)
     * @return array
     */
    public static function getHotelsForSync(array $conditions = [], int $limit = 0, array $fields = []): array
    {
        if (empty($fields)) {
            // Minimal fields for sync operations (excludes large JSON columns)
            $fields = ['hotel_id', 'hotel_name', 'country', 'city', 'has_prices', 'product_id'];
        }

        $fieldList = implode(', ', $fields);
        $where = [];
        $params = [];

        foreach ($conditions as $key => $value) {
            if (is_array($value)) {
                $where[] = "{$key} IN (?a)";
                $params[] = $value;
            } elseif ($value === null) {
                $where[] = "{$key} IS NULL";
            } else {
                $where[] = "{$key} = ?s";
                $params[] = $value;
            }
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $limitClause = $limit > 0 ? "LIMIT ?i" : "";

        if ($limit > 0) {
            $params[] = $limit;
        }

        $query = "SELECT {$fieldList} FROM ?:novoton_hotels {$whereClause} ORDER BY hotel_name {$limitClause}";

        return \db_get_array($query, ...$params);
    }

    /**
     * Get last sync date for a specific sync type
     *
     * @param string $syncType
     * @param string|null $subType Optional sub-type from notes JSON
     * @return string|null
     */
    public static function getLastSyncDate(string $syncType, ?string $subType = null): ?string
    {
        $query = "SELECT MAX(sync_date) FROM ?:novoton_sync_log
                  WHERE sync_type = ?s AND status = 'completed'";
        $params = [$syncType];

        if ($subType !== null) {
            $query .= " AND notes LIKE ?s";
            $params[] = '%"sync_type":"' . $subType . '"%';
        }

        return \db_get_field($query, ...$params) ?: null;
    }

    /**
     * Get sync statistics from sync_log
     *
     * @param string $syncType
     * @param int $days Number of days to look back
     * @return array
     */
    public static function getSyncStats(string $syncType, int $days = 30): array
    {
        $stats = \db_get_row(
            "SELECT
                COUNT(*) as total_runs,
                SUM(products_updated) as total_updated,
                SUM(products_failed) as total_failed,
                AVG(duration_seconds) as avg_duration,
                MAX(sync_date) as last_sync
             FROM ?:novoton_sync_log
             WHERE sync_type = ?s
             AND sync_date > DATE_SUB(NOW(), INTERVAL ?i DAY)",
            $syncType,
            $days
        );

        return [
            'total_runs' => intval($stats['total_runs'] ?? 0),
            'total_updated' => intval($stats['total_updated'] ?? 0),
            'total_failed' => intval($stats['total_failed'] ?? 0),
            'avg_duration' => round(floatval($stats['avg_duration'] ?? 0), 1),
            'last_sync' => $stats['last_sync'] ?? null,
        ];
    }

    /**
     * Cleanup old sync logs
     *
     * @param int $days Keep logs newer than this many days
     * @return int Number of deleted records
     */
    public static function cleanupOldLogs(int $days = 90): int
    {
        return (int) \db_query(
            "DELETE FROM ?:novoton_sync_log
             WHERE sync_date < DATE_SUB(NOW(), INTERVAL ?i DAY)",
            $days
        );
    }

    /**
     * Get product code for hotel ID
     *
     * @param string $hotelId
     * @return string
     */
    public static function getProductCode(string $hotelId): string
    {
        return ConfigService::PRODUCT_CODE_PREFIX . $hotelId;
    }

    /**
     * Extract hotel ID from product code
     *
     * @param string $productCode
     * @return string|null
     */
    public static function extractHotelId(string $productCode): ?string
    {
        $prefix = ConfigService::PRODUCT_CODE_PREFIX;
        if (strpos($productCode, $prefix) === 0) {
            return substr($productCode, strlen($prefix));
        }

        // Try extracting any number
        preg_match('/\d+/', $productCode, $matches);
        return $matches[0] ?? null;
    }

    /**
     * Clear lookup caches
     */
    public static function clearCache(): void
    {
        $self = self::getInstance();
        $self->productCodeCache = [];
        $self->hotelCache = [];
    }
}

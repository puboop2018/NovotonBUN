<?php
declare(strict_types=1);
/**
 * Database Helper Interface
 *
 * Contract for batch database operations and lookup caching.
 *
 * @package NovotonHolidays
 * @since 3.5.0
 */

namespace Tygh\Addons\NovotonHolidays\Helpers;

interface DatabaseHelperInterface
{
    /**
     * Batch update hotels has_room_price flag after room_price API checks.
     *
     * @param array<string, mixed> $withPrices    Hotel IDs that have prices
     * @param array<string, mixed> $withoutPrices Hotel IDs that don't have prices
     * @return int Number of rows affected
     */
    public function batchUpdateHasRoomPriceFlag(array $withPrices, array $withoutPrices): int;

    /**
     * Bulk lookup: Get existing product IDs for multiple hotel IDs.
     *
     * @param array<string, mixed> $hotelIds
     * @return array<string, int> Map of hotel_id => product_id
     */
    public function getProductIdsByHotelIds(array $hotelIds): array;

    /**
     * Bulk lookup: Check which hotel IDs already exist in database.
     *
     * @param array<string, mixed> $hotelIds
     * @return string[] Existing hotel IDs
     */
    public function getExistingHotelIds(array $hotelIds): array;

    /**
     * Bulk upsert hotels (INSERT ... ON DUPLICATE KEY UPDATE).
     *
     * @param array<string, mixed> $hotels
     * @return array{inserted: int, updated: int}
     */
    public function upsertHotels(array $hotels): array;

    /**
     * Upsert hotel packages (batch).
     *
     * @param string $hotelId
     * @param array  $packages
     * @return int Number of packages upserted
     */
    public function upsertHotelPackages(string $hotelId, array $packages): int;

    /**
     * Link products to hotels in batch.
     *
     * @param array<string, mixed> $links Array of ['hotel_id' => x, 'product_id' => y]
     * @return int Number of links created
     */
    public function linkProductsToHotels(array $links): int;

    /**
     * Get hotels for sync with optimized field selection.
     *
     * @param array<string, mixed> $conditions
     * @param int   $limit
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function getHotelsForSync(array $conditions = [], int $limit = 0, array $fields = []): array;

    /**
     * Get last sync date for a specific sync type.
     *
     * @param string      $syncType
     * @param string|null $subType
     * @return string|null
     */
    public function getLastSyncDate(string $syncType, ?string $subType = null): ?string;

    /**
     * Get sync statistics from sync_log.
     *
     * @param string $syncType
     * @param int    $days
     * @return array<string, mixed>
     */
    public function getSyncStats(string $syncType, int $days = 30): array;

    /**
     * Cleanup old sync logs.
     *
     * @param int $days
     * @return int Number of deleted records
     */
    public function cleanupOldLogs(int $days = 90): int;

    /**
     * Get product code for hotel ID.
     *
     * @param string $hotelId
     * @return string
     */
    public function getProductCode(string $hotelId): string;

    /**
     * Extract hotel ID from product code.
     *
     * @param string $productCode
     * @return string|null
     */
    public function extractHotelId(string $productCode): ?string;
}

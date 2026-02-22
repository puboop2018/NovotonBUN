<?php
declare(strict_types=1);
/**
 * Database Iterator Interface
 *
 * Contract for iterating over large database result sets using generators.
 *
 * @package NovotonHolidays
 * @since 3.5.0
 */

namespace Tygh\Addons\NovotonHolidays\Helpers;

interface DatabaseIteratorInterface
{
    /**
     * Iterate over hotels with optional filters.
     *
     * @param array $filters    Filter conditions (country, has_prices, etc.)
     * @param int   $chunk_size
     * @return \Generator Yields one hotel row at a time
     */
    public function iterateHotels(array $filters = [], int $chunk_size = 500): \Generator;

    /**
     * Iterate over hotel IDs only (more memory efficient).
     *
     * @param array $filters
     * @param int   $chunk_size
     * @return \Generator Yields one hotel_id at a time
     */
    public function iterateHotelIds(array $filters = [], int $chunk_size = 500): \Generator;

    /**
     * Iterate over hotel packages.
     *
     * @param array $filters
     * @param int   $chunk_size
     * @return \Generator Yields one package row at a time
     */
    public function iteratePackages(array $filters = [], int $chunk_size = 500): \Generator;

    /**
     * Iterate over bookings.
     *
     * @param array $filters Filter conditions (status, novoton_status, etc.)
     * @param int   $chunk_size
     * @return \Generator Yields one booking row at a time
     */
    public function iterateBookings(array $filters = [], int $chunk_size = 500): \Generator;

    /**
     * Iterate over sync logs.
     *
     * @param string $type       Optional sync type filter
     * @param int    $chunk_size
     * @return \Generator Yields one log row at a time
     */
    public function iterateSyncLogs(string $type = '', int $chunk_size = 500): \Generator;

    /**
     * Generic query iterator — iterate over any query results.
     *
     * @param string $query  SQL query with LIMIT ?i OFFSET ?i placeholders
     * @param array  $params
     * @param int    $chunk_size
     * @return \Generator Yields one row at a time
     */
    public function iterateQuery(string $query, array $params = [], int $chunk_size = 500): \Generator;

    /**
     * Count total items matching filters.
     *
     * @param string $table   Table name (without prefix)
     * @param array  $filters
     * @return int
     */
    public function countItems(string $table, array $filters = []): int;
}

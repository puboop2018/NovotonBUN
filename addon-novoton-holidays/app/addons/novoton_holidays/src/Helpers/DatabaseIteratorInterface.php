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
     * @param array<string, mixed> $filters Filter conditions (country, has_room_price, etc.)
     * @return \Generator Yields one hotel row at a time
     */
    public function iterateHotels(array $filters = [], int $chunk_size = 100): \Generator;

    /**
     * Iterate over hotel IDs only (more memory efficient).
     *
     * @param array<string, mixed> $filters
     * @return \Generator Yields one hotel_id at a time
     */
    public function iterateHotelIds(array $filters = [], int $chunk_size = 100): \Generator;

    /**
     * Iterate over hotel packages.
     *
     * @param array<string, mixed> $filters
     * @return \Generator Yields one package row at a time
     */
    public function iteratePackages(array $filters = [], int $chunk_size = 100): \Generator;

    /**
     * Iterate over bookings.
     *
     * @param array<string, mixed> $filters Filter conditions (status, novoton_status, etc.)
     * @return \Generator Yields one booking row at a time
     */
    public function iterateBookings(array $filters = [], int $chunk_size = 100): \Generator;

    /**
     * Iterate over sync logs.
     *
     * @param string $type Optional sync type filter
     * @return \Generator Yields one log row at a time
     */
    public function iterateSyncLogs(string $type = '', int $chunk_size = 100): \Generator;

    /**
     * Generic query iterator — iterate over any query results.
     *
     * @param string $query SQL query with LIMIT ?i OFFSET ?i placeholders
     * @param array<string, mixed> $params
     * @return \Generator Yields one row at a time
     */
    public function iterateQuery(string $query, array $params = [], int $chunk_size = 100): \Generator;

    /**
     * Count total items matching filters.
     *
     * @param string $table Table name (without prefix)
     * @param array<string, mixed> $filters
     */
    public function countItems(string $table, array $filters = []): int;
}

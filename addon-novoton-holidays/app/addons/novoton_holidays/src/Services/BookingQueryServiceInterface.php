<?php
declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

/**
 * Interface for Novoton booking query + display enrichment operations.
 *
 * @since 3.5.0
 */
interface BookingQueryServiceInterface
{
    /**
     * Get booking statistics.
     *
     * @return array{total: int, pending: int, confirmed: int, cancelled: int, with_orders: int, orphans: int}
     */
    public function getStats(): array;

    /**
     * Get unified booking list with joined hotel + order metadata.
     *
     * @param array<string, mixed> $params Filter parameters (show_orphans, order_id, hotel_id, status, etc.)
     * @return list<array<string, mixed>> Unified bookings list with display enrichment
     */
    public function getUnifiedBookings(array $params = []): array;
}
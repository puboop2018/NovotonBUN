<?php
declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Repository;

use Tygh\Addons\TravelCore\Contracts\TravelBookingRepositoryInterface;

/**
 * Database-backed repository for the travel_bookings table.
 *
 * All raw db_*() calls for travel bookings live here,
 * extracted from the travel_bookings admin controller.
 */
class TravelBookingRepository implements TravelBookingRepositoryInterface
{
    #[\Override]
    public function getProviderInfo(int $bookingId): ?array
    {
        $row = db_get_row(
            "SELECT provider, provider_booking_id FROM ?:travel_bookings WHERE booking_id = ?i",
            $bookingId
        );

        return $row ?: null;
    }

    #[\Override]
    public function getById(int $bookingId): ?array
    {
        $row = db_get_row(
            "SELECT * FROM ?:travel_bookings WHERE booking_id = ?i",
            $bookingId
        );

        return $row ?: null;
    }

    /** @return array{items: array<int, array<string, mixed>>, total: int} */
    #[\Override]
    public function getPaginated(string $condition, string $sortColumn, string $sortOrder, int $offset, int $limit): array
    {
        $total = (int) db_get_field(
            "SELECT COUNT(*) FROM ?:travel_bookings tb WHERE 1 ?p",
            $condition
        );

        $items = db_get_array(
            "SELECT tb.* FROM ?:travel_bookings tb
             WHERE 1 ?p
             ORDER BY {$sortColumn} {$sortOrder}
             LIMIT ?i, ?i",
            $condition, $offset, $limit
        );

        return ['items' => $items, 'total' => $total];
    }
}

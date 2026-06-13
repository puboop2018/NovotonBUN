<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;
use Tygh\Addons\TravelCore\TravelConstants;

/**
 * Ownership-scoped read access to novoton_bookings.
 *
 * The security-sensitive booking lookups, lifted out of BookingRepository so
 * the ownership boundary is a small, auditable surface. Every query is scoped
 * to the requesting user_id and/or session_id; with no ownership context the
 * methods return nothing rather than leak another customer's bookings.
 *
 * Behaviour (SQL and parameters) is preserved verbatim from BookingRepository.
 */
class BookingOwnershipRepository implements BookingOwnershipRepositoryInterface
{
    use RowNarrowingTrait;

    /**
     * Find bookings by multiple product IDs (batch query for cart).
     *
     * @param list<int> $product_ids Product IDs
     * @param list<string> $statuses Optional status filter (default: pending + confirmed)
     * @return list<array<string, mixed>> Booking rows
     */
    public function findByProductIds(array $product_ids, array $statuses = [TravelConstants::STATUS_PENDING, TravelConstants::STATUS_CONFIRMED], string $session_id = '', int $user_id = 0): array
    {
        if (empty($product_ids)) {
            return [];
        }

        // Safety: if no ownership context is provided, return nothing rather than
        // leaking all users' bookings. Callers must provide session_id and/or user_id.
        if ($user_id <= 0 && empty($session_id)) {
            return [];
        }

        $select = 'SELECT booking_id, product_id, hotel_id, hotel_name, room_id, room_type,
                    board_id, check_in, check_out, nights, adults, children, children_ages,
                    num_rooms, rooms_data, total_price, currency, status, guests_data,
                    package_name, session_id, holder_name, guest_name
             FROM ?:novoton_bookings
             WHERE product_id IN (?n) AND status IN (?a)';

        // Scope to current user/session to prevent cross-user booking leakage
        if ($user_id > 0 && !empty($session_id)) {
            return self::asRowList(db_get_array(
                $select . ' AND (session_id = ?s OR user_id = ?i) ORDER BY booking_id DESC',
                $product_ids,
                $statuses,
                $session_id,
                $user_id,
            ));
        } elseif ($user_id > 0) {
            return self::asRowList(db_get_array(
                $select . ' AND user_id = ?i ORDER BY booking_id DESC',
                $product_ids,
                $statuses,
                $user_id,
            ));
        }

        return self::asRowList(db_get_array(
            $select . ' AND session_id = ?s ORDER BY booking_id DESC',
            $product_ids,
            $statuses,
            $session_id,
        ));
    }

    /**
     * Find booking by ownership (user_id or session_id) — for frontend security checks.
     * @return array<string, mixed>|null
     */
    public function findByIdWithOwnership(int $booking_id, int $user_id, string $session_id): ?array
    {
        $row = self::asRow(db_get_row(
            'SELECT * FROM ?:novoton_bookings WHERE booking_id = ?i AND (user_id = ?i OR session_id = ?s)',
            $booking_id,
            $user_id,
            $session_id,
        ));
        return $row === [] ? null : $row;
    }

    /**
     * Check booking ownership (returns booking_id or null).
     */
    public function checkOwnership(int $booking_id, int $user_id, string $session_id): ?int
    {
        $id = TypeCoerce::toInt(db_get_field(
            'SELECT booking_id FROM ?:novoton_bookings WHERE booking_id = ?i AND (user_id = ?i OR session_id = ?s)',
            $booking_id,
            $user_id,
            $session_id,
        ));
        return $id > 0 ? $id : null;
    }
}

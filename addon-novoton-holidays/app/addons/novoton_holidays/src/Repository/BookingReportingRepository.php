<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;

/**
 * Novoton Holidays - Booking Reporting Repository.
 *
 * Admin/aggregate booking queries — filtered counts, admin listing/detail
 * views, and CSV export — used by the backend dashboard, diagnostics, and
 * cleanup tooling.
 *
 * Extracted from BookingRepository so the read-only reporting surface is a
 * small collaborator, mirroring HotelReportingRepository. SQL is preserved
 * verbatim; the only change is coercing db_quote/db_get_field results through
 * TypeCoerce (equivalent for the data that flows here) so the new file stays
 * level-10 clean rather than carrying baseline entries.
 */
class BookingReportingRepository implements BookingReportingRepositoryInterface
{
    use RowNarrowingTrait;

    /**
     * Count bookings matching the given filters.
     *
     * @param array<string, mixed> $filters
     */
    public function count(array $filters = []): int
    {
        $where = $this->buildWhereClause($filters);
        return TypeCoerce::toInt(db_get_field("SELECT COUNT(*) FROM ?:novoton_bookings {$where}"));
    }

    /**
     * Count orphan bookings (no order, older than N hours).
     */
    public function countOrphans(int $hours = 48): int
    {
        return TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:novoton_bookings
             WHERE order_id = 0 AND created_at < DATE_SUB(NOW(), INTERVAL ?i HOUR)',
            $hours,
        ));
    }

    /**
     * Find bookings for admin listing with order info joined.
     *
     * @param string $condition Extra WHERE conditions (must start with " AND ...")
     * @return list<array<string, mixed>>
     */
    public function findForAdminList(string $condition = '', int $limit = 500): array
    {
        return self::asRowList(db_get_array(
            "SELECT b.booking_id, b.order_id, b.hotel_id, b.hotel_name, b.room_type,
                    b.check_in, b.check_out, b.nights, b.adults, b.children,
                    b.total_price, b.currency, b.status, b.novoton_status, b.created_at,
                    o.status as order_status, o.email
             FROM ?:novoton_bookings b
             LEFT JOIN ?:orders o ON b.order_id = o.order_id
             WHERE 1=1 {$condition}
             ORDER BY b.created_at DESC
             LIMIT ?i",
            $limit,
        ));
    }

    /**
     * Find a booking with full order and product info for admin detail view.
     * @return array<string, mixed>|null
     */
    public function findWithOrderDetails(int $booking_id): ?array
    {
        $row = self::asRow(db_get_row(
            'SELECT b.*, o.*, p.product
             FROM ?:novoton_bookings b
             LEFT JOIN ?:orders o ON b.order_id = o.order_id
             LEFT JOIN ?:products p ON b.product_id = p.product_id
             WHERE b.booking_id = ?i',
            $booking_id,
        ));
        return $row === [] ? null : $row;
    }

    /**
     * Find all bookings with order info for CSV export.
     * @return list<array<string, mixed>>
     */
    public function findAllForExport(): array
    {
        return self::asRowList(db_get_array(
            'SELECT b.*, o.email, o.status as order_status
             FROM ?:novoton_bookings b
             LEFT JOIN ?:orders o ON b.order_id = o.order_id
             ORDER BY b.created_at DESC',
        ));
    }

    /**
     * Build a WHERE clause from the supported filter keys.
     *
     * @param array<string, mixed> $filters
     */
    private function buildWhereClause(array $filters): string
    {
        $conditions = [];

        if (!empty($filters['status'])) {
            $conditions[] = TypeCoerce::toString(db_quote('status = ?s', $filters['status']));
        }
        if (!empty($filters['hotel_id'])) {
            $conditions[] = TypeCoerce::toString(db_quote('hotel_id = ?s', $filters['hotel_id']));
        }
        if (!empty($filters['order_id'])) {
            $conditions[] = TypeCoerce::toString(db_quote('order_id = ?i', $filters['order_id']));
        }
        if (!empty($filters['user_id'])) {
            $conditions[] = TypeCoerce::toString(db_quote('user_id = ?i', $filters['user_id']));
        }
        if (!empty($filters['has_order'])) {
            $conditions[] = 'order_id > 0';
        }
        if (!empty($filters['no_order'])) {
            $conditions[] = 'order_id = 0';
        }
        if (!empty($filters['check_in_from'])) {
            $conditions[] = TypeCoerce::toString(db_quote('check_in >= ?s', $filters['check_in_from']));
        }
        if (!empty($filters['check_in_to'])) {
            $conditions[] = TypeCoerce::toString(db_quote('check_in <= ?s', $filters['check_in_to']));
        }

        return !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
    }
}

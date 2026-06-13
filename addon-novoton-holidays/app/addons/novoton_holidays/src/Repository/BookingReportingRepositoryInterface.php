<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Repository;

/**
 * Novoton Holidays - Booking Reporting Repository contract.
 *
 * Admin/aggregate booking queries — filtered counts, admin listing/detail
 * views, and CSV export — used by the backend dashboard, diagnostics, and
 * cleanup tooling. Kept apart from the operational BookingRepository so the
 * reporting surface is a small, read-only collaborator (mirrors
 * HotelReportingRepository).
 */
interface BookingReportingRepositoryInterface
{
    /**
     * Count bookings matching the given filters.
     *
     * Supported filter keys: status, hotel_id, order_id, user_id, has_order,
     * no_order, check_in_from, check_in_to.
     *
     * @param array<string, mixed> $filters
     */
    public function count(array $filters = []): int;

    /**
     * Count orphan bookings (no order, older than N hours).
     */
    public function countOrphans(int $hours = 48): int;

    /**
     * Find bookings for admin listing with order info joined.
     *
     * @param string $condition Extra WHERE conditions (must start with " AND ...")
     * @return list<array<string, mixed>>
     */
    public function findForAdminList(string $condition = '', int $limit = 500): array;

    /**
     * Find a booking with full order and product info for admin detail view.
     *
     * @return array<string, mixed>|null
     */
    public function findWithOrderDetails(int $booking_id): ?array;

    /**
     * Find all bookings with order info for CSV export.
     *
     * @return list<array<string, mixed>>
     */
    public function findAllForExport(): array;
}

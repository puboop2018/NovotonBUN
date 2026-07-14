<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Repository;

use Tygh\Addons\TravelCore\Repository\TravelBookingMirror;

/**
 * Synchronises novoton bookings into the shared travel_bookings table.
 *
 * All access to travel_bookings for novoton bookings lives here, lifted out of
 * BookingRepository so the cross-provider coupling is a single, explicit,
 * testable surface. The per-booking upsert/partial-update/delete now delegate
 * to travel_core's TravelBookingMirror (the once-duplicated field map and SQL
 * live there); the novoton-specific JOIN/bulk operations stay local.
 */
class BookingSyncRepository implements BookingSyncRepositoryInterface
{
    private readonly TravelBookingMirror $mirror;

    public function __construct()
    {
        $this->mirror = new TravelBookingMirror('novoton');
    }

    /**
     * Mirror a created (or replaced) booking into travel_bookings.
     *
     * @param array<string, mixed> $data novoton_bookings column data
     */
    public function upsertFromBooking(int $booking_id, array $data): void
    {
        $this->mirror->upsert($booking_id, $data);
    }

    /**
     * Mirror a partial booking update into travel_bookings. Skipped entirely
     * when no mirrored field changed.
     *
     * @param array<string, mixed> $data Changed novoton_bookings columns
     */
    public function applyBookingUpdate(int $booking_id, array $data): void
    {
        $this->mirror->applyUpdate($booking_id, $data);
    }

    /**
     * Remove the travel_bookings mirror for a single booking.
     */
    public function deleteByBookingId(int $booking_id): void
    {
        $this->mirror->deleteByProviderBookingId($booking_id);
    }

    /**
     * Remove travel_bookings mirrors for orphan novoton bookings (no order,
     * older than the given age in hours).
     */
    public function deleteOrphansOlderThan(int $hours): void
    {
        db_query(
            "DELETE tb FROM ?:travel_bookings tb
             INNER JOIN ?:novoton_bookings nb ON tb.provider_booking_id = CAST(nb.booking_id AS CHAR)
             WHERE tb.provider = 'novoton' AND nb.order_id = 0
               AND nb.created_at < DATE_SUB(NOW(), INTERVAL ?i HOUR)",
            $hours,
        );
    }

    /**
     * Remove travel_bookings mirrors for the given novoton booking IDs.
     *
     * @param list<string> $booking_ids provider_booking_id values
     */
    public function deleteByBookingIds(array $booking_ids): void
    {
        if ($booking_ids === []) {
            return;
        }

        db_query(
            "DELETE FROM ?:travel_bookings WHERE provider = 'novoton' AND provider_booking_id IN (?a)",
            $booking_ids,
        );
    }

    /**
     * Re-assign travel_bookings mirrors to a user (after login claims bookings).
     *
     * @param list<string> $booking_ids provider_booking_id values
     */
    public function assignUser(int $user_id, array $booking_ids): void
    {
        if ($booking_ids === []) {
            return;
        }

        db_query(
            "UPDATE ?:travel_bookings SET user_id = ?i WHERE provider = 'novoton' AND provider_booking_id IN (?a)",
            $user_id,
            $booking_ids,
        );
    }
}

<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Repository;

/**
 * Synchronises novoton bookings into the shared, provider-agnostic
 * travel_bookings table.
 *
 * Every write novoton makes to a booking is mirrored into travel_bookings so
 * cross-provider tooling sees a unified view. That coupling used to be smeared
 * across BookingRepository's write methods (create, update, delete,
 * deleteOrphans, deleteByProductId, linkToUser*); this interface gathers it
 * behind a single collaborator so the cross-table boundary is explicit and
 * independently testable. BookingRepository owns the novoton_bookings writes
 * and the surrounding transaction; it delegates the travel_bookings half here.
 */
interface BookingSyncRepositoryInterface
{
    /**
     * Mirror a created (or replaced) booking into travel_bookings.
     *
     * Idempotent upsert keyed on (provider, provider_booking_id).
     *
     * @param array<string, mixed> $data novoton_bookings column data
     */
    public function upsertFromBooking(int $booking_id, array $data): void;

    /**
     * Mirror a partial booking update into travel_bookings.
     *
     * No-op when none of the changed fields are mirrored columns.
     *
     * @param array<string, mixed> $data Changed novoton_bookings columns
     */
    public function applyBookingUpdate(int $booking_id, array $data): void;

    /**
     * Remove the travel_bookings mirror for a single booking.
     */
    public function deleteByBookingId(int $booking_id): void;

    /**
     * Remove travel_bookings mirrors for orphan novoton bookings (no order,
     * older than the given age in hours).
     */
    public function deleteOrphansOlderThan(int $hours): void;

    /**
     * Remove travel_bookings mirrors for the given novoton booking IDs.
     *
     * @param list<string> $booking_ids provider_booking_id values
     */
    public function deleteByBookingIds(array $booking_ids): void;

    /**
     * Re-assign travel_bookings mirrors to a user (after login claims bookings).
     *
     * @param list<string> $booking_ids provider_booking_id values
     */
    public function assignUser(int $user_id, array $booking_ids): void;
}

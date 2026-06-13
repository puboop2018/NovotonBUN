<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Support;

use Tygh\Addons\NovotonHolidays\Repository\BookingSyncRepositoryInterface;

/**
 * Recording test double for BookingSyncRepositoryInterface.
 *
 * Captures every delegated call so BookingRepository's orchestration — the
 * surrounding transaction and the delegation to the travel_bookings sync
 * collaborator — can be asserted without a database.
 */
final class RecordingBookingSyncRepository implements BookingSyncRepositoryInterface
{
    /** @var list<array<int, mixed>> Each entry: [method, ...args] */
    public array $calls = [];

    /** When true, upsertFromBooking throws — to exercise transaction rollback. */
    public bool $throwOnUpsert = false;

    public function upsertFromBooking(int $booking_id, array $data): void
    {
        $this->calls[] = ['upsertFromBooking', $booking_id, $data];
        if ($this->throwOnUpsert) {
            throw new \RuntimeException('sync failed');
        }
    }

    public function applyBookingUpdate(int $booking_id, array $data): void
    {
        $this->calls[] = ['applyBookingUpdate', $booking_id, $data];
    }

    public function deleteByBookingId(int $booking_id): void
    {
        $this->calls[] = ['deleteByBookingId', $booking_id];
    }

    public function deleteOrphansOlderThan(int $hours): void
    {
        $this->calls[] = ['deleteOrphansOlderThan', $hours];
    }

    public function deleteByBookingIds(array $booking_ids): void
    {
        $this->calls[] = ['deleteByBookingIds', $booking_ids];
    }

    public function assignUser(int $user_id, array $booking_ids): void
    {
        $this->calls[] = ['assignUser', $user_id, $booking_ids];
    }
}

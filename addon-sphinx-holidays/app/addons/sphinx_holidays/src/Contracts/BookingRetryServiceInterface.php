<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Contracts;

/**
 * Contract for the Sphinx booking retry service.
 *
 * Re-verifies a failed booking's offer and re-attempts the booking call
 * against the Sphinx API.
 */
interface BookingRetryServiceInterface
{
    /**
     * Retry a failed booking.
     *
     * @param int $bookingId The sphinx_bookings.booking_id
     * @return array{success: bool, message: string, booking_ref: string|null}
     */
    public function retry(int $bookingId): array;
}

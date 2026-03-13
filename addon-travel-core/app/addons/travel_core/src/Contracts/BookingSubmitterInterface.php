<?php
declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Contracts;

/**
 * Booking submission contract.
 *
 * Each travel provider implements this to submit bookings
 * to its respective API.
 */
interface BookingSubmitterInterface
{
    /**
     * Submit a booking to the provider API.
     *
     * @param array $bookingData Standardized booking data
     * @return array {success: bool, provider_booking_id: string|null, status: string, error: string|null}
     */
    public function submitBooking(array $bookingData): array;

    /**
     * Check the status of an existing booking.
     *
     * @param string $providerBookingId Provider-specific booking ID
     * @return array {status: string, raw_status: string, details: array}
     */
    public function checkBookingStatus(string $providerBookingId): array;

    /**
     * Cancel a booking.
     *
     * @param string $providerBookingId Provider-specific booking ID
     * @return array {success: bool, cancellation_fee: float|null, error: string|null}
     */
    public function cancelBooking(string $providerBookingId): array;
}

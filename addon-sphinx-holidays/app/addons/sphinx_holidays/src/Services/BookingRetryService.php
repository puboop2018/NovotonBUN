<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Addons\SphinxHolidays\Repository\SphinxBookingRepository;
use Tygh\Addons\TravelCore\TravelConstants;

/**
 * Retries a failed Sphinx hotel booking.
 *
 * Re-verifies the offer with the API, then re-attempts the booking.
 * Status flow: failed → pending → confirmed/failed.
 *
 * @package SphinxHolidays
 * @since   1.2.0
 */
class BookingRetryService
{
    private SphinxApi $api;
    private SphinxBookingRepository $repo;

    public function __construct(SphinxApi $api, SphinxBookingRepository $repo)
    {
        $this->api = $api;
        $this->repo = $repo;
    }

    /**
     * Retry a failed booking.
     *
     * @param int $bookingId The sphinx_bookings.booking_id
     * @return array{success: bool, message: string, booking_ref: string|null}
     */
    public function retry(int $bookingId): array
    {
        $booking = $this->repo->findById($bookingId);
        if ($booking === null) {
            return ['success' => false, 'message' => 'Booking not found', 'booking_ref' => null];
        }

        if (($booking['status'] ?? '') !== TravelConstants::STATUS_FAILED) {
            return ['success' => false, 'message' => 'Only failed bookings can be retried', 'booking_ref' => null];
        }

        $offerId = $booking['offer_id'] ?? '';
        if (empty($offerId)) {
            return ['success' => false, 'message' => 'No offer ID stored — cannot retry', 'booking_ref' => null];
        }

        $bookingType = $booking['room_type'] ?? 'hotel';
        // Normalize: room_type stores 'circuit' or 'experience' for non-hotel bookings
        if (!in_array($bookingType, ['circuit', 'experience'], true)) {
            $bookingType = 'hotel';
        }

        // Step 1: Re-verify the offer (only hotels have a verify endpoint)
        if ($bookingType === 'hotel') {
            try {
                $verifyResult = $this->api->verifyHotelOffer($offerId);
            } catch (\Throwable $e) {
                return ['success' => false, 'message' => 'Verification failed: ' . $e->getMessage(), 'booking_ref' => null];
            }

            if (empty($verifyResult)) {
                return ['success' => false, 'message' => 'Offer no longer available', 'booking_ref' => null];
            }
        }

        // Mark as pending before retry
        $this->repo->update($bookingId, ['status' => TravelConstants::STATUS_PENDING]);

        // Step 2: Re-attempt booking using type-aware dispatch
        try {
            $guestsData = [];
            if (!empty($booking['guests_data'])) {
                $guestsData = is_string($booking['guests_data'])
                    ? json_decode($booking['guests_data'], true)
                    : $booking['guests_data'];
            }

            $price = (float)($booking['total_price'] ?? 0);
            $currency = $booking['currency'] ?? 'EUR';

            $bookResult = $this->submitBooking($bookingType, $offerId, $price, $currency, $booking, $guestsData);

            if (!empty($bookResult['booking_reference'])) {
                $this->repo->updateApiResponse(
                    $bookingId,
                    $bookResult['booking_reference'],
                    json_encode($bookResult)
                );
            }

            $this->repo->update($bookingId, [
                'status' => TravelConstants::STATUS_CONFIRMED,
            ]);

            fn_log_event('general', 'runtime', [
                'message' => "Sphinx booking #{$bookingId} retry succeeded",
                'booking_ref' => $bookResult['booking_reference'] ?? '',
            ]);

            return [
                'success' => true,
                'message' => 'Booking retry successful',
                'booking_ref' => $bookResult['booking_reference'] ?? null,
            ];

        } catch (\Throwable $e) {
            $this->repo->update($bookingId, [
                'status' => TravelConstants::STATUS_FAILED,
            ]);

            fn_log_event('general', 'runtime', [
                'message' => "Sphinx booking #{$bookingId} retry failed: " . $e->getMessage(),
            ]);

            return ['success' => false, 'message' => 'Retry failed: ' . $e->getMessage(), 'booking_ref' => null];
        }
    }

    /**
     * Dispatch booking to the correct API method based on type.
     */
    private function submitBooking(string $type, string $offerId, float $price, string $currency, array $booking, array $guestsData): array
    {
        switch ($type) {
            case 'circuit':
                $occupancy = \fn_sphinx_holidays_build_room_occupancy($guestsData, $booking);
                $payload = [
                    'offer_id' => $offerId,
                    'price' => $price,
                    'currency' => $currency,
                    'occupancy' => $occupancy,
                ];
                if (!empty($booking['order_id'])) {
                    $payload['reference_code'] = (string)$booking['order_id'];
                }
                return $this->api->bookCircuit($payload) ?: [];

            case 'experience':
                $occupancy = \fn_sphinx_holidays_build_flat_occupancy($guestsData);
                $payload = [
                    'offer_id' => $offerId,
                    'price' => $price,
                    'currency' => $currency,
                    'occupancy' => $occupancy,
                ];
                if (!empty($booking['order_id'])) {
                    $payload['reference_code'] = (string)$booking['order_id'];
                }
                return $this->api->bookExperience($payload) ?: [];

            default: // hotel
                return $this->api->bookHotel([
                    'offer_id' => $offerId,
                    'guests' => $guestsData ?: [],
                    'contact' => [
                        'email' => $booking['guest_email'] ?? '',
                        'phone' => $booking['guest_phone'] ?? '',
                    ],
                ]) ?: [];
        }
    }
}

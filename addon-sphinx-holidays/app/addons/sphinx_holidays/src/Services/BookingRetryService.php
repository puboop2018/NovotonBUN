<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\Contracts\BookingRetryServiceInterface;
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
class BookingRetryService implements BookingRetryServiceInterface
{
    public function __construct(
        private readonly SphinxApi $api,
        private readonly SphinxBookingRepository $repo,
    ) {
    }

    /**
     * Retry a failed booking.
     *
     * @param int $bookingId The sphinx_bookings.booking_id
     * @return array{success: bool, message: string, booking_ref: string|null}
     */
    #[\Override]
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
        // Normalize: room_type stores 'circuit', 'experience', or 'package' for non-hotel bookings
        if (!in_array($bookingType, ['circuit', 'experience', 'package'], true)) {
            $bookingType = 'hotel';
        }

        // Step 1: Re-verify the offer (hotels and packages have verify endpoints)
        if ($bookingType === 'hotel' || $bookingType === 'package') {
            try {
                if ($bookingType === 'package') {
                    $verifyResult = $this->api->verifyPackageOffer($offerId);
                    // Normalize package verify response
                    if (!empty($verifyResult['data'])) {
                        $verifyResult = ['available' => !($verifyResult['data']['must_verify'] ?? true)];
                    }
                } else {
                    $verifyResult = $this->api->verifyHotelOffer($offerId);
                    // Normalize hotel verify response: {data: {must_verify, pricing: {selling_price}}}
                    if (!empty($verifyResult['data'])) {
                        $verifyResult = ['available' => !($verifyResult['data']['must_verify'] ?? true)];
                    }
                }
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
                    (string) json_encode($bookResult)
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
     * @param array<string, mixed> $booking
     * @param array<string, mixed> $guestsData
     * @return array<string, mixed>
     */
    private function submitBooking(string $type, string $offerId, float $price, string $currency, array $booking, array $guestsData): array
    {
        // Experiences use a flat occupancy; everything else uses room-based
        $occupancy = $type === 'experience'
            ? \fn_sphinx_holidays_build_flat_occupancy($guestsData)
            : \fn_sphinx_holidays_build_room_occupancy($guestsData, $booking);

        $payload = [
            'offer_id'  => $offerId,
            'price'     => $price,
            'currency'  => $currency,
            'occupancy' => $occupancy,
        ];
        if (!empty($booking['order_id'])) {
            $payload['reference_code'] = (string) $booking['order_id'];
        }

        $result = match ($type) {
            'circuit'    => $this->api->bookCircuit($payload),
            'package'    => $this->api->bookPackage($payload),
            'experience' => $this->api->bookExperience($payload),
            default      => $this->api->bookHotel($payload),
        };

        return $result ?: [];
    }
}

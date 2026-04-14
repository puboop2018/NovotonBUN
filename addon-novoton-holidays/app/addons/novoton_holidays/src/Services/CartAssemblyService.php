<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\TravelCore\ValueObjects\BoardType;
use Tygh\Addons\TravelCore\ValueObjects\RoomType;

/**
 * Assembles CS-Cart cart product entries from Novoton booking data.
 *
 * Extracted from BookingService (SRP) — pure data transformation, no I/O.
 * Combines booking metadata, hotel info, guest data, and pricing into the
 * structure expected by CS-Cart's cart system.
 *
 * @package NovotonHolidays
 * @since   3.4.0
 */
class CartAssemblyService implements CartAssemblyServiceInterface
{
    /**
     * Assemble the full cart product array for a Novoton booking.
     *
     * @param int $productId CS-Cart product ID
     * @param int $bookingId Novoton booking ID
     * @param array<string, mixed> $bookingData Raw form data
     * @param array<string, mixed> $hotelInfo Hotel data from repository
     * @param array<string, mixed> $guestsData Parsed guest data
     * @param array<string, mixed> $priceResult Result from verifyPrice()
     * @param array<string, mixed> $roomsData Parsed rooms data
     * @return array<string, mixed> Cart product entry with 'extra' containing all booking metadata
     */
    #[\Override]
    public function assembleCartProduct(
        int $productId,
        int $bookingId,
        array $bookingData,
        array $hotelInfo,
        array $guestsData,
        array $priceResult,
        array $roomsData,
    ): array {
        $boardId = $bookingData['board_id'] ?? 'BB';
        $nights = self::calculateNights($bookingData['check_in'], $bookingData['check_out']);

        $guestNames = [];
        foreach ($guestsData as $g) {
            if (!empty($g['name'])) {
                $guestNames[] = $g['name'];
            }
        }
        $holderName = $guestNames[0] ?? '';
        $guestList = implode(', ', $guestNames);

        $childAges = [];
        foreach ($guestsData as $g) {
            if (isset($g['type']) && $g['type'] === 'child' && isset($g['age'])) {
                $childAges[] = (int) $g['age'];
            }
        }

        $totalPrice = $priceResult['total_price'];

        return [
            'product_id' => $productId,
            'amount' => 1,
            'price' => $totalPrice,
            'base_price' => $totalPrice,
            'original_price' => $totalPrice,
            'stored_price' => 'Y',
            'extra' => [
                'travel_booking' => true,
                'novoton_booking' => true,
                'novoton_booking_id' => $bookingId,
                'hotel_id' => $bookingData['hotel_id'],
                'hotel_name' => $hotelInfo['hotel_name'] ?? '',
                'hotel_city' => $hotelInfo['city'] ?? '',
                'hotel_region' => $hotelInfo['region'] ?? '',
                'hotel_country' => $hotelInfo['country'] ?? Constants::DEFAULT_COUNTRY,
                'package_name' => $bookingData['package_name'] ?? '',
                'room_id' => $bookingData['room_id'],
                'room_name' => str_replace(['%2b', '%2B'], '+', $bookingData['room_id']),
                'room_type_display' => RoomType::formatRoomLabel($bookingData['room_id']),
                'board_id' => $boardId,
                'board_name' => BoardType::toDisplayName($boardId),
                'check_in' => $bookingData['check_in'],
                'check_out' => $bookingData['check_out'],
                'nights' => $nights,
                'adults' => (int) ($bookingData['adults'] ?? 2),
                'children' => (int) ($bookingData['children'] ?? 0),
                'children_ages' => !empty($childAges) ? implode(',', $childAges) : ($bookingData['children_ages'] ?? ''),
                'num_rooms' => (int) ($bookingData['num_rooms'] ?? 1),
                'rooms_data' => $roomsData,
                'guest_names' => $guestList,
                'holder_name' => $holderName,
                'guests_data' => json_encode($guestsData),
                'contact_email' => $bookingData['contact']['email'] ?? '',
                'contact_phone' => $bookingData['contact']['phone'] ?? '',
                'terms_of_payment' => TermsFormatter::formatPaymentTerms($priceResult['terms_of_payment']),
                'terms_of_cancellation' => TermsFormatter::formatCancellationTerms($priceResult['terms_of_cancellation'], $bookingData['check_in']),
                'terms_of_payment_raw' => $priceResult['terms_of_payment'],
                'terms_of_cancellation_raw' => $priceResult['terms_of_cancellation'],
                'remark' => $priceResult['remark'],
                'important' => $priceResult['important'],
                'total_price' => $totalPrice,
                'currency' => ConfigProvider::getApiCurrency(),
            ],
        ];
    }

    /**
     * Enrich rooms_data with display fields needed by Smarty templates.
     *
     * Adds children_ages_str and room_type_display to each room entry,
     * and syncs children ages from guest form data back to rooms.
     *
     * @param array<string, mixed> $roomsData Rooms data array
     * @param array<string, mixed> $guestsData Parsed guest data
     * @return array<string, mixed> Enriched rooms data
     */
    #[\Override]
    public function enrichRoomsData(array $roomsData, array $guestsData): array
    {
        foreach ($roomsData as $roomIdx => &$room) {
            $roomNum = (int) $roomIdx + 1;

            // Collect children ages from guests for this room
            $childAgesForRoom = [];
            foreach ($guestsData as $guest) {
                if (isset($guest['room']) && (int) $guest['room'] === $roomNum && ($guest['type'] ?? '') === 'child') {
                    $childAgesForRoom[] = (int) ($guest['age'] ?? 0);
                }
            }

            if (!empty($childAgesForRoom)) {
                $room['childrenAges'] = $childAgesForRoom;
            }

            // Build display string for children ages
            if (!empty($room['childrenAges']) && is_array($room['childrenAges'])) {
                $validAges = array_filter($room['childrenAges'], fn ($age) => $age !== null && $age !== '');
                $room['children_ages_str'] = !empty($validAges)
                    ? implode(', ', $validAges) . ' ' . __('novoton_holidays.years_old')
                    : '';
            } else {
                $room['children_ages_str'] = '';
            }

            // Ensure room_type_display is set
            if (empty($room['room_type_display']) && !empty($room['room_id'])) {
                $room['room_type_display'] = RoomType::formatRoomLabel($room['room_id']);
                $room['room_name'] = RoomType::formatRoomLabel($room['room_id']);
            }

            // Normalize room_id and room_name: restore + lost by URL decoding
            if (!empty($room['room_id'])) {
                $room['room_id'] = RoomType::normalizeRoomCode($room['room_id']);
            }
            if (!empty($room['room_name'])) {
                $room['room_name'] = RoomType::normalizeRoomCode($room['room_name']);
            }
        }
        unset($room);

        return $roomsData;
    }

    /**
     * Build cart extra metadata from a booking record.
     *
     * Used by addToCart() for the simpler path (existing booking → cart).
     *
     * @param array<string, mixed> $booking Booking record from DB
     * @param array<string, mixed> $bookingData Additional data from form
     * @return array<string, mixed> Cart extra array
     */
    #[\Override]
    public function buildCartExtra(array $booking, array $bookingData): array
    {
        return [
            'travel_booking' => true,
            'novoton_booking' => true,
            'novoton_booking_id' => $booking['booking_id'],
            'hotel_id' => $booking['hotel_id'],
            'hotel_name' => $booking['hotel_name'],
            'hotel_city' => $bookingData['hotel_city'] ?? '',
            'hotel_country' => $bookingData['hotel_country'] ?? Constants::DEFAULT_COUNTRY,
            'package_name' => $booking['package_name'],
            'room_id' => $booking['room_id'],
            'room_name' => str_replace(['%2b', '%2B'], '+', $booking['room_id']),
            'room_type_display' => $booking['room_type'],
            'board_id' => $booking['board_id'],
            'board_name' => BoardType::toDisplayName($booking['board_id']),
            'check_in' => $booking['check_in'],
            'check_out' => $booking['check_out'],
            'nights' => $booking['nights'],
            'adults' => $booking['adults'],
            'children' => $booking['children'],
            'children_ages' => $booking['children_ages'],
            'num_rooms' => $booking['num_rooms'],
            'rooms_data' => $booking['rooms_data'],
            'holder_name' => $booking['holder_name'],
            'guest_name' => $booking['guest_name'],
            'guests_data' => $booking['guests_data'],
            'total_price' => $booking['total_price'],
            'terms_of_payment' => $bookingData['terms_of_payment'] ?? '',
            'terms_of_cancellation' => $bookingData['terms_of_cancellation'] ?? '',
            'terms_of_payment_raw' => $bookingData['terms_of_payment_raw'] ?? '',
            'terms_of_cancellation_raw' => $bookingData['terms_of_cancellation_raw'] ?? '',
        ];
    }

    /**
     * Calculate nights between two dates.
     */
    public static function calculateNights(string $checkIn, string $checkOut): int
    {
        $in = strtotime($checkIn);
        $out = strtotime($checkOut);

        if (!$in || !$out || $out <= $in) {
            return 0;
        }

        return (int) (($out - $in) / 86400);
    }
}

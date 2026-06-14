<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

/**
 * Constructs the Novoton reservation API request payload for a single room
 * group: hotel/package/dates, the holder name (first guest, else booking
 * holder), guests + rooms, the order number (suffixed per group), and the
 * single-room shortcut that pins room_id/board_id.
 *
 * Extracted verbatim from BookingSubmissionService. Pure — depends only on the
 * passed group/guests/rooms/booking arrays — so it is directly unit-testable;
 * the service delegates to it inside its per-group loop.
 */
class ApiBookingRequestBuilder
{
    /**
     * @param array<string, mixed> $group
     * @param list<array<string, mixed>> $allGuests
     * @param list<array<string, mixed>> $apiRooms
     * @param array<string, mixed> $bookingData
     * @return array<string, mixed>
     */
    public function build(
        array $group,
        array $allGuests,
        array $apiRooms,
        array $bookingData,
        int $orderId,
        int $groupNum,
        int $totalGroups,
        string $orderComment = '',
    ): array {
        $suffix = $totalGroups > 1 ? "-G{$groupNum}" : '';

        $firstGuestName = (is_array($allGuests[0] ?? null) && !empty($allGuests[0]['name']))
            ? PriceInfoFormatter::toScalar($allGuests[0]['name'])
            : PriceInfoFormatter::toScalar($bookingData['holder_name'] ?? 'Guest');

        $apiData = [
            'hotel_id' => PriceInfoFormatter::toScalar($bookingData['hotel_id'] ?? ''),
            'package_name' => PriceInfoFormatter::toScalar($group['package_name'] ?? ''),
            'check_in' => PriceInfoFormatter::toScalar($group['check_in'] ?? ''),
            'check_out' => PriceInfoFormatter::toScalar($group['check_out'] ?? ''),
            'holder' => $firstGuestName,
            'guests' => $allGuests,
            'rooms' => $apiRooms,
            'order_num' => $orderId . $suffix,
            'remark' => '',
            'comment' => $orderComment,
        ];

        // Single-room shortcut
        $groupRoomsArr = is_array($group['rooms'] ?? null) ? $group['rooms'] : [];
        if (count($groupRoomsArr) === 1) {
            $firstGroupRoom = is_array($groupRoomsArr[0] ?? null) ? $groupRoomsArr[0] : [];
            $apiData['room_id'] = PriceInfoFormatter::toScalar($firstGroupRoom['room_id'] ?? $bookingData['room_id'] ?? '');
            $apiData['board_id'] = PriceInfoFormatter::toScalar($firstGroupRoom['board_id'] ?? $bookingData['board_id'] ?? '');
        }

        return $apiData;
    }
}

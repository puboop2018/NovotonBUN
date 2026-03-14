<?php
declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

/**
 * Booking Display Service
 *
 * Formats travel booking data for display in cart, checkout, and order pages.
 * Provider-agnostic — works with any travel provider through the registry.
 */
class BookingDisplayService
{
    /**
     * Add booking display data to a cart product.
     *
     * Populates product_options_value[] with formatted booking details
     * for display in cart, checkout, and order pages.
     *
     * @param array $product Cart product (by reference)
     * @param array|null $cart Cart data
     */
    public static function addBookingDisplayData(array &$product, ?array $cart = null): void
    {
        $extra = $product['extra'] ?? [];

        // Format dates
        $date_format = \Tygh\Registry::get('settings.Appearance.date_format') ?: '%d.%m.%Y';
        $check_in_ts  = !empty($extra['check_in'])  ? strtotime($extra['check_in'])  : false;
        $check_out_ts = !empty($extra['check_out']) ? strtotime($extra['check_out']) : false;
        $check_in_fmt  = ($check_in_ts  !== false) ? fn_date_format($check_in_ts, $date_format)  : '';
        $check_out_fmt = ($check_out_ts !== false) ? fn_date_format($check_out_ts, $date_format) : '';

        $num_rooms  = (int)($extra['num_rooms'] ?? 1);
        $rooms_data = $extra['rooms_data'] ?? [];
        if (is_string($rooms_data)) {
            $decoded = json_decode($rooms_data, true);
            $rooms_data = is_array($decoded) ? $decoded : [];
        }

        // Build guests string
        $adults   = (int)($extra['adults']   ?? 2);
        $children = (int)($extra['children'] ?? 0);

        $guests_str = '';
        if ($num_rooms > 1) {
            $guests_str .= $num_rooms . ' rooms, ';
        }
        $guests_str .= $adults . ' adult' . ($adults > 1 ? 's' : '');

        if ($children > 0) {
            $guests_str .= ', ' . $children . ' child' . ($children > 1 ? 'ren' : '');

            if (!empty($extra['children_ages'])) {
                $ages_str = $extra['children_ages'];
                if (is_array($ages_str)) {
                    $ages_str = implode(', ', $ages_str);
                }
                $ages_arr = array_map('trim', explode(',', $ages_str));
                $ages_arr = array_filter($ages_arr, function ($a) { return $a !== '' && $a !== 'age_needed'; });
                if (!empty($ages_arr)) {
                    $guests_str .= ' (' . implode(' and ', array_map(function ($a) { return $a . ' y/o'; }, $ages_arr)) . ')';
                }
            }
        }

        // Build product_options_value for display
        $product['product_options_value'] = [];

        // Package
        if (!empty($extra['package_name'])) {
            $product['product_options_value'][] = [
                'option_name' => __('travel_core.package'),
                'value'       => $extra['package_name'],
            ];
        }

        // Dates
        $nights = $extra['nights'] ?? 7;
        $product['product_options_value'][] = [
            'option_name' => __('travel_core.dates'),
            'value'       => $check_in_fmt . ' → ' . $check_out_fmt . ' (' . $nights . ' ' . __('travel_core.nights') . ')',
        ];

        // Room info
        $room_name = $extra['room_name'] ?? $extra['room_id'] ?? '';
        if ($num_rooms > 1 && !empty($rooms_data)) {
            $room_name = self::buildMultiRoomDisplay($room_name, $rooms_data);
        }
        $product['product_options_value'][] = [
            'option_name' => __('travel_core.room'),
            'value'       => $room_name,
        ];

        // Board/Meal plan
        $board_name = $extra['board_name'] ?? $extra['board_id'] ?? '';
        if ($num_rooms > 1 && !empty($rooms_data)) {
            $board_name = self::buildMultiBoardDisplay($board_name, $rooms_data);
        }
        $product['product_options_value'][] = [
            'option_name' => __('travel_core.board'),
            'value'       => $board_name,
        ];

        // Guests
        $product['product_options_value'][] = [
            'option_name' => __('travel_core.guests'),
            'value'       => $guests_str,
        ];

        // Per-room breakdown
        if ($num_rooms > 1 && !empty($rooms_data)) {
            foreach ($rooms_data as $idx => $room) {
                $room_num    = $idx + 1;
                $room_guests = (int)($room['adults'] ?? 2) . ' adults';
                if (!empty($room['children']) && $room['children'] > 0) {
                    $room_guests .= ', ' . $room['children'] . ' children';
                    if (!empty($room['childrenAges'])) {
                        $ages = array_filter($room['childrenAges'], function ($a) { return $a !== null && $a !== ''; });
                        if (!empty($ages)) {
                            $room_guests .= ' (' . implode(', ', $ages) . ' y/o)';
                        }
                    }
                }
                $product['product_options_value'][] = [
                    'option_name' => 'Room ' . $room_num,
                    'value'       => $room_guests,
                ];
            }
        }

        // Holder name
        if (!empty($extra['holder_name']) || !empty($extra['guest_names'])) {
            $product['product_options_value'][] = [
                'option_name' => __('travel_core.holder'),
                'value'       => $extra['holder_name'] ?? $extra['guest_names'],
            ];
        }

        $product['is_hotel_booking'] = true;

        if (empty($product['product_options'])) {
            $product['product_options'] = [];
        }
    }

    /**
     * Build room display string for multi-room bookings.
     */
    private static function buildMultiRoomDisplay(string $defaultName, array $rooms_data): string
    {
        $room_types = [];
        $has_different = false;
        $first = null;

        foreach ($rooms_data as $room) {
            $id = $room['room_id'] ?? $room['room_name'] ?? '';
            if ($first === null) {
                $first = $id;
            } elseif ($id !== $first) {
                $has_different = true;
            }
            $room_types[] = $room['room_name'] ?? $room['room_id'] ?? $defaultName;
        }

        return $has_different
            ? implode(', ', $room_types)
            : count($rooms_data) . 'x ' . $defaultName;
    }

    /**
     * Build board display string for multi-room bookings.
     */
    private static function buildMultiBoardDisplay(string $defaultBoard, array $rooms_data): string
    {
        $boards = [];
        $has_different = false;
        $first = null;

        foreach ($rooms_data as $room) {
            $board = $room['board_name'] ?? $room['board_id'] ?? '';
            if (!empty($board)) {
                if ($first === null) {
                    $first = $board;
                } elseif ($board !== $first) {
                    $has_different = true;
                }
                $boards[] = $board;
            }
        }

        return $has_different ? implode(', ', $boards) : $defaultBoard;
    }
}

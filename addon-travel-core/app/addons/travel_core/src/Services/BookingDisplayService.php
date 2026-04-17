<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Contracts\BookingDisplayServiceInterface;
use Tygh\Addons\TravelCore\Helpers\ValidationHelpers;

/**
 * Booking Display Service
 *
 * Formats travel booking data for display in cart, checkout, and order pages.
 * Provider-agnostic — works with any travel provider through the registry.
 */
class BookingDisplayService implements BookingDisplayServiceInterface
{
    /**
     * Add booking display data to a cart product.
     *
     * Populates product_options_value[] with formatted booking details
     * for display in cart, checkout, and order pages.
     *
     * Provider addons can pass a $config array to customize behavior:
     *   - 'lang_prefix'         (string)   Lang key prefix (default: 'travel_core')
     *   - 'json_decoder'        (callable) JSON decode function: fn(string, string): array (default: json_decode)
     *   - 'board_name_formatter' (callable) Board name formatter: fn(string): string (default: null)
     *   - 'room_name_formatter'  (callable) Room name formatter: fn(array room_data): string (default: null)
     *
     * @param array<string, mixed> $product Cart product (by reference)
     * @param array<string, mixed>|null $cart Cart data
     * @param array<string, mixed> $config Provider-specific configuration overrides
     */
    #[\Override]
    public static function addBookingDisplayData(array &$product, ?array $cart = null, array $config = []): void
    {
        /** @var array<string, mixed> $extra */
        $extra = is_array($product['extra'] ?? null) ? $product['extra'] : [];
        $langPrefix = is_string($config['lang_prefix'] ?? null) ? $config['lang_prefix'] : 'travel_core';
        $jsonDecoder = ($config['json_decoder'] ?? null);
        $boardFormatter = ($config['board_name_formatter'] ?? null);
        $roomFormatter = ($config['room_name_formatter'] ?? null);

        // Format dates
        $date_format = \Tygh\Registry::get('settings.Appearance.date_format') ?: '%d.%m.%Y';
        $checkInStr = is_string($extra['check_in'] ?? null) ? $extra['check_in'] : '';
        $checkOutStr = is_string($extra['check_out'] ?? null) ? $extra['check_out'] : '';
        $check_in_ts = !empty($checkInStr) ? strtotime($checkInStr) : false;
        $check_out_ts = !empty($checkOutStr) ? strtotime($checkOutStr) : false;
        $check_in_fmt = ($check_in_ts !== false) ? fn_date_format($check_in_ts, $date_format) : '';
        $check_out_fmt = ($check_out_ts !== false) ? fn_date_format($check_out_ts, $date_format) : '';

        $num_rooms = ValidationHelpers::toInt($extra['num_rooms'] ?? 1);
        $rooms_data = $extra['rooms_data'] ?? [];
        if (is_string($rooms_data)) {
            if (is_callable($jsonDecoder)) {
                $rooms_data = $jsonDecoder($rooms_data, 'rooms_data');
            } else {
                $decoded = json_decode($rooms_data, true);
                $rooms_data = is_array($decoded) ? $decoded : [];
            }
        }
        /** @var array<string, mixed> $rooms_data */
        $rooms_data = is_array($rooms_data) ? $rooms_data : [];

        // Build guests string
        $adults = ValidationHelpers::toInt($extra['adults'] ?? 2);
        $children = ValidationHelpers::toInt($extra['children'] ?? 0);

        $guests_str = '';
        if ($num_rooms > 1) {
            $guests_str .= $num_rooms . ' rooms, ';
        }
        $guests_str .= $adults . ' adult' . ($adults > 1 ? 's' : '');

        if ($children > 0) {
            $guests_str .= ', ' . $children . ' child' . ($children > 1 ? 'ren' : '');

            if (!empty($extra['children_ages'])) {
                $ages_raw = $extra['children_ages'];
                if (is_array($ages_raw)) {
                    /** @var array<string> $agesStrArr */
                    $agesStrArr = array_map(fn ($a) => ValidationHelpers::toString($a), $ages_raw);
                    $ages_str = implode(', ', $agesStrArr);
                } else {
                    $ages_str = ValidationHelpers::toString($ages_raw);
                }
                $ages_arr = array_map('trim', explode(',', $ages_str));
                $ages_arr = array_filter($ages_arr, fn ($a) => $a !== '' && $a !== 'age_needed');
                if (!empty($ages_arr)) {
                    $guests_str .= ' (' . implode(' and ', array_map(fn ($a) => $a . ' y/o', $ages_arr)) . ')';
                }
            }
        }

        // Build product_options_value for display
        $product['product_options_value'] = [];

        // Package
        $packageName = ValidationHelpers::toString($extra['package_name'] ?? '');
        if (!empty($packageName)) {
            $product['product_options_value'][] = [
                'option_name' => __($langPrefix . '.package'),
                'value' => $packageName,
            ];
        }

        // Dates
        $nights = ValidationHelpers::toInt($extra['nights'] ?? 7);
        $product['product_options_value'][] = [
            'option_name' => __($langPrefix . '.dates'),
            'value' => $check_in_fmt . ' → ' . $check_out_fmt . ' (' . $nights . ' ' . __($langPrefix . '.nights') . ')',
        ];

        // Room info
        $room_name = ValidationHelpers::toString($extra['room_name'] ?? $extra['room_id'] ?? '');
        if ($num_rooms > 1 && !empty($rooms_data)) {
            $room_name = self::buildMultiRoomDisplay($room_name, $rooms_data, is_callable($roomFormatter) ? $roomFormatter : null);
        }
        $product['product_options_value'][] = [
            'option_name' => __($langPrefix . '.room'),
            'value' => $room_name,
        ];

        // Board/Meal plan
        $board_name = ValidationHelpers::toString($extra['board_name'] ?? $extra['board_id'] ?? '');
        if ($num_rooms > 1 && !empty($rooms_data)) {
            $board_name = self::buildMultiBoardDisplay($board_name, $rooms_data, is_callable($boardFormatter) ? $boardFormatter : null);
        }
        $product['product_options_value'][] = [
            'option_name' => __($langPrefix . '.board'),
            'value' => $board_name,
        ];

        // Guests
        $product['product_options_value'][] = [
            'option_name' => __($langPrefix . '.guests'),
            'value' => $guests_str,
        ];

        // Per-room breakdown
        if ($num_rooms > 1 && !empty($rooms_data)) {
            foreach ($rooms_data as $idx => $room) {
                if (!is_array($room)) {
                    continue;
                }
                $room_num = (is_numeric($idx) ? (int) $idx : 0) + 1;
                $room_guests = ValidationHelpers::toInt($room['adults'] ?? 2) . ' adults';
                $roomChildren = ValidationHelpers::toInt($room['children'] ?? 0);
                if ($roomChildren > 0) {
                    $room_guests .= ', ' . $roomChildren . ' children';
                    if (!empty($room['childrenAges']) && is_array($room['childrenAges'])) {
                        /** @var array<string> $agesStr */
                        $agesStr = array_map(fn ($a) => ValidationHelpers::toString($a), $room['childrenAges']);
                        $ages = array_filter($agesStr, fn ($a) => $a !== '');
                        if (!empty($ages)) {
                            $room_guests .= ' (' . implode(', ', $ages) . ' y/o)';
                        }
                    }
                }
                $product['product_options_value'][] = [
                    'option_name' => 'Room ' . $room_num,
                    'value' => $room_guests,
                ];
            }
        }

        // Holder name
        $holderName = ValidationHelpers::toString($extra['holder_name'] ?? '');
        $guestNames = ValidationHelpers::toString($extra['guest_names'] ?? '');
        if (!empty($holderName) || !empty($guestNames)) {
            $product['product_options_value'][] = [
                'option_name' => __($langPrefix . '.holder'),
                'value' => $holderName ?: $guestNames,
            ];
        }

        $product['is_hotel_booking'] = true;
    }

    /**
     * Build room display string for multi-room bookings.
     *
     * @param string $defaultName Default room name
     * @param array<string, mixed> $rooms_data Per-room data
     * @param callable|null $formatter Optional formatter: fn(array $room): string
     */
    private static function buildMultiRoomDisplay(string $defaultName, array $rooms_data, ?callable $formatter = null): string
    {
        $room_types = [];
        $has_different = false;
        $first = null;

        foreach ($rooms_data as $room) {
            if (!is_array($room)) {
                continue;
            }
            $id = ValidationHelpers::toString($room['room_id'] ?? $room['room_name'] ?? '');
            if ($first === null) {
                $first = $id;
            } elseif ($id !== $first) {
                $has_different = true;
            }
            if ($formatter !== null) {
                $room_types[] = $formatter($room);
            } else {
                $room_types[] = ValidationHelpers::toString($room['room_name'] ?? $room['room_id'] ?? $defaultName);
            }
        }

        return $has_different
            ? implode(', ', $room_types)
            : count($rooms_data) . 'x ' . $defaultName;
    }

    /**
     * Build board display string for multi-room bookings.
     *
     * @param string $defaultBoard Default board name
     * @param array<string, mixed> $rooms_data Per-room data
     * @param callable|null $formatter Optional formatter: fn(string $boardId): string
     */
    private static function buildMultiBoardDisplay(string $defaultBoard, array $rooms_data, ?callable $formatter = null): string
    {
        $boards = [];
        $has_different = false;
        $first = null;

        foreach ($rooms_data as $room) {
            if (!is_array($room)) {
                continue;
            }
            $boardId = ValidationHelpers::toString($room['board_id'] ?? '');
            $boardName = ValidationHelpers::toString($room['board_name'] ?? '');
            $board = !empty($boardName) ? $boardName : ($formatter !== null && !empty($boardId) ? $formatter($boardId) : $boardId);
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

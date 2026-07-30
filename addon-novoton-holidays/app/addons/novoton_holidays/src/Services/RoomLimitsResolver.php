<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Age categories and per-room occupancy limits for the booking form.
 *
 * The form's JS uses these to police the guest pickers (max adults, max
 * children, total capacity) and to decide which date-of-birth counts as a
 * child. They come from the hotel's cached `hotel_data` JSON, falling back to
 * a live getHotelInfo call.
 *
 * Extracted from booking_form.php because the edit-booking mode renders the
 * SAME template and read the same variables — but never assigned them. They
 * are all `|default:`-guarded in the template, so the miss was silent: the
 * limits quietly became 4 adults / 2 children / capacity 2 for every hotel.
 */
final class RoomLimitsResolver
{
    /** What the form falls back to when the provider tells us nothing. */
    public const array DEFAULT_LIMITS = [
        'max_adults' => 4,
        'max_children' => 2,
        'min_pax' => 1,
        'rb' => 2,
        'eb' => 2,
    ];

    /**
     * @param array<string, mixed> $hotelInfo a ?:novoton_hotels row (its
     *                                        `hotel_data` JSON is the cached copy of the provider payload)
     *
     * @return array{age_categories: list<array<string, mixed>>, current_room_limits: array<string, mixed>}
     */
    public static function resolve(array $hotelInfo, string $hotelId, string $currentRoomId): array
    {
        [$ageCategories, $roomLimits] = self::fromHotelData($hotelInfo);

        if ($ageCategories === [] || $roomLimits === []) {
            [$apiAges, $apiRooms] = self::fromApi($hotelId);
            $ageCategories = $ageCategories === [] ? $apiAges : $ageCategories;
            $roomLimits = $roomLimits === [] ? $apiRooms : $roomLimits;
        }

        return [
            'age_categories' => $ageCategories,
            'current_room_limits' => TypeCoerce::toStringMap($roomLimits[$currentRoomId] ?? self::DEFAULT_LIMITS),
        ];
    }

    /**
     * @param array<string, mixed> $hotelInfo
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, array<string, mixed>>}
     */
    private static function fromHotelData(array $hotelInfo): array
    {
        $ageCategories = [];
        $roomLimits = [];

        if (empty($hotelInfo['hotel_data'])) {
            return [$ageCategories, $roomLimits];
        }

        $hotelData = TypeCoerce::toStringMap(json_decode(TypeCoerce::toString($hotelInfo['hotel_data']), true));

        if (!empty($hotelData['ages'])) {
            // A single age category arrives as the object itself, not a list.
            $agesRaw = TypeCoerce::toStringMap($hotelData['ages']);
            $ages = isset($agesRaw['IdAge']) ? [$agesRaw] : TypeCoerce::toRowList($hotelData['ages']);
            foreach ($ages as $age) {
                $ageCategories[] = [
                    'id' => TypeCoerce::toString($age['IdAge'] ?? ''),
                    'is_child' => TypeCoerce::toString($age['fAge'] ?? '0') === '1',
                    'from_year' => TypeCoerce::toFloat($age['FromYear'] ?? 0),
                    'to_year' => TypeCoerce::toFloat($age['ToYear'] ?? 99),
                ];
            }
        }

        if (!empty($hotelData['rooms'])) {
            $roomsRaw = TypeCoerce::toStringMap($hotelData['rooms']);
            $rooms = isset($roomsRaw['IdRoom']) ? [$roomsRaw] : TypeCoerce::toRowList($hotelData['rooms']);
            foreach ($rooms as $room) {
                $roomId = TypeCoerce::toString($room['IdRoom'] ?? $room['id'] ?? '');
                if ($roomId !== '' && $roomId !== '0') {
                    $roomLimits[$roomId] = $room;
                }
            }
        }

        return [$ageCategories, $roomLimits];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: array<string, array<string, mixed>>}
     */
    private static function fromApi(string $hotelId): array
    {
        $ageCategories = [];
        $roomLimits = [];

        $api = function_exists('fn_novoton_holidays_get_api') ? fn_novoton_holidays_get_api() : null;
        if ($api === null) {
            return [$ageCategories, $roomLimits];
        }

        // A provider hiccup must not 500 this customer-facing page — degrade to
        // the default age/room limits, same as the no-API-configured path.
        try {
            $response = $api->hotels()->getHotelInfo($hotelId);
        } catch (\Throwable $e) {
            if (function_exists('fn_log_event')) {
                fn_log_event('general', 'runtime', [
                    'message' => 'Novoton booking form: getHotelInfo failed — using default age/room limits',
                    'hotel_id' => $hotelId,
                    'error' => $e->getMessage(),
                ]);
            }

            return [$ageCategories, $roomLimits];
        }

        if (!(bool) $response || !isset($response->hotels->hotel)) {
            return [$ageCategories, $roomLimits];
        }

        $hotel = $response->hotels->hotel;

        if (isset($hotel->age)) {
            $ages = isset($hotel->age->IdAge) ? [$hotel->age] : $hotel->age;
            foreach ($ages as $age) {
                $ageCategories[] = [
                    'id' => (string) ($age->IdAge ?? ''),
                    'is_child' => ((string) ($age->fAge ?? '0')) === '1',
                    'from_year' => (float) ((string) ($age->FromYear ?? 0)),
                    'to_year' => (float) ((string) ($age->ToYear ?? 99)),
                ];
            }
        }

        if (isset($hotel->rooms)) {
            $rooms = isset($hotel->rooms->IdRoom) ? [$hotel->rooms] : $hotel->rooms;
            foreach ($rooms as $room) {
                $roomId = (string) ($room->IdRoom ?? '');
                $roomLimits[$roomId] = [
                    'id' => $roomId,
                    'type' => (string) ($room->Type ?? ''),
                    'rb' => (int) ((string) ($room->RB ?? 2)),
                    'eb' => (int) ((string) ($room->EB ?? 0)),
                    'max_adults' => (int) ((string) ($room->maxADT ?? 4)),
                    'max_children' => (int) ((string) ($room->maxCHD ?? 2)),
                    'min_pax' => (int) ((string) ($room->minPAX ?? 1)),
                ];
            }
        }

        return [$ageCategories, $roomLimits];
    }
}

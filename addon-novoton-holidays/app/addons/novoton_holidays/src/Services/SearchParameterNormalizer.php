<?php

declare(strict_types=1);

/**
 * Normalizes raw HTTP search parameters into a clean, validated structure.
 *
 * Handles React JSON rooms_data, legacy URL parameters, children age
 * normalization, and date calculation.  The output is a single array
 * that both the controller and API callers can rely on.
 *
 * @package NovotonHolidays
 * @since   3.6.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\TravelCore\Dto\Search\SearchQuery;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

class SearchParameterNormalizer
{
    /**
     * Typed view of {@see self::normalize()}.
     *
     * Returns the same shape as a {@see SearchQuery} DTO. New code paths
     * should prefer this over normalize(); existing array callers keep
     * working unchanged.
     *
     * @param array<string, mixed> $searchParams Already-sanitized request params (from SecurityService)
     */
    public function normalizeAsDto(array $searchParams): SearchQuery
    {
        return SearchQuery::fromNormalized($this->normalize($searchParams), $searchParams);
    }

    /**
     * Normalize raw search parameters from the HTTP request.
     *
     * @param array<string, mixed> $searchParams Already-sanitized request params (from SecurityService)
     * @return array<string, mixed> Normalized parameter bag with keys:
     *                              check_in, check_out, nights, adults, children (ages array),
     *                              children_count, children_ages_str, num_rooms, rooms_data,
     *                              rooms_data_json, flex_days, meal_plan, hotel_id, product_id,
     *                              novoton_params (template-ready sub-array)
     */
    public function normalize(array $searchParams): array
    {
        $checkIn = $this->resolveCheckIn($searchParams);
        $nights = $this->resolveNights($searchParams, $checkIn);

        $adults = !empty($searchParams['adults']) ? TypeCoerce::toInt($searchParams['adults']) : 2;
        $flexDays = !empty($searchParams['flex_days']) ? TypeCoerce::toInt($searchParams['flex_days']) : 0;

        // ── Multi-room data ──────────────────────────────────────────
        $roomsData = $this->parseRoomsData($searchParams);
        $roomsData = $this->mergeUrlChildrenAges($roomsData, $searchParams);

        // ── Totals ───────────────────────────────────────────────────
        [$totalAdults, , $allChildrenAges] = $this->calculateTotals($roomsData);
        $adults = $totalAdults;
        $numRooms = count($roomsData);

        // ── Check-out ────────────────────────────────────────────────
        $checkOut = !empty($checkIn)
            ? date('Y-m-d', (int) strtotime($checkIn . ' +' . $nights . ' days'))
            : '';

        // ── Meal plan ────────────────────────────────────────────────
        $mealPlan = !empty($searchParams['meal_plan']) ? $searchParams['meal_plan'] : '';

        $childrenAgesStr = !empty($allChildrenAges) ? implode(',', $allChildrenAges) : '';

        // ── Hotel / product ID ───────────────────────────────────────
        $hotelId = $searchParams['hotel_id'] ?? '';
        $productId = !empty($searchParams['product_id']) ? TypeCoerce::toInt($searchParams['product_id']) : 0;

        if (!empty($hotelId) && empty($productId)) {
            $prefix = ConfigProvider::getFirstProductCodePrefix();
            $productId = TypeCoerce::toInt(db_get_field(
                'SELECT product_id FROM ?:products WHERE product_code = ?s',
                $prefix . TypeCoerce::toString($hotelId),
            ));
        }

        $novotonParams = [
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'nights' => $nights,
            'adults' => $adults,
            'children' => $allChildrenAges,
            'children_count' => count($allChildrenAges),
            'children_ages' => $childrenAgesStr,
            'children_ages_str' => $childrenAgesStr,
            'children_ages_array' => $allChildrenAges,
            'meal_plan' => $mealPlan ?: (__('novoton_holidays.all_boards') ?: 'All Boards'),
            'hotel_id' => $hotelId,
            'product_id' => $productId,
            'num_rooms' => $numRooms,
            'rooms_data' => $roomsData,
            'rooms_data_json' => json_encode($roomsData),
            'flex_days' => $flexDays,
        ];

        return [
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'nights' => $nights,
            'adults' => $adults,
            'children' => $allChildrenAges,
            'children_count' => count($allChildrenAges),
            'children_ages_str' => $childrenAgesStr,
            'num_rooms' => $numRooms,
            'rooms_data' => $roomsData,
            'flex_days' => $flexDays,
            'meal_plan' => $mealPlan,
            'hotel_id' => $hotelId,
            'product_id' => $productId,
            'novoton_params' => $novotonParams,
        ];
    }

    // =====================================================================
    // Internals
    // =====================================================================

    /**
     * @param array<string, mixed> $params
     */
    private function resolveCheckIn(array $params): string
    {
        return !empty($params['check_in']) ? TypeCoerce::toString($params['check_in']) : '';
    }

    /**
     * @param array<string, mixed> $params
     */
    private function resolveNights(array $params, string $checkIn): int
    {
        if (!empty($params['check_out'])) {
            if (!empty($checkIn)) {
                try {
                    $d1 = new \DateTime($checkIn);
                    $d2 = new \DateTime(TypeCoerce::toString($params['check_out']));
                    $nights = $d1->diff($d2)->days;
                    return $nights >= 1 ? $nights : 7;
                } catch (\Exception $e) {
                    return 7;
                }
            }
            return 7;
        }

        return !empty($params['nights']) ? TypeCoerce::toInt($params['nights']) : 7;
    }

    /**
     * Parse rooms_data / room_data JSON from the React or legacy form.
     * Falls back to constructing a single-room entry from scalar params.
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function parseRoomsData(array $params): array
    {
        $roomsData = [];

        foreach (['rooms_data', 'room_data'] as $key) {
            if (!empty($params[$key])) {
                if (is_array($params[$key])) {
                    $roomsData = $params[$key];
                    break;
                }
                if (is_string($params[$key])) {
                    $decoded = json_decode($params[$key], true);
                    if (is_array($decoded) && !empty($decoded)) {
                        $roomsData = $decoded;
                        break;
                    }
                }
            }
        }

        // Normalize each room
        if (!empty($roomsData)) {
            $normalizedRooms = [];
            foreach (TypeCoerce::toRowList($roomsData) as $room) {
                $cleanAges = [];
                if (!empty($room['childrenAges']) && is_array($room['childrenAges'])) {
                    foreach ($room['childrenAges'] as $age) {
                        if ($age !== null && $age !== '' && $age !== 'null' && is_numeric($age)) {
                            $cleanAges[] = (int) $age;
                        }
                    }
                }
                $room['adults'] = TypeCoerce::toInt($room['adults'] ?? 2);
                $room['children'] = !empty($cleanAges) ? count($cleanAges) : TypeCoerce::toInt($room['children'] ?? 0);
                $room['childrenAges'] = $cleanAges;
                $normalizedRooms[] = $room;
            }
            return $normalizedRooms;
        }

        // Fallback: build single room from scalar params
        $childrenCount = !empty($params['children']) ? TypeCoerce::toInt($params['children']) : 0;
        $childrenAges = $this->parseCommaAges(TypeCoerce::toString($params['children_ages'] ?? ''));

        // Legacy: child_age_1, child_age_2, ... individual URL params
        if (empty($childrenAges) && $childrenCount > 0) {
            for ($i = 1; $i <= $childrenCount; $i++) {
                $age = $params['child_age_' . $i] ?? null;
                if ($age !== null && $age !== '' && is_numeric($age)) {
                    $childrenAges[] = (int) $age;
                }
            }
        }

        if (!empty($childrenAges)) {
            $childrenCount = count($childrenAges);
        }

        return [
            [
                'adults' => !empty($params['adults']) ? TypeCoerce::toInt($params['adults']) : 2,
                'children' => $childrenCount,
                'childrenAges' => $childrenAges,
            ],
        ];
    }

    /**
     * If the URL also carries a children_ages param, distribute them
     * to rooms whose childrenAges are still empty.
     * @param list<array<string, mixed>> $roomsData
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function mergeUrlChildrenAges(array $roomsData, array $params): array
    {
        if (empty($params['children_ages']) || !is_string($params['children_ages'])) {
            return $roomsData;
        }

        $urlAges = $this->parseCommaAges($params['children_ages']);
        if (empty($urlAges)) {
            return $roomsData;
        }

        foreach ($roomsData as $idx => $room) {
            if (empty($room['childrenAges']) && ($room['children'] ?? 0) > 0) {
                $roomsData[$idx]['childrenAges'] = array_slice($urlAges, 0, TypeCoerce::toInt($room['children'] ?? 0));
            }
        }

        return $roomsData;
    }

    /**
     * @return array{int, int, int[]} [totalAdults, totalChildren, allAges]
     * @param list<array<string, mixed>> $roomsData
     */
    private function calculateTotals(array $roomsData): array
    {
        $totalAdults = 0;
        $totalChildren = 0;
        $allAges = [];

        foreach ($roomsData as $room) {
            $totalAdults += TypeCoerce::toInt($room['adults'] ?? 2);
            $totalChildren += TypeCoerce::toInt($room['children'] ?? 0);
            if (!empty($room['childrenAges'])) {
                foreach (TypeCoerce::toList($room['childrenAges']) as $age) {
                    if ($age !== null && $age !== 'age_needed') {
                        $allAges[] = TypeCoerce::toInt($age);
                    }
                }
            }
        }

        return [$totalAdults, $totalChildren, $allAges];
    }

    /** @return list<int> */
    private function parseCommaAges(string $raw): array
    {
        $ages = [];
        foreach (explode(',', $raw) as $age) {
            $age = trim($age);
            if ($age !== '' && is_numeric($age)) {
                $ages[] = (int) $age;
            }
        }
        return $ages;
    }
}

<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Normalises a raw search request into the structured parameter array the
 * search pipeline expects: typed scalars, the parsed/sythesised rooms_data, and
 * the per-occupancy totals (adults, children, children ages).
 *
 * Extracted verbatim from SearchService. Pure — depends only on the request and
 * rooms arrays — so it is directly unit-testable; SearchService keeps the
 * parseSearchParams()/calculateRoomTotals() methods on its interface and
 * delegates here.
 */
class SearchParamsNormalizer
{
    /**
     * Parse search parameters from request.
     *
     * @param array<string, mixed> $request Request parameters
     * @return array<string, mixed> Normalized search parameters
     */
    public function parseSearchParams(array $request): array
    {
        $params = [
            'check_in' => $request['check_in'] ?? '',
            'nights' => PriceInfoFormatter::toInt($request['nights'] ?? 7),
            'adults' => PriceInfoFormatter::toInt($request['adults'] ?? 2),
            'children' => PriceInfoFormatter::toInt($request['children'] ?? 0),
            'num_rooms' => PriceInfoFormatter::toInt($request['rooms'] ?? 1),
            'flex_days' => PriceInfoFormatter::toInt($request['flex_days'] ?? 0),
            'hotel_id' => $request['hotel_id'] ?? '',
            'product_id' => PriceInfoFormatter::toInt($request['product_id'] ?? 0),
            'destination' => $request['destination'] ?? '',
            'country' => $request['country'] ?? '',
            'region' => $request['region'] ?? '',
            'city' => $request['city'] ?? '',
        ];

        // Parse multi-room data
        $rooms_data = [];
        if (!empty($request['room_data'])) {
            $rooms_data = TypeCoerce::toRowList(
                json_decode(PriceInfoFormatter::toScalar($request['room_data']), true),
            );
        }

        // Create default single room if no room_data
        if (empty($rooms_data)) {
            $children_ages = $this->parseChildrenAges($request, $params['children']);
            $rooms_data = [[
                'adults' => $params['adults'],
                'children' => $params['children'],
                'childrenAges' => $children_ages,
            ]];
        }

        // Calculate totals from rooms
        $totals = $this->calculateRoomTotals($rooms_data);
        $params['total_adults'] = $totals['adults'];
        $params['total_children'] = $totals['children'];
        $params['children_ages'] = $totals['ages'];
        $params['rooms_data'] = $rooms_data;
        $params['num_rooms'] = count($rooms_data);

        return $params;
    }

    /**
     * Calculate totals from rooms data.
     *
     * @param list<array<string, mixed>> $rooms_data Rooms configuration
     * @return array{adults: int, children: int, ages: list<int>}
     */
    public function calculateRoomTotals(array $rooms_data): array
    {
        $total_adults = 0;
        $total_children = 0;
        $all_ages = [];

        foreach ($rooms_data as $room) {
            $total_adults += PriceInfoFormatter::toInt($room['adults'] ?? 2);
            $total_children += PriceInfoFormatter::toInt($room['children'] ?? 0);
            if (!empty($room['childrenAges'])) {
                foreach (TypeCoerce::toList($room['childrenAges']) as $age) {
                    if ($age !== null && $age !== 'age_needed') {
                        $all_ages[] = PriceInfoFormatter::toInt($age);
                    }
                }
            }
        }

        return [
            'adults' => $total_adults,
            'children' => $total_children,
            'ages' => $all_ages,
        ];
    }

    /**
     * Parse children ages from request.
     *
     * @param array<string, mixed> $request Request data
     * @param int $children_count Number of children
     * @return list<int> Children ages
     */
    private function parseChildrenAges(array $request, int $children_count): array
    {
        $ages = [];
        for ($i = 1; $i <= $children_count; $i++) {
            if (isset($request['child_age_' . $i])) {
                $age = $request['child_age_' . $i];
                if ($age !== '' && $age !== 'age_needed') {
                    $ages[] = PriceInfoFormatter::toInt($age);
                }
            }
        }
        return $ages;
    }
}

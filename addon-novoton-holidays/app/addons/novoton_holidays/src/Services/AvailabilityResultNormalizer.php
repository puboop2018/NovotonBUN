<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

/**
 * Pure shaping/normalization helpers for hotel-availability results.
 *
 * Extracted from HotelAvailabilitySearcher — the stateless pieces shared by its
 * multi-room and single-room search paths: deriving the max room capacity from
 * the `N+M` room-id pattern, cleaning a raw children-ages list, and the canonical
 * empty result envelope. Pure (no API/DB/debug state), so directly unit-testable;
 * the searcher and the multi-room batcher both delegate to it.
 */
class AvailabilityResultNormalizer
{
    /**
     * Derive the maximum room capacity from the `adults+children` room-id codes
     * (e.g. "DBL 2+1"). Falls back to 2 adults / 2 children when none parse.
     *
     * @param list<array<string, mixed>> $results
     * @return array{adults: int, children: int, total: int}
     */
    public function calculateMaxCapacity(array $results): array
    {
        $maxAdults = 0;
        $maxChildren = 0;

        foreach ($results as $result) {
            $roomId = PriceInfoFormatter::toScalar($result['room_id']);
            if (preg_match('/(\d+)\+(\d+)/', $roomId, $m) === 1) {
                $maxAdults = max($maxAdults, (int) $m[1]);
                $maxChildren = max($maxChildren, (int) $m[2]);
            }
        }

        if ($maxAdults === 0) {
            $maxAdults = 2;
            $maxChildren = 2;
        }

        return [
            'adults' => $maxAdults,
            'children' => $maxChildren,
            'total' => $maxAdults + $maxChildren,
        ];
    }

    /**
     * Drop empty / placeholder ages and coerce the rest to ints.
     *
     * @param list<mixed> $raw
     * @return list<int>
     */
    public function cleanChildrenAges(array $raw): array
    {
        $clean = [];
        foreach ($raw as $age) {
            if ($age !== null && $age !== '' && $age !== 'age_needed') {
                $clean[] = PriceInfoFormatter::toInt($age);
            }
        }
        return $clean;
    }

    /**
     * The canonical "no availability" result envelope.
     *
     * @return array{
     *   results: list<array<string, mixed>>,
     *   all_room_results: array<int, list<array<string, mixed>>>,
     *   is_multi_room: bool,
     *   multi_room_total_options: int,
     *   no_availability: bool,
     *   max_room_capacity: array<string, int>,
     *   early_booking_discounts: list<array<string, mixed>>,
     *   early_booking_range: array<string, mixed>
     * }
     */
    public function emptyResult(): array
    {
        return [
            'results' => [],
            'all_room_results' => [],
            'is_multi_room' => false,
            'multi_room_total_options' => 0,
            'no_availability' => true,
            'max_room_capacity' => ['adults' => 2, 'children' => 2, 'total' => 4],
            'early_booking_discounts' => [],
            'early_booking_range' => [],
        ];
    }
}

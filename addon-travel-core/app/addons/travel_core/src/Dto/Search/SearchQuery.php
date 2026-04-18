<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Dto\Search;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Typed view of the parsed search request (check-in, occupancy, destination,
 * hotel scope, meal plan).
 *
 * Replaces the `array<string, mixed>` shape returned by
 * `SearchParameterNormalizer::normalize()` for new code paths. Existing
 * array callers keep working via `toArray()` at the boundary — see PR
 * plan for the migration sequence.
 *
 * Construction paths:
 *   - `fromNormalized()` — wraps the output of the existing
 *     `SearchParameterNormalizer::normalize()` so we don't duplicate
 *     its request-parsing logic. This is the production entry point.
 *
 * The DTO holds one source of truth per concept — totals (`totalAdults`,
 * `totalChildren`, flat `childrenAges`) are derivable from `roomsData` but
 * kept as eagerly-computed fields so template-layer consumers don't have
 * to recompute them on every render.
 */
final readonly class SearchQuery
{
    /**
     * @param list<RoomSpec> $roomsData
     * @param list<int> $childrenAges flat list across all rooms
     */
    public function __construct(
        public string $checkIn,
        public string $checkOut,
        public int $nights,
        public int $flexDays,
        public int $totalAdults,
        public int $totalChildren,
        public array $childrenAges,
        public array $roomsData,
        public Destination $destination,
        public string $hotelId,
        public int $productId,
        public string $mealPlan,
    ) {
    }

    public function numRooms(): int
    {
        return count($this->roomsData);
    }

    public function isHotelScoped(): bool
    {
        return $this->hotelId !== '';
    }

    /**
     * Build from the normalized array shape returned by
     * `SearchParameterNormalizer::normalize()`. Tolerant of missing keys.
     *
     * @param array<string, mixed> $n Output of normalize()
     * @param array<string, mixed> $raw Original request bag (for destination fields)
     */
    public static function fromNormalized(array $n, array $raw = []): self
    {
        $roomsData = [];
        if (isset($n['rooms_data']) && is_array($n['rooms_data'])) {
            foreach ($n['rooms_data'] as $room) {
                if (is_array($room)) {
                    $roomsData[] = RoomSpec::fromArray(TypeCoerce::toStringMap($room));
                }
            }
        }

        $childrenAges = [];
        if (isset($n['children']) && is_array($n['children'])) {
            foreach ($n['children'] as $age) {
                if (is_numeric($age)) {
                    $childrenAges[] = (int) $age;
                }
            }
        }

        return new self(
            checkIn: isset($n['check_in']) && is_string($n['check_in']) ? $n['check_in'] : '',
            checkOut: isset($n['check_out']) && is_string($n['check_out']) ? $n['check_out'] : '',
            nights: isset($n['nights']) && is_numeric($n['nights']) ? (int) $n['nights'] : 7,
            flexDays: isset($n['flex_days']) && is_numeric($n['flex_days']) ? (int) $n['flex_days'] : 0,
            totalAdults: isset($n['adults']) && is_numeric($n['adults']) ? (int) $n['adults'] : 0,
            totalChildren: isset($n['children_count']) && is_numeric($n['children_count']) ? (int) $n['children_count'] : 0,
            childrenAges: $childrenAges,
            roomsData: $roomsData,
            destination: Destination::fromRequest($raw),
            hotelId: isset($n['hotel_id']) && is_string($n['hotel_id']) ? $n['hotel_id'] : '',
            productId: isset($n['product_id']) && is_numeric($n['product_id']) ? (int) $n['product_id'] : 0,
            mealPlan: isset($n['meal_plan']) && is_string($n['meal_plan']) ? $n['meal_plan'] : '',
        );
    }

    /**
     * Re-emit in the legacy `normalize()` shape for back-compat at controller /
     * service boundaries that still consume arrays. This mirrors the top-level
     * shape; the `novoton_params` template-ready sub-array is NOT emitted here
     * (it duplicates the same data and callers that need it can build it from
     * the DTO via toNovotonParamsArray()).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $roomsData = array_map(static fn (RoomSpec $r): array => $r->toArray(), $this->roomsData);

        return [
            'check_in' => $this->checkIn,
            'check_out' => $this->checkOut,
            'nights' => $this->nights,
            'adults' => $this->totalAdults,
            'children' => $this->childrenAges,
            'children_count' => $this->totalChildren,
            'children_ages_str' => $this->childrenAgesString(),
            'num_rooms' => $this->numRooms(),
            'rooms_data' => $roomsData,
            'flex_days' => $this->flexDays,
            'meal_plan' => $this->mealPlan,
            'hotel_id' => $this->hotelId,
            'product_id' => $this->productId,
        ];
    }

    /**
     * Emit the template-ready sub-array historically assigned as
     * `novoton_params` (used by SearchResultFormatter).
     *
     * @return array<string, mixed>
     */
    public function toNovotonParamsArray(): array
    {
        $roomsData = array_map(static fn (RoomSpec $r): array => $r->toArray(), $this->roomsData);
        $agesStr = $this->childrenAgesString();

        return [
            'check_in' => $this->checkIn,
            'check_out' => $this->checkOut,
            'nights' => $this->nights,
            'adults' => $this->totalAdults,
            'children' => $this->childrenAges,
            'children_count' => $this->totalChildren,
            'children_ages' => $agesStr,
            'children_ages_str' => $agesStr,
            'children_ages_array' => $this->childrenAges,
            'meal_plan' => $this->mealPlan,
            'hotel_id' => $this->hotelId,
            'product_id' => $this->productId,
            'num_rooms' => $this->numRooms(),
            'rooms_data' => $roomsData,
            'rooms_data_json' => json_encode($roomsData),
            'flex_days' => $this->flexDays,
        ];
    }

    private function childrenAgesString(): string
    {
        return $this->childrenAges === [] ? '' : implode(',', $this->childrenAges);
    }
}

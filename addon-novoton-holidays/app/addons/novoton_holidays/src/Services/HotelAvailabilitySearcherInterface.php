<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

/**
 * Interface for hotel-specific availability search orchestration.
 *
 * @since 3.6.0
 */
interface HotelAvailabilitySearcherInterface
{
    /**
     * Search a specific hotel for room availability.
     *
     * @param array<string, mixed> $params Normalized params from SearchParameterNormalizer
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
    public function search(array $params): array;

    /**
     * @return string[]
     */
    public function getDebugLog(): array;

    /**
     * @return \SimpleXMLElement[] The rooms XML nodes (for alternative search)
     */
    public function getRooms(string $hotelId): array;

    /**
     * @return string[] Board type identifiers
     */
    public function getBoardTypes(string $hotelId, string $mealPlan = ''): array;
}

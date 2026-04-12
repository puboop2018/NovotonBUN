<?php
declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

/**
 * Interface for alternative-date availability search.
 *
 * @since 3.6.0
 */
interface AlternativeDateSearcherInterface
{
    /**
     * Search alternative dates for a hotel.
     *
     * @param string $hotelId    Hotel identifier
     * @param string $checkIn    Original check-in date
     * @param int    $nights     Stay duration
     * @param int    $adults     Total adults
     * @param array<string, mixed>  $children   Children ages
     * @param int    $flexDays   Days to search before/after (0 = use default 10)
     * @param array<string, mixed>  $rooms      Room XML nodes from HotelAvailabilitySearcher::getRooms()
     * @param array<string, mixed>  $boardTypes Board type IDs from HotelAvailabilitySearcher::getBoardTypes()
     * @return array{
     *   results: array,
     *   check_in: string,
     *   check_out: string
     * }
     */
    public function search(
        string $hotelId,
        string $checkIn,
        int    $nights,
        int    $adults,
        array  $children,
        int    $flexDays,
        array  $rooms,
        array  $boardTypes
    ): array;

    /**
     * @return string[]
     */
    public function getDebugLog(): array;
}
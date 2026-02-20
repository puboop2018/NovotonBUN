<?php
declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

/**
 * Interface for hotel search operations.
 *
 * @since 3.5.0
 */
interface SearchServiceInterface
{
    public function parseSearchParams(array $request): array;

    public function calculateRoomTotals(array $rooms_data): array;

    public function searchAvailability(array $params): array;

    public function searchFlexibleDates(array $params, int $flex_days): array;

    public function processSearchResults($response, array $params): array;

    public function getBoardName(string $board_id): string;

    /**
     * Parse room_price API XML response into structured result array.
     */
    public function parseRoomPriceResponse(
        string  $rawXml,
        int     $nights,
        string  $checkIn,
        string  $checkOut,
        string  $mealPlan,
        array   $quotaMap,
        array   $roomTypeMap,
        ?int    $forRoom,
        ?string $occupancyStr
    ): array;
}

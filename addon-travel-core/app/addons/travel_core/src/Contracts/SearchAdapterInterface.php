<?php
declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Contracts;

/**
 * Search adapter contract.
 *
 * Each travel provider (Novoton, Sphinx, etc.) implements this interface
 * to translate its API-specific search into standardized results.
 */
interface SearchAdapterInterface
{
    /**
     * Search availability for a specific hotel or destination.
     *
     * @param array $params Normalized search params (from SearchParameterNormalizer)
     * @return array Standardized result items
     */
    public function searchAvailability(array $params): array;

    /**
     * Batch search for flexible dates (±N days).
     *
     * @param array $params  Base search params
     * @param int   $flexDays Number of days to try each direction
     * @return array Standardized result items across all dates
     */
    public function searchFlexibleDates(array $params, int $flexDays): array;

    /**
     * Get hotel metadata (rooms, boards, packages, age categories).
     *
     * @param string $hotelId Provider-specific hotel ID
     * @return array Standardized hotel info
     */
    public function getHotelInfo(string $hotelId): array;
}

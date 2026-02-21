<?php
declare(strict_types=1);
/**
 * Guest Data Service Interface
 *
 * Contract for guest data parsing, formatting, and validation.
 *
 * @package NovotonHolidays
 * @since 3.3.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

interface GuestDataServiceInterface
{
    /**
     * Parse guests data from booking form.
     *
     * Accepts any supported format (keyed, indexed-array, or JSON string)
     * and always returns canonical keyed format via GuestDataNormalizer.
     *
     * @param array $bookingData Booking form data
     * @return array Parsed guests data in canonical keyed format
     */
    public function parseGuestsData(array $bookingData): array;

    /**
     * Format name for API (FirstName LastName)
     *
     * @param array $guest Guest data
     * @return string Formatted API name
     */
    public function formatApiName(array $guest): string;

    /**
     * Build comma-separated guest list
     *
     * @param array $guests_data Guests data (keyed array)
     * @return string Guest list
     */
    public function buildGuestList(array $guests_data): string;

    /**
     * Get holder name from guests data
     *
     * @param array $guests_data Guests data
     * @param array $bookingData Fallback booking data
     * @return string Holder name
     */
    public function getHolderName(array $guests_data, array $bookingData = []): string;

    /**
     * Get guests grouped by room
     *
     * @param array $guests_data Guests data
     * @return array Guests by room [room_num => [guests]]
     */
    public function getGuestsByRoom(array $guests_data): array;

    /**
     * Get guest counts per room
     *
     * @param array $guests_data Guests data
     * @return array Room counts [room_num => [adults, children]]
     */
    public function getRoomCounts(array $guests_data): array;

    /**
     * Format guests for API request
     *
     * @param array $guests_data Guests data (keyed array)
     * @param array $rooms_data Rooms configuration
     * @return array API-formatted guests
     */
    public function formatForApi(array $guests_data, array $rooms_data = []): array;

    /**
     * Format guests for display
     *
     * @param array $guests_data Guests data
     * @return array Display-formatted guests
     */
    public function formatForDisplay(array $guests_data): array;

    /**
     * Validate guests data
     *
     * @param array $guests_data Guests data
     * @param int $expected_adults Expected adult count
     * @param int $expected_children Expected children count
     * @return array Validation result [valid, errors]
     */
    public function validate(array $guests_data, int $expected_adults = 0, int $expected_children = 0): array;

    /**
     * Merge guest data from multiple sources
     *
     * @param array $sources Array of guest data sources
     * @return array Merged guests data
     */
    public function merge(array ...$sources): array;
}

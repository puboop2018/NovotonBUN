<?php
declare(strict_types=1);
/**
 * PriceInfo Calculation Interface
 *
 * Contract for calculating prices from priceinfo data.
 * Orchestrates PriceInfoParser, PriceInfoCalculator, and PriceInfoFormatter.
 *
 * @package NovotonHolidays
 * @since 3.5.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

interface PriceInfoCalculationInterface
{
    /**
     * Calculate price for a booking.
     *
     * @param array<string, mixed> $params Calculation parameters:
     *   - hotel_id: Hotel ID
     *   - package_name: Package name
     *   - check_in: Check-in date (Y-m-d)
     *   - nights: Number of nights
     *   - room_id: Room type ID
     *   - board_id: Board type ID
     *   - adults: Number of adults
     *   - children_ages: Array of children ages
     *   - booking_date: Date of booking for EB check (default: today)
     * @return array Calculation result with price breakdowns
     */
    public function calculate(array $params): array;

    /**
     * Enable/disable debug mode.
     *
     * @param bool $debug
     */
    public function setDebug(bool $debug): void;

    /**
     * Get debug log.
     *
     * @return array<string, mixed>
     */
    public function getDebugLog(): array;

    /**
     * Verify season-to-price correlation (debug helper).
     *
     * @param string $checkIn
     * @param int    $nights
     * @return array<string, mixed>
     */
    public function verifySeasonPriceMapping(string $checkIn, int $nights): array;

    /**
     * Get sample prices for verification (debug helper).
     *
     * @param string $roomId
     * @param string $boardId
     * @return array<string, mixed>
     */
    public function getSamplePrices(string $roomId, string $boardId): array;

    /**
     * Get the parser instance (for direct priceinfo access by debug tools).
     *
     * @return PriceInfoParser
     */
    public function getParser(): PriceInfoParser;

    /**
     * Log debug message.
     *
     * @param string     $message Log message
     * @param mixed|null $data    Additional data
     */
    public function log(string $message, mixed $data = null): void;
}

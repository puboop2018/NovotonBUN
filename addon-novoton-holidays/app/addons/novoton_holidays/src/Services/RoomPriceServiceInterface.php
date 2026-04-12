<?php
declare(strict_types=1);
/**
 * Room Price Service Interface
 *
 * Contract for real-time room price calculations, commission application, and formatting.
 * Currency operations have been moved to CurrencyService.
 *
 * @package NovotonHolidays
 * @since 3.3.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

interface RoomPriceServiceInterface
{
    /**
     * Apply commission to base price
     *
     * @param float $base_price Base price from API
     * @return float Price with commission
     */
    public function applyCommission(float $base_price): float;

    /**
     * Remove commission from price (get base price)
     *
     * @param float $price_with_commission Price with commission
     * @return float Base price
     */
    public function removeCommission(float $price_with_commission): float;

    /**
     * Calculate total price for rooms
     *
     * @param array<string, mixed> $rooms_data Rooms with prices
     * @return float Total price
     */
    public function calculateTotal(array $rooms_data): float;

    /**
     * Calculate price per night
     *
     * @param float $total_price Total price
     * @param int $nights Number of nights
     * @return float Price per night
     */
    public function calculatePerNight(float $total_price, int $nights): float;

    /**
     * Calculate price per person per night
     *
     * @param float $total_price Total price
     * @param int $nights Number of nights
     * @param int $guests Number of guests
     * @return float Price per person per night
     */
    public function calculatePerPersonPerNight(float $total_price, int $nights, int $guests): float;

    /**
     * Get room price from API
     *
     * @param array<string, mixed> $params Price request parameters
     * @return array<string, mixed>|null Price data [price, base_price, availability]
     */
    public function getRoomPrice(array $params): ?array;

    /**
     * Get multiple room prices
     *
     * @param array<string, mixed> $rooms_params Array of room parameters
     * @return array Prices by room index
     */
    public function getMultipleRoomPrices(array $rooms_params): array;

    /**
     * Format price for display using CS-Cart currency settings
     *
     * @param float $price Price value
     * @param string|null $currency Currency code
     * @param bool $include_symbol Include currency symbol
     * @return string Formatted price
     */
    public function format(float $price, ?string $currency = null, bool $include_symbol = true): string;

    /**
     * Format price range using CS-Cart currency settings
     *
     * @param float $min_price Minimum price
     * @param float $max_price Maximum price
     * @param string|null $currency Currency code
     * @return string Formatted range
     */
    public function formatRange(float $min_price, float $max_price, ?string $currency = null): string;

    /**
     * Compare prices and get savings
     *
     * @param float $original_price Original/higher price
     * @param float $discounted_price Discounted price
     * @return array Savings info [amount, percentage]
     */
    public function calculateSavings(float $original_price, float $discounted_price): array;

    /**
     * Validate price
     *
     * @param float $price Price to validate
     * @param float $min_price Minimum acceptable price
     * @param float $max_price Maximum acceptable price
     * @return bool Is valid
     */
    public function validate(float $price, float $min_price = 0, float $max_price = 100000): bool;

    /**
     * Get commission info
     *
     * @return array Commission info
     */
    public function getCommissionInfo(): array;

    /**
     * Set commission (for testing)
     *
     * @param float $commission Commission percentage
     */
    public function setCommission(float $commission): void;
}

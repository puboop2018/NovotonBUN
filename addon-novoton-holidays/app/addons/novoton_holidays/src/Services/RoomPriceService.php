<?php

declare(strict_types=1);

/**
 * Novoton Room Price Service
 *
 * Handles real-time room price calculations, commission application, and currency formatting.
 *
 * @package NovotonHolidays
 * @since 2.7.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Api\Contracts\PricingApiClientInterface;
use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\NovotonApi;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

class RoomPriceService implements RoomPriceServiceInterface
{
    private float $commission;
    private string $currency;
    private CacheServiceInterface $cache;
    private bool $debug = false;

    /** @var PricingApiClientInterface Pricing sub-client (lazy-fallback wired) */
    private readonly PricingApiClientInterface $pricing;

    /**
     * RoomPriceService only calls getRoomPrice() on the pricing sub-client,
     * so the dependency is narrowed to PricingApiClientInterface rather
     * than the whole NovotonApiKitInterface.
     *
     * The lazy fallback `(new NovotonApi())->pricing()` keeps the
     * zero-argument construction path working so existing callsites
     * that do `new RoomPriceService()` don't break — same pattern as
     * CronService and AlternativeRequestService from earlier waves.
     */
    public function __construct(
        ?PricingApiClientInterface $pricing = null,
        ?CacheServiceInterface $cache = null,
    ) {
        $this->commission = ConfigProvider::getCommission();
        $this->currency = ConfigProvider::getApiCurrency();
        $this->cache = $cache ?? new CacheService();
        $this->debug = ConfigProvider::isDebugLogging();
        $this->pricing = $pricing ?? (new NovotonApi())->pricing();
    }

    /**
     * Apply commission to base price.
     *
     * Respects the round_prices addon setting: when enabled, rounds to
     * nearest integer (same as CommissionCalculator::apply). Otherwise
     * rounds to 2 decimal places.
     *
     * @param float $base_price Base price from API
     * @return float Price with commission
     */
    public function applyCommission(float $base_price): float
    {
        if ($this->commission <= 0) {
            return $base_price;
        }

        $price = $base_price * (1 + ($this->commission / 100));

        return ConfigProvider::isRoundPrices() ? round($price) : round($price, 2);
    }

    /**
     * Remove commission from price (get base price)
     *
     * @param float $price_with_commission Price with commission
     * @return float Base price
     */
    public function removeCommission(float $price_with_commission): float
    {
        if ($this->commission <= 0) {
            return $price_with_commission;
        }

        $divisor = 1 + ($this->commission / 100);
        if (abs($divisor) < 0.0001) {
            return $price_with_commission;
        }

        return round($price_with_commission / $divisor, 2);
    }

    /**
     * Calculate total price for rooms
     *
     * @param array<string, mixed> $rooms_data Rooms with prices
     * @return float Total price
     */
    public function calculateTotal(array $rooms_data): float
    {
        $total = 0;

        foreach ($rooms_data as $room) {
            $total += TypeCoerce::toFloat(TypeCoerce::toStringMap($room)['price'] ?? 0);
        }

        return round($total, 2);
    }

    /**
     * Calculate price per night
     *
     * @param float $total_price Total price
     * @param int $nights Number of nights
     * @return float Price per night
     */
    public function calculatePerNight(float $total_price, int $nights): float
    {
        if ($nights <= 0) {
            return $total_price;
        }

        return round($total_price / $nights, 2);
    }

    /**
     * Calculate price per person per night
     *
     * @param float $total_price Total price
     * @param int $nights Number of nights
     * @param int $guests Number of guests
     * @return float Price per person per night
     */
    public function calculatePerPersonPerNight(float $total_price, int $nights, int $guests): float
    {
        if ($nights <= 0 || $guests <= 0) {
            return $total_price;
        }

        return round($total_price / $nights / $guests, 2);
    }

    /**
     * Get room price from API
     *
     * @param array<string, mixed> $params Price request parameters
     * @return array<string, mixed>|null Price data [price, base_price, availability]
     */
    public function getRoomPrice(array $params): ?array
    {
        // Check cache first
        $cache_key = $this->buildCacheKey($params);
        $cached = $this->cache->get($cache_key);

        if ($cached !== null) {
            $this->log('Price cache hit', ['key' => $cache_key]);
            return TypeCoerce::toStringMap($cached);
        }

        // Call API via the injected pricing sub-client.
        $response = $this->pricing->getRoomPrice($params);

        if (!($response instanceof \SimpleXMLElement) || !isset($response->Price)) {
            return null;
        }

        $base_price = (float) (string)$response->Price;
        $price_with_commission = $this->applyCommission($base_price);

        $result = [
            'base_price' => $base_price,
            'price' => $price_with_commission,
            'commission' => $this->commission,
            'commission_amount' => $price_with_commission - $base_price,
            'availability' => Constants::normalizeApiStatus((string)($response->Status ?? 'OK')),
            'currency' => $this->currency,
        ];

        // Cache for 10 minutes
        $this->cache->set($cache_key, $result, 600);

        return $result;
    }

    /**
     * Get multiple room prices
     *
     * @param array<string, mixed> $rooms_params Array of room parameters
     * @return array<string, mixed> Prices by room index
     */
    public function getMultipleRoomPrices(array $rooms_params): array
    {
        $prices = [];

        foreach ($rooms_params as $index => $params) {
            $price_data = $this->getRoomPrice(TypeCoerce::toStringMap($params));
            $prices[$index] = $price_data;
        }

        return $prices;
    }

    /**
     * Format price for display using CS-Cart currency settings
     *
     * @param float $price Price value
     * @param string|null $currency Currency code
     * @param bool $include_symbol Include currency symbol
     * @return string Formatted price
     */
    public function format(float $price, ?string $currency = null, bool $include_symbol = true): string
    {
        $currency_code = $currency ?? $this->currency;

        // Try to use CS-Cart's currency settings
        $currencies = ConfigProvider::getCurrencies();

        if (!empty($currencies[$currency_code])) {
            $curr = TypeCoerce::toStringMap($currencies[$currency_code]);
            $decimals = isset($curr['decimals']) ? TypeCoerce::toInt($curr['decimals']) : 2;
            $dec_sign = isset($curr['decimals_separator']) && is_string($curr['decimals_separator']) ? $curr['decimals_separator'] : ',';
            $ths_sign = isset($curr['thousands_separator']) && is_string($curr['thousands_separator']) ? $curr['thousands_separator'] : '.';

            $formatted = number_format($price, $decimals, $dec_sign, $ths_sign);

            if (!$include_symbol) {
                return $formatted;
            }

            $symbol = isset($curr['symbol']) && is_string($curr['symbol']) ? $curr['symbol'] : $currency_code;
            $after = !empty($curr['after']) && $curr['after'] === 'Y';

            return $after ? $formatted . ' ' . $symbol : $symbol . ' ' . $formatted;
        }

        // Fallback if CS-Cart currencies not available
        $formatted = number_format($price, 2, ',', '.');

        if (!$include_symbol) {
            return $formatted;
        }

        return $formatted . ' ' . $currency_code;
    }

    /**
     * Format price range using CS-Cart currency settings
     *
     * @param float $min_price Minimum price
     * @param float $max_price Maximum price
     * @param string|null $currency Currency code
     * @return string Formatted range
     */
    public function formatRange(float $min_price, float $max_price, ?string $currency = null): string
    {
        if ($min_price === $max_price) {
            return $this->format($min_price, $currency);
        }

        $currency_code = $currency ?? $this->currency;

        // Try to use CS-Cart's currency settings
        $currencies = ConfigProvider::getCurrencies();

        if (!empty($currencies[$currency_code])) {
            $curr = TypeCoerce::toStringMap($currencies[$currency_code]);
            $decimals = isset($curr['decimals']) ? TypeCoerce::toInt($curr['decimals']) : 2;
            $dec_sign = isset($curr['decimals_separator']) && is_string($curr['decimals_separator']) ? $curr['decimals_separator'] : ',';
            $ths_sign = isset($curr['thousands_separator']) && is_string($curr['thousands_separator']) ? $curr['thousands_separator'] : '.';
            $symbol = isset($curr['symbol']) && is_string($curr['symbol']) ? $curr['symbol'] : $currency_code;
            $after = !empty($curr['after']) && $curr['after'] === 'Y';

            $min_formatted = number_format($min_price, $decimals, $dec_sign, $ths_sign);
            $max_formatted = number_format($max_price, $decimals, $dec_sign, $ths_sign);

            $range = $min_formatted . ' - ' . $max_formatted;

            return $after ? $range . ' ' . $symbol : $symbol . ' ' . $range;
        }

        // Fallback
        return number_format($min_price, 2, ',', '.') . ' - ' .
               number_format($max_price, 2, ',', '.') . ' ' . $currency_code;
    }

    /**
     * Compare prices and get savings
     *
     * @param float $original_price Original/higher price
     * @param float $discounted_price Discounted price
     * @return array<string, mixed> Savings info [amount, percentage]
     */
    public function calculateSavings(float $original_price, float $discounted_price): array
    {
        if ($original_price <= 0 || $discounted_price >= $original_price) {
            return [
                'amount' => 0,
                'percentage' => 0,
            ];
        }

        $savings = $original_price - $discounted_price;
        $percentage = ($savings / $original_price) * 100;

        return [
            'amount' => round($savings, 2),
            'percentage' => round($percentage, 1),
        ];
    }

    /**
     * Validate price
     *
     * @param float $price Price to validate
     * @param float $min_price Minimum acceptable price
     * @param float $max_price Maximum acceptable price
     * @return bool Is valid
     */
    public function validate(float $price, float $min_price = 0, float $max_price = 100000): bool
    {
        return $price >= $min_price && $price <= $max_price;
    }

    /**
     * Get commission info
     *
     * @return array<string, mixed> Commission info
     */
    public function getCommissionInfo(): array
    {
        return [
            'percentage' => $this->commission,
            'multiplier' => 1 + ($this->commission / 100),
        ];
    }

    /**
     * Set commission (for testing)
     *
     * @param float $commission Commission percentage
     */
    public function setCommission(float $commission): void
    {
        $this->commission = $commission;
    }

    /**
     * Build cache key for price request
     *
     * @param array<string, mixed> $params Request parameters
     * @return string Cache key
     */
    private function buildCacheKey(array $params): string
    {
        $key_parts = [
            $params['hotel_id'] ?? '',
            $params['room_id'] ?? '',
            $params['board_id'] ?? '',
            $params['check_in'] ?? '',
            $params['check_out'] ?? '',
            $params['adults'] ?? 2,
            json_encode($params['children'] ?? []),
        ];

        return 'nvt_price_' . md5(implode('|', TypeCoerce::toStringList($key_parts)));
    }

    /**
     * Log debug message
     *
     * @param string $message Message
     * @param array<string, mixed> $context Context
     */
    private function log(string $message, array $context = []): void
    {
        if ($this->debug) {
            fn_log_event('general', 'runtime', array_merge(
                ['message' => 'NovotonPrice: ' . $message],
                $context,
            ));
        }
    }
}

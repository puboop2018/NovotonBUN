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

class RoomPriceService
{
    /** @var float Commission percentage */
    private $commission;
    
    /** @var string Default currency */
    private $currency;
    
    /** @var CacheServiceInterface */
    private $cache;
    
    /** @var bool Debug mode */
    private $debug = false;
    
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->commission = ConfigProvider::getCommission();
        $this->currency = ConfigProvider::getApiCurrency();
        $this->cache = new CacheService();
        $this->debug = ConfigProvider::isDebugLogging();
    }
    
    /**
     * Get the current display currency code.
     * Returns CART_SECONDARY_CURRENCY (user's selected) or CART_PRIMARY_CURRENCY.
     *
     * @return string Currency code (e.g. 'USD', 'EUR', 'RON')
     */
    public static function getDisplayCurrency(): string
    {
        if (defined('CART_SECONDARY_CURRENCY')) {
            return CART_SECONDARY_CURRENCY;
        }
        if (defined('CART_PRIMARY_CURRENCY')) {
            return CART_PRIMARY_CURRENCY;
        }
        return 'EUR';
    }

    /**
     * Get the source currency that the Novoton API returns prices in.
     * Reads from addon settings ("API prices currency"), defaults to EUR.
     *
     * @return string Currency code (e.g. 'EUR', 'USD')
     */
    public static function getApiCurrency(): string
    {
        return ConfigProvider::getApiCurrency();
    }

    /**
     * Convert a price from the API currency to the CS-Cart display currency.
     *
     * Uses CS-Cart's currency coefficients from the currencies table.
     * In CS-Cart, coefficient converts from primary currency to that currency:
     *   amount_in_currency = amount_in_primary * coefficient
     *
     * To convert from source to target:
     *   target_price = source_price * (target_coefficient / source_coefficient)
     *
     * @param float $api_price Price from Novoton API (in api_currency)
     * @param string|null $target_currency Target currency code (null = display currency)
     * @return float Converted price
     */
    public static function convertFromApiCurrency(float $api_price, ?string $target_currency = null): float
    {
        $source = self::getApiCurrency();
        $target = $target_currency ?? self::getDisplayCurrency();

        if ($source === $target) {
            return $api_price;
        }

        $currencies = \Tygh\Registry::get('currencies');
        if (empty($currencies)) {
            if (function_exists('fn_get_currencies')) {
                $currencies = fn_get_currencies();
            }
            if (empty($currencies)) {
                return $api_price;
            }
        }

        // Get source (API) coefficient (1.0 if it is the primary currency)
        $source_coefficient = 1.0;
        if (isset($currencies[$source]['coefficient'])) {
            $source_coefficient = floatval($currencies[$source]['coefficient']);
        }

        // Get target (display) coefficient (1.0 if it is the primary currency)
        $target_coefficient = 1.0;
        if (isset($currencies[$target]['coefficient'])) {
            $target_coefficient = floatval($currencies[$target]['coefficient']);
        }

        // Guard against invalid coefficient
        if ($source_coefficient <= 0) {
            $source_coefficient = 1.0;
        }

        return round($api_price / $source_coefficient * $target_coefficient, 2);
    }

    /**
     * Convert all price fields in a search results array from API currency to display currency.
     *
     * @param array $results Search results array
     * @return array Results with converted prices
     */
    public static function convertResultsCurrency(array $results): array
    {
        $source = self::getApiCurrency();
        $display = self::getDisplayCurrency();
        if ($source === $display) {
            return $results;
        }

        foreach ($results as &$result) {
            if (isset($result['total_price'])) {
                $result['total_price'] = self::convertFromApiCurrency(floatval($result['total_price']));
            }
            if (isset($result['price_per_night'])) {
                $result['price_per_night'] = self::convertFromApiCurrency(floatval($result['price_per_night']));
            }
        }
        unset($result);

        return $results;
    }

    /**
     * Apply commission to base price
     *
     * @param float $base_price Base price from API
     * @return float Price with commission
     */
    public function applyCommission(float $base_price): float
    {
        if ($this->commission <= 0) {
            return $base_price;
        }
        
        return round($base_price * (1 + ($this->commission / 100)), 2);
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
     * @param array $rooms_data Rooms with prices
     * @return float Total price
     */
    public function calculateTotal(array $rooms_data): float
    {
        $total = 0;
        
        foreach ($rooms_data as $room) {
            $total += floatval($room['price'] ?? 0);
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
     * @param array $params Price request parameters
     * @return array|null Price data [price, base_price, availability]
     */
    public function getRoomPrice(array $params): ?array
    {
        // Check cache first
        $cache_key = $this->buildCacheKey($params);
        $cached = $this->cache->get($cache_key);
        
        if ($cached !== null) {
            $this->log('Price cache hit', ['key' => $cache_key]);
            return $cached;
        }
        
        // Call API
        $api = fn_novoton_get_api();
        $response = $api->getRoomPrice($params);
        
        if (!$response || !isset($response->Price)) {
            return null;
        }
        
        $base_price = floatval((string)$response->Price);
        $price_with_commission = $this->applyCommission($base_price);
        
        $result = [
            'base_price' => $base_price,
            'price' => $price_with_commission,
            'commission' => $this->commission,
            'commission_amount' => $price_with_commission - $base_price,
            'availability' => (string)($response->Status ?? 'OK'),
            'currency' => $this->currency,
        ];
        
        // Cache for 10 minutes
        $this->cache->set($cache_key, $result, 600);
        
        return $result;
    }
    
    /**
     * Get multiple room prices
     * 
     * @param array $rooms_params Array of room parameters
     * @return array Prices by room index
     */
    public function getMultipleRoomPrices(array $rooms_params): array
    {
        $prices = [];
        
        foreach ($rooms_params as $index => $params) {
            $price_data = $this->getRoomPrice($params);
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
        $currencies = \Tygh\Registry::get('currencies');
        
        if (!empty($currencies[$currency_code])) {
            $curr = $currencies[$currency_code];
            $decimals = isset($curr['decimals']) ? (int)$curr['decimals'] : 2;
            $dec_sign = $curr['decimals_separator'] ?? ',';
            $ths_sign = $curr['thousands_separator'] ?? '.';
            
            $formatted = number_format($price, $decimals, $dec_sign, $ths_sign);
            
            if (!$include_symbol) {
                return $formatted;
            }
            
            $symbol = $curr['symbol'] ?? $currency_code;
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
        $currencies = \Tygh\Registry::get('currencies');
        
        if (!empty($currencies[$currency_code])) {
            $curr = $currencies[$currency_code];
            $decimals = isset($curr['decimals']) ? (int)$curr['decimals'] : 2;
            $dec_sign = $curr['decimals_separator'] ?? ',';
            $ths_sign = $curr['thousands_separator'] ?? '.';
            $symbol = $curr['symbol'] ?? $currency_code;
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
     * @return array Savings info [amount, percentage]
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
     * @return array Commission info
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
     * @param array $params Request parameters
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
        
        return 'nvt_price_' . md5(implode('|', $key_parts));
    }
    
    /**
     * Log debug message
     * 
     * @param string $message Message
     * @param array $context Context
     */
    private function log(string $message, array $context = []): void
    {
        if ($this->debug) {
            fn_log_event('general', 'runtime', array_merge(
                ['message' => 'NovotonPrice: ' . $message],
                $context
            ));
        }
    }
}

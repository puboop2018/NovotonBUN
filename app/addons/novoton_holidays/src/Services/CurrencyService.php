<?php
declare(strict_types=1);
/**
 * Novoton Currency Service
 *
 * Handles currency resolution and conversion between the Novoton API currency
 * (EUR) and the CS-Cart display currency. Used by both PriceInfoService
 * (static calendar prices) and RoomPriceService (real-time booking prices).
 *
 * @package NovotonHolidays
 * @since 3.4.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

class CurrencyService
{
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
            $source_coefficient = (float) $currencies[$source]['coefficient'];
        }

        // Get target (display) coefficient (1.0 if it is the primary currency)
        $target_coefficient = 1.0;
        if (isset($currencies[$target]['coefficient'])) {
            $target_coefficient = (float) $currencies[$target]['coefficient'];
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
                $result['total_price'] = self::convertFromApiCurrency((float) $result['total_price']);
            }
            if (isset($result['price_per_night'])) {
                $result['price_per_night'] = self::convertFromApiCurrency((float) $result['price_per_night']);
            }
        }
        unset($result);

        return $results;
    }
}

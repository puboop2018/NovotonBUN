<?php

declare(strict_types=1);

/**
 * Travel Core Currency Service
 *
 * Handles currency resolution and conversion between provider API currencies
 * and the CS-Cart display currency. Provider-agnostic: the API currency is
 * passed as a constructor parameter rather than read from a specific addon.
 *
 * @package TravelCore
 * @since   1.0.0
 */

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Contracts\CurrencyServiceInterface;

class CurrencyService implements CurrencyServiceInterface
{
    /**
     * @param string $apiCurrency Currency code the API uses (e.g. 'EUR', 'USD')
     */
    public function __construct(
        private readonly string $apiCurrency = 'EUR',
    ) {
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
     * Get the API currency this service was constructed with.
     *
     * @return string Currency code
     */
    #[\Override]
    public function getApiCurrency(): string
    {
        return $this->apiCurrency;
    }

    /**
     * Convert a price from the API currency to a target currency.
     *
     * Uses CS-Cart's currency coefficients from the currencies table.
     * In CS-Cart, coefficient converts from primary currency to that currency:
     *   amount_in_currency = amount_in_primary * coefficient
     *
     * To convert from source to target:
     *   target_price = source_price * (target_coefficient / source_coefficient)
     *
     * @param float $apiPrice Price from API (in api_currency)
     * @param string|null $targetCurrency Target currency code (null = display currency)
     * @return float Converted price
     */
    #[\Override]
    public function convertFromApiCurrency(float $apiPrice, ?string $targetCurrency = null): float
    {
        $source = $this->apiCurrency;
        $target = $targetCurrency ?? self::getDisplayCurrency();

        if ($source === $target) {
            return $apiPrice;
        }

        $currencies = \Tygh\Registry::get('currencies');
        if (empty($currencies)) {
            if (function_exists('fn_get_currencies')) {
                $currencies = fn_get_currencies();
            }
            if (empty($currencies)) {
                return $apiPrice;
            }
        }

        $sourceCoefficient = 1.0;
        if (isset($currencies[$source]['coefficient'])) {
            $sourceCoefficient = (float)$currencies[$source]['coefficient'];
        }

        $targetCoefficient = 1.0;
        if (isset($currencies[$target]['coefficient'])) {
            $targetCoefficient = (float)$currencies[$target]['coefficient'];
        }

        if ($sourceCoefficient <= 0) {
            $sourceCoefficient = 1.0;
        }

        return round($apiPrice / $sourceCoefficient * $targetCoefficient, 2);
    }

    /**
     * Convert all price fields in a search results array from API currency to display currency.
     *
     * @param array<string, mixed> $results Search results array
     * @return array<string, mixed> Results with converted prices
     */
    #[\Override]
    public function convertResultsCurrency(array $results): array
    {
        $source = $this->apiCurrency;
        $display = self::getDisplayCurrency();
        if ($source === $display) {
            return $results;
        }

        foreach ($results as &$result) {
            if (isset($result['total_price'])) {
                $result['total_price'] = $this->convertFromApiCurrency((float)$result['total_price']);
            }
            if (isset($result['price_per_night'])) {
                $result['price_per_night'] = $this->convertFromApiCurrency((float)$result['price_per_night']);
            }
        }
        unset($result);

        return $results;
    }
}

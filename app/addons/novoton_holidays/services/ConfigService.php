<?php
/**
 * Novoton Holidays - Typed Configuration Service
 *
 * Wraps Registry::get('addons.novoton_holidays') with proper type coercion.
 * All addon setting access should go through this class.
 *
 * @package NovotonHolidays
 * @since 3.2.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Registry;

class ConfigService
{
    /** @var array|null Cached settings array, loaded once per request. */
    private static $settings;

    private static function settings(): array
    {
        if (self::$settings === null) {
            self::$settings = Registry::get('addons.novoton_holidays') ?? [];
        }
        return self::$settings;
    }

    /**
     * Reset cached settings (useful after settings are updated mid-request).
     */
    public static function reset(): void
    {
        self::$settings = null;
    }

    // ========== Boolean Settings ==========

    public static function isDebugMode(): bool
    {
        return (self::settings()['debug_mode'] ?? 'N') === 'Y';
    }

    public static function isDebugLogging(): bool
    {
        return (self::settings()['debug_logging'] ?? 'Y') === 'Y';
    }

    public static function isApiDisabled(): bool
    {
        return (self::settings()['disable_api_submission'] ?? 'N') === 'Y';
    }

    public static function isRoundPrices(): bool
    {
        $val = self::settings()['round_prices'] ?? 'Y';
        return $val === 'Y' || $val === true;
    }

    public static function isTestBooking(): bool
    {
        return (self::settings()['test_booking'] ?? 'N') === 'Y';
    }

    public static function isDeleteProductsOnUninstall(): bool
    {
        return (self::settings()['delete_products_on_uninstall'] ?? 'N') === 'Y';
    }

    // ========== Float Settings ==========

    public static function getCommission(): float
    {
        return floatval(self::settings()['commission'] ?? 0);
    }

    public static function getCurrencyRiskCommission(): float
    {
        return max(0.0, floatval(self::settings()['currency_risk_commission'] ?? 0));
    }

    // ========== String Settings ==========

    /**
     * Get the currency that the Novoton API returns prices in.
     * Configurable in addon settings; defaults to EUR.
     */
    public static function getApiCurrency(): string
    {
        $val = (string)(self::settings()['api_currency'] ?? 'EUR');
        return $val !== '' ? $val : 'EUR';
    }

    public static function getApiUrl(): string
    {
        return (string)(self::settings()['api_url'] ?? '');
    }

    public static function getApiUser(): string
    {
        return (string)(self::settings()['api_user'] ?? '');
    }

    public static function getApiPassword(): string
    {
        return (string)(self::settings()['api_password'] ?? '');
    }

    public static function getApiKey(): string
    {
        return (string)(self::settings()['api_key'] ?? '');
    }

    public static function getCronAccessKey(): string
    {
        return (string)(self::settings()['cron_access_key'] ?? '');
    }

    public static function getDefaultCountry(): string
    {
        return (string)(self::settings()['default_country'] ?? 'BULGARIA');
    }

    public static function getLastExchangeRateUpdate(): string
    {
        return (string)(self::settings()['last_exchange_rate_update'] ?? '');
    }

    public static function getVersion(): string
    {
        return (string)(self::settings()['version'] ?? 'unknown');
    }

    // ========== Array Settings ==========

    /**
     * @return string[] Parsed list of selected country names.
     */
    public static function getSelectedCountries(): array
    {
        $val = self::settings()['selected_countries'] ?? '';
        if (is_array($val)) {
            return $val;
        }
        return $val !== '' ? array_map('trim', explode(',', $val)) : [];
    }

    /**
     * @return string[] All configured product code prefixes (e.g. ['NVT']).
     */
    public static function getProductCodePrefixes(): array
    {
        $val = self::settings()['product_code_prefixes'] ?? 'NVT';
        return array_map('trim', explode(',', $val));
    }

    /**
     * @return string The first (primary) product code prefix.
     */
    public static function getFirstProductCodePrefix(): string
    {
        return self::getProductCodePrefixes()[0] ?? 'NVT';
    }

    /**
     * @return string[] Excluded resort names.
     */
    public static function getExcludedResorts(): array
    {
        $val = self::settings()['excluded_resorts'] ?? '';
        return $val !== '' ? array_map('trim', explode(',', $val)) : [];
    }

    // ========== Raw Settings Access ==========

    /**
     * Return the full settings array (for passing to components that need it).
     */
    public static function all(): array
    {
        return self::settings();
    }

    /**
     * Get a single raw setting value by key.
     *
     * @param string $key     Setting key name
     * @param mixed  $default Default if not set
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        return self::settings()[$key] ?? $default;
    }
}

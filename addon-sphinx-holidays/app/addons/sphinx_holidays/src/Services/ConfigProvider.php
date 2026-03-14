<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Registry;

/**
 * Sphinx Holidays configuration provider.
 *
 * Centralizes access to all addon settings with type-safe getters
 * and sensible defaults.
 */
class ConfigProvider
{
    private const ADDON_ID = 'sphinx_holidays';

    public static function getApiBaseUrl(): string
    {
        return rtrim((string) self::getSetting('api_base_url'), '/');
    }

    public static function getApiKey(): string
    {
        return (string) self::getSetting('api_key');
    }

    public static function isApiCacheEnabled(): bool
    {
        return self::getSetting('enable_api_cache') === 'Y';
    }

    public static function getCacheTtlSearch(): int
    {
        return max(0, (int) self::getSetting('cache_ttl_search', 300));
    }

    public static function getDefaultCurrency(): string
    {
        return (string) self::getSetting('default_currency', 'EUR');
    }

    public static function getIgnoreDomains(): string
    {
        return (string) self::getSetting('ignore_domains', '');
    }

    public static function getSearchPollInterval(): int
    {
        return max(1, (int) self::getSetting('search_poll_interval', 2));
    }

    public static function getSearchMaxPolls(): int
    {
        return max(1, (int) self::getSetting('search_max_polls', 30));
    }

    public static function getCommission(): float
    {
        return (float) self::getSetting('commission', 0);
    }

    public static function shouldRoundPrices(): bool
    {
        return self::getSetting('round_prices') === 'Y';
    }

    public static function getHotelsCategoryId(): int
    {
        return (int) self::getSetting('hotels_category_id', 0);
    }

    public static function getPackagesCategoryId(): int
    {
        return (int) self::getSetting('packages_category_id', 0);
    }

    public static function getMaxRetries(): int
    {
        return max(0, (int) self::getSetting('api_max_retries', 3));
    }

    public static function getRetryDelayMs(): int
    {
        return max(0, (int) self::getSetting('api_retry_delay_ms', 500));
    }

    public static function getRetryMultiplier(): float
    {
        return max(1.0, (float) self::getSetting('api_retry_multiplier', 2));
    }

    public static function getCircuitBreakerThreshold(): int
    {
        return max(1, (int) self::getSetting('circuit_breaker_threshold', 5));
    }

    public static function getCircuitBreakerTimeout(): int
    {
        return max(1, (int) self::getSetting('circuit_breaker_timeout', 60));
    }

    public static function isDebugLogging(): bool
    {
        return self::getSetting('debug_logging') === 'Y';
    }

    public static function getCronAccessKey(): string
    {
        return (string) self::getSetting('cron_access_key');
    }

    /**
     * Check if the addon is properly configured (has API key).
     */
    public static function isConfigured(): bool
    {
        $key = self::getApiKey();
        $url = self::getApiBaseUrl();

        if (empty($key) || empty($url)) {
            return false;
        }

        // Sphinx keys have format: digits|alphanumeric (e.g. 51|q3s6ZrK7...)
        if (!preg_match('/^\d+\|[a-zA-Z0-9]+$/', $key)) {
            return false;
        }

        if (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with($url, 'https://')) {
            return false;
        }

        return true;
    }

    private static function getSetting(string $key, mixed $default = ''): mixed
    {
        $value = Registry::get('addons.' . self::ADDON_ID . '.' . $key);
        return $value !== null ? $value : $default;
    }
}

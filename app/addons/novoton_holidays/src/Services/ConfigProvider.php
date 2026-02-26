<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Configuration Provider
 *
 * Read-only access to addon settings from Registry with typed getters.
 * Single Responsibility: settings retrieval and type coercion only.
 *
 * @package NovotonHolidays
 * @since 3.3.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Registry;
use Tygh\Addons\NovotonHolidays\Constants;

class ConfigProvider
{
    public const ADDON_ID = 'novoton_holidays';

    // API rate limiting
    public const API_DELAY_MS = 100;

    // Batch processing bounds (for clamping admin input)
    public const MIN_BATCH_SIZE = 10;
    public const MAX_BATCH_SIZE = 500;
    public const MIN_EXECUTION_TIME = 60;
    public const MAX_EXECUTION_TIME = 3600;

    // Image settings
    public const IMAGE_BASE_URL = 'https://booking.allinclusive.bg';
    public const MAX_IMAGES_PER_HOTEL = 10;

    // Product code prefix
    public const PRODUCT_CODE_PREFIX = 'NVT';

    // Stale threshold for incremental sync (hours)
    public const STALE_HOURS = 24;

    /** @var array|null Cached settings array, loaded once per request. */
    private static $settings;

    /** @var string|null Cached addon version, loaded once per request. */
    private static $version;

    public static function settings(): array
    {
        if (self::$settings === null) {
            self::$settings = Registry::get('addons.novoton_holidays') ?? [];
        }
        return self::$settings;
    }

    public static function reset(): void
    {
        self::$settings = null;
        self::$version = null;
    }

    // ── Boolean Settings ──

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

    public static function isShowCalendarPrices(): bool
    {
        return (self::settings()['show_calendar_prices'] ?? 'Y') === 'Y';
    }

    // ── Float Settings ──

    public static function getCommission(): float
    {
        return max(0.0, (float) (self::settings()['commission'] ?? 0));
    }

    public static function getCurrencyRiskCommission(): float
    {
        return max(0.0, (float) (self::settings()['currency_risk_commission'] ?? 0));
    }

    // ── String Settings ──

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
        if (self::$version === null) {
            self::$version = (string) db_get_field(
                "SELECT version FROM ?:addons WHERE addon = ?s",
                self::ADDON_ID
            ) ?: 'unknown';
        }

        return self::$version;
    }

    // ── Array Settings ──

    /** @return string[] */
    public static function getSelectedCountries(): array
    {
        $val = self::settings()['selected_countries'] ?? '';
        $countries = [];

        // CS-Cart stores "multiple checkboxes" as ['KEY' => 'Y', 'KEY2' => 'N', ...]
        if (is_array($val)) {
            foreach ($val as $key => $enabled) {
                if ($enabled === 'Y') {
                    $countries[] = (string) $key;
                }
            }
        } elseif ($val !== '' && is_string($val)) {
            $countries = array_filter(array_map('trim', explode(',', $val)));
        }

        // Fallback: if nothing selected, return all available countries
        if (empty($countries)) {
            return Constants::COUNTRIES;
        }

        return $countries;
    }

    /** @return string[] */
    public static function getProductCodePrefixes(): array
    {
        $val = self::settings()['product_code_prefixes'] ?? 'NVT';
        return array_map('trim', explode(',', $val));
    }

    public static function getFirstProductCodePrefix(): string
    {
        return self::getProductCodePrefixes()[0] ?? 'NVT';
    }

    /**
     * Get the configured category ID for a country.
     *
     * Reads the country_category_map setting (one "COUNTRY:id" per line).
     * Returns the mapped category_id, or 0 if no mapping exists.
     */
    public static function getCategoryForCountry(string $country): int
    {
        $raw = trim((string)(self::settings()['country_category_map'] ?? ''));
        if ($raw === '') {
            return 0;
        }

        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, ':') === false) {
                continue;
            }
            [$key, $value] = explode(':', $line, 2);
            if (strtoupper(trim($key)) === strtoupper($country)) {
                $id = (int) trim($value);
                return $id > 0 ? $id : 0;
            }
        }

        return 0;
    }

    /** @return string[] */
    public static function getExcludedResorts(): array
    {
        $val = self::settings()['excluded_resorts'] ?? '';
        return $val !== '' ? array_map('trim', explode(',', $val)) : [];
    }

    // ── Advanced Settings (Cache, Sync, Rate Limits) ──

    public static function getCacheTtlRoomPrice(): int
    {
        return max(0, (int)(self::settings()['cache_ttl_room_price'] ?? 300));
    }

    public static function getCacheTtlAvailability(): int
    {
        return max(0, (int)(self::settings()['cache_ttl_availability'] ?? 180));
    }

    public static function getCacheTtlSearch(): int
    {
        return max(0, (int)(self::settings()['cache_ttl_search'] ?? 300));
    }

    /** Returns the hotel info full sync interval in seconds. */
    public static function getSyncIntervalHotelInfo(): int
    {
        $days = max(1, (int)(self::settings()['sync_interval_hotel_info_days'] ?? 180));
        return $days * 86400;
    }

    /** Returns the price info full sync interval in seconds. */
    public static function getSyncIntervalPriceInfo(): int
    {
        $days = max(1, (int)(self::settings()['sync_interval_price_info_days'] ?? 7));
        return $days * 86400;
    }

    /** Returns the facilities sync interval in seconds. */
    public static function getSyncIntervalFacilities(): int
    {
        $days = max(1, (int)(self::settings()['sync_interval_facilities_days'] ?? 30));
        return $days * 86400;
    }

    public static function getCronBatchSize(): int
    {
        $val = (int)(self::settings()['cron_batch_size'] ?? 100);
        return max(self::MIN_BATCH_SIZE, min(self::MAX_BATCH_SIZE, $val));
    }

    public static function getCronMaxExecutionTime(): int
    {
        $val = (int)(self::settings()['cron_max_execution_time'] ?? 300);
        return max(self::MIN_EXECUTION_TIME, min(self::MAX_EXECUTION_TIME, $val));
    }

    public static function getSlowItemWarningMs(): int
    {
        return max(1000, (int)(self::settings()['slow_item_warning_ms'] ?? 30000));
    }

    public static function getRateLimitRequestsPerMin(): int
    {
        return max(1, (int)(self::settings()['rate_limit_requests_per_min'] ?? 100));
    }

    public static function getRateLimitBookingsPerHour(): int
    {
        return max(1, (int)(self::settings()['rate_limit_bookings_per_hour'] ?? 20));
    }

    public static function getSpecialRequestsMaxLength(): int
    {
        return max(100, (int)(self::settings()['special_requests_max_length'] ?? 2000));
    }

    // ── Raw Access ──

    public static function all(): array
    {
        return self::settings();
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        return self::settings()[$key] ?? $default;
    }

    // ── Environment ──

    public static function getTimezone(): string
    {
        return Registry::get('settings.Appearance.timezone') ?: 'Europe/Bucharest';
    }

    public static function getAdminEmail(): string
    {
        $email = Registry::get('settings.Company.company_orders_email');

        if (empty($email)) {
            $email = Registry::get('settings.Company.company_site_administrator');
        }

        if (empty($email)) {
            $email = db_get_field(
                "SELECT email FROM ?:users WHERE user_type = 'A' AND status = 'A' ORDER BY user_id LIMIT 1"
            );
        }

        return $email ?: '';
    }

    public static function getCompanyId(): int
    {
        return (int) (Registry::get('runtime.company_id') ?: 1);
    }
}

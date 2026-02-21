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
    const ADDON_ID = 'novoton_holidays';

    // API rate limiting
    const API_DELAY_MS = 100;

    // Batch processing defaults
    const DEFAULT_BATCH_SIZE = 100;
    const DEFAULT_MAX_EXECUTION_TIME = 300;
    const MIN_BATCH_SIZE = 10;
    const MAX_BATCH_SIZE = 500;
    const MIN_EXECUTION_TIME = 60;
    const MAX_EXECUTION_TIME = 3600;

    // Image settings
    const IMAGE_BASE_URL = 'https://booking.allinclusive.bg';
    const MAX_IMAGES_PER_HOTEL = 10;

    // Product code prefix
    const PRODUCT_CODE_PREFIX = 'NVT';

    // Sync intervals
    const FULL_SYNC_INTERVAL_DAYS = 180;
    const PRICE_SYNC_INTERVAL_DAYS = 7;
    const STALE_HOURS = 24;

    /** @var array|null Cached settings array, loaded once per request. */
    private static $settings;

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
        return (string)(self::settings()['version'] ?? 'unknown');
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

    /** @return string[] */
    public static function getExcludedResorts(): array
    {
        $val = self::settings()['excluded_resorts'] ?? '';
        return $val !== '' ? array_map('trim', explode(',', $val)) : [];
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

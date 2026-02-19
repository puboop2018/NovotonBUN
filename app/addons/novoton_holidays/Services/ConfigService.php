<?php
declare(strict_types=1);
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
    // ========== Constants (migrated from Helpers\Config) ==========

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

    /** @var array|null Cached paths array. */
    private static $paths;

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

    // ========== Paths ==========

    /**
     * Get all addon paths (cached).
     *
     * @return array
     */
    public static function getPaths(): array
    {
        if (self::$paths === null) {
            $addon_dir = Registry::get('config.dir.addons') . self::ADDON_ID . '/';
            $cache_dir = Registry::get('config.dir.cache_misc') ?? (defined('DIR_ROOT') ? DIR_ROOT . '/var/cache/' : '/tmp/');

            self::$paths = [
                'addon'     => $addon_dir,
                'src'       => $addon_dir . 'src/',
                'helpers'   => $addon_dir . 'Helpers/',
                'functions' => $addon_dir . 'functions/',
                'cache'     => $cache_dir . 'novoton/',
                'reports'   => function_exists('fn_get_files_dir_path')
                    ? fn_get_files_dir_path() . 'novoton_reports/'
                    : $addon_dir . 'reports/',
            ];
        }
        return self::$paths;
    }

    /**
     * Get a specific addon path.
     *
     * @param string $key Path key (addon, src, helpers, functions, cache, reports)
     * @return string
     */
    public static function getPath(string $key): string
    {
        $paths = self::getPaths();
        return $paths[$key] ?? '';
    }

    // ========== Environment ==========

    /**
     * Get timezone from CS-Cart settings.
     *
     * @return string
     */
    public static function getTimezone(): string
    {
        return Registry::get('settings.Appearance.timezone') ?: 'Europe/Bucharest';
    }

    /**
     * Get admin email for notifications.
     *
     * @return string
     */
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

    /**
     * Get current company ID.
     *
     * @return int
     */
    public static function getCompanyId(): int
    {
        return intval(Registry::get('runtime.company_id') ?: 1);
    }

    /**
     * Ensure cache directory exists.
     *
     * @return bool
     */
    public static function ensureCacheDir(): bool
    {
        $cache_dir = self::getPath('cache');

        if (!is_dir($cache_dir)) {
            return @mkdir($cache_dir, 0755, true);
        }

        return true;
    }

    /**
     * Ensure reports directory exists.
     *
     * @return bool
     */
    public static function ensureReportsDir(): bool
    {
        $reports_dir = self::getPath('reports');

        if (!is_dir($reports_dir)) {
            return function_exists('fn_mkdir') ? fn_mkdir($reports_dir) : @mkdir($reports_dir, 0755, true);
        }

        return true;
    }
}

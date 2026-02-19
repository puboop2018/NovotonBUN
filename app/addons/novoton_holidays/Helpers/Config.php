<?php
/**
 * Novoton Holidays - Configuration Manager
 *
 * @deprecated Use \Tygh\Addons\NovotonHolidays\Services\ConfigService instead.
 *             ConfigService provides the same settings access with proper type coercion,
 *             plus all constants, path helpers, and utility methods that were previously
 *             split across both classes. This class is kept for backwards compatibility
 *             but will be removed in a future release.
 *
 * @package NovotonHolidays
 * @since 3.1.0
 */

namespace Tygh\Addons\NovotonHolidays\Helpers;

use Tygh\Registry;

class Config
{
    /**
     * Configuration constants
     */

    // Addon paths
    const ADDON_ID = 'novoton_holidays';

    // Default countries supported by Novoton API
    const DEFAULT_COUNTRIES = [
        'ALBANIA',
        'BULGARIA',
        'CYPRUS',
        'EGYPT',
        'FRANCE',
        'GREECE',
        'ITALY',
        'MALDIVES',
        'SPAIN',
        'TURKEY',
        'UNITED ARAB EMIRATES',
        'UNITED KINGDOM',
    ];

    // Sync intervals (in seconds)
    const FULL_SYNC_INTERVAL_DAYS = 180;  // 6 months for hotel info
    const PRICE_SYNC_INTERVAL_DAYS = 7;   // 1 week for price info
    const STALE_HOURS = 24;               // Hours before data is considered stale

    // Batch processing defaults
    const DEFAULT_BATCH_SIZE = 100;
    const DEFAULT_MAX_EXECUTION_TIME = 300;  // 5 minutes
    const MIN_BATCH_SIZE = 10;
    const MAX_BATCH_SIZE = 500;
    const MIN_EXECUTION_TIME = 60;
    const MAX_EXECUTION_TIME = 3600;

    // API rate limiting
    const API_DELAY_MS = 100;  // Delay between API calls in milliseconds

    // Image settings
    const IMAGE_BASE_URL = 'https://booking.allinclusive.bg';
    const MAX_IMAGES_PER_HOTEL = 10;

    // Product code prefix
    const PRODUCT_CODE_PREFIX = 'NVT';

    /**
     * Singleton instance (replaceable for testing)
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Cached settings (instance-level, not static)
     * @var array|null
     */
    private ?array $settings = null;

    /**
     * Cached paths (instance-level, not static)
     * @var array|null
     */
    private ?array $paths = null;

    /**
     * Constructor allows injecting settings for testing.
     *
     * @param array|null $overrideSettings Pre-loaded settings (bypasses Registry)
     */
    public function __construct(?array $overrideSettings = null)
    {
        if ($overrideSettings !== null) {
            $this->settings = $overrideSettings;
        }
    }

    /**
     * Get the singleton instance (creates one if needed).
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Replace the singleton instance (for testing / DI).
     */
    public static function setInstance(?self $instance): void
    {
        self::$instance = $instance;
    }

    /**
     * Get addon settings (cached)
     *
     * @return array
     */
    public static function getSettings(): array
    {
        $self = self::getInstance();
        if ($self->settings === null) {
            $self->settings = Registry::get('addons.' . self::ADDON_ID) ?? [];
        }
        return $self->settings;
    }

    /**
     * Get a specific setting value
     *
     * @param string $key Setting key
     * @param mixed $default Default value if not set
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        $settings = self::getSettings();
        return $settings[$key] ?? $default;
    }

    /**
     * Get cron access key
     *
     * @return string
     */
    public static function getCronAccessKey(): string
    {
        return self::get('cron_access_key', '');
    }

    /**
     * Check if debug mode is enabled
     *
     * @return bool
     */
    public static function isDebugMode(): bool
    {
        if (!empty($_REQUEST['debug_novoton'])) {
            return true;
        }
        return self::get('debug_mode', 'N') === 'Y';
    }

    /**
     * Get commission percentage
     *
     * @return float
     */
    public static function getCommission(): float
    {
        return floatval(self::get('commission_percent', 0));
    }

    /**
     * Get selected countries from settings
     *
     * @return array
     */
    public static function getSelectedCountries(): array
    {
        $selected = self::get('selected_countries', '');
        $countries = [];

        if (is_array($selected)) {
            foreach ($selected as $key => $value) {
                if ($value === 'Y' || $value === '1' || $value === 1) {
                    $countries[] = strtoupper(trim($key));
                } elseif (is_string($value) && strlen($value) > 2) {
                    $countries[] = strtoupper(trim($value));
                }
            }
        } elseif (!empty($selected) && is_string($selected)) {
            $countries = array_map(function ($c) {
                return strtoupper(trim($c));
            }, explode(',', $selected));
        }

        $countries = array_filter($countries);

        // Return all countries if none selected
        return !empty($countries) ? $countries : self::DEFAULT_COUNTRIES;
    }

    /**
     * Get excluded resorts
     *
     * @return array
     */
    public static function getExcludedResorts(): array
    {
        $value = self::get('excluded_resorts', '[]');

        if (empty($value)) {
            return [];
        }

        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return array_filter($decoded);
        }

        // Try comma-separated (legacy format)
        return array_filter(array_map('trim', explode(',', $value)));
    }

    /**
     * Get addon paths (cached)
     *
     * @return array
     */
    public static function getPaths(): array
    {
        $self = self::getInstance();
        if ($self->paths === null) {
            $addon_dir = Registry::get('config.dir.addons') . self::ADDON_ID . '/';
            $cache_dir = Registry::get('config.dir.cache_misc') ?? (DIR_ROOT . '/var/cache/');

            $self->paths = [
                'addon' => $addon_dir,
                'src' => $addon_dir . 'src/',
                'helpers' => $addon_dir . 'Helpers/',
                'functions' => $addon_dir . 'functions/',
                'cache' => $cache_dir . 'novoton/',
                'reports' => fn_get_files_dir_path() . 'novoton_reports/',
            ];
        }
        return $self->paths;
    }

    /**
     * Get a specific path
     *
     * @param string $key Path key (addon, src, helpers, functions, cache, reports)
     * @return string
     */
    public static function getPath(string $key): string
    {
        $paths = self::getPaths();
        return $paths[$key] ?? '';
    }

    /**
     * Get timezone from CS-Cart settings
     *
     * @return string
     */
    public static function getTimezone(): string
    {
        return Registry::get('settings.Appearance.timezone') ?: 'Europe/Bucharest';
    }

    /**
     * Get admin email for notifications
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
     * Get current company ID
     *
     * @return int
     */
    public static function getCompanyId(): int
    {
        return intval(Registry::get('runtime.company_id') ?: 1);
    }

    /**
     * Clear cached settings (useful after settings update)
     */
    public static function clearCache(): void
    {
        $self = self::getInstance();
        $self->settings = null;
        $self->paths = null;
    }

    /**
     * Ensure cache directory exists
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
     * Ensure reports directory exists
     *
     * @return bool
     */
    public static function ensureReportsDir(): bool
    {
        $reports_dir = self::getPath('reports');

        if (!is_dir($reports_dir)) {
            return fn_mkdir($reports_dir);
        }

        return true;
    }
}

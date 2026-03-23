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

    /** @return array<string, string> Currency code => display symbol */
    public static function getCurrencySymbols(): array
    {
        return ['EUR' => '€', 'USD' => '$', 'GBP' => '£', 'RON' => 'lei', 'BGN' => 'лв'];
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

    public static function getCircuitsCategoryId(): int
    {
        return (int) self::getSetting('circuits_category_id', 0);
    }

    public static function getExperiencesCategoryId(): int
    {
        return (int) self::getSetting('experiences_category_id', 0);
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
     * Get the configured languages for hotel product descriptions.
     *
     * CS-Cart stores "multiple checkboxes" values as comma-separated string.
     * Returns array of lang_code strings (e.g. ['ro', 'en']).
     * Defaults to 'ro' if no setting configured.
     *
     * @return string[]
     */
    public static function getProductLanguages(): array
    {
        $value = (string) self::getSetting('product_languages', 'ro');
        if (empty($value)) {
            return [];
        }
        return array_filter(array_map('trim', explode(',', $value)));
    }

    /**
     * Get selected country codes for hotel sync filtering.
     *
     * @return string[] Uppercase country codes (e.g. ['GR', 'BG', 'TR'])
     */
    public static function getSelectedCountryCodes(): array
    {
        self::migrateFromLegacySetting();

        $whitelistCodes = db_get_fields(
            "SELECT DISTINCT d.country_code FROM ?:sphinx_destination_whitelist w
             JOIN ?:sphinx_destinations d ON w.destination_id = d.destination_id
             WHERE d.country_code != ''
             ORDER BY d.country_code"
        );

        return !empty($whitelistCodes) ? $whitelistCodes : [];
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

    /**
     * Get the full set of allowed destination IDs from sync targets.
     *
     * Resolves country codes → all destination IDs for those countries,
     * merges with explicitly selected destination IDs + their children.
     * Used by circuit, experience, and package route sync services
     * for client-side filtering.
     *
     * @return int[] Destination IDs allowed by sync targets (empty = nothing configured)
     */
    public static function getAllowedDestinationIds(): array
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        self::migrateFromLegacySetting();

        $whitelistEntries = db_get_array("SELECT destination_id, selection_type FROM ?:sphinx_destination_whitelist");
        if (!empty($whitelistEntries)) {
            $cached = self::resolveWhitelistEntries($whitelistEntries);
        } else {
            $cached = [];
        }

        return $cached;
    }

    /**
     * Resolve whitelist table entries into a flat set of destination IDs.
     *
     * For entries with selection_type='all' (country-level), includes all
     * destinations under that country. For 'specific', includes only the
     * explicitly listed destinations.
     *
     * @param array $entries Rows from sphinx_destination_whitelist
     * @return int[] Deduplicated destination IDs
     */
    private static function resolveWhitelistEntries(array $entries): array
    {
        $allIds = [];

        foreach ($entries as $entry) {
            $destId = (int) $entry['destination_id'];
            $selType = $entry['selection_type'];
            $allIds[] = $destId;

            if ($selType === 'all') {
                // This is a country with "all children" — include every destination under it
                $countryCode = db_get_field(
                    "SELECT country_code FROM ?:sphinx_destinations WHERE destination_id = ?i",
                    $destId
                );
                if (!empty($countryCode)) {
                    $childIds = db_get_fields(
                        "SELECT destination_id FROM ?:sphinx_destinations WHERE country_code = ?s",
                        $countryCode
                    );
                    $allIds = array_merge($allIds, array_map('intval', $childIds));
                }
            }
        }

        return array_values(array_unique($allIds));
    }

    /**
     * One-time migration: if the whitelist table is empty but the legacy
     * selected_destinations textarea setting has values, populate the
     * whitelist table with country-level entries (selection_type='all').
     */
    private static function migrateFromLegacySetting(): void
    {
        static $migrated = false;
        if ($migrated) {
            return;
        }
        $migrated = true;

        $count = (int) db_get_field("SELECT COUNT(*) FROM ?:sphinx_destination_whitelist");
        if ($count > 0) {
            return; // Whitelist already has data, no migration needed
        }

        $val = (string) self::getSetting('selected_destinations', '');
        $tokens = array_filter(array_map('trim', explode(',', $val)));
        if (empty($tokens)) {
            return;
        }

        foreach ($tokens as $token) {
            if (strlen($token) === 2 && ctype_alpha($token)) {
                // Country code — find the country destination and add with selection_type='all'
                $countryCode = strtoupper($token);
                $countryDestId = (int) db_get_field(
                    "SELECT destination_id FROM ?:sphinx_destinations WHERE country_code = ?s AND type = 'country' LIMIT 1",
                    $countryCode
                );
                if ($countryDestId > 0) {
                    db_query(
                        "INSERT IGNORE INTO ?:sphinx_destination_whitelist (destination_id, selection_type) VALUES (?i, 'all')",
                        $countryDestId
                    );
                }
            }
        }
    }

    private static function getSetting(string $key, mixed $default = ''): mixed
    {
        $value = Registry::get('addons.' . self::ADDON_ID . '.' . $key);
        return $value !== null ? $value : $default;
    }
}

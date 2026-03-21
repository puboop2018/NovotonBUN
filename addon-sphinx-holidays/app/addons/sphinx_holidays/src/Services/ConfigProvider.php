<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Registry;
use Tygh\Addons\SphinxHolidays\Repository\DestinationRepository;

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
     * Get the category path template for product creation.
     *
     * Supports placeholders: {country}, {region}, {city}
     * Default: "Hotels/{country}/{region}/{city}"
     */
    public static function getProductCategoryTemplate(): string
    {
        return (string) self::getSetting('product_category_template', 'Hotels/{country}/{region}/{city}');
    }

    /**
     * Get selected sync targets — country codes, destination names, and/or destination IDs.
     *
     * Setting format: comma-separated, supports three token types:
     *   - 2-letter alpha codes → country codes (e.g. "GR", "BG")
     *   - Numeric strings → destination IDs (e.g. "1234")
     *   - Anything else → destination names resolved from DB (e.g. "Crete", "Rhodes")
     *
     * Examples: "GR,Crete" → sync all Greece + Crete region specifically
     *           "GR,BG,Rhodes" → sync Greece, Bulgaria, + Rhodes by name
     *
     * @return array{country_codes: string[], destination_ids: int[]}
     */
    public static function getSelectedSyncTargets(): array
    {
        $val = (string) self::getSetting('selected_destinations', '');
        $countryCodes = [];
        $destinationIds = [];
        $nameTokens = [];

        foreach (array_filter(array_map('trim', explode(',', $val))) as $token) {
            if (ctype_digit($token)) {
                $destinationIds[] = (int) $token;
            } elseif (strlen($token) === 2 && ctype_alpha($token)) {
                $countryCodes[] = strtoupper($token);
            } else {
                $nameTokens[] = $token;
            }
        }

        // Resolve destination names to IDs via DB lookup
        if (!empty($nameTokens)) {
            $resolvedIds = self::resolveNameTokens($nameTokens, $countryCodes);
            $destinationIds = array_merge($destinationIds, $resolvedIds);
        }

        return ['country_codes' => $countryCodes, 'destination_ids' => $destinationIds];
    }

    /**
     * Resolve destination name tokens to destination IDs via DB lookup.
     *
     * Supports both plain names and full_path queries for disambiguation:
     *   - "Crete" → name match (unambiguous if unique)
     *   - "Athens, Greece" → full_path prefix match (resolves "Athens Problem")
     *
     * Disambiguation strategy:
     *   1. If token contains ", " → treat as full_path query (unambiguous match)
     *   2. Match via findByNameOrPath() (name exact OR full_path prefix)
     *   3. If multiple matches, prefer those in already-selected countries
     *   4. Among remaining, prefer higher hierarchy (region > city > destination)
     *   5. If still ambiguous, include ALL matches and log warning with full_path breadcrumbs
     *
     * @param string[] $nameTokens Destination names to resolve
     * @param string[] $contextCountryCodes Country codes already selected (for disambiguation)
     * @return int[] Resolved destination IDs
     */
    private static function resolveNameTokens(array $nameTokens, array $contextCountryCodes): array
    {
        $repo = new DestinationRepository();
        $resolvedIds = [];

        foreach ($nameTokens as $name) {
            $matches = $repo->findByNameOrPath($name);

            if (empty($matches)) {
                fn_log_event('general', 'runtime', [
                    'message' => "Sphinx sync: destination '{$name}' not found in synced destinations. Skipping.",
                ]);
                continue;
            }

            if (count($matches) === 1) {
                $resolvedIds[] = (int) $matches[0]['destination_id'];
                continue;
            }

            // Full_path query (contains ", ") should already be narrow — if still multiple,
            // use all matches since the admin explicitly provided a path prefix
            if (str_contains($name, ', ')) {
                foreach ($matches as $m) {
                    $resolvedIds[] = (int) $m['destination_id'];
                }
                continue;
            }

            // Multiple matches from plain name — disambiguate
            // Step 1: prefer matches in context countries
            if (!empty($contextCountryCodes)) {
                $contextMatches = array_filter($matches, function ($m) use ($contextCountryCodes) {
                    return in_array(strtoupper($m['country_code'] ?? ''), $contextCountryCodes, true);
                });
                if (!empty($contextMatches)) {
                    $matches = array_values($contextMatches);
                }
            }

            if (count($matches) === 1) {
                $resolvedIds[] = (int) $matches[0]['destination_id'];
                continue;
            }

            // Step 2: include ALL matches (not just first) — let the sync be inclusive
            foreach ($matches as $m) {
                $resolvedIds[] = (int) $m['destination_id'];
            }

            // Log warning with full_path breadcrumbs for admin to refine
            $labels = array_map(
                fn($m) => $m['destination_id'] . ' (' . ($m['full_path'] ?? $m['type'] . ', ' . $m['country_code']) . ')',
                $matches
            );
            fn_log_event('general', 'runtime', [
                'message' => "Sphinx sync: '{$name}' matched " . count($matches) . " destinations: "
                    . implode('; ', $labels)
                    . ". To disambiguate, use full path in settings (e.g. \"{$name}, "
                    . ($matches[0]['country_code'] ?? '') . "\").",
            ]);
        }

        return array_unique($resolvedIds);
    }

    /**
     * Get selected country codes for hotel sync filtering.
     *
     * Convenience wrapper over getSelectedSyncTargets().
     *
     * @return string[] Uppercase country codes (e.g. ['GR', 'BG', 'TR'])
     */
    public static function getSelectedCountryCodes(): array
    {
        // Priority 1: Derive from whitelist table if populated
        $whitelistCodes = db_get_fields(
            "SELECT DISTINCT d.country_code FROM ?:sphinx_destination_whitelist w
             JOIN ?:sphinx_destinations d ON w.destination_id = d.destination_id
             WHERE d.country_code != ''
             ORDER BY d.country_code"
        );
        if (!empty($whitelistCodes)) {
            return $whitelistCodes;
        }

        // Priority 2: Fall back to textarea setting
        $targets = self::getSelectedSyncTargets();

        if (!empty($targets['country_codes'])) {
            return $targets['country_codes'];
        }

        return [];
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

        // Priority 1: Use whitelist table if it has entries
        $whitelistEntries = db_get_array("SELECT destination_id, selection_type FROM ?:sphinx_destination_whitelist");
        if (!empty($whitelistEntries)) {
            $cached = self::resolveWhitelistEntries($whitelistEntries);
            return $cached;
        }

        // Priority 2: Fall back to textarea setting
        $targets = self::getSelectedSyncTargets();
        $countryCodes = $targets['country_codes'];
        $extraIds = $targets['destination_ids'];

        if (empty($countryCodes) && empty($extraIds)) {
            $cached = [];
            return $cached;
        }

        $allIds = [];

        // Resolve country codes → all destination IDs in those countries
        if (!empty($countryCodes)) {
            $placeholders = implode(',', array_fill(0, count($countryCodes), '?s'));
            $countryDestIds = db_get_fields(
                "SELECT destination_id FROM ?:sphinx_destinations WHERE country_code IN ($placeholders)",
                ...$countryCodes
            );
            $allIds = array_map('intval', $countryDestIds);
        }

        // Add explicitly selected IDs + their children
        if (!empty($extraIds)) {
            $allIds = array_merge($allIds, $extraIds);
            $idPlaceholders = implode(',', array_fill(0, count($extraIds), '?i'));
            $children = db_get_fields(
                "SELECT destination_id FROM ?:sphinx_destinations WHERE parent_id IN ($idPlaceholders)",
                ...$extraIds
            );
            $allIds = array_merge($allIds, array_map('intval', $children));
        }

        $cached = array_values(array_unique($allIds));
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

    private static function getSetting(string $key, mixed $default = ''): mixed
    {
        $value = Registry::get('addons.' . self::ADDON_ID . '.' . $key);
        return $value !== null ? $value : $default;
    }
}

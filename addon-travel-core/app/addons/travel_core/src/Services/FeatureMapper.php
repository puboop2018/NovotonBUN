<?php
declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

/**
 * Feature mapper service.
 *
 * Resolves API-specific values (e.g., "Mic dejun", "AI", "Twin Room with Sea View")
 * to canonical codes using the travel_feature_map + travel_api_alias tables.
 */
class FeatureMapper
{
    /** @var array Per-request in-memory cache for resolve() results */
    private static array $cache = [];

    /**
     * Resolve an API value to a canonical mapping.
     *
     * Uses a single combined query with priority ordering (exact > prefix > contains)
     * and caches results in memory for the duration of the request.
     *
     * @param string $apiSource   Provider name ('novoton', 'sphinx')
     * @param string $featureType Feature type ('board', 'room_type', 'stars', etc.)
     * @param string $apiValue    Raw value from the API
     * @return array|null {map_id, canonical_code, display_name_en, display_name_ro, cscart_variant_id}
     */
    public static function resolve(string $apiSource, string $featureType, string $apiValue): ?array
    {
        $cacheKey = $apiSource . '|' . $featureType . '|' . $apiValue;

        if (array_key_exists($cacheKey, self::$cache)) {
            return self::$cache[$cacheKey];
        }

        $result = db_get_row(
            "SELECT m.map_id, m.canonical_code, m.display_name_en, m.display_name_ro, m.cscart_variant_id
             FROM ?:travel_api_alias a
             JOIN ?:travel_feature_map m ON m.map_id = a.map_id
             WHERE a.api_source = ?s AND m.feature_type = ?s AND m.status = 'A'
               AND (
                   a.api_value = ?s
                   OR (a.match_type = 'prefix' AND ?s LIKE CONCAT(a.api_value, '%'))
                   OR (a.match_type = 'contains' AND ?s LIKE CONCAT('%', a.api_value, '%'))
               )
             ORDER BY FIELD(
                 CASE WHEN a.api_value = ?s THEN 'exact' ELSE a.match_type END,
                 'exact', 'prefix', 'contains'
             ), LENGTH(a.api_value) DESC
             LIMIT 1",
            $apiSource, $featureType,
            $apiValue, $apiValue, $apiValue, $apiValue
        );

        self::$cache[$cacheKey] = $result ?: null;

        return $result;
    }

    /**
     * Clear the in-memory resolve cache.
     * Call after import/sync operations to free memory.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
        self::$trackedUnmapped = [];
    }

    /**
     * Get CS-Cart variant_id directly (for product feature assignment).
     */
    public static function toVariantId(string $apiSource, string $featureType, string $apiValue): ?int
    {
        $mapping = self::resolve($apiSource, $featureType, $apiValue);
        return $mapping ? ($mapping['cscart_variant_id'] ? (int) $mapping['cscart_variant_id'] : null) : null;
    }

    /**
     * Bulk resolve for import performance (keyed result).
     *
     * Delegates to resolve() per value to support all match types
     * (exact, prefix, contains) with priority ordering. Results are
     * cached in memory, so repeated calls within the same request
     * are nearly free.
     *
     * @return array<string, array> Keyed by api_value
     */
    public static function resolveMany(string $apiSource, string $featureType, array $apiValues): array
    {
        if (empty($apiValues)) {
            return [];
        }

        $result = [];
        foreach ($apiValues as $apiValue) {
            $mapping = self::resolve($apiSource, $featureType, $apiValue);
            if ($mapping !== null) {
                $result[$apiValue] = $mapping;
            }
        }

        return $result;
    }

    /**
     * Track an unmapped API value so admins can see what's missing.
     *
     * Uses INSERT ... ON DUPLICATE KEY UPDATE to increment hotel_count
     * and refresh last_seen_at. Lightweight — one query per unique miss.
     *
     * @param string $apiSource   Provider name ('sphinx', 'novoton')
     * @param string $featureType Feature type ('facility', 'board', etc.)
     * @param string $apiValue    Raw value from the API that had no match
     * @param string $apiLabel    Optional human-readable name (e.g. facility name from API)
     */
    public static function trackUnmapped(string $apiSource, string $featureType, string $apiValue, string $apiLabel = ''): void
    {
        // Deduplicate within the same request to avoid repeated DB writes
        $dedupeKey = $apiSource . '|' . $featureType . '|' . $apiValue;
        if (isset(self::$trackedUnmapped[$dedupeKey])) {
            return;
        }
        self::$trackedUnmapped[$dedupeKey] = true;

        db_query(
            "INSERT INTO ?:travel_unmapped_values (api_source, feature_type, api_value, api_label, hotel_count)
             VALUES (?s, ?s, ?s, ?s, 1)
             ON DUPLICATE KEY UPDATE
                hotel_count = hotel_count + 1,
                api_label = IF(?s != '', ?s, api_label)",
            $apiSource, $featureType, $apiValue, $apiLabel,
            $apiLabel, $apiLabel
        );
    }

    /** @var array Per-request deduplication for trackUnmapped() */
    private static array $trackedUnmapped = [];

    /**
     * Get display name for a canonical code (language-aware).
     */
    public static function getDisplayName(string $featureType, string $canonicalCode, string $lang = 'en'): string
    {
        $column = $lang === 'ro' ? 'display_name_ro' : 'display_name_en';

        return (string) db_get_field(
            "SELECT `{$column}` FROM ?:travel_feature_map WHERE feature_type = ?s AND canonical_code = ?s AND status = 'A'",
            $featureType, $canonicalCode
        );
    }

    /**
     * Register an alias (called by each API addon during install/sync).
     */
    public static function addAlias(string $apiSource, string $apiValue, int $mapId, string $matchType = 'exact'): void
    {
        db_query(
            "INSERT INTO ?:travel_api_alias (map_id, api_source, api_value, match_type)
             VALUES (?i, ?s, ?s, ?s)
             ON DUPLICATE KEY UPDATE map_id = ?i, match_type = ?s",
            $mapId, $apiSource, $apiValue, $matchType,
            $mapId, $matchType
        );

        self::$cache = [];
    }

    /** Feature type → travel_core addon setting key */
    private const FEATURE_SETTING_KEYS = [
        'stars'         => 'feature_id_property_rating',
        'property_type' => 'feature_id_property_type',
        'resort'        => 'feature_id_location',
        'board'         => 'feature_id_meals',
        'region'        => 'feature_id_region',
        'city'          => 'feature_id_city',
        'travel_group'  => 'feature_id_travel_group',
    ];

    /**
     * Get CS-Cart feature_id for a travel feature type from addon settings.
     *
     * @return int feature_id or 0 if not configured
     */
    public static function getFeatureId(string $featureType): int
    {
        $settingKey = self::FEATURE_SETTING_KEYS[$featureType]
                      ?? ('feature_id_' . $featureType);
        return (int) \Tygh\Registry::get('addons.travel_core.' . $settingKey);
    }

    /**
     * Get all canonical codes for a feature type.
     *
     * @return array<string, array>
     */
    public static function allCodes(string $featureType): array
    {
        return db_get_hash_array(
            "SELECT canonical_code, map_id, display_name_en, display_name_ro, cscart_variant_id
             FROM ?:travel_feature_map
             WHERE feature_type = ?s AND status = 'A'
             ORDER BY position, canonical_code",
            'canonical_code',
            $featureType
        );
    }
}

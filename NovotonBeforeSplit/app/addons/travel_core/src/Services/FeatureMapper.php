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
     * Bulk resolve for import performance (single query, keyed result).
     *
     * @return array<string, array> Keyed by api_value
     */
    public static function resolveMany(string $apiSource, string $featureType, array $apiValues): array
    {
        if (empty($apiValues)) {
            return [];
        }

        $rows = db_get_array(
            "SELECT a.api_value, m.map_id, m.canonical_code, m.display_name_en, m.display_name_ro, m.cscart_variant_id
             FROM ?:travel_api_alias a
             JOIN ?:travel_feature_map m ON m.map_id = a.map_id
             WHERE a.api_source = ?s AND a.api_value IN (?a) AND m.feature_type = ?s AND m.status = 'A'",
            $apiSource, $apiValues, $featureType
        );

        $result = [];
        foreach ($rows as $row) {
            $result[$row['api_value']] = $row;
        }

        return $result;
    }

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

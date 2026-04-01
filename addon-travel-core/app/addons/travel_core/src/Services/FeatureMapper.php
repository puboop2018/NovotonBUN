<?php
declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Traits\CsCartFeatureAssignment;

/**
 * Feature mapper service.
 *
 * Resolves API-specific values (e.g., "Mic dejun", "AI", "Twin Room with Sea View")
 * to canonical codes using the travel_feature_map + travel_api_alias tables.
 *
 * Supports:
 * - Multi-pass fuzzy matching (exact > prefix > contains)
 * - Auto-registration of unmapped values for dynamic feature types
 * - 3-pass variant name matching (exact → case-insensitive → normalized)
 * - Auto-creation of CS-Cart variants
 * - Manual lock (variant_source='manual') to prevent auto-overwrite
 */
class FeatureMapper
{
    use CsCartFeatureAssignment;

    /** @var array Per-request in-memory cache for resolve() results */
    private static array $cache = [];

    /** @var array Per-request deduplication for trackUnmapped() */
    private static array $trackedUnmapped = [];

    /** @var self|null Singleton for instance methods (variant creation uses the trait) */
    private static ?self $instance = null;

    /**
     * Strict feature types: unknown codes are logged + skipped, never auto-registered.
     * These have well-defined value sets that shouldn't grow automatically.
     */
    public const STRICT_FEATURE_TYPES = [
        'board', 'room_type', 'stars', 'property_type', 'travel_group',
    ];

    /**
     * Dynamic feature types: unknown codes are auto-registered as new mappings.
     * These grow organically as new hotels/facilities appear in API data.
     */
    public const DYNAMIC_FEATURE_TYPES = [
        'facility', 'resort', 'region', 'city', 'beach_access',
    ];

    /** Feature type → travel_core addon setting key */
    private const FEATURE_SETTING_KEYS = [
        'stars'          => 'feature_id_property_rating',
        'property_type'  => 'feature_id_property_type',
        'resort'         => 'feature_id_location',
        'board'          => 'feature_id_meals',
        'region'         => 'feature_id_region',
        'city'           => 'feature_id_city',
        'travel_group'   => 'feature_id_travel_group',
        'hotel_facility' => 'feature_id_hotel_facility',
        'room_facility'  => 'feature_id_room_facility',
        'beach_access'   => 'feature_id_beach_access',
    ];

    // ── Core resolve ──

    /**
     * Resolve an API value to a canonical mapping.
     *
     * Uses a single combined query with priority ordering (exact > prefix > contains)
     * and caches results in memory for the duration of the request.
     *
     * @param string $apiSource   Provider name ('novoton', 'sphinx')
     * @param string $featureType Feature type ('board', 'room_type', 'stars', 'facility', etc.)
     * @param string $apiValue    Raw value from the API
     * @return array|null {map_id, canonical_code, display_name_en, display_name_ro, cscart_variant_id, variant_source}
     */
    public static function resolve(string $apiSource, string $featureType, string $apiValue): ?array
    {
        $cacheKey = $apiSource . '|' . $featureType . '|' . $apiValue;

        if (array_key_exists($cacheKey, self::$cache)) {
            return self::$cache[$cacheKey];
        }

        $result = db_get_row(
            "SELECT m.map_id, m.canonical_code, m.display_name_en, m.display_name_ro,
                    m.cscart_feature_id, m.cscart_variant_id, m.variant_source
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

        if ($result) {
            // Update last_used_at (fire-and-forget, not on every call — only on cache miss)
            db_query("UPDATE ?:travel_feature_map SET last_used_at = NOW() WHERE map_id = ?i", $result['map_id']);
        }

        self::$cache[$cacheKey] = $result ?: null;

        return $result;
    }

    // ── Resolve with variant auto-creation ──

    /**
     * Resolve an API value and ensure a CS-Cart variant exists.
     *
     * If the mapping has no variant yet (or the variant was deleted from CS-Cart),
     * uses 3-pass name matching (exact → case-insensitive → normalized) to find
     * an existing variant, or auto-creates one.
     *
     * Respects variant_source='manual' — never auto-overwrites admin-locked mappings.
     *
     * @return array|null The mapping with guaranteed cscart_variant_id (if resolvable)
     */
    public static function resolveWithVariant(string $apiSource, string $featureType, string $apiValue): ?array
    {
        $mapping = self::resolve($apiSource, $featureType, $apiValue);
        if (!$mapping) {
            return null;
        }

        $variantId = self::getInstance()->ensureVariantExists($mapping);
        if ($variantId > 0) {
            $mapping['cscart_variant_id'] = $variantId;
        }

        return $mapping;
    }

    /**
     * Ensure a CS-Cart variant exists for a mapping row.
     *
     * Ported from Novoton's FeatureMapper::ensureVariantExists().
     * 1. If stored variant_id exists in CS-Cart, use it.
     * 2. If variant_source='manual', never auto-resolve (admin locked).
     * 3. Try 3-pass name matching against existing CS-Cart variants.
     * 4. Auto-create the variant if no match found.
     *
     * @return int variant_id or 0
     */
    private function ensureVariantExists(array $mapping): int
    {
        $variantId = (int) ($mapping['cscart_variant_id'] ?? 0);
        $featureId = (int) ($mapping['cscart_feature_id'] ?? 0);
        $mapId = (int) $mapping['map_id'];
        $variantSource = $mapping['variant_source'] ?? 'auto';

        if ($featureId <= 0) {
            // Try to get feature_id from settings
            $featureType = $mapping['feature_type'] ?? '';
            if ($featureType !== '') {
                $featureId = self::getFeatureId($featureType);
                if ($featureId > 0) {
                    db_query("UPDATE ?:travel_feature_map SET cscart_feature_id = ?i WHERE map_id = ?i", $featureId, $mapId);
                }
            }
            if ($featureId <= 0) {
                return 0;
            }
        }

        // Check if stored variant still exists in CS-Cart
        if ($variantId > 0) {
            $exists = db_get_field(
                "SELECT variant_id FROM ?:product_feature_variants WHERE variant_id = ?i AND feature_id = ?i",
                $variantId, $featureId
            );
            if ($exists) {
                return $variantId;
            }
            // Variant was deleted — if manually set, don't auto-resolve
            if ($variantSource === 'manual') {
                return 0;
            }
        }

        // Never auto-resolve manually locked mappings
        if ($variantSource === 'manual') {
            return $variantId;
        }

        // Try 3-pass name match against existing CS-Cart variants
        $matched = $this->findVariantByName($mapping, $featureId);
        if ($matched > 0) {
            self::updateVariantId($mapId, $matched, 'auto');
            return $matched;
        }

        // Auto-create the variant
        $nameEn = trim($mapping['display_name_en'] ?? '');
        $nameRo = trim($mapping['display_name_ro'] ?? '');
        if ($nameEn === '') {
            return 0;
        }

        $variantId = $this->createVariant($featureId, $nameEn, $nameRo);
        if ($variantId > 0) {
            self::updateVariantId($mapId, $variantId, 'auto');
        }

        return $variantId;
    }

    /**
     * 3-pass variant name matching (ported from Novoton).
     *
     * Per language (EN first, then RO fallback):
     *   1. Exact match
     *   2. Case-insensitive match via LOWER()
     *   3. Normalized match — strips non-alphanumeric chars and collapses whitespace
     *
     * @return int Matched variant_id or 0
     */
    private function findVariantByName(array $mapping, int $featureId): int
    {
        $nameEn = trim($mapping['display_name_en'] ?? '');
        $nameRo = trim($mapping['display_name_ro'] ?? '');

        if ($featureId <= 0 || $nameEn === '') {
            return 0;
        }

        $candidates = [['en', $nameEn]];
        if ($nameRo !== '' && $nameRo !== $nameEn) {
            $candidates[] = ['ro', $nameRo];
        }

        foreach ($candidates as [$lang, $name]) {
            // Pass 1: Exact match
            $variantId = db_get_field(
                "SELECT v.variant_id
                 FROM ?:product_feature_variants v
                 JOIN ?:product_feature_variant_descriptions vd ON v.variant_id = vd.variant_id
                 WHERE v.feature_id = ?i AND vd.lang_code = ?s AND vd.variant = ?s
                 LIMIT 1",
                $featureId, $lang, $name
            );
            if ($variantId) {
                return (int) $variantId;
            }

            // Pass 2: Case-insensitive match
            $variantId = db_get_field(
                "SELECT v.variant_id
                 FROM ?:product_feature_variants v
                 JOIN ?:product_feature_variant_descriptions vd ON v.variant_id = vd.variant_id
                 WHERE v.feature_id = ?i AND vd.lang_code = ?s AND LOWER(vd.variant) = LOWER(?s)
                 LIMIT 1",
                $featureId, $lang, $name
            );
            if ($variantId) {
                return (int) $variantId;
            }

            // Pass 3: Normalized match — strips punctuation, collapses whitespace
            $normalized = self::normalizeName($name);
            $rows = db_get_array(
                "SELECT v.variant_id, vd.variant
                 FROM ?:product_feature_variants v
                 JOIN ?:product_feature_variant_descriptions vd ON v.variant_id = vd.variant_id
                 WHERE v.feature_id = ?i AND vd.lang_code = ?s",
                $featureId, $lang
            );
            foreach ($rows as $row) {
                if (self::normalizeName($row['variant']) === $normalized) {
                    return (int) $row['variant_id'];
                }
            }
        }

        return 0;
    }

    /**
     * Normalize a variant name for fuzzy comparison.
     *
     * Lowercases, strips non-alphanumeric/non-space chars, and collapses whitespace.
     * e.g. "All-Inclusive (Premium)" => "all inclusive premium"
     */
    private static function normalizeName(string $name): string
    {
        $name = mb_strtolower($name, 'UTF-8');
        $name = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $name);
        return trim(preg_replace('/\s+/', ' ', $name));
    }

    // ── Unmapped value handling ──

    /**
     * Handle an unmapped API value based on feature type classification.
     *
     * - Strict types: log a warning + track in travel_unmapped_values
     * - Dynamic types: auto-register as a new mapping row + alias
     *
     * @return int|null map_id of the auto-registered row (dynamic), or null (strict/failed)
     */
    public static function handleUnmapped(string $apiSource, string $featureType, string $apiValue, string $apiLabel = ''): ?int
    {
        // Always track in unmapped_values for visibility
        self::trackUnmapped($apiSource, $featureType, $apiValue, $apiLabel);

        if (in_array($featureType, self::STRICT_FEATURE_TYPES, true)) {
            // Strict: log + skip
            if (function_exists('fn_log_event')) {
                fn_log_event('general', 'runtime', [
                    'message'      => "FeatureMapper: Unmapped strict value skipped",
                    'api_source'   => $apiSource,
                    'feature_type' => $featureType,
                    'api_value'    => $apiValue,
                ]);
            }
            return null;
        }

        if (in_array($featureType, self::DYNAMIC_FEATURE_TYPES, true)) {
            return self::registerUnmapped($apiSource, $featureType, $apiValue, $apiLabel);
        }

        return null;
    }

    /**
     * Auto-register an unmapped API value as a real mapping + alias.
     *
     * Creates a travel_feature_map row (mapping_source='auto', status='A')
     * and a travel_api_alias row linking the API value.
     *
     * @return int|null map_id of the new row, or null on failure
     */
    public static function registerUnmapped(string $apiSource, string $featureType, string $apiValue, string $apiLabel = ''): ?int
    {
        $effectiveLabel = $apiLabel !== ''
            ? $apiLabel
            : mb_convert_case($apiValue, MB_CASE_TITLE, 'UTF-8');

        // Use api_value as canonical code (sanitized)
        $canonicalCode = preg_replace('/[^a-z0-9_]/', '_', strtolower(trim($apiValue)));
        if ($canonicalCode === '') {
            return null;
        }

        // Determine cscart_feature_id from addon settings
        $featureId = self::getFeatureId($featureType);

        // Insert mapping row
        $mapId = (int) db_query(
            "INSERT IGNORE INTO ?:travel_feature_map
             (feature_type, canonical_code, display_name_en, display_name_ro,
              cscart_feature_id, mapping_source, status)
             VALUES (?s, ?s, ?s, ?s, ?i, 'auto', 'A')",
            $featureType, $canonicalCode, $effectiveLabel, $effectiveLabel,
            $featureId ?: null
        );

        if ($mapId <= 0) {
            // Row may already exist (INSERT IGNORE), fetch existing map_id
            $mapId = (int) db_get_field(
                "SELECT map_id FROM ?:travel_feature_map WHERE feature_type = ?s AND canonical_code = ?s",
                $featureType, $canonicalCode
            );
        }

        if ($mapId <= 0) {
            return null;
        }

        // Create alias linking this api_value to the canonical row
        self::addAlias($apiSource, $apiValue, $mapId, 'exact');

        // Remove from unmapped_values since it's now mapped
        db_query(
            "DELETE FROM ?:travel_unmapped_values WHERE api_source = ?s AND feature_type = ?s AND api_value = ?s",
            $apiSource, $featureType, $apiValue
        );

        self::$cache = [];

        return $mapId;
    }

    /**
     * Track an unmapped API value for admin visibility.
     *
     * Uses INSERT ... ON DUPLICATE KEY UPDATE to increment hotel_count
     * and refresh last_seen_at. Lightweight — one query per unique miss per request.
     */
    public static function trackUnmapped(string $apiSource, string $featureType, string $apiValue, string $apiLabel = ''): void
    {
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

    // ── Variant ID management ──

    /**
     * Update the variant_id for a mapping row.
     */
    public static function updateVariantId(int $mapId, int $variantId, string $source = 'auto'): void
    {
        db_query(
            "UPDATE ?:travel_feature_map SET cscart_variant_id = ?i, variant_source = ?s WHERE map_id = ?i",
            $variantId, $source, $mapId
        );
        self::$cache = [];
    }

    // ── Cache management ──

    /**
     * Clear the in-memory resolve cache.
     * Call after import/sync operations to free memory.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
        self::$trackedUnmapped = [];
    }

    // ── Convenience methods ──

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

    /**
     * Get singleton instance for methods that need the CsCartFeatureAssignment trait.
     */
    private static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
}

<?php
declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Contracts;

/**
 * Contract for the shared feature mapper service.
 *
 * Resolves API-specific values (e.g., "Mic dejun", "AI", "Twin Room with Sea View")
 * to canonical codes using the travel_feature_map + travel_api_alias tables.
 */
interface FeatureMapperInterface
{
    /**
     * Resolve an API value to a canonical mapping.
     *
     * @param string $apiSource   Provider name ('novoton', 'sphinx')
     * @param string $featureType Feature type ('board', 'room_type', 'stars', 'facility', etc.)
     * @param string $apiValue    Raw value from the API
     * @return array|null Mapping row or null if unresolved
     */
    public static function resolve(string $apiSource, string $featureType, string $apiValue): ?array;

    /**
     * Resolve an API value and ensure a CS-Cart variant exists.
     *
     * If the mapping has no variant yet, uses 3-pass name matching to find
     * an existing variant, or auto-creates one.
     *
     * @return array|null The mapping with guaranteed cscart_variant_id (if resolvable)
     */
    public static function resolveWithVariant(string $apiSource, string $featureType, string $apiValue): ?array;

    /**
     * Handle an unmapped API value (auto-register for dynamic types, track for strict types).
     *
     * @return int|null The newly created map_id, or null if only tracked
     */
    public static function handleUnmapped(string $apiSource, string $featureType, string $apiValue, string $apiLabel = ''): ?int;

    /**
     * Track an unmapped API value for admin visibility (no auto-registration).
     */
    public static function trackUnmapped(string $apiSource, string $featureType, string $apiValue, string $apiLabel = ''): void;

    /**
     * Update the CS-Cart variant ID for a mapping row.
     */
    public static function updateVariantId(int $mapId, int $variantId, string $source = 'auto'): void;

    /**
     * Clear in-memory cache and flush batched last_used_at updates.
     */
    public static function clearCache(): void;

    /**
     * Convenience: resolve and return just the variant_id.
     */
    public static function toVariantId(string $apiSource, string $featureType, string $apiValue): ?int;

    /**
     * Bulk-resolve multiple API values.
     *
     * @param string[] $apiValues
     * @return array<string, array|null> Keyed by apiValue
     */
    public static function resolveMany(string $apiSource, string $featureType, array $apiValues): array;

    /**
     * Get the display name for a canonical code in the given language.
     */
    public static function getDisplayName(string $featureType, string $canonicalCode, string $lang = 'en'): string;

    /**
     * Register an alias linking an API value to a mapping row.
     */
    public static function addAlias(string $apiSource, string $apiValue, int $mapId, string $matchType = 'exact'): void;

    /**
     * Get the CS-Cart feature_id from addon settings for a feature type.
     */
    public static function getFeatureId(string $featureType): int;

    /**
     * Get all canonical codes for a feature type.
     *
     * @return array<string, array> Keyed by canonical_code
     */
    public static function allCodes(string $featureType): array;
}

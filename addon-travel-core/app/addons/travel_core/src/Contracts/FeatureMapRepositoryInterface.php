<?php
declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Contracts;

/**
 * Repository contract for travel_feature_map + travel_api_alias DB operations.
 *
 * Extracted from FeatureMapper to enable unit testing without DB,
 * and to centralize query logic used by both the service and admin controller.
 */
interface FeatureMapRepositoryInterface
{
    // ── Resolve / lookup ──

    /**
     * Find a mapping by alias match (exact > prefix > contains priority).
     *
     * @return array|null Mapping row with map_id, feature_type, canonical_code, display names, variant info
     */
    public function findByAlias(string $apiSource, string $featureType, string $apiValue): ?array;

    /**
     * Get display name for a canonical code.
     */
    public function getDisplayName(string $featureType, string $canonicalCode, string $lang = 'en'): string;

    /**
     * Get all active canonical codes for a feature type.
     *
     * @return array<string, array> Keyed by canonical_code
     */
    public function allCodes(string $featureType): array;

    // ── Mutations ──

    /**
     * Insert a new mapping row (INSERT IGNORE).
     *
     * @return int map_id of inserted row, or 0 if ignored
     */
    public function insertMapping(string $featureType, string $canonicalCode, string $nameEn, string $nameRo, ?int $featureId): int;

    /**
     * Find map_id by feature_type + canonical_code.
     */
    public function findMapId(string $featureType, string $canonicalCode): int;

    /**
     * Update variant_id and variant_source for a mapping.
     */
    public function updateVariantId(int $mapId, int $variantId, string $source = 'auto'): void;

    /**
     * Update cscart_feature_id for a mapping.
     */
    public function updateFeatureId(int $mapId, int $featureId): void;

    /**
     * Update a mapping row with arbitrary data (admin controller).
     */
    public function updateMapping(int $mapId, array $data): void;

    /**
     * Bulk-update status for multiple map_ids.
     *
     * @param int[] $mapIds
     */
    public function bulkUpdateStatus(array $mapIds, string $status): void;

    /**
     * Delete mappings and their aliases by map_ids.
     *
     * @param int[] $mapIds
     */
    public function deleteMappings(array $mapIds): void;

    // ── Aliases ──

    /**
     * Upsert an alias row.
     */
    public function upsertAlias(string $apiSource, string $apiValue, int $mapId, string $matchType = 'exact'): void;

    /**
     * Delete an alias by alias_id.
     */
    public function deleteAlias(int $aliasId): void;

    // ── Unmapped values ──

    /**
     * Track an unmapped API value (INSERT ... ON DUPLICATE KEY UPDATE).
     */
    public function trackUnmapped(string $apiSource, string $featureType, string $apiValue, string $apiLabel = ''): void;

    /**
     * Remove a value from the unmapped table.
     */
    public function deleteUnmapped(string $apiSource, string $featureType, string $apiValue): void;

    /**
     * Get a single unmapped row by ID.
     */
    public function getUnmappedById(int $unmappedId): ?array;

    // ── Batch operations ──

    /**
     * Batch-update last_used_at for a set of map_ids.
     *
     * @param int[] $mapIds
     */
    public function batchUpdateLastUsed(array $mapIds): void;

    /**
     * Get feature types that have no cscart_feature_id set.
     *
     * @return string[]
     */
    public function getFeatureTypesWithoutFeatureId(): array;

    /**
     * Bulk-set cscart_feature_id for all entries of a feature type that lack one.
     */
    public function bulkSetFeatureId(string $featureType, int $featureId): void;

    /**
     * Get active mappings that need variant resolution.
     * (No variant, has feature_id, not manually locked.)
     *
     * @return array[]
     */
    public function getUnresolvedMappings(): array;

    // ── Stats / listing (admin controller) ──

    /**
     * Get per-feature-type stats for the dashboard.
     *
     * @return array<string, array>
     */
    public function getTypeStats(): array;

    /**
     * Get global mapping stats (total, active, unmapped, alias count).
     */
    public function getGlobalStats(): array;

    /**
     * Get total unmapped values count.
     */
    public function getUnmappedCount(): int;

    /**
     * Get paginated mappings with alias counts.
     *
     * @return array{items: array[], total: int}
     */
    public function getPaginatedMappings(string $condition, int $offset, int $limit): array;

    /**
     * Get stats for a single feature type.
     */
    public function getTypeStatsSingle(string $featureType): array;

    /**
     * Get paginated unmapped values.
     *
     * @return array{items: array[], total: int}
     */
    public function getPaginatedUnmapped(string $condition, int $offset, int $limit): array;

    /**
     * Get a single mapping by map_id.
     */
    public function getMappingById(int $mapId): ?array;

    /**
     * Get aliases for a mapping.
     */
    public function getAliasesForMapping(int $mapId): array;
}

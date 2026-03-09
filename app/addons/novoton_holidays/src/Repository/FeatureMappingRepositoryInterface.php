<?php
declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Repository;

interface FeatureMappingRepositoryInterface
{
    /**
     * Find a mapping by provider, feature type, and provider code.
     *
     * @return array|null Row from hotel_feature_mappings or null
     */
    public function findMapping(string $provider, string $featureType, string $providerCode): ?array;

    /**
     * Get all active mappings for a feature type, indexed by provider_code.
     *
     * @return array<string, array>
     */
    public function findByFeatureType(string $featureType, string $provider = 'novoton'): array;

    /**
     * Get the CS-Cart feature_id for a given feature type.
     */
    public function getFeatureId(string $featureType, string $provider = 'novoton'): ?int;

    /**
     * Get the cached CS-Cart feature type char (S or M) for a feature type.
     */
    public function getCsCartFeatureType(string $featureType, string $provider = 'novoton'): ?string;

    /**
     * Update the cs_cart_variant_id and optionally variant_source.
     *
     * @param string|null $variantSource 'auto' or 'manual' (null = don't change)
     */
    public function updateVariantId(int $mappingId, int $variantId, ?string $variantSource = null): bool;

    /**
     * Auto-register an unknown value with mapping_source='auto'.
     *
     * @return int The new mapping_id, or 0 on failure
     */
    public function registerUnmapped(string $provider, string $featureType, string $providerCode, string $displayName = ''): int;

    /**
     * Touch the last_synced_at timestamp.
     */
    public function updateLastSynced(int $mappingId): bool;

    /**
     * Get all mappings for a provider.
     */
    public function findAll(string $provider = 'novoton'): array;

    /**
     * Get mappings filtered by mapping_source.
     *
     * @param string $mappingSource 'seed', 'auto', or 'manual'
     */
    public function findBySource(string $mappingSource, string $provider = 'novoton'): array;

    /**
     * Save or update a mapping row.
     *
     * @param array $data Associative array of column => value
     * @return int The mapping_id (new or existing)
     */
    public function save(array $data): int;

    /**
     * Delete a mapping by ID.
     */
    public function delete(int $mappingId): bool;

    /**
     * Validate that a feature type string is allowed.
     */
    public function isValidFeatureType(string $type): bool;

    /**
     * Update the cached cs_cart_feature_type for all mappings of a given type.
     */
    public function updateCachedFeatureType(string $featureType, string $csCartFeatureType, string $provider = 'novoton'): void;

    /**
     * Find which feature_type a provider code is mapped to (across all feature types).
     * Used for data-driven facility routing during product sync.
     */
    public function findFeatureTypeForCode(string $provider, string $providerCode): ?string;
}

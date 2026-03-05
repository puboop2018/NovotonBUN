<?php
declare(strict_types=1);
/**
 * Feature Mapping Repository
 *
 * Database access for the hotel_feature_mappings table.
 * Includes in-memory cache to avoid repeated DB lookups during batch sync.
 *
 * @package NovotonHolidays
 * @since 3.3.0
 */

namespace Tygh\Addons\NovotonHolidays\Repository;

use Tygh\Addons\NovotonHolidays\Constants;

class FeatureMappingRepository implements FeatureMappingRepositoryInterface
{
    /** @var array<string, array|null> In-memory cache: "provider:featureType:providerCode" => row|null */
    private array $cache = [];

    /** @var array<string, int|null> Cached feature IDs: "featureType:provider" => feature_id */
    private array $featureIdCache = [];

    public function findMapping(string $provider, string $featureType, string $providerCode): ?array
    {
        $providerCode = trim($providerCode);
        $cacheKey = "{$provider}:{$featureType}:{$providerCode}";

        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        $row = db_get_row(
            "SELECT * FROM ?:hotel_feature_mappings WHERE provider = ?s AND feature_type = ?s AND provider_code = ?s AND is_active = 'Y'",
            $provider,
            $featureType,
            $providerCode
        );

        $result = !empty($row) ? $row : null;
        $this->cache[$cacheKey] = $result;

        return $result;
    }

    public function findByFeatureType(string $featureType, string $provider = 'novoton'): array
    {
        $rows = db_get_array(
            "SELECT * FROM ?:hotel_feature_mappings WHERE feature_type = ?s AND provider = ?s AND is_active = 'Y' ORDER BY position",
            $featureType,
            $provider
        );

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[$row['provider_code']] = $row;
        }

        return $indexed;
    }

    public function getFeatureId(string $featureType, string $provider = 'novoton'): ?int
    {
        $cacheKey = "{$featureType}:{$provider}";

        if (array_key_exists($cacheKey, $this->featureIdCache)) {
            return $this->featureIdCache[$cacheKey];
        }

        $featureId = db_get_field(
            "SELECT cs_cart_feature_id FROM ?:hotel_feature_mappings WHERE feature_type = ?s AND provider = ?s AND cs_cart_feature_id > 0 LIMIT 1",
            $featureType,
            $provider
        );

        $result = $featureId ? (int) $featureId : null;
        $this->featureIdCache[$cacheKey] = $result;

        return $result;
    }

    public function getCsCartFeatureType(string $featureType, string $provider = 'novoton'): ?string
    {
        $type = db_get_field(
            "SELECT cs_cart_feature_type FROM ?:hotel_feature_mappings WHERE feature_type = ?s AND provider = ?s LIMIT 1",
            $featureType,
            $provider
        );

        return $type ?: null;
    }

    public function updateVariantId(int $mappingId, int $variantId): bool
    {
        $this->clearCache();

        return (bool) db_query(
            "UPDATE ?:hotel_feature_mappings SET cs_cart_variant_id = ?i WHERE mapping_id = ?i",
            $variantId,
            $mappingId
        );
    }

    public function registerUnmapped(string $provider, string $featureType, string $providerCode, string $displayName = ''): int
    {
        $providerCode = trim($providerCode);

        if (!$this->isValidFeatureType($featureType)) {
            return 0;
        }

        // Determine feature_id from addon settings
        $settingKey = Constants::FEATURE_TYPE_TO_SETTING[$featureType] ?? '';
        $featureId = $settingKey ? (int) \Tygh\Registry::get($settingKey) : 0;

        // Determine default is_active for dynamic types
        $isActive = ($featureType === Constants::FEATURE_TYPE_RESORT) ? 'Y' : 'N';

        // Determine cs_cart_feature_type from existing mappings of same type or defaults
        $csCartFeatureType = $this->getCsCartFeatureType($featureType, $provider);
        if ($csCartFeatureType === null) {
            $csCartFeatureType = in_array($featureType, [
                Constants::FEATURE_TYPE_BOARD,
                Constants::FEATURE_TYPE_HOTEL_FACILITY,
                Constants::FEATURE_TYPE_ROOM_FACILITY,
            ], true) ? 'M' : 'S';
        }

        $mappingId = db_query(
            "INSERT IGNORE INTO ?:hotel_feature_mappings SET " .
            "provider = ?s, feature_type = ?s, provider_code = ?s, " .
            "cs_cart_feature_id = ?i, cs_cart_feature_type = ?s, " .
            "display_name_en = ?s, display_name_ro = ?s, " .
            "is_active = ?s, mapping_source = 'auto', " .
            "last_synced_at = NOW()",
            $provider,
            $featureType,
            $providerCode,
            $featureId,
            $csCartFeatureType,
            $displayName ?: $providerCode,
            $displayName ?: $providerCode,
            $isActive
        );

        $this->clearCache();

        return (int) $mappingId;
    }

    public function updateLastSynced(int $mappingId): bool
    {
        return (bool) db_query(
            "UPDATE ?:hotel_feature_mappings SET last_synced_at = NOW() WHERE mapping_id = ?i",
            $mappingId
        );
    }

    public function findAll(string $provider = 'novoton'): array
    {
        return db_get_array(
            "SELECT * FROM ?:hotel_feature_mappings WHERE provider = ?s ORDER BY feature_type, position",
            $provider
        );
    }

    public function findBySource(string $mappingSource, string $provider = 'novoton'): array
    {
        return db_get_array(
            "SELECT * FROM ?:hotel_feature_mappings WHERE mapping_source = ?s AND provider = ?s ORDER BY feature_type, position",
            $mappingSource,
            $provider
        );
    }

    public function save(array $data): int
    {
        if (isset($data['provider_code'])) {
            $data['provider_code'] = trim($data['provider_code']);
        }

        if (!empty($data['mapping_id'])) {
            $mappingId = (int) $data['mapping_id'];
            unset($data['mapping_id']);
            db_query("UPDATE ?:hotel_feature_mappings SET ?u WHERE mapping_id = ?i", $data, $mappingId);
            $this->clearCache();
            return $mappingId;
        }

        // Insert with ON DUPLICATE KEY UPDATE for idempotent seeding
        $provider = $data['provider'] ?? 'novoton';
        $featureType = $data['feature_type'] ?? '';
        $providerCode = $data['provider_code'] ?? '';

        $existing = db_get_field(
            "SELECT mapping_id FROM ?:hotel_feature_mappings WHERE provider = ?s AND feature_type = ?s AND provider_code = ?s",
            $provider,
            $featureType,
            $providerCode
        );

        if ($existing) {
            $mappingId = (int) $existing;
            unset($data['provider'], $data['feature_type'], $data['provider_code']);
            if (!empty($data)) {
                db_query("UPDATE ?:hotel_feature_mappings SET ?u WHERE mapping_id = ?i", $data, $mappingId);
            }
            $this->clearCache();
            return $mappingId;
        }

        $mappingId = db_query("INSERT INTO ?:hotel_feature_mappings ?e", $data);
        $this->clearCache();

        return (int) $mappingId;
    }

    public function delete(int $mappingId): bool
    {
        $this->clearCache();

        return (bool) db_query("DELETE FROM ?:hotel_feature_mappings WHERE mapping_id = ?i", $mappingId);
    }

    public function isValidFeatureType(string $type): bool
    {
        return in_array($type, Constants::VALID_FEATURE_TYPES, true);
    }

    /**
     * Clear all in-memory caches.
     */
    private function clearCache(): void
    {
        $this->cache = [];
        $this->featureIdCache = [];
    }
}

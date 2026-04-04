<?php
declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Repository;

use Tygh\Addons\TravelCore\Contracts\FeatureMapRepositoryInterface;

/**
 * Database-backed repository for travel_feature_map + travel_api_alias.
 *
 * All raw db_*() calls for the feature mapping system live here,
 * extracted from FeatureMapper and the admin controller.
 */
class FeatureMapRepository implements FeatureMapRepositoryInterface
{
    // ── Resolve / lookup ──

    /** Facility sub-types that the legacy 'facility' meta-type expands to */
    private const FACILITY_SUB_TYPES = ['hotel_facility', 'room_facility', 'beach_access'];

    public function findByAlias(string $apiSource, string $featureType, string $apiValue): ?array
    {
        // Legacy callers pass 'facility' — expand to all facility sub-types
        $typeCondition = ($featureType === 'facility')
            ? db_quote("m.feature_type IN (?a)", self::FACILITY_SUB_TYPES)
            : db_quote("m.feature_type = ?s", $featureType);

        $result = db_get_row(
            "SELECT m.map_id, m.feature_type, m.canonical_code, m.display_name_en, m.display_name_ro,
                    m.cscart_feature_id, m.cscart_variant_id, m.variant_source
             FROM ?:travel_api_alias a
             JOIN ?:travel_feature_map m ON m.map_id = a.map_id
             WHERE a.api_source = ?s AND {$typeCondition} AND m.status = 'A'
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
            $apiSource,
            $apiValue, $apiValue, $apiValue, $apiValue
        );

        return $result ?: null;
    }

    public function getDisplayName(string $featureType, string $canonicalCode, string $lang = 'en'): string
    {
        $column = $lang === 'ro' ? 'display_name_ro' : 'display_name_en';

        return (string) db_get_field(
            "SELECT `{$column}` FROM ?:travel_feature_map WHERE feature_type = ?s AND canonical_code = ?s AND status = 'A'",
            $featureType, $canonicalCode
        );
    }

    public function allCodes(string $featureType): array
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

    // ── Mutations ──

    public function insertMapping(string $featureType, string $canonicalCode, string $nameEn, string $nameRo, ?int $featureId): int
    {
        return (int) db_query(
            "INSERT IGNORE INTO ?:travel_feature_map
             (feature_type, canonical_code, display_name_en, display_name_ro,
              cscart_feature_id, mapping_source, status)
             VALUES (?s, ?s, ?s, ?s, ?i, 'auto', 'A')",
            $featureType, $canonicalCode, $nameEn, $nameRo,
            $featureId ?: null
        );
    }

    public function findMapId(string $featureType, string $canonicalCode): int
    {
        return (int) db_get_field(
            "SELECT map_id FROM ?:travel_feature_map WHERE feature_type = ?s AND canonical_code = ?s",
            $featureType, $canonicalCode
        );
    }

    public function updateVariantId(int $mapId, int $variantId, string $source = 'auto'): void
    {
        db_query(
            "UPDATE ?:travel_feature_map SET cscart_variant_id = ?i, variant_source = ?s WHERE map_id = ?i",
            $variantId, $source, $mapId
        );
    }

    public function updateFeatureId(int $mapId, int $featureId): void
    {
        db_query(
            "UPDATE ?:travel_feature_map SET cscart_feature_id = ?i WHERE map_id = ?i",
            $featureId, $mapId
        );
    }

    public function updateMapping(int $mapId, array $data): void
    {
        if (!empty($data)) {
            db_query("UPDATE ?:travel_feature_map SET ?u WHERE map_id = ?i", $data, $mapId);
        }
    }

    public function bulkUpdateStatus(array $mapIds, string $status): void
    {
        if (!empty($mapIds)) {
            db_query("UPDATE ?:travel_feature_map SET status = ?s WHERE map_id IN (?n)", $status, $mapIds);
        }
    }

    public function deleteMappings(array $mapIds): void
    {
        if (!empty($mapIds)) {
            db_query("DELETE FROM ?:travel_api_alias WHERE map_id IN (?n)", $mapIds);
            db_query("DELETE FROM ?:travel_feature_map WHERE map_id IN (?n)", $mapIds);
        }
    }

    // ── Aliases ──

    public function upsertAlias(string $apiSource, string $apiValue, int $mapId, string $matchType = 'exact'): void
    {
        db_query(
            "INSERT INTO ?:travel_api_alias (map_id, api_source, api_value, match_type)
             VALUES (?i, ?s, ?s, ?s)
             ON DUPLICATE KEY UPDATE map_id = ?i, match_type = ?s",
            $mapId, $apiSource, $apiValue, $matchType,
            $mapId, $matchType
        );
    }

    public function deleteAlias(int $aliasId): void
    {
        db_query("DELETE FROM ?:travel_api_alias WHERE alias_id = ?i", $aliasId);
    }

    // ── Unmapped values ──

    public function trackUnmapped(string $apiSource, string $featureType, string $apiValue, string $apiLabel = ''): void
    {
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

    public function deleteUnmapped(string $apiSource, string $featureType, string $apiValue): void
    {
        db_query(
            "DELETE FROM ?:travel_unmapped_values WHERE api_source = ?s AND feature_type = ?s AND api_value = ?s",
            $apiSource, $featureType, $apiValue
        );
    }

    public function getUnmappedById(int $unmappedId): ?array
    {
        $row = db_get_row("SELECT * FROM ?:travel_unmapped_values WHERE unmapped_id = ?i", $unmappedId);
        return $row ?: null;
    }

    // ── Batch operations ──

    public function batchUpdateLastUsed(array $mapIds): void
    {
        if (!empty($mapIds)) {
            db_query("UPDATE ?:travel_feature_map SET last_used_at = NOW() WHERE map_id IN (?n)", $mapIds);
        }
    }

    public function getFeatureTypesWithoutFeatureId(): array
    {
        return db_get_fields(
            "SELECT DISTINCT feature_type FROM ?:travel_feature_map WHERE cscart_feature_id = 0 OR cscart_feature_id IS NULL"
        );
    }

    public function bulkSetFeatureId(string $featureType, int $featureId): void
    {
        db_query(
            "UPDATE ?:travel_feature_map SET cscart_feature_id = ?i WHERE feature_type = ?s AND (cscart_feature_id = 0 OR cscart_feature_id IS NULL)",
            $featureId, $featureType
        );
    }

    public function getUnresolvedMappings(): array
    {
        return db_get_array(
            "SELECT * FROM ?:travel_feature_map
             WHERE (cscart_variant_id IS NULL OR cscart_variant_id = 0)
             AND cscart_feature_id > 0 AND status = 'A'
             AND (variant_source IS NULL OR variant_source != 'manual')"
        );
    }

    // ── Stats / listing ──

    public function getTypeStats(): array
    {
        return db_get_hash_array(
            "SELECT m.feature_type,
                    COUNT(*) AS total,
                    SUM(m.status = 'A') AS active,
                    SUM(m.cscart_variant_id IS NULL OR m.cscart_variant_id = 0) AS unmapped,
                    SUM(m.mapping_source = 'auto') AS auto_registered,
                    GROUP_CONCAT(DISTINCT a.api_source ORDER BY a.api_source) AS providers
             FROM ?:travel_feature_map m
             LEFT JOIN ?:travel_api_alias a ON a.map_id = m.map_id
             GROUP BY m.feature_type
             ORDER BY FIELD(m.feature_type, 'hotel_facility', 'room_facility', 'beach_access', 'board', 'resort', 'stars', 'property_type', 'travel_group', 'room_type', 'region', 'city')",
            'feature_type'
        );
    }

    public function getGlobalStats(): array
    {
        $row = db_get_row(
            "SELECT COUNT(*) AS total,
                    SUM(status = 'A') AS active,
                    SUM(cscart_variant_id IS NULL OR cscart_variant_id = 0) AS unmapped
             FROM ?:travel_feature_map"
        );

        return [
            'total'    => (int) ($row['total'] ?? 0),
            'active'   => (int) ($row['active'] ?? 0),
            'unmapped' => (int) ($row['unmapped'] ?? 0),
            'aliases'  => (int) db_get_field("SELECT COUNT(*) FROM ?:travel_api_alias"),
        ];
    }

    public function getUnmappedCount(): int
    {
        return (int) db_get_field("SELECT COUNT(*) FROM ?:travel_unmapped_values");
    }

    public function getPaginatedMappings(string $condition, int $offset, int $limit): array
    {
        $total = (int) db_get_field(
            "SELECT COUNT(*) FROM ?:travel_feature_map m WHERE 1 ?p",
            $condition
        );

        $items = db_get_array(
            "SELECT m.*, COUNT(a.alias_id) as alias_count,
                    GROUP_CONCAT(DISTINCT a.api_source ORDER BY a.api_source) as api_sources
             FROM ?:travel_feature_map m
             LEFT JOIN ?:travel_api_alias a ON a.map_id = m.map_id AND a.api_source != ''
             WHERE 1 ?p
             GROUP BY m.map_id
             ORDER BY m.position, m.canonical_code
             LIMIT ?i, ?i",
            $condition, $offset, $limit
        );

        return ['items' => $items, 'total' => $total];
    }

    public function getTypeStatsSingle(string $featureType): array
    {
        $row = db_get_row(
            "SELECT COUNT(*) AS total,
                    SUM(status = 'A') AS active,
                    SUM(cscart_variant_id IS NULL OR cscart_variant_id = 0) AS unmapped
             FROM ?:travel_feature_map WHERE feature_type = ?s",
            $featureType
        );

        return [
            'total'    => (int) ($row['total'] ?? 0),
            'active'   => (int) ($row['active'] ?? 0),
            'unmapped' => (int) ($row['unmapped'] ?? 0),
        ];
    }

    public function getPaginatedUnmapped(string $condition, int $offset, int $limit): array
    {
        $total = (int) db_get_field(
            "SELECT COUNT(*) FROM ?:travel_unmapped_values WHERE 1 ?p",
            $condition
        );

        $items = db_get_array(
            "SELECT * FROM ?:travel_unmapped_values WHERE 1 ?p ORDER BY hotel_count DESC, last_seen_at DESC LIMIT ?i, ?i",
            $condition, $offset, $limit
        );

        return ['items' => $items, 'total' => $total];
    }

    public function getMappingById(int $mapId): ?array
    {
        $row = db_get_row("SELECT * FROM ?:travel_feature_map WHERE map_id = ?i", $mapId);
        return $row ?: null;
    }

    public function getAliasesForMapping(int $mapId): array
    {
        return db_get_array(
            "SELECT * FROM ?:travel_api_alias WHERE map_id = ?i ORDER BY api_source, api_value",
            $mapId
        );
    }
}

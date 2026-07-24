<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Install;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\FeatureMapper;

/**
 * Seeds sphinx API aliases + whitelisted-region mappings into travel_core's
 * shared feature-mapping tables (travel_feature_map / travel_api_alias).
 *
 * Bodies extracted from func.php; the fn_sphinx_holidays_seed_aliases /
 * fn_sphinx_holidays_seed_region_mappings shells delegate here (post_install
 * and the init.php self-heal probes call those by name). Idempotent — alias
 * writes go through FeatureMapper::addAlias() (INSERT … ON DUPLICATE KEY
 * UPDATE) and region map rows use INSERT IGNORE.
 */
final class FeatureAliasSeeder
{
    /**
     * Seed Sphinx API aliases (board, room type, stars, property type,
     * facilities, beach access) mapping free-text API values to canonical
     * feature codes.
     *
     * @param string $tablePrefix The ?: table prefix, read at the func.php
     *                            boundary (Registry access is banned in src/).
     */
    public static function seedAliases(string $tablePrefix): void
    {
        if (!class_exists(FeatureMapper::class)) {
            return;
        }

        // Guard: travel_feature_map table may not exist if travel_core isn't installed yet
        if (!self::tableExists($tablePrefix, 'travel_feature_map')) {
            fn_log_event('general', 'runtime', [
                'message' => 'Sphinx: Skipping alias seeding — travel_feature_map table not found (travel_core not installed?)',
            ]);
            return;
        }

        self::seedAliasGroup('board', SphinxAliasSeedData::boardAliases(), 'exact');
        self::seedAliasGroup('room_type', SphinxAliasSeedData::roomAliases(), 'prefix');
        self::seedAliasGroup('stars', SphinxAliasSeedData::starAliases(), 'exact');
        self::seedAliasGroup('property_type', SphinxAliasSeedData::propertyTypeAliases(), 'exact');
        self::seedAliasGroup('hotel_facility', SphinxAliasSeedData::hotelFacilityAliases(), 'exact');
        self::seedAliasGroup('room_facility', SphinxAliasSeedData::roomFacilityAliases(), 'exact');
        self::seedAliasGroup('beach_access', SphinxAliasSeedData::beachAccessAliases(), 'exact');

        // Travel groups are NOT seeded as aliases — they're derived from facilities
        // at runtime via TravelGroupResolver::derive(). No API value mapping needed.

        // Clear resolve cache after batch alias inserts
        FeatureMapper::clearCache();
    }

    /**
     * Resolve canonical codes to map_ids and register one group of aliases.
     * Batch-loads all map_ids per feature type to avoid N+1 queries.
     *
     * @param array<int|string, string> $aliases api value => canonical code
     */
    private static function seedAliasGroup(string $featureType, array $aliases, string $matchType = 'exact'): void
    {
        $allMaps = db_get_hash_single_array(
            'SELECT canonical_code, map_id FROM ?:travel_feature_map WHERE feature_type = ?s',
            ['canonical_code', 'map_id'],
            $featureType,
        );
        $allMaps = is_array($allMaps) ? $allMaps : [];

        foreach ($aliases as $apiValue => $canonicalCode) {
            $mapId = TypeCoerce::toInt($allMaps[$canonicalCode] ?? 0);
            if ($mapId > 0) {
                FeatureMapper::addAlias('sphinx', (string) $apiValue, $mapId, $matchType);
            }
        }
    }

    /**
     * Seed whitelisted regions into travel_feature_map.
     *
     * For each region in the Destination Whitelist, creates a
     * travel_feature_map row (feature_type='region') and a travel_api_alias
     * linking the Sphinx destination_id, so SphinxFeatureAssigner::
     * assignRegion() resolves regions through the standard FeatureMapper
     * pipeline instead of creating variants ad-hoc.
     */
    public static function seedRegionMappings(string $tablePrefix): void
    {
        if (!class_exists(FeatureMapper::class)) {
            return;
        }

        if (!self::tableExists($tablePrefix, 'travel_feature_map') || !self::tableExists($tablePrefix, 'sphinx_destination_whitelist')) {
            return;
        }

        // Get all whitelisted regions:
        // 1) Regions under countries with selection_type='all' (all children included)
        // 2) Regions explicitly whitelisted with selection_type='specific'
        $regions = db_get_array(
            "SELECT d.destination_id, d.name, d.country_code
             FROM ?:sphinx_destinations d
             JOIN ?:sphinx_destination_whitelist w ON w.destination_id = d.parent_id AND w.selection_type = 'all'
             WHERE d.type = 'region'
             UNION
             SELECT d.destination_id, d.name, d.country_code
             FROM ?:sphinx_destinations d
             JOIN ?:sphinx_destination_whitelist w ON w.destination_id = d.destination_id AND w.selection_type = 'specific'
             WHERE d.type = 'region'",
        );

        if (!is_array($regions) || $regions === []) {
            return;
        }

        foreach ($regions as $region) {
            if (!is_array($region)) {
                continue;
            }
            $destId = TypeCoerce::toInt($region['destination_id'] ?? 0);
            $canonicalCode = 'region_' . $destId;
            $displayName = TypeCoerce::toString($region['name'] ?? '');

            db_query(
                "INSERT IGNORE INTO ?:travel_feature_map (feature_type, canonical_code, display_name_en, mapping_source, status)
                 VALUES ('region', ?s, ?s, 'auto', 'A')",
                $canonicalCode,
                $displayName,
            );

            $mapId = TypeCoerce::toInt(db_get_field(
                "SELECT map_id FROM ?:travel_feature_map WHERE feature_type = 'region' AND canonical_code = ?s",
                $canonicalCode,
            ));

            if ($mapId > 0) {
                FeatureMapper::addAlias('sphinx', (string) $destId, $mapId, 'exact');
            }
        }

        FeatureMapper::clearCache();
    }

    private static function tableExists(string $prefix, string $unprefixedTable): bool
    {
        return (bool) db_get_field(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?s',
            $prefix . $unprefixedTable,
        );
    }
}

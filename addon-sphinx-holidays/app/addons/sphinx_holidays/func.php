<?php
declare(strict_types=1);
/***************************************************************************
 *                                                                          *
 *   (c) 2024-2026 VacanteLitoral.ro                                       *
 *                                                                          *
 *   Location: app/addons/sphinx_holidays/func.php                         *
 *                                                                          *
 ***************************************************************************/

use Tygh\Addons\TravelCore\Services\FeatureMapper;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

/**
 * Addon uninstall function.
 * Drops Sphinx-specific tables and cleans up.
 */
function fn_sphinx_holidays_uninstall(): bool
{
    // Remove Sphinx aliases from shared feature mapping (table may not exist if travel_core already uninstalled)
    $tablePrefix = \Tygh\Registry::get('config.table_prefix');
    $aliasTableExists = db_get_field(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?s",
        $tablePrefix . 'travel_api_alias'
    );
    if ($aliasTableExists) {
        db_query("DELETE FROM ?:travel_api_alias WHERE api_source = 'sphinx'");
    }

    // Remove language variables
    db_query("DELETE FROM ?:language_values WHERE name LIKE 'sphinx_holidays.%'");

    // Drop Sphinx-specific tables (order matters for FK constraints)
    db_query("DROP TABLE IF EXISTS ?:sphinx_cache");
    db_query("DROP TABLE IF EXISTS ?:sphinx_sync_log");
    db_query("DROP TABLE IF EXISTS ?:sphinx_bookings");
    db_query("DROP TABLE IF EXISTS ?:sphinx_package_routes");
    db_query("DROP TABLE IF EXISTS ?:sphinx_destinations");
    db_query("DROP TABLE IF EXISTS ?:sphinx_hotels");

    return true;
}

/**
 * Post-install function.
 * Seeds Sphinx-specific aliases into the shared feature mapping.
 */
function fn_sphinx_holidays_post_install(): bool
{
    fn_sphinx_holidays_seed_aliases();
    return true;
}

/**
 * Seed Sphinx API aliases into the shared travel_api_alias table.
 * Maps Sphinx free-text values to canonical feature codes.
 *
 * Idempotent — uses FeatureMapper::addAlias() which does INSERT ON DUPLICATE KEY UPDATE.
 */
function fn_sphinx_holidays_seed_aliases(): void
{
    // Board/Meal aliases
    $boardAliases = [
        // Romanian free-text
        'All Inclusive'         => 'AI',
        'ALL INCLUSIVE PLUS'    => 'AI',
        'Ultra All Inclusive'   => 'UAI',
        'Pensiune completa'    => 'FB',
        'Pensiune completă'    => 'FB',
        'Full Board'           => 'FB',
        'Demipensiune'         => 'HB',
        'Half Board'           => 'HB',
        'Mic dejun'            => 'BB',
        'Bed & Breakfast'      => 'BB',
        'Bed and Breakfast'    => 'BB',
        'Fara masa'            => 'RO',
        'Fără masă'            => 'RO',
        'Room Only'            => 'RO',
        'ROOM ONLY'            => 'RO',
        'Self Catering'        => 'SC',
    ];

    // Room type aliases (prefix match)
    $roomAliases = [
        'Single Room'  => 'SGL',
        'Double Room'  => 'DBL',
        'Twin Room'    => 'TWIN',
        'Triple Room'  => 'TRP',
        'Quadruple'    => 'QUAD',
        'Suite'        => 'SUITE',
        'Apartment'    => 'APT',
        'Studio'       => 'STUDIO',
        'Family Room'  => 'DBL',
    ];

    // Guard: travel_feature_map table may not exist if travel_core isn't installed yet
    $tablePrefix = \Tygh\Registry::get('config.table_prefix');
    $tableExists = db_get_field(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?s",
        $tablePrefix . 'travel_feature_map'
    );
    if (!$tableExists) {
        fn_log_event('general', 'runtime', [
            'message' => 'Sphinx: Skipping alias seeding — travel_feature_map table not found (travel_core not installed?)',
        ]);
        return;
    }

    // Resolve map_ids from canonical codes and insert aliases
    foreach ($boardAliases as $apiValue => $canonicalCode) {
        $mapId = (int) db_get_field(
            "SELECT map_id FROM ?:travel_feature_map WHERE feature_type = 'board' AND canonical_code = ?s",
            $canonicalCode
        );
        if ($mapId > 0) {
            FeatureMapper::addAlias('sphinx', $apiValue, $mapId, 'exact');
        }
    }

    foreach ($roomAliases as $apiValue => $canonicalCode) {
        $mapId = (int) db_get_field(
            "SELECT map_id FROM ?:travel_feature_map WHERE feature_type = 'room_type' AND canonical_code = ?s",
            $canonicalCode
        );
        if ($mapId > 0) {
            FeatureMapper::addAlias('sphinx', $apiValue, $mapId, 'prefix');
        }
    }

    // Clear resolve cache after batch alias inserts
    FeatureMapper::clearCache();
}

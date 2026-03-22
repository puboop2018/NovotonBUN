<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Installation Functions
 * 
 * Functions for addon install, uninstall, and upgrades.
 * 
 * @package NovotonHolidays
 * @since 3.0.0
 */

use Tygh\Registry;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

/**
 * Addon uninstall function
 * Cleans up addon data and drops tables
 * 
 * @return bool
 */
function fn_novoton_holidays_uninstall(): bool
{
    // Remove Novoton aliases from shared feature mapping (table may not exist if travel_core already uninstalled)
    $tablePrefix = Registry::get('config.table_prefix');
    $aliasTableExists = db_get_field(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?s",
        $tablePrefix . 'travel_api_alias'
    );
    if ($aliasTableExists) {
        db_query("DELETE FROM ?:travel_api_alias WHERE api_source = 'novoton'");
    }

    // Remove auto-generated Theme Editor preset files
    fn_novoton_holidays_remove_theme_presets();

    // Clean up layout style references and logos tied to novoton_default
    // Reset any layout that uses novoton_default style back to empty (CS-Cart default)
    db_query("UPDATE ?:bm_layouts SET style_id = '' WHERE style_id = 'novoton_default'");
    // Remove logos created for the novoton_default style
    db_query("DELETE FROM ?:logos WHERE style_id = 'novoton_default'");

    // Remove product tabs
    $tab_ids = db_get_fields("SELECT tab_id FROM ?:product_tabs WHERE addon = ?s", 'novoton_holidays');
    if (!empty($tab_ids)) {
        db_query("DELETE FROM ?:product_tabs WHERE tab_id IN (?n)", $tab_ids);
        db_query("DELETE FROM ?:product_tabs_descriptions WHERE tab_id IN (?n)", $tab_ids);
    }
    
    // Clean up block manager blocks
    db_query("DELETE FROM ?:bm_blocks WHERE type LIKE 'novoton%'");
    
    // Remove email templates
    db_query("DELETE FROM ?:template_emails WHERE addon = ?s", 'novoton_holidays');
    
    // OPTIONAL: Delete products that were created by the addon
    $addon_settings = Registry::get('addons.novoton_holidays') ?? [];
    $delete_products = ($addon_settings['delete_products_on_uninstall'] ?? 'N') === 'Y';
    
    if ($delete_products) {
        $addon_product_ids = db_get_fields(
            "SELECT product_id FROM ?:novoton_hotels WHERE product_id > 0"
        );
        
        if (!empty($addon_product_ids)) {
            foreach ($addon_product_ids as $product_id) {
                fn_delete_product($product_id);
            }
            
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton uninstall: Deleted ' . count($addon_product_ids) . ' addon-created products'
            ]);
        }
    }
    
    // Remove addon language variables
    db_query("DELETE FROM ?:language_values WHERE name LIKE 'novoton_holidays.%'");

    // Remove novoton_reports directory and its contents
    $reports_dir = fn_get_files_dir_path() . 'novoton_reports/';
    if (is_dir($reports_dir)) {
        fn_rm($reports_dir);
    }

    // Remove novoton_logs directory and its contents
    $logs_dir = fn_get_files_dir_path() . 'novoton_logs/';
    if (is_dir($logs_dir)) {
        fn_rm($logs_dir);
    }

    // Remove API file cache directory (var/cache/novoton/)
    $cache_dir = Registry::get('config.dir.root') . '/var/cache/novoton/';
    if (is_dir($cache_dir)) {
        fn_rm($cache_dir);
    }

    // Drop all addon tables (in correct order due to foreign key constraints)
    db_query("DROP TABLE IF EXISTS ?:hotel_feature_mappings");
    db_query("DROP TABLE IF EXISTS ?:novoton_resorts");
    db_query("DROP TABLE IF EXISTS ?:novoton_hotel_facilities");
    db_query("DROP TABLE IF EXISTS ?:novoton_facilities");
    db_query("DROP TABLE IF EXISTS ?:novoton_alternative_requests");
    db_query("DROP TABLE IF EXISTS ?:novoton_cache");
    db_query("DROP TABLE IF EXISTS ?:novoton_sync_log");
    db_query("DROP TABLE IF EXISTS ?:novoton_bookings");
    db_query("DROP TABLE IF EXISTS ?:novoton_hotel_packages");
    // Hotels table last (other tables may reference it)
    db_query("DROP TABLE IF EXISTS ?:novoton_hotels");
    
    return true;
}

/**
 * Fix tab name if empty
 * 
 * @param int|null $tab_id Tab ID
 * @return bool
 */
function fn_novoton_holidays_fix_tab_name(?int $tab_id = null): bool
{
    if (empty($tab_id)) {
        $tab_id = db_get_field("SELECT tab_id FROM ?:product_tabs WHERE addon = ?s", 'novoton_holidays');
    }
    
    if ($tab_id) {
        $languages = db_get_array("SELECT lang_code FROM ?:languages WHERE status = 'A'");
        foreach ($languages as $lang) {
            $exists = db_get_field(
                "SELECT tab_id FROM ?:product_tabs_descriptions WHERE tab_id = ?i AND lang_code = ?s",
                $tab_id, $lang['lang_code']
            );
            
            if ($exists) {
                db_query(
                    "UPDATE ?:product_tabs_descriptions SET name = 'Hotel Prices' WHERE tab_id = ?i AND lang_code = ?s",
                    $tab_id, $lang['lang_code']
                );
            } else {
                db_query(
                    "INSERT INTO ?:product_tabs_descriptions SET tab_id = ?i, lang_code = ?s, name = 'Hotel Prices'",
                    $tab_id, $lang['lang_code']
                );
            }
        }
        
        return true;
    }
    
    return false;
}

/**
 * Post-install function
 * Called after addon installation to setup additional components
 * 
 * @return bool
 */
function fn_novoton_holidays_post_install(): bool
{
    // Find the tab created by CS-Cart
    $tab_id = db_get_field("SELECT tab_id FROM ?:product_tabs WHERE addon = ?s", 'novoton_holidays');
    
    if ($tab_id) {
        $languages = db_get_array("SELECT lang_code FROM ?:languages WHERE status = 'A'");
        
        foreach ($languages as $lang) {
            db_query(
                "INSERT INTO ?:product_tabs_descriptions (tab_id, lang_code, name) 
                 VALUES (?i, ?s, 'Hotel Prices')
                 ON DUPLICATE KEY UPDATE name = 'Hotel Prices'",
                $tab_id, $lang['lang_code']
            );
        }
    }
    
    // Verify Theme Editor preset files exist (shipped with addon package)
    fn_novoton_holidays_create_theme_presets();

    // Setup database constraints and language variables
    fn_novoton_holidays_setup_db();

    // Seed Novoton API aliases into shared travel_core feature mapping
    fn_novoton_holidays_seed_travel_aliases();

    // Create novoton_reports directory for report storage
    $reports_dir = fn_get_files_dir_path() . 'novoton_reports/';
    if (!is_dir($reports_dir)) {
        fn_mkdir($reports_dir);
    }

    return true;
}

/**
 * Seed Novoton API aliases into the shared travel_api_alias table.
 * Maps Novoton API values to canonical feature codes.
 *
 * Idempotent — uses FeatureMapper::addAlias() which does INSERT ON DUPLICATE KEY UPDATE.
 */
function fn_novoton_holidays_seed_travel_aliases(): void
{
    if (!class_exists(\Tygh\Addons\TravelCore\Services\FeatureMapper::class)) {
        return;
    }

    $featureMapper = \Tygh\Addons\TravelCore\Services\FeatureMapper::class;

    // Board/Meal aliases (Novoton XML API values)
    $boardAliases = [
        'AI'                   => 'AI',
        'ALL INCL'             => 'AI',
        'ALL INCLUSIVE'        => 'AI',
        'ALLINC'               => 'AI',
        'UAI'                  => 'UAI',
        'ULTRA ALL INCL'       => 'UAI',
        'ULTRA ALL INCLUSIVE'   => 'UAI',
        'FB'                   => 'FB',
        'FULL BOARD'           => 'FB',
        'FB+'                  => 'FB',
        'HB'                   => 'HB',
        'HALF BOARD'           => 'HB',
        'HB+'                  => 'HB',
        'BB'                   => 'BB',
        'BED AND BREAKFAST'    => 'BB',
        'B&B'                  => 'BB',
        'RO'                   => 'RO',
        'ROOM ONLY'            => 'RO',
        'SC'                   => 'SC',
        'SELF CATERING'        => 'SC',
    ];

    // Room type aliases (Novoton uses short codes)
    $roomAliases = [
        'SGL'     => 'SGL',
        'DBL'     => 'DBL',
        'TWIN'    => 'TWIN',
        'TRP'     => 'TRP',
        'QUAD'    => 'QUAD',
        'SUITE'   => 'SUITE',
        'APT'     => 'APT',
        'STUDIO'  => 'STUDIO',
    ];

    // Guard: travel_feature_map table may not exist if travel_core isn't installed yet
    $tableExists = db_get_field(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?s",
        Registry::get('config.table_prefix') . 'travel_feature_map'
    );
    if (!$tableExists) {
        fn_log_event('general', 'runtime', [
            'message' => 'Novoton: Skipping alias seeding — travel_feature_map table not found (travel_core not installed?)',
        ]);
        return;
    }

    foreach ($boardAliases as $apiValue => $canonicalCode) {
        $mapId = (int) db_get_field(
            "SELECT map_id FROM ?:travel_feature_map WHERE feature_type = 'board' AND canonical_code = ?s",
            $canonicalCode
        );
        if ($mapId > 0) {
            $featureMapper::addAlias('novoton', $apiValue, $mapId, 'exact');
        }
    }

    foreach ($roomAliases as $apiValue => $canonicalCode) {
        $mapId = (int) db_get_field(
            "SELECT map_id FROM ?:travel_feature_map WHERE feature_type = 'room_type' AND canonical_code = ?s",
            $canonicalCode
        );
        if ($mapId > 0) {
            $featureMapper::addAlias('novoton', $apiValue, $mapId, 'exact');
        }
    }

    // Star rating aliases (Novoton uses simple '1'-'5' codes, same as canonical)
    $starAliases = ['1' => '1', '2' => '2', '3' => '3', '4' => '4', '5' => '5'];
    foreach ($starAliases as $apiValue => $canonicalCode) {
        $mapId = (int) db_get_field(
            "SELECT map_id FROM ?:travel_feature_map WHERE feature_type = 'stars' AND canonical_code = ?s",
            $canonicalCode
        );
        if ($mapId > 0) {
            $featureMapper::addAlias('novoton', $apiValue, $mapId, 'exact');
        }
    }

    // Property type aliases (Novoton canonical codes match travel_core)
    $propertyTypeAliases = [
        'hotel'          => 'hotel',
        'villa'          => 'villa',
        'apartment'      => 'apartment',
        'resort'         => 'resort',
        'hostel'         => 'hostel',
        'guest_house'    => 'guest_house',
        'chalet'         => 'chalet',
        'motel'          => 'motel',
        'boarding_house' => 'boarding_house',
        'cabin'          => 'cabin',
    ];
    foreach ($propertyTypeAliases as $apiValue => $canonicalCode) {
        $mapId = (int) db_get_field(
            "SELECT map_id FROM ?:travel_feature_map WHERE feature_type = 'property_type' AND canonical_code = ?s",
            $canonicalCode
        );
        if ($mapId > 0) {
            $featureMapper::addAlias('novoton', $apiValue, $mapId, 'exact');
        }
    }

    // Clear resolve cache after batch alias inserts
    $featureMapper::clearCache();
}

/**
 * Setup database constraints and language variables.
 *
 * Creates foreign keys (not in CREATE TABLE to avoid install-order issues)
 * and inserts language variables used by templates.
 *
 * @return void
 */
function fn_novoton_holidays_setup_db(): void
{
    $table_prefix = Registry::get('config.table_prefix');
    $resolve = function (string $table) use ($table_prefix): string {
        return str_replace('?:', $table_prefix, $table);
    };

    // ── Language variables ──
    $lang_vars = [
        'novoton_holidays.until'                     => ['en' => 'until',                     'ro' => 'până la'],
        'novoton_holidays.free_cancellation'          => ['en' => 'Free Cancellation',          'ro' => 'Anulare gratuită'],
        'novoton_holidays.free_cancellation_until'    => ['en' => 'Free cancellation until',    'ro' => 'Anulare gratuită până la'],
        'novoton_holidays.on_booking'                 => ['en' => 'on booking',                 'ro' => 'la rezervare'],
    ];

    foreach ($lang_vars as $name => $translations) {
        foreach ($translations as $lang_code => $value) {
            $exists = db_get_field(
                "SELECT COUNT(*) FROM ?:language_values WHERE name = ?s AND lang_code = ?s",
                $name, $lang_code
            );
            if (!$exists) {
                db_query(
                    "INSERT INTO ?:language_values (name, lang_code, value) VALUES (?s, ?s, ?s)",
                    $name, $lang_code, $value
                );
            }
        }
    }

    // ── Schema migrations (idempotent — only adds columns if missing) ──
    $migrations = [
        [
            'table'   => '?:novoton_hotels',
            'column'  => 'calendar_prices_raw',
            'sql'     => "ALTER TABLE ?:novoton_hotels ADD COLUMN `calendar_prices_raw` JSON COMMENT 'JSON: precomputed per-date raw EUR prices for calendar display' AFTER `last_price_check`",
        ],
        [
            'table'   => '?:novoton_hotel_packages',
            'column'  => 'needs_price_compute',
            'sql'     => "ALTER TABLE ?:novoton_hotel_packages ADD COLUMN `needs_price_compute` enum('Y','N') DEFAULT 'N' COMMENT 'Flag: price metadata needs recomputation by compute_prices cron' AFTER `currency`",
            'post_sql' => "ALTER TABLE ?:novoton_hotel_packages ADD KEY `idx_needs_price_compute` (`needs_price_compute`)",
        ],
        [
            'table'   => '?:novoton_hotels',
            'column'  => 'property_type',
            'sql'     => "ALTER TABLE ?:novoton_hotels ADD COLUMN `property_type` varchar(20) DEFAULT 'hotel' COMMENT 'Detected: hotel,villa,apartment,chalet,guest_house,resort,hostel,motel,boarding_house,cabin' AFTER `star_rating`",
            'post_sql' => "ALTER TABLE ?:novoton_hotels ADD KEY `idx_property_type` (`property_type`)",
        ],
        [
            'table'   => '?:novoton_hotels',
            'column'  => 'is_adults_only',
            'sql'     => "ALTER TABLE ?:novoton_hotels ADD COLUMN `is_adults_only` enum('Y','N') DEFAULT 'N' COMMENT 'Detected from hotel name: Adults Only, 18+, etc.' AFTER `property_type`",
        ],
        [
            'table'   => '?:hotel_feature_mappings',
            'column'  => 'variant_source',
            'sql'     => "ALTER TABLE ?:hotel_feature_mappings ADD COLUMN `variant_source` enum('auto','manual') DEFAULT NULL COMMENT 'How variant was resolved: auto=name-match/create, manual=admin override' AFTER `mapping_source`",
        ],
    ];

    foreach ($migrations as $migration) {
        $col_exists = db_get_field(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = ?s AND COLUMN_NAME = ?s",
            $resolve($migration['table']), $migration['column']
        );
        if (!$col_exists) {
            @db_query($migration['sql']);
            if (!empty($migration['post_sql'])) {
                @db_query($migration['post_sql']);
            }
        }
    }

    // ── Feature type rename: star_rating → property_rating, board → meals ──
    // Updates existing data in hotel_feature_mappings and addon settings
    $mappingsTable = $resolve('?:hotel_feature_mappings');
    $hasOldStarRating = (int) db_get_field(
        "SELECT COUNT(*) FROM ?:hotel_feature_mappings WHERE feature_type = 'star_rating'"
    );
    if ($hasOldStarRating > 0) {
        @db_query("UPDATE ?:hotel_feature_mappings SET feature_type = 'property_rating' WHERE feature_type = 'star_rating'");
    }
    $hasOldBoard = (int) db_get_field(
        "SELECT COUNT(*) FROM ?:hotel_feature_mappings WHERE feature_type = 'board'"
    );
    if ($hasOldBoard > 0) {
        @db_query("UPDATE ?:hotel_feature_mappings SET feature_type = 'meals' WHERE feature_type = 'board'");
    }

    // Migrate addon settings keys: feature_id_star_rating → feature_id_property_rating, feature_id_board → feature_id_meals
    $settingRenames = [
        'feature_id_star_rating' => 'feature_id_property_rating',
        'feature_id_board'       => 'feature_id_meals',
    ];
    foreach ($settingRenames as $oldName => $newName) {
        $oldExists = db_get_field(
            "SELECT object_id FROM ?:settings_objects WHERE name = ?s AND section_id IN (SELECT section_id FROM ?:settings_sections WHERE name = 'novoton_holidays')",
            $oldName
        );
        if ($oldExists) {
            @db_query("UPDATE ?:settings_objects SET name = ?s WHERE name = ?s AND section_id IN (SELECT section_id FROM ?:settings_sections WHERE name = 'novoton_holidays')", $newName, $oldName);
        }
    }

    // ── Property type code migration: hyphens → underscores ──
    // Aligns Novoton codes with travel_core canonical codes (e.g. guest-house → guest_house)
    $hyphenRenames = [
        'guest-house'    => 'guest_house',
        'boarding-house' => 'boarding_house',
    ];
    foreach ($hyphenRenames as $oldCode => $newCode) {
        @db_query("UPDATE ?:novoton_hotels SET property_type = ?s WHERE property_type = ?s", $newCode, $oldCode);
        @db_query("UPDATE ?:hotel_feature_mappings SET provider_code = ?s WHERE provider_code = ?s AND feature_type = 'property_type'", $newCode, $oldCode);
    }

    // ── Facility type migration: enum('hotel','room') → varchar(30) feature type ──
    // Allows each facility to map directly to a CS-Cart feature type (hotel_facility,
    // room_facility, travel_group, beach_access, etc.) instead of just hotel/room.
    $facTable = $resolve('?:novoton_facilities');
    $colType = db_get_field(
        "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = ?s AND COLUMN_NAME = 'facility_type'",
        $facTable
    );
    if ($colType && str_contains(strtolower($colType), strtolower('enum'))) {
        @db_query("ALTER TABLE ?:novoton_facilities MODIFY COLUMN `facility_type` varchar(30) NOT NULL DEFAULT 'hotel_facility' COMMENT 'CS-Cart feature type: hotel_facility, room_facility, travel_group, beach_access, etc.'");
        // Convert legacy enum values to feature type constants
        @db_query("UPDATE ?:novoton_facilities SET facility_type = 'hotel_facility' WHERE facility_type = 'hotel'");
        @db_query("UPDATE ?:novoton_facilities SET facility_type = 'room_facility'  WHERE facility_type = 'room'");
    }

    // ── Cache table migration: TIMESTAMP → INT UNSIGNED for expires_at/created_at ──
    // Aligns with sphinx_cache (INT unix timestamp) for consistency and performance
    $cacheTable = $resolve('?:novoton_cache');
    $cacheExpiresType = db_get_field(
        "SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = ?s AND COLUMN_NAME = 'expires_at'",
        $cacheTable
    );
    if ($cacheExpiresType && strtolower($cacheExpiresType) === 'timestamp') {
        // Convert existing TIMESTAMP values to unix timestamps, then change column type
        @db_query("ALTER TABLE ?:novoton_cache ADD COLUMN `expires_at_new` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `expires_at`");
        @db_query("UPDATE ?:novoton_cache SET `expires_at_new` = UNIX_TIMESTAMP(`expires_at`)");
        @db_query("ALTER TABLE ?:novoton_cache DROP COLUMN `expires_at`");
        @db_query("ALTER TABLE ?:novoton_cache CHANGE `expires_at_new` `expires_at` INT UNSIGNED NOT NULL COMMENT 'Unix timestamp'");
        @db_query("ALTER TABLE ?:novoton_cache ADD KEY `idx_expires` (`expires_at`)");
        // Also convert created_at if it's a TIMESTAMP
        @db_query("ALTER TABLE ?:novoton_cache ADD COLUMN `created_at_new` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `created_at`");
        @db_query("UPDATE ?:novoton_cache SET `created_at_new` = UNIX_TIMESTAMP(`created_at`)");
        @db_query("ALTER TABLE ?:novoton_cache DROP COLUMN `created_at`");
        @db_query("ALTER TABLE ?:novoton_cache CHANGE `created_at_new` `created_at` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Unix timestamp'");
    }

    // ── Foreign key constraints (idempotent — only adds if missing) ──
    $foreign_keys = [
        [
            'table'       => '?:novoton_hotel_packages',
            'constraint'  => 'fk_nhp_hotel_id',
            'column'      => 'hotel_id',
            'ref_table'   => '?:novoton_hotels',
            'ref_column'  => 'hotel_id',
            'on_delete'   => 'CASCADE',
        ],
        [
            'table'       => '?:novoton_hotel_facilities',
            'constraint'  => 'fk_nhf_hotel_id',
            'column'      => 'hotel_id',
            'ref_table'   => '?:novoton_hotels',
            'ref_column'  => 'hotel_id',
            'on_delete'   => 'CASCADE',
        ],
        [
            'table'       => '?:novoton_hotel_facilities',
            'constraint'  => 'fk_nhf_facility_id',
            'column'      => 'facility_id',
            'ref_table'   => '?:novoton_facilities',
            'ref_column'  => 'facility_id',
            'on_delete'   => 'CASCADE',
        ],
        [
            'table'       => '?:novoton_bookings',
            'constraint'  => 'fk_nb_hotel_id',
            'column'      => 'hotel_id',
            'ref_table'   => '?:novoton_hotels',
            'ref_column'  => 'hotel_id',
            'on_delete'   => 'RESTRICT',
        ],
        [
            'table'       => '?:novoton_alternative_requests',
            'constraint'  => 'fk_nar_hotel_id',
            'column'      => 'hotel_id',
            'ref_table'   => '?:novoton_hotels',
            'ref_column'  => 'hotel_id',
            'on_delete'   => 'RESTRICT',
        ],
    ];

    foreach ($foreign_keys as $fk) {
        $fk_exists = db_get_field(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = ?s AND CONSTRAINT_NAME = ?s AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
            $resolve($fk['table']), $fk['constraint']
        );
        if (!$fk_exists) {
            @db_query(
                "ALTER TABLE {$fk['table']}
                 ADD CONSTRAINT `{$fk['constraint']}`
                 FOREIGN KEY (`{$fk['column']}`) REFERENCES {$fk['ref_table']}(`{$fk['ref_column']}`)
                 ON DELETE {$fk['on_delete']} ON UPDATE CASCADE"
            );
        }
    }
}

/**
 * Ensure the feature mapping table is populated.
 *
 * Checks whether `hotel_feature_mappings` has active rows for each feature
 * type whose addon setting (feature_id_*) is configured (> 0).
 * If mappings are missing, calls the seed function to populate them.
 *
 * Returns diagnostic info about which feature types are configured vs unconfigured.
 *
 * Idempotent — safe to call on every cron run (fast no-op when mappings exist).
 *
 * @return array{configured: string[], unconfigured: string[], seeded: int}
 */
function fn_novoton_holidays_ensure_feature_mappings(): array
{
    $configured   = [];
    $unconfigured = [];
    $needsSeed    = false;

    foreach (\Tygh\Addons\NovotonHolidays\Constants::FEATURE_TYPE_TO_SETTING as $featureType => $settingKey) {
        $featureId = (int) Registry::get($settingKey);

        if ($featureId <= 0) {
            $unconfigured[] = $featureType;
            continue;
        }

        $configured[] = $featureType;

        // Check if this feature type has any active mappings
        $count = (int) db_get_field(
            "SELECT COUNT(*) FROM ?:hotel_feature_mappings WHERE feature_type = ?s AND cs_cart_feature_id = ?i AND is_active = 'Y'",
            $featureType,
            $featureId
        );

        if ($count === 0) {
            $needsSeed = true;
        }
    }

    $seeded = 0;
    if ($needsSeed && !empty($configured)) {
        $seedResult = fn_novoton_holidays_seed_feature_mappings();
        $seeded = $seedResult['seeded'] ?? 0;
    }

    return [
        'configured'   => $configured,
        'unconfigured' => $unconfigured,
        'seeded'       => $seeded,
    ];
}

/**
 * Verify addon theme assets are in place.
 *
 * Addon color fields are registered via schema.post.php (Theme Editor
 * schema extension) — no core files are modified.  Default LESS variable
 * values live in the addon's own styles.less.
 *
 * This function only performs legacy cleanup (removing old novoton_default
 * preset artefacts from previous versions).
 *
 * Called from fn_novoton_holidays_post_install().
 *
 * @return void
 */
function fn_novoton_holidays_create_theme_presets(): void
{
    // No-op: color fields are registered via schema.post.php,
    // LESS variable defaults are in styles.less.
    // Legacy cleanup is handled by fn_novoton_holidays_remove_theme_presets().
}

/**
 * Clean up addon theme artefacts on uninstall.
 *
 * Addon color fields are registered via schema.post.php — CS-Cart
 * automatically stops loading them when the addon is disabled/removed,
 * so no schema.json cleanup is needed.
 *
 * This function only removes legacy novoton_default preset files that
 * may have been created by older addon versions.
 *
 * @return void
 */
function fn_novoton_holidays_remove_theme_presets(): void
{
    $root = rtrim(Registry::get('config.dir.root'), '/');
    $themes = ['nova_theme', 'responsive'];

    foreach ($themes as $theme) {
        $styles_dir = "{$root}/design/themes/{$theme}/styles";

        // Legacy cleanup: novoton_default preset artefacts
        $flat_file = "{$styles_dir}/data/novoton_default.less";
        if (file_exists($flat_file)) {
            unlink($flat_file);
        }

        $dir = "{$styles_dir}/data/novoton_default";
        if (is_dir($dir)) {
            $dir_file = "{$dir}/styles.less";
            if (file_exists($dir_file)) {
                unlink($dir_file);
            }
            if (count(scandir($dir)) === 2) {
                @rmdir($dir);
            }
        }

        $manifest_path = "{$styles_dir}/manifest.json";
        if (!file_exists($manifest_path)) {
            continue;
        }

        $content = file_get_contents($manifest_path);
        $manifest = ($content !== false) ? json_decode($content, true) : null;
        if (!is_array($manifest)) {
            continue;
        }

        $changed = false;
        if (isset($manifest['names']['novoton_default'])) {
            unset($manifest['names']['novoton_default']);
            $changed = true;
        }
        if (isset($manifest['default']) && is_array($manifest['default'])) {
            $key = array_search('novoton_default', $manifest['default'], true);
            if ($key !== false) {
                array_splice($manifest['default'], $key, 1);
                $changed = true;
            }
        }
        if (($manifest['default_style'] ?? '') === 'novoton_default') {
            $remaining = array_keys($manifest['names'] ?? []);
            $manifest['default_style'] = $remaining[0] ?? '';
            $changed = true;
        }
        if ($changed) {
            file_put_contents($manifest_path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        }
    }
}

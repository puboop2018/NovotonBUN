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
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

/**
 * Addon uninstall function
 * Cleans up addon data and drops tables
 * 
 * @return bool
 */
function fn_novoton_holidays_uninstall(): bool
{
    // Remove auto-generated Theme Editor preset files
    fn_novoton_holidays_remove_theme_presets();

    // Clean up layout style references and logos tied to novoton_default
    // Reset any layout that uses novoton_default style back to empty (CS-Cart default)
    db_query("UPDATE ?:bm_layouts SET style_id = '' WHERE style_id = 'novoton_default'");
    // Remove logos created for the novoton_default style
    db_query("DELETE FROM ?:logos WHERE style_id = 'novoton_default'");

    // Remove product tabs
    $tab_ids = db_get_fields("SELECT tab_id FROM ?:product_tabs WHERE addon = 'novoton_holidays'");
    if (!empty($tab_ids)) {
        db_query("DELETE FROM ?:product_tabs WHERE tab_id IN (?n)", $tab_ids);
        db_query("DELETE FROM ?:product_tabs_descriptions WHERE tab_id IN (?n)", $tab_ids);
    }
    
    // Clean up block manager blocks
    db_query("DELETE FROM ?:bm_blocks WHERE type LIKE 'novoton%'");
    
    // Remove email templates
    db_query("DELETE FROM ?:template_emails WHERE addon = 'novoton_holidays'");
    
    // OPTIONAL: Delete products that were created by the addon
    $delete_products = ConfigProvider::isDeleteProductsOnUninstall();
    
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
    
    // Drop all addon tables (in correct order due to foreign key constraints)
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
        $tab_id = db_get_field("SELECT tab_id FROM ?:product_tabs WHERE addon = 'novoton_holidays'");
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
    $tab_id = db_get_field("SELECT tab_id FROM ?:product_tabs WHERE addon = 'novoton_holidays'");
    
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

    // Create novoton_reports directory for report storage
    $reports_dir = fn_get_files_dir_path() . 'novoton_reports/';
    if (!is_dir($reports_dir)) {
        fn_mkdir($reports_dir);
    }

    return true;
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
            'sql'     => "ALTER TABLE ?:novoton_hotels ADD COLUMN `property_type` varchar(20) DEFAULT 'hotel' COMMENT 'Detected: hotel,villa,apartment,chalet,guest-house,resort,hostel,motel,boarding-house,cabin' AFTER `star_rating`",
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

        $manifest = @json_decode(file_get_contents($manifest_path), true);
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

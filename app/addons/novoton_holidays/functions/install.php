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
use Tygh\Tygh;
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
    // Clean up legacy AJAX price handler from CS-Cart root (if still present from older versions)
    $ajax_file = Registry::get('config.dir.root') . '/novoton_ajax_price.php';
    if (file_exists($ajax_file)) {
        @unlink($ajax_file);
    }

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
    // Legacy tables (from older versions, may not exist)
    db_query("DROP TABLE IF EXISTS ?:novoton_early_booking");
    db_query("DROP TABLE IF EXISTS ?:novoton_seasons");
    db_query("DROP TABLE IF EXISTS ?:novoton_hotel_prices");
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
function fn_novoton_holidays_fix_tab_name($tab_id = null): bool
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
    // Legacy: standalone ajax_price.php no longer needed — controller mode used instead.
    // Clean up any leftover copy in CS-Cart root from prior versions.
    $legacy_ajax = Registry::get('config.dir.root') . '/novoton_ajax_price.php';
    if (file_exists($legacy_ajax)) {
        @unlink($legacy_ajax);
    }

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
    
    // Create Theme Editor preset files (must live at design/themes/{theme}/styles/data/)
    fn_novoton_holidays_create_theme_presets();

    // Upgrade database schema
    fn_novoton_holidays_upgrade_db();

    // Create novoton_reports directory for report storage
    $reports_dir = fn_get_files_dir_path() . 'novoton_reports/';
    if (!is_dir($reports_dir)) {
        fn_mkdir($reports_dir);
    }

    // Note: Email templates are registered via addon.xml <email_templates> section.
    // Do NOT call fn_novoton_holidays_install_email_templates() here — it would
    // create duplicates in cscart_template_emails since CS-Cart already processes
    // the XML templates before calling post_install.

    return true;
}

/**
 * Install email templates programmatically
 * For upgrades from older versions
 * 
 * @return bool
 */
function fn_novoton_holidays_install_email_templates(): bool
{
    if (!class_exists('\Tygh\Template\Mail\Service')) {
        return false;
    }
    
    try {
        /** @var \Tygh\Template\Mail\Service $service */
        $service = Tygh::$app['template.mail.service'];
        
        $templates = [
            [
                'code' => 'novoton_holidays_import_report',
                'area' => 'A',
                'status' => 'A',
                'default_subject' => '[Novoton] {{ import_type_label }} Report - {{ country }} - {{ date }}',
                'default_template' => '{{ snippet("header") }}
<h2>Novoton Holidays Import Report</h2>
<table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
    <tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>Type:</strong></td><td style="padding: 8px; border: 1px solid #ddd;">{{ import_type_label }}</td></tr>
    <tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>Country:</strong></td><td style="padding: 8px; border: 1px solid #ddd;">{{ country }}</td></tr>
    <tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>Date:</strong></td><td style="padding: 8px; border: 1px solid #ddd;">{{ date }}</td></tr>
</table>
<h3>Summary</h3>
<table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
    <tr style="background: #f5f5f5;"><td style="padding: 8px; border: 1px solid #ddd;"><strong>Added:</strong></td><td style="padding: 8px; border: 1px solid #ddd;">{{ summary.added }}</td></tr>
    <tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>Updated:</strong></td><td style="padding: 8px; border: 1px solid #ddd;">{{ summary.updated }}</td></tr>
    <tr style="background: #f5f5f5;"><td style="padding: 8px; border: 1px solid #ddd;"><strong>Skipped:</strong></td><td style="padding: 8px; border: 1px solid #ddd;">{{ summary.skipped }}</td></tr>
    <tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>Errors:</strong></td><td style="padding: 8px; border: 1px solid #ddd;">{{ summary.errors }}</td></tr>
    <tr style="background: #f5f5f5;"><td style="padding: 8px; border: 1px solid #ddd;"><strong>Duration:</strong></td><td style="padding: 8px; border: 1px solid #ddd;">{{ summary.duration }}</td></tr>
</table>
{% if summary.errors > 0 %}<p style="color: #c00;">There were errors during import. Check the attached CSV for details.</p>{% endif %}
<p>See attached CSV file for detailed results.</p>
{{ snippet("footer") }}',
                'addon' => 'novoton_holidays'
            ],
            [
                'code' => 'novoton_holidays_alternatives_available',
                'area' => 'C',
                'status' => 'A',
                'default_subject' => 'Alternative Hotels Available for Your Booking',
                'default_template' => '{{ snippet("header") }}
<h2>Alternative Hotels Are Now Available</h2>
<p>Dear {{ customer_name }},</p>
<p>We have found alternative options for your booking at {{ hotel_name }} (Order #{{ order_id }}).</p>
<p>Please contact us if you would like to switch to one of these alternatives.</p>
{{ snippet("footer") }}',
                'addon' => 'novoton_holidays'
            ],
            [
                'code' => 'novoton_holidays_alternatives_request',
                'area' => 'C',
                'status' => 'A',
                'default_subject' => 'Alternative Request Received - Your Booking',
                'default_template' => '{{ snippet("header") }}
<h2>Your Alternative Request Has Been Received</h2>
<p>Dear {{ customer_name }},</p>
<p>We have received your request for alternative options for {{ hotel_name }}.</p>
<p>We will notify you as soon as alternative options become available.</p>
{{ snippet("footer") }}',
                'addon' => 'novoton_holidays'
            ]
        ];
        
        foreach ($templates as $template_data) {
            $exists = db_get_field(
                "SELECT template_id FROM ?:template_emails WHERE code = ?s",
                $template_data['code']
            );
            
            if (!$exists) {
                $service->createTemplate($template_data);
            }
        }
        
        return true;
    } catch (\Exception $e) {
        fn_log_event('novoton', 'error', 'Failed to create email templates: ' . $e->getMessage());
        return false;
    }
}

/**
 * Upgrade database schema
 * Adds new columns if they don't exist (for upgrades).
 * Uses data-driven migration tables instead of repetitive per-column blocks.
 *
 * @return void
 */
function fn_novoton_holidays_upgrade_db()
{
    // Resolve ?:-prefixed table names to actual DB table names (e.g. cscart_novoton_hotels)
    // for use in INFORMATION_SCHEMA queries where ?s bound params don't resolve the prefix.
    $table_prefix = Registry::get('config.table_prefix');
    $resolve = function (string $table) use ($table_prefix): string {
        return str_replace('?:', $table_prefix, $table);
    };

    // ── Column additions (table => [[column, definition, ?key], ...]) ──
    $add_columns = [
        '?:novoton_hotels' => [
            ['region',           "VARCHAR(100) DEFAULT NULL AFTER `city`"],
            ['has_prices',       "ENUM('Y','N') DEFAULT NULL"],
            ['last_price_check', "DATETIME DEFAULT NULL"],
            ['hotel_type',       "VARCHAR(50) DEFAULT '' AFTER `country`"],
            ['latitude',         "DECIMAL(10,7) DEFAULT NULL AFTER `hotel_type`"],
            ['longitude',        "DECIMAL(10,7) DEFAULT NULL AFTER `latitude`"],
        ],
        '?:novoton_bookings' => [
            ['num_rooms',                      "INT(11) DEFAULT 1 AFTER `children_ages`"],
            ['rooms_data',                     "JSON AFTER `num_rooms`"],
            ['session_id',                     "VARCHAR(64) DEFAULT NULL AFTER `user_id`",   'idx_session'],
            ['holder_name',                    "VARCHAR(255) DEFAULT '' AFTER `guest_name`"],
            ['terms_of_payment_raw',           "LONGTEXT DEFAULT NULL AFTER `api_response`"],
            ['terms_of_cancellation_raw',      "LONGTEXT DEFAULT NULL AFTER `api_response`"],
            ['terms_of_payment_formatted',     "LONGTEXT DEFAULT NULL AFTER `api_response`"],
            ['terms_of_cancellation_formatted',"LONGTEXT DEFAULT NULL AFTER `api_response`"],
            ['board_name',                     "VARCHAR(100) DEFAULT NULL AFTER `board_id`"],
            ['item_id',                        "VARCHAR(32) DEFAULT NULL AFTER `board_name`", 'idx_item_id'],
            ['room_number',                    "INT(2) DEFAULT 1 AFTER `rooms_data`"],
            ['total_rooms',                    "INT(2) DEFAULT 1 AFTER `room_number`"],
        ],
        '?:novoton_facilities' => [
            ['facility_name_en', "VARCHAR(255) DEFAULT '' AFTER `facility_name`"],
            ['facility_name_ro', "VARCHAR(255) DEFAULT '' AFTER `facility_name_en`"],
        ],
        '?:novoton_alternative_requests' => [
            ['nights',    "INT(3) DEFAULT NULL AFTER `check_out`"],
            ['num_rooms', "INT(2) NOT NULL DEFAULT 1 AFTER `nights`"],
        ],
    ];

    foreach ($add_columns as $table => $columns) {
        $resolved_table = $resolve($table);
        foreach ($columns as $spec) {
            [$column, $definition] = $spec;
            $key = $spec[2] ?? null;

            $exists = db_get_field(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                 AND TABLE_NAME = ?s AND COLUMN_NAME = ?s",
                $resolved_table, $column
            );
            if ($exists) {
                continue;
            }

            $sql = "ALTER TABLE {$table} ADD COLUMN `{$column}` {$definition}";
            if ($key) {
                $sql .= ", ADD KEY `{$key}` (`{$column}`)";
            }
            @db_query($sql);
        }
    }

    // ── Missing indexes for query performance ──
    $add_indexes = [
        '?:novoton_bookings' => [
            'idx_user_id'    => 'user_id',
            'idx_status'     => 'status',
            'idx_hotel_id'   => 'hotel_id',
            'idx_created_at' => 'created_at',
        ],
        '?:novoton_alternative_requests' => [
            'idx_status'     => 'status',
        ],
    ];

    foreach ($add_indexes as $table => $indexes) {
        $resolved_table = $resolve($table);
        foreach ($indexes as $index_name => $column) {
            $idx_exists = db_get_field(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                 AND TABLE_NAME = ?s AND INDEX_NAME = ?s",
                $resolved_table, $index_name
            );
            if (!$idx_exists) {
                @db_query("ALTER TABLE {$table} ADD INDEX `{$index_name}` (`{$column}`)");
            }
        }
    }

    // ── Column type changes ──
    $sync_type_info = db_get_row(
        "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = ?s AND COLUMN_NAME = 'sync_type'",
        $resolve('?:novoton_sync_log')
    );
    if ($sync_type_info && strpos($sync_type_info['COLUMN_TYPE'], 'enum') !== false) {
        @db_query("ALTER TABLE ?:novoton_sync_log MODIFY COLUMN `sync_type` VARCHAR(50) NOT NULL DEFAULT 'hotels'");
    }

    // ── Data migration: copy facility_name → facility_name_en ──
    $has_old = db_get_field("SHOW COLUMNS FROM ?:novoton_facilities LIKE 'facility_name'");
    if (!empty($has_old)) {
        db_query("UPDATE ?:novoton_facilities SET facility_name_en = facility_name WHERE facility_name_en = '' AND facility_name != ''");
    }

    // ── Missing language variables ──
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

    // ── LONGTEXT → JSON column type migration (MySQL 5.7+ / MariaDB 10.2+) ──
    // MariaDB treats JSON as a LONGTEXT alias with CHECK(JSON_VALID(…) OR col IS NULL).
    // MySQL stores JSON in an optimized binary format.
    // Both accept ALTER TABLE … MODIFY … JSON.
    $json_columns = [
        ['?:novoton_hotels',                'hotel_data',       "JSON COMMENT 'JSON: full hotelinfo API response'"],
        ['?:novoton_hotel_packages',        'priceinfo_data',   "JSON COMMENT 'JSON: full priceinfo API response'"],
        ['?:novoton_bookings',              'rooms_data',       "JSON COMMENT 'JSON: rooms configuration'"],
        ['?:novoton_bookings',              'guests_data',      "JSON COMMENT 'JSON: all guests details'"],
        ['?:novoton_bookings',              'api_request',      "JSON COMMENT 'JSON: API request sent'"],
        ['?:novoton_bookings',              'api_response',     "JSON COMMENT 'JSON: API response received'"],
        ['?:novoton_bookings',              'alternatives_data', "JSON COMMENT 'JSON: alternative hotels'"],
        ['?:novoton_alternative_requests',  'alternatives_data', "JSON COMMENT 'JSON: found alternatives'"],
    ];

    foreach ($json_columns as [$table, $column, $definition]) {
        $resolved_table = $resolve($table);
        $col_type = db_get_field(
            "SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?s AND COLUMN_NAME = ?s",
            $resolved_table, $column
        );
        // Only convert if currently longtext (skip if already json or column missing)
        if ($col_type && strtolower($col_type) === 'longtext') {
            // Sanitize: empty strings and invalid JSON → NULL
            // (MariaDB adds CHECK(JSON_VALID(…) OR col IS NULL); MySQL validates on ALTER)
            @db_query("UPDATE {$table} SET `{$column}` = NULL WHERE `{$column}` = ''");
            @db_query("UPDATE {$table} SET `{$column}` = NULL WHERE `{$column}` IS NOT NULL AND JSON_VALID(`{$column}`) = 0");
            @db_query("ALTER TABLE {$table} MODIFY COLUMN `{$column}` {$definition}");
        }
    }
}

/**
 * Create Theme Editor preset files and register the preset in manifest.json.
 *
 * CS-Cart's Theme Editor reads preset data from a hardcoded path:
 *   design/themes/{theme}/styles/data/{preset_name}/styles.less
 *
 * The preset is registered by MERGING into the existing manifest.json
 * (never overwriting it) so core presets are preserved.
 *
 * Idempotent — skips files/entries that already exist.
 *
 * @return void
 */
function fn_novoton_holidays_create_theme_presets(): void
{
    $root = rtrim(Registry::get('config.dir.root'), '/');
    $themes = ['nova_theme', 'responsive'];

    $content = <<<'LESS'
/**
 * Novoton Default Style — Theme Editor Preset
 *
 * These LESS variables are managed by the CS-Cart Theme Editor.
 * When an admin changes a color via Design > Themes > Theme Editor,
 * CS-Cart updates this file and recompiles the CSS.
 *
 * Per CS-Cart docs, LESS functions (darken, lighten, fade) belong HERE
 * in the preset file. The computed values are bridged to CSS custom
 * properties in css/addons/novoton_holidays/styles.less.
 *
 * Variable names must match the field names in schema.json.
 */

// Colors — Brand (Theme Editor fields)
@novoton-primary:           #003580;
@novoton-accent:            #febb02;
@novoton-search-btn-bg:     #006ce4;
@novoton-search-btn-hover:  #0057b8;

// Colors — UI (Theme Editor fields)
@novoton-text:              #333333;
@novoton-bg:                #ffffff;
@novoton-border:            #e0e0e0;
@novoton-success:           #28a745;
@novoton-danger:            #dc3545;

// Fonts (Theme Editor fields)
@novoton-font-family:       Arial, Helvetica, sans-serif;
@novoton-font-size-base-value: 14px;
@novoton-font-weight:       normal;

// Backgrounds (Theme Editor fields)
@novoton-bg-pattern:        none;
@novoton-bg-repeat:         no-repeat;
@novoton-bg-transparent:    false;

// General
@full_width:                false;
LESS;

    foreach ($themes as $theme) {
        // 1. Create the styles.less preset file
        $dir = "{$root}/design/themes/{$theme}/styles/data/novoton_default";
        $file = "{$dir}/styles.less";

        if (!file_exists($file)) {
            if (!is_dir($dir)) {
                fn_mkdir($dir);
            }
            file_put_contents($file, $content . "\n");
        }

        // 2. Merge the preset into the existing manifest.json (preserve core presets)
        $manifest_path = "{$root}/design/themes/{$theme}/styles/manifest.json";
        if (file_exists($manifest_path)) {
            $manifest = @json_decode(file_get_contents($manifest_path), true);
            if (!is_array($manifest)) {
                continue;
            }
        } else {
            $manifest = ['default_style' => '', 'names' => [], 'default' => []];
        }

        $changed = false;

        if (!isset($manifest['names']['novoton_default'])) {
            $manifest['names']['novoton_default'] = 'Novoton Default';
            $changed = true;
        }

        if (!in_array('novoton_default', $manifest['default'] ?? [], true)) {
            $manifest['default'][] = 'novoton_default';
            $changed = true;
        }

        if ($changed) {
            file_put_contents($manifest_path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        }
    }
}

/**
 * Remove Theme Editor preset files and unregister from manifest.json on uninstall.
 *
 * @return void
 */
function fn_novoton_holidays_remove_theme_presets(): void
{
    $root = rtrim(Registry::get('config.dir.root'), '/');
    $themes = ['nova_theme', 'responsive'];

    foreach ($themes as $theme) {
        // 1. Remove the styles.less preset file
        $dir = "{$root}/design/themes/{$theme}/styles/data/novoton_default";
        $file = "{$dir}/styles.less";

        if (file_exists($file)) {
            @unlink($file);
        }

        // Remove directory if empty
        if (is_dir($dir) && count(scandir($dir)) === 2) {
            @rmdir($dir);
        }

        // 2. Remove the preset from manifest.json (restore core state)
        $manifest_path = "{$root}/design/themes/{$theme}/styles/manifest.json";
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

        // If default_style was novoton_default, revert to first available preset
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

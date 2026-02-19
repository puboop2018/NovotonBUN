<?php
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

if (!defined('BOOTSTRAP')) { die('Access denied'); }

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
            ['rooms_data',                     "LONGTEXT AFTER `num_rooms`"],
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
        foreach ($columns as $spec) {
            [$column, $definition] = $spec;
            $key = $spec[2] ?? null;

            $exists = db_get_field(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                 AND TABLE_NAME = ?s AND COLUMN_NAME = ?s",
                $table, $column
            );
            if ($exists) {
                continue;
            }

            $sql = "ALTER TABLE {$table} ADD COLUMN `{$column}` {$definition}";
            if ($key) {
                $sql .= ", ADD KEY {$key} (`{$column}`)";
            }
            @db_query($sql);
        }
    }

    // ── Column type changes ──
    $sync_type_info = db_get_row(
        "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = '?:novoton_sync_log' AND COLUMN_NAME = 'sync_type'"
    );
    if ($sync_type_info && strpos($sync_type_info['COLUMN_TYPE'], 'enum') !== false) {
        @db_query("ALTER TABLE ?:novoton_sync_log MODIFY COLUMN `sync_type` VARCHAR(50) NOT NULL DEFAULT 'hotels'");
    }

    // ── Data migration: copy facility_name → facility_name_en ──
    $has_old = db_get_field("SHOW COLUMNS FROM `?:novoton_facilities` LIKE 'facility_name'");
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
}

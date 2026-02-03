<?php
/**
 * Novoton Holidays - Installation Functions
 * 
 * Functions for addon install, uninstall, and upgrades.
 * 
 * @package NovotonHolidays
 * @since 2.8.0
 */

use Tygh\Registry;
use Tygh\Tygh;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

/**
 * Addon uninstall function
 * Cleans up addon data and drops tables
 * 
 * @return bool
 */
function fn_novoton_holidays_uninstall()
{
    // Remove AJAX price handler from CS-Cart root
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
    
    // Drop all addon tables
    db_query("DROP TABLE IF EXISTS ?:novoton_resorts");
    db_query("DROP TABLE IF EXISTS ?:novoton_hotel_facilities");
    db_query("DROP TABLE IF EXISTS ?:novoton_facilities");
    db_query("DROP TABLE IF EXISTS ?:novoton_alternative_requests");
    db_query("DROP TABLE IF EXISTS ?:novoton_cache");
    db_query("DROP TABLE IF EXISTS ?:novoton_sync_log");
    db_query("DROP TABLE IF EXISTS ?:novoton_bookings");
    db_query("DROP TABLE IF EXISTS ?:novoton_early_booking");
    db_query("DROP TABLE IF EXISTS ?:novoton_seasons");
    db_query("DROP TABLE IF EXISTS ?:novoton_hotel_prices");
    db_query("DROP TABLE IF EXISTS ?:novoton_hotels");
    
    return true;
}

/**
 * Fix tab name if empty
 * 
 * @param int|null $tab_id Tab ID
 * @return bool
 */
function fn_novoton_holidays_fix_tab_name($tab_id = null)
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
function fn_novoton_holidays_post_install()
{
    // Copy AJAX price handler to CS-Cart root
    $source = Registry::get('config.dir.addons') . 'novoton_holidays/ajax_price.php';
    $dest = Registry::get('config.dir.root') . '/novoton_ajax_price.php';
    
    if (file_exists($source) && !file_exists($dest)) {
        @copy($source, $dest);
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
function fn_novoton_holidays_install_email_templates()
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
 * Adds new columns if they don't exist (for upgrades)
 * 
 * @return void
 */
function fn_novoton_holidays_upgrade_db()
{
    // Add region column to novoton_hotels table
    $region_exists = db_get_field(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
         WHERE TABLE_SCHEMA = DATABASE() 
         AND TABLE_NAME = '?:novoton_hotels' 
         AND COLUMN_NAME = 'region'"
    );
    
    if (!$region_exists) {
        @db_query(
            "ALTER TABLE ?:novoton_hotels 
             ADD COLUMN `region` varchar(100) DEFAULT NULL AFTER `city`"
        );
    }
    
    // Add has_prices column
    $has_prices_exists = db_get_field(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
         WHERE TABLE_SCHEMA = DATABASE() 
         AND TABLE_NAME = '?:novoton_hotels' 
         AND COLUMN_NAME = 'has_prices'"
    );
    
    if (!$has_prices_exists) {
        @db_query(
            "ALTER TABLE ?:novoton_hotels 
             ADD COLUMN `has_prices` ENUM('Y', 'N') DEFAULT NULL"
        );
    }
    
    // Add last_price_check column
    $last_price_check_exists = db_get_field(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
         WHERE TABLE_SCHEMA = DATABASE() 
         AND TABLE_NAME = '?:novoton_hotels' 
         AND COLUMN_NAME = 'last_price_check'"
    );
    
    if (!$last_price_check_exists) {
        @db_query(
            "ALTER TABLE ?:novoton_hotels 
             ADD COLUMN `last_price_check` DATETIME DEFAULT NULL"
        );
    }
    
    // Add num_rooms column to bookings
    $num_rooms_exists = db_get_field(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
         WHERE TABLE_SCHEMA = DATABASE() 
         AND TABLE_NAME = '?:novoton_bookings' 
         AND COLUMN_NAME = 'num_rooms'"
    );
    
    if (!$num_rooms_exists) {
        @db_query(
            "ALTER TABLE ?:novoton_bookings 
             ADD COLUMN `num_rooms` INT(11) DEFAULT 1 AFTER `children_ages`"
        );
    }
    
    // Add rooms_data column to bookings
    $rooms_data_exists = db_get_field(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
         WHERE TABLE_SCHEMA = DATABASE() 
         AND TABLE_NAME = '?:novoton_bookings' 
         AND COLUMN_NAME = 'rooms_data'"
    );
    
    if (!$rooms_data_exists) {
        @db_query(
            "ALTER TABLE ?:novoton_bookings 
             ADD COLUMN `rooms_data` LONGTEXT AFTER `num_rooms`"
        );
    }
    
    // Add session_id column to bookings
    $session_id_exists = db_get_field(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
         WHERE TABLE_SCHEMA = DATABASE() 
         AND TABLE_NAME = '?:novoton_bookings' 
         AND COLUMN_NAME = 'session_id'"
    );
    
    if (!$session_id_exists) {
        @db_query(
            "ALTER TABLE ?:novoton_bookings 
             ADD COLUMN `session_id` VARCHAR(64) DEFAULT NULL AFTER `user_id`,
             ADD KEY idx_session (session_id)"
        );
    }
    
    // Add holder_name column to bookings
    $holder_name_exists = db_get_field(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = '?:novoton_bookings'
         AND COLUMN_NAME = 'holder_name'"
    );

    if (!$holder_name_exists) {
        @db_query(
            "ALTER TABLE ?:novoton_bookings
             ADD COLUMN `holder_name` VARCHAR(255) DEFAULT '' AFTER `guest_name`"
        );
    }

    // Add hotel_type column to novoton_hotels
    $hotel_type_exists = db_get_field(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = '?:novoton_hotels'
         AND COLUMN_NAME = 'hotel_type'"
    );

    if (!$hotel_type_exists) {
        @db_query(
            "ALTER TABLE ?:novoton_hotels
             ADD COLUMN `hotel_type` VARCHAR(50) DEFAULT '' AFTER `country`"
        );
    }

    // Add latitude column to novoton_hotels
    $latitude_exists = db_get_field(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = '?:novoton_hotels'
         AND COLUMN_NAME = 'latitude'"
    );

    if (!$latitude_exists) {
        @db_query(
            "ALTER TABLE ?:novoton_hotels
             ADD COLUMN `latitude` DECIMAL(10,7) DEFAULT NULL AFTER `hotel_type`"
        );
    }

    // Add longitude column to novoton_hotels
    $longitude_exists = db_get_field(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = '?:novoton_hotels'
         AND COLUMN_NAME = 'longitude'"
    );

    if (!$longitude_exists) {
        @db_query(
            "ALTER TABLE ?:novoton_hotels
             ADD COLUMN `longitude` DECIMAL(10,7) DEFAULT NULL AFTER `latitude`"
        );
    }

    // Install missing language variables for search results translations
    $lang_vars = [
        'novoton_holidays.until' => ['en' => 'until', 'ro' => 'până la'],
        'novoton_holidays.free_cancellation' => ['en' => 'Free Cancellation', 'ro' => 'Anulare gratuită'],
        'novoton_holidays.free_cancellation_until' => ['en' => 'Free cancellation until', 'ro' => 'Anulare gratuită până la'],
        'novoton_holidays.on_booking' => ['en' => 'on booking', 'ro' => 'la rezervare'],
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

    // Fix novoton_facilities table: add facility_name_en, facility_name_ro if missing
    // addon.xml originally created the table with only facility_name column
    $facilities_columns = db_get_fields("SHOW COLUMNS FROM ?:novoton_facilities");
    if (!in_array('facility_name_en', $facilities_columns)) {
        db_query("ALTER TABLE ?:novoton_facilities ADD COLUMN facility_name_en VARCHAR(255) DEFAULT '' AFTER facility_name");
    }
    if (!in_array('facility_name_ro', $facilities_columns)) {
        db_query("ALTER TABLE ?:novoton_facilities ADD COLUMN facility_name_ro VARCHAR(255) DEFAULT '' AFTER facility_name_en");
    }
    // Copy existing facility_name data to facility_name_en if it was populated
    if (in_array('facility_name', $facilities_columns)) {
        db_query("UPDATE ?:novoton_facilities SET facility_name_en = facility_name WHERE facility_name_en = '' AND facility_name != ''");
    }
}

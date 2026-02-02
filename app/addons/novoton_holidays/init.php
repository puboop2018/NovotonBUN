<?php
/***************************************************************************
 *                                                                          *
 *   (c) 2024-2025 VacanteLitoral.ro                                       *
 *                                                                          *
 *   Location: app/addons/novoton_holidays/init.php                        *
 *                                                                          *
 ***************************************************************************/

use Tygh\Registry;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

// Load addon constants
$config_file = __DIR__ . '/config.php';
if (file_exists($config_file)) {
    require_once $config_file;
}

// Auto-create missing tables for existing installations
// This runs once per request but checks are fast
fn_novoton_ensure_tables_exist();

/**
 * Ensure all required tables exist (for updates to existing installations)
 */
function fn_novoton_ensure_tables_exist()
{
    static $checked = false;
    if ($checked) return;
    $checked = true;
    
    // Check if resorts table exists
    $table_exists = db_get_field("SHOW TABLES LIKE '?:novoton_resorts'");
    
    if (empty($table_exists)) {
        db_query("CREATE TABLE IF NOT EXISTS `?:novoton_resorts` (
            `resort_id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `country` varchar(100) NOT NULL,
            `resort_name` varchar(255) NOT NULL,
            `hotel_count` int(11) unsigned DEFAULT 0,
            `hotels_with_prices` int(11) unsigned DEFAULT 0,
            `synced_at` datetime DEFAULT NULL,
            PRIMARY KEY (`resort_id`),
            UNIQUE KEY `idx_country_resort` (`country`, `resort_name`),
            KEY `idx_country` (`country`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    
    // Rename synced_at -> hotel_list_synced_at if old column still exists
    $old_col = db_get_field("SHOW COLUMNS FROM `?:novoton_hotels` LIKE 'synced_at'");
    if (!empty($old_col)) {
        db_query("ALTER TABLE `?:novoton_hotels` CHANGE COLUMN `synced_at` `hotel_list_synced_at` datetime DEFAULT NULL COMMENT 'Last hotel_list API sync date'");
    } else {
        $new_col = db_get_field("SHOW COLUMNS FROM `?:novoton_hotels` LIKE 'hotel_list_synced_at'");
        if (empty($new_col)) {
            db_query("ALTER TABLE `?:novoton_hotels` ADD COLUMN `hotel_list_synced_at` datetime DEFAULT NULL COMMENT 'Last hotel_list API sync date' AFTER `last_price_check`");
        }
    }

    // Add hotelinfo_synced_at column if missing (for existing installations)
    $col_exists = db_get_field("SHOW COLUMNS FROM `?:novoton_hotels` LIKE 'hotelinfo_synced_at'");
    if (empty($col_exists)) {
        db_query("ALTER TABLE `?:novoton_hotels` ADD COLUMN `hotelinfo_synced_at` datetime DEFAULT NULL COMMENT 'Last hotelinfo API sync date' AFTER `ages_data`");
    }

    // Check if sync_log table exists
    $sync_log_exists = db_get_field("SHOW TABLES LIKE '?:novoton_sync_log'");
    
    if (empty($sync_log_exists)) {
        db_query("CREATE TABLE IF NOT EXISTS `?:novoton_sync_log` (
            `log_id` int(11) NOT NULL AUTO_INCREMENT,
            `sync_type` varchar(50) DEFAULT '',
            `sync_date` datetime DEFAULT NULL,
            `hotels_synced` int(11) DEFAULT 0,
            `hotels_added` int(11) DEFAULT 0,
            `hotels_updated` int(11) DEFAULT 0,
            `errors` int(11) DEFAULT 0,
            `duration` int(11) DEFAULT 0,
            `details` longtext,
            `status` enum('running','completed','failed') DEFAULT 'running',
            PRIMARY KEY (`log_id`),
            KEY `idx_sync_type` (`sync_type`),
            KEY `idx_sync_date` (`sync_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

// Register PSR-4 autoloader for addon classes
spl_autoload_register(function ($class) {
    // Handle Services namespace
    $servicePrefix = 'Tygh\\Addons\\NovotonHolidays\\Services\\';
    $serviceBaseDir = __DIR__ . '/services/';
    
    if (strncmp($servicePrefix, $class, strlen($servicePrefix)) === 0) {
        $relativeClass = substr($class, strlen($servicePrefix));
        $file = $serviceBaseDir . str_replace('\\', '/', $relativeClass) . '.php';
        
        if (file_exists($file)) {
            require $file;
            return;
        }
    }
    
    // Handle main namespace (Constants, etc.)
    $mainPrefix = 'Tygh\\Addons\\NovotonHolidays\\';
    $mainBaseDir = __DIR__ . '/';
    
    if (strncmp($mainPrefix, $class, strlen($mainPrefix)) === 0) {
        $relativeClass = substr($class, strlen($mainPrefix));
        $file = $mainBaseDir . str_replace('\\', '/', $relativeClass) . '.php';
        
        if (file_exists($file)) {
            require $file;
            return;
        }
    }
});

// Force load hooks.php
$hooks_file = __DIR__ . '/hooks.php';
if (file_exists($hooks_file)) {
    require_once($hooks_file);
}

// Define Smarty modifier functions first (before registration)
/**
 * Smarty modifier to format room type code to full name
 * Usage in templates: {$room_id|novoton_format_room_type}
 */
function fn_novoton_smarty_format_room_type($room_id)
{
    if (empty($room_id)) {
        return '';
    }
    
    // First fix the + sign issue
    $room_id = str_replace(['%2b', '%2B'], '+', $room_id);
    $room_id = preg_replace('/(\d)\s+(\d)/', '$1+$2', $room_id);
    
    // If already a full name (contains Romanian characters), return as-is
    if (preg_match('/[ăîâșț]/iu', $room_id)) {
        return $room_id;
    }
    
    // Use the existing function if available
    if (function_exists('fn_novoton_format_room_type')) {
        return fn_novoton_format_room_type($room_id);
    }
    
    return $room_id;
}

/**
 * Smarty modifier to format board code to full name
 * Usage in templates: {$board_id|novoton_format_board}
 */
function fn_novoton_smarty_format_board($board_id)
{
    if (empty($board_id)) {
        return '';
    }
    
    // Use the existing function if available
    if (function_exists('fn_novoton_format_board_name')) {
        return fn_novoton_format_board_name($board_id);
    }
    
    // Fallback mapping
    $map = [
        'AI' => 'All Inclusive',
        'ALL INCL' => 'All Inclusive',
        'ALLINC' => 'All Inclusive',
        'UAI' => 'Ultra All Inclusive',
        'FB' => 'Full Board',
        'HB' => 'Half Board',
        'BB' => 'Bed & Breakfast',
        'RO' => 'Room Only'
    ];
    
    $key = strtoupper(trim($board_id));
    return $map[$key] ?? $board_id;
}

/**
 * Register Smarty modifiers
 * Called at the right time when Smarty is ready
 */
function fn_novoton_register_smarty_modifiers()
{
    static $registered = false;
    
    if ($registered) {
        return;
    }
    
    try {
        if (class_exists('Tygh') && !empty(\Tygh::$app) && \Tygh::$app->offsetExists('view')) {
            $smarty = \Tygh::$app['view'];
            if ($smarty && method_exists($smarty, 'registerPlugin')) {
                // Check if already registered to avoid errors
                $plugins = $smarty->registered_plugins ?? [];
                if (!isset($plugins['modifier']['novoton_format_room_type'])) {
                    $smarty->registerPlugin('modifier', 'novoton_format_room_type', 'fn_novoton_smarty_format_room_type');
                }
                if (!isset($plugins['modifier']['novoton_format_board'])) {
                    $smarty->registerPlugin('modifier', 'novoton_format_board', 'fn_novoton_smarty_format_board');
                }
                $registered = true;
            }
        }
    } catch (\Exception $e) {
        // Silently fail - modifiers won't be available but templates should still work
    }
}

// Try to register immediately if possible
fn_novoton_register_smarty_modifiers();

// Register addon hooks
fn_register_hooks(
    'get_product_data_post',                  // Add hotel data to products
    'gather_additional_product_data_post',    // Pass data to templates (for tabs)
    'update_product_pre',                      // Before updating products
    'delete_product_post',                     // Cleanup after product deletion
    'place_order',                             // Create bookings on order
    'get_orders_post',                         // Add booking info to orders
    'get_order_info',                          // Format terms on order detail page
    'get_product_tabs_post',                   // Add tab in ADMIN product edit
    'dispatch_before_display',                 // Ensure meta variables are set
    'get_cart_product_data_post',              // Add booking info to cart items
    'calculate_cart_items',                    // After cart calculation
    'calculate_cart_items_post'                // After cart items calculation - for rooms_data
);
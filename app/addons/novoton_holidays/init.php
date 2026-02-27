<?php
declare(strict_types=1);
/***************************************************************************
 *                                                                          *
 *   (c) 2024-2025 VacanteLitoral.ro                                       *
 *                                                                          *
 *   Location: app/addons/novoton_holidays/init.php                        *
 *                                                                          *
 ***************************************************************************/

use Tygh\Registry;
use Tygh\Addons\NovotonHolidays\Constants;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

// Addon version constant — single source of truth from addon.xml via Registry.
// Strips build suffix (e.g. "3.0.0-A86" → "3.0.0") for use in script cache-busting.
if (!defined('NOVOTON_VERSION')) {
    $__nv = Registry::get('addons.novoton_holidays.version') ?: '0.0.0';
    define('NOVOTON_VERSION', preg_replace('/-.*$/', '', $__nv));
    unset($__nv);
}

// Register PSR-4 autoloader for ALL addon namespaces.
// All classes live under src/ — single PSR-4 root.
spl_autoload_register(function ($class) {
    $prefix = 'Tygh\\Addons\\NovotonHolidays\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    $file = __DIR__ . '/src/' . $relative;

    if (file_exists($file)) {
        require $file;
        return;
    }

    // Fallback: addon root for Constants.php (lives next to addon.xml)
    $file = __DIR__ . '/' . $relative;
    if (file_exists($file)) {
        require $file;
    }
});

// Force load hooks.php
require_once __DIR__ . '/hooks.php';

// Define Smarty modifier functions first (before registration)
/**
 * Smarty modifier to format room type code to full name
 * Usage in templates: {$room_id|novoton_format_room_type}
 *
 * Delegates to RoomType value object (single source of truth).
 */
function fn_novoton_holidays_smarty_format_room_type($room_id)
{
    if (empty($room_id)) {
        return '';
    }

    // If already a full name (contains Romanian characters), return as-is
    if (preg_match('/[ăîâșț]/iu', $room_id)) {
        return $room_id;
    }

    return \Tygh\Addons\NovotonHolidays\ValueObjects\RoomType::formatRoomLabel($room_id);
}

/**
 * Smarty modifier to format board code to full name
 * Usage in templates: {$board_id|novoton_format_board}
 *
 * Delegates to BoardType value object (single source of truth).
 */
function fn_novoton_holidays_smarty_format_board($board_id)
{
    if (empty($board_id)) {
        return '';
    }

    return \Tygh\Addons\NovotonHolidays\ValueObjects\BoardType::toDisplayName($board_id);
}

/**
 * Register Smarty modifiers
 * Called at the right time when Smarty is ready
 */
function fn_novoton_holidays_register_smarty_modifiers()
{
    static $registered = false;

    if ($registered) {
        return;
    }

    if (class_exists('Tygh\Tygh') && !empty(\Tygh\Tygh::$app) && \Tygh\Tygh::$app->offsetExists('view')) {
        $smarty = \Tygh\Tygh::$app['view'];
        if ($smarty) {
            $smarty->registerPlugin('modifier', 'novoton_format_room_type', 'fn_novoton_holidays_smarty_format_room_type');
            $smarty->registerPlugin('modifier', 'novoton_format_board', 'fn_novoton_holidays_smarty_format_board');
            $registered = true;
        }
    }
}

// Try to register immediately if possible
fn_novoton_holidays_register_smarty_modifiers();

// Register addon hooks
fn_register_hooks(
    'get_products_post',                       // Batch prefetch hotel data for product listings
    'get_product_data_post',                   // Add hotel data to products
    'gather_additional_product_data_post',     // Pass data to templates (for tabs)
    'delete_product_post',                     // Cleanup after product deletion
    'place_order_post',                        // Create bookings on order (post — needs order_id)
    'get_orders_post',                         // Add booking info to orders
    'get_order_info',                          // Format terms on order detail page
    'dispatch_before_display',                 // Ensure meta variables are set
    'get_cart_product_data_post',              // Add booking info to cart items
    'calculate_cart_items',                    // After cart calculation
    'calculate_cart_items_post',              // After cart items calculation - for rooms_data
    'user_login_post',                         // Link session bookings to logged-in user
    'create_user_post',                        // Link bookings to newly registered users
    'checkout_pre_dispatch'                    // Debug info on checkout pages
);
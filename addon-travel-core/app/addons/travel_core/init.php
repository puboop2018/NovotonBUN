<?php
declare(strict_types=1);
/***************************************************************************
 *                                                                          *
 *   (c) 2024-2026 VacanteLitoral.ro                                       *
 *                                                                          *
 *   Location: app/addons/travel_core/init.php                             *
 *                                                                          *
 ***************************************************************************/

use Tygh\Registry;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

// Addon version constant
if (!defined('TRAVEL_CORE_VERSION')) {
    $__tv = Registry::get('addons.travel_core.version') ?: '0.0.0';
    define('TRAVEL_CORE_VERSION', preg_replace('/-.*$/', '', $__tv));
    unset($__tv);
}

// React bundle cache version — bump when JS bundles are rebuilt
if (!defined('TRAVEL_CACHE_VER')) {
    define('TRAVEL_CACHE_VER', '3');
}

// Register PSR-4 autoloader for travel_core namespace.
spl_autoload_register(function ($class) {
    $prefix = 'Tygh\\Addons\\TravelCore\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    $file = __DIR__ . '/src/' . $relative;

    if (file_exists($file)) {
        require $file;
    }
});

// Load shared function libraries
require_once __DIR__ . '/functions/exchange_rates.php';
require_once __DIR__ . '/functions/hotels.php';

// Load hook functions
require_once __DIR__ . '/hooks.php';

// Register addon hooks
fn_register_hooks(
    'get_cart_product_data_post',      // Format cart items for travel bookings
    'calculate_cart_items_post',       // Ensure rooms_data preserved as array
    'dispatch_before_display',         // CSS loading for booking pages
    'get_order_info'                   // Format booking data in order view
);

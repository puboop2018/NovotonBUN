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

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

// Addon version constant
if (!defined('TRAVEL_CORE_VERSION')) {
    $__tvRaw = Registry::get('addons.travel_core.version');
    $__tv = is_scalar($__tvRaw) && $__tvRaw !== '' ? (string) $__tvRaw : '0.0.0';
    define('TRAVEL_CORE_VERSION', preg_replace('/-.*$/', '', $__tv));
    unset($__tv);
}

// React bundle cache version — bump when JS bundles are rebuilt
if (!defined('TRAVEL_CACHE_VER')) {
    define('TRAVEL_CACHE_VER', '6');
}

// Register PSR-4 autoloader for travel_core namespace.
spl_autoload_register(function ($class): void {
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
require_once __DIR__ . '/functions/cart_guests.php';
require_once __DIR__ . '/functions/exchange_rates.php';
require_once __DIR__ . '/functions/hotels.php';
require_once __DIR__ . '/functions/self_heal.php';

// Load hook functions
require_once __DIR__ . '/hooks.php';

// Self-heal: ensure the tables that predate a migration get it (alias unique
// key, bookings-grid index, unmapped-values table). The heal is idempotent
// but costs catalog queries + a CREATE IF NOT EXISTS, so it is stamp-gated:
// it runs once per deployed version of func.php (where its migrations live)
// and every later admin request pays one ?:storage_data read.
if (defined('AREA') && AREA === 'A' && function_exists('fn_travel_core_ensure_schema')) {
    $__tc_heal_fp = (string) @md5_file(__DIR__ . '/func.php');
    if (fn_travel_core_self_heal_due('travel_core_schema', $__tc_heal_fp)) {
        fn_travel_core_ensure_schema();
        fn_travel_core_self_heal_stamp('travel_core_schema', $__tc_heal_fp);
    }
    unset($__tc_heal_fp);
}

// Self-heal language keys: addon.xml/.po are only imported at install, so new
// or changed labels never reach existing stores on their own. Compare a stored
// stamp against the current fingerprint of addon.xml + lang_keys.php and
// reseed on any change; the seeder is idempotent. Deliberately NOT gated to
// the admin area: a deploy followed by storefront-only traffic used to show
// customers raw "_travel_core.…" keys until someone opened an admin page.
// Steady-state cost is one indexed stamp SELECT per request.
if (function_exists('fn_travel_core_seed_language_keys')) {
    $__stamp = db_get_field(
        "SELECT value FROM ?:language_values WHERE name = ?s AND lang_code = ?s LIMIT 1",
        'travel_core._lang_seed_hash', 'en'
    );
    if ($__stamp !== fn_travel_core_language_seed_hash()) {
        fn_travel_core_seed_language_keys();
    }
    unset($__stamp);
}

// Register addon hooks
fn_register_hooks(
    'get_cart_product_data_post',      // Format cart items for travel bookings
    'calculate_cart_items_post',       // Ensure rooms_data preserved as array
    'dispatch_before_display',         // CSS loading for booking pages
    'get_order_info',                   // Format booking data in order view
);

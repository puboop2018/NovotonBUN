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

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

// Addon version constant — single source of truth from addon.xml via Registry.
// Strips build suffix (e.g. "3.0.0-A86" → "3.0.0") for display purposes.
if (!defined('NOVOTON_VERSION')) {
    $__nv = Registry::get('addons.novoton_holidays.version') ?: '0.0.0';
    define('NOVOTON_VERSION', preg_replace('/-.*$/', '', $__nv));
    unset($__nv);
}

// Cache-busting version — changes automatically when JS bundle is modified.
// Uses filemtime of the React bundle so every deploy busts browser cache
// even within the same addon version (e.g. hotfixes, rebuilds).
if (!defined('NOVOTON_CACHE_VER')) {
    $__bundle = __DIR__ . '/../../../../js/addons/novoton_holidays/react19-bundle.js';
    $__mtime = file_exists($__bundle) ? (string) filemtime($__bundle) : '0';
    define('NOVOTON_CACHE_VER', substr(md5(NOVOTON_VERSION . $__mtime), 0, 8));
    unset($__bundle, $__mtime);
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

// ── Schema migrations ──
// Column additions are handled ONLY by setup_db() during addon install/upgrade.
// Runtime migrations in init.php are dangerous — if they crash, the entire
// addon (hooks, modifiers, registration) is killed. Never run DB schema
// changes in init.php.

// Load service accessor functions (_nvt_api, _nvt_hotel_repo, etc.)
// These are plain functions (not class methods), so the PSR-4 autoloader won't load them.
require_once __DIR__ . '/src/Services/ServiceLoader.php';

// Force load hooks.php
require_once __DIR__ . '/hooks.php';

// ── Smarty modifier functions ──────────────────────────────────────────
// Named using Smarty's auto-discovery convention: smarty_modifier_{name}
// Smarty finds these by function name WITHOUT needing registerPlugin().
// This eliminates the timing issue where registerPlugin() fails because
// $app['view'] isn't ready yet during init.php loading.
//
// CRITICAL: each function is wrapped in try/catch. If a modifier throws
// inside a {capture} block, Smarty's output buffer breaks and the ENTIRE
// page crashes with "unexpected {/capture}". These modifiers are cosmetic
// (format a code to a display name) — they must NEVER crash the page.

/**
 * Smarty modifier: {$room_id|novoton_format_room_type}
 */
function smarty_modifier_novoton_format_room_type($room_id)
{
    try {
        if (empty($room_id) || !is_string($room_id)) {
            return is_string($room_id) ? $room_id : '';
        }
        if (preg_match('/[ăîâșț]/iu', $room_id)) {
            return $room_id;
        }
        return \Tygh\Addons\TravelCore\ValueObjects\RoomType::formatRoomLabel($room_id);
    } catch (\Throwable) {
        return is_string($room_id) ? $room_id : '';
    }
}

/**
 * Smarty modifier: {$board_id|novoton_format_board}
 */
function smarty_modifier_novoton_format_board($board_id)
{
    try {
        if (empty($board_id) || !is_string($board_id)) {
            return is_string($board_id) ? $board_id : '';
        }
        return \Tygh\Addons\TravelCore\ValueObjects\BoardType::toDisplayName($board_id);
    } catch (\Throwable) {
        return is_string($board_id) ? $board_id : '';
    }
}

/**
 * Smarty modifier: {$value|novoton_trace:"label"}
 *
 * Diagnostic breadcrumb for isolating template-render crashes (e.g. the
 * "Not matching {capture}{/capture}" error on order details). When a hook
 * template aborts mid-render inside a core {capture} block, the capture is
 * left unbalanced and the real culprit is masked. Placing ENTER/EXIT markers
 * around each hook template's body makes the failure point obvious: after a
 * crash, the LAST "ENTER" with no matching "EXIT" in the trace log names the
 * exact template that blew up.
 *
 * Transparent: returns $value unchanged so it can wrap a real value, or be
 * used standalone as {''|novoton_trace:"..."} to emit nothing. Gated behind
 * the addon's Debug mode (default OFF) so there is zero overhead in
 * production until an admin explicitly enables it. Never throws — a broken
 * diagnostic must not break the page it is diagnosing.
 *
 * Log file: var/novoton_tpl_trace.log
 *
 * @param mixed  $value
 * @param string $label
 * @return mixed
 */
function smarty_modifier_novoton_trace($value, $label = '')
{
    try {
        if (\Tygh\Addons\NovotonHolidays\Services\ConfigProvider::isDebugMode()) {
            $root = (string) \Tygh\Registry::get('config.dir.root');
            $file = rtrim($root, '/') . '/var/novoton_tpl_trace.log';
            $line = '[' . date('Y-m-d H:i:s') . '] '
                . (defined('AREA') ? AREA : '?') . ' '
                . (string) $label . PHP_EOL;
            @file_put_contents($file, $line, FILE_APPEND);
        }
    } catch (\Throwable) {
        // A diagnostic must never crash the page it is diagnosing.
    }

    return $value;
}

/**
 * Register Smarty modifiers explicitly as backup.
 * The smarty_modifier_{name} naming convention handles auto-discovery,
 * but explicit registration ensures CS-Cart's Smarty also knows about them.
 */
function fn_novoton_holidays_register_smarty_modifiers()
{
    static $registered = false;

    if ($registered) {
        return;
    }

    try {
        if (class_exists('Tygh\Tygh') && !empty(\Tygh\Tygh::$app) && \Tygh\Tygh::$app->offsetExists('view')) {
            $smarty = \Tygh\Tygh::$app['view'];
            if ($smarty) {
                $smarty->registerPlugin('modifier', 'novoton_format_room_type', 'smarty_modifier_novoton_format_room_type');
                $smarty->registerPlugin('modifier', 'novoton_format_board', 'smarty_modifier_novoton_format_board');
                $smarty->registerPlugin('modifier', 'novoton_trace', 'smarty_modifier_novoton_trace');
                $registered = true;
            }
        }
    } catch (\Throwable) {
        // Registration failed — auto-discovery will still work
    }
}

// Try to register immediately if possible (may fail early in bootstrap — that's OK)
fn_novoton_holidays_register_smarty_modifiers();

// Register with shared travel provider registry (guard against travel_core not being loaded)
if (class_exists(\Tygh\Addons\TravelCore\Services\TravelProviderRegistry::class)) {
    \Tygh\Addons\TravelCore\Services\TravelProviderRegistry::register(
        'novoton',
        'Novoton Holidays',
        new \Tygh\Addons\NovotonHolidays\Api\NovotonNormalizer()
    );
    \Tygh\Addons\TravelCore\Services\TravelProviderRegistry::setBookingAdminProvider(
        'novoton',
        new \Tygh\Addons\NovotonHolidays\Services\BookingAdminProvider()
    );
    \Tygh\Addons\TravelCore\Services\TravelProviderRegistry::setStatusCallbacks(
        'novoton',
        function () {
            return fn_novoton_holidays_cron_resinfo();
        },
        function (int $bookingId) {
            $provider = new \Tygh\Addons\NovotonHolidays\Services\BookingAdminProvider();
            return $provider->checkStatus((string) $bookingId);
        }
    );
    \Tygh\Addons\TravelCore\Services\TravelProviderRegistry::setHotelProductProvider(
        'novoton',
        new \Tygh\Addons\NovotonHolidays\Providers\NovotonHotelProductProvider()
    );
}

// Seed SEO defaults on first admin load (mirrors sphinx pattern)
if (defined('AREA') && AREA === 'A' && function_exists('fn_novoton_holidays_seed_seo_defaults')) {
    if (\Tygh\Registry::get('addons.novoton_holidays.seo_page_title') === null) {
        fn_novoton_holidays_seed_seo_defaults();
    }
}

// Register addon hooks
fn_register_hooks(
    'get_products_post',                       // Batch prefetch hotel data for product listings
    'get_product_data_post',                   // Add hotel data to products
    'gather_additional_product_data_post',     // Pass data to templates (for tabs)
    'get_product_tabs_post',                   // Hide Hotel Prices tab on non-Novoton products
    'delete_product_post',                     // Cleanup after product deletion
    'pre_place_order',                         // Real-time price verification before order
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
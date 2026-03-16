<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Cart Hook Functions
 *
 * Responsible for:
 *   - get_cart_product_data_post: Format cart line items for hotel bookings
 *   - calculate_cart_items: Inject booking data from DB into cart products
 *   - calculate_cart_items_post: Ensure rooms_data is preserved as array
 *   - checkout_pre_dispatch: Debug info on checkout pages
 *   - dispatch_before_display: Meta variables, Smarty modifiers, CSS loading
 *
 * Also contains the fn_novoton_holidays_add_booking_display_data() helper that formats
 * booking details for display in cart/checkout.
 *
 * @package NovotonHolidays
 * @since   3.0.0
 */

use Tygh\Registry;
use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\NovotonHolidays\Helpers\JsonDecoder;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Addons\TravelCore\TravelConstants;

/**
 * Hook: Format cart product info for hotel bookings
 */
function fn_novoton_holidays_get_cart_product_data_post(&$product, $cart, $auth): void
{
    if (!empty($product['extra']['novoton_booking'])) {
        fn_novoton_holidays_add_booking_display_data($product);
    }
}

/**
 * Hook: After cart is calculated - inject booking data from database.
 *
 * Ensures booking details are shown even if extra data was lost.
 * Supports multiple bookings for the same hotel product.
 */
function fn_novoton_holidays_calculate_cart_items(&$cart, &$cart_products, $auth): void
{
    if (empty($cart_products)) {
        return;
    }

    $product_ids = array_column($cart_products, 'product_id');
    if (empty($product_ids)) {
        return;
    }

    $repo = Container::getInstance()->bookingRepository();
    $auth_user_id = !empty($auth['user_id']) ? (int) $auth['user_id'] : 0;
    $current_session_id = session_id() ?: '';
    $default_statuses = [TravelConstants::STATUS_PENDING, TravelConstants::STATUS_CONFIRMED];
    $all_bookings = $repo->findByProductIds($product_ids, $default_statuses, $current_session_id, $auth_user_id);

    if (empty($all_bookings)) {
        return;
    }

    // Group by product_id
    $bookings_by_product = [];
    foreach ($all_bookings as $booking) {
        $bookings_by_product[$booking['product_id']][] = $booking;
    }

    // Assign bookings to cart items (first-come, first-served)
    $used_booking_ids = [];

    foreach ($cart_products as $cart_id => &$product) {
        $product_id = $product['product_id'];

        // Already has booking data with ID — keep it
        if (!empty($product['extra']['novoton_booking']) && !empty($product['extra']['novoton_booking_id'])) {
            fn_novoton_holidays_add_booking_display_data($product, $cart);
            $used_booking_ids[] = $product['extra']['novoton_booking_id'];
            continue;
        }

        // Find first unused booking for this product
        if (!isset($bookings_by_product[$product_id])) {
            continue;
        }

        foreach ($bookings_by_product[$product_id] as $booking) {
            if (in_array($booking['booking_id'], $used_booking_ids)) {
                continue;
            }

            $used_booking_ids[] = $booking['booking_id'];
            _nvt_inject_booking_into_cart_product($product, $booking, $cart, (string) $cart_id);
            fn_novoton_holidays_add_booking_display_data($product, $cart);
            break;
        }
    }
}

/**
 * Hook: after calculate cart - ensure rooms_data is preserved as array
 */
function fn_novoton_holidays_calculate_cart_items_post(&$cart, &$cart_products, $auth): void
{
    // rooms_data decode is handled by travel_core's calculate_cart_items_post hook
    // (novoton products now set travel_booking = true alongside novoton_booking)
    if (fn_novoton_holidays_is_debug()) {
        fn_log_event('general', 'runtime', [
            'message'             => 'Novoton calculate_cart_items_post',
            'cart_products_count' => count($cart_products),
        ]);
    }
}

/**
 * Hook: checkout page display - add debug info and price change alerts
 */
function fn_novoton_holidays_checkout_pre_dispatch(array &$cart, array &$auth, ?int $storefront_id = null): void
{
    if (fn_novoton_holidays_is_debug()) {
        \Tygh\Tygh::$app['view']->assign('novoton_checkout_debug', true);
        \Tygh\Tygh::$app['view']->assign('novoton_debug_cart_products', $cart['products'] ?? []);
    }

    // Pass any pending price change alerts to the checkout template.
    // Alerts are stored by PriceChangeDetector at add_to_cart or pre_place_order.
    $detector = Container::getInstance()->priceChangeDetector();
    $alerts = $detector->peekAlerts();
    if (!empty($alerts)) {
        \Tygh\Tygh::$app['view']->assign('novoton_price_change_alerts', $alerts);
    }
}

/**
 * Hook: dispatch_before_display - Ensure meta variables are never null,
 * register Smarty modifiers, and load frontend CSS.
 */
function fn_novoton_holidays_dispatch_before_display(): void
{
    // Register Smarty modifiers for ALL dispatches
    if (function_exists('fn_novoton_holidays_register_smarty_modifiers')) {
        fn_novoton_holidays_register_smarty_modifiers();
    }

    $dispatch = $_REQUEST['dispatch'] ?? '';

    // Meta variable null-safety for our addon controllers
    if (strpos($dispatch, 'novoton_') === 0) {
        _nvt_ensure_meta_variables();
    }

    // Frontend CSS for booking-related pages
    if (AREA === 'C') {
        $booking_pages = ['novoton_', 'products.', 'checkout', 'cart'];
        $needs_css = false;
        foreach ($booking_pages as $prefix) {
            if (strpos($dispatch, $prefix) === 0) {
                $needs_css = true;
                break;
            }
        }

        if ($needs_css) {
            $styles = Registry::get('runtime.styles') ?: [];
            $css_path = 'addons/novoton_holidays/styles.css';
            if (!in_array($css_path, $styles)) {
                $styles[] = $css_path;
                Registry::set('runtime.styles', $styles);
            }
        }
    }
}

/**
 * Smarty modifier to decode JSON in templates
 */
function smarty_modifier_json_decode($string, $assoc = true)
{
    if (empty($string)) {
        return $assoc ? [] : null;
    }
    return json_decode($string, $assoc);
}

// ============================================================================
// CART HELPERS (private-by-convention)
// ============================================================================

/**
 * Inject DB booking fields into a cart product's extra data.
 */
function _nvt_inject_booking_into_cart_product(
    array &$product,
    array  $booking,
    array &$cart,
    string $cart_id
): void {
    $product['extra']['travel_booking']     = true;
    $product['extra']['novoton_booking']    = true;
    $product['extra']['novoton_booking_id'] = $booking['booking_id'];
    $product['extra']['hotel_id']           = $booking['hotel_id'];
    $product['extra']['room_id']            = $booking['room_id'];
    $product['extra']['room_name']          = fn_novoton_holidays_format_room_type($booking['room_id'], $booking['room_type'] ?? '');
    $product['extra']['board_id']           = $booking['board_id'];
    $product['extra']['board_name']         = fn_novoton_holidays_format_board_name($booking['board_id']);
    $product['extra']['check_in']           = $booking['check_in'];
    $product['extra']['check_out']          = $booking['check_out'];
    $product['extra']['nights']             = $booking['nights'];
    $product['extra']['adults']             = $booking['adults'];
    $product['extra']['children']           = $booking['children'];
    $product['extra']['children_ages']      = $booking['children_ages'] ?? '';
    $product['extra']['holder_name']        = $booking['holder_name'] ?? '';
    $product['extra']['guest_names']        = $booking['guest_name'] ?? '';
    $product['extra']['guests_data']        = class_exists(\Tygh\Addons\TravelCore\Services\GuestDataNormalizer::class)
        ? (new \Tygh\Addons\TravelCore\Services\GuestDataNormalizer())->toJson($booking['guests_data'] ?? '')
        : ($booking['guests_data'] ?? '');
    $product['extra']['total_price']        = $booking['total_price'];
    $product['extra']['package_name']       = $booking['package_name'] ?? '';
    $product['extra']['num_rooms']          = (int)($booking['num_rooms'] ?? 1);

    if (!empty($booking['rooms_data'])) {
        $rooms = json_decode($booking['rooms_data'], true);
        if (is_array($rooms)) {
            $product['extra']['rooms_data'] = $rooms;
        }
    }

    // Sync back to cart session
    if (isset($cart['products'][$cart_id])) {
        $cart['products'][$cart_id]['extra'] = $product['extra'];
    }
}

/**
 * Helper: Add booking display data to a product.
 *
 * Delegates to BookingDisplayService::addBookingDisplayData() with
 * Novoton-specific config (lang prefix, formatters, JSON decoder).
 */
function fn_novoton_holidays_add_booking_display_data(array &$product, ?array $cart = null): void
{
    // Pre-set board/room display names using Novoton formatters
    $board_id = $product['extra']['board_id'] ?? '';
    $product['extra']['board_name'] = fn_novoton_holidays_format_board_name($board_id);

    $room_id   = $product['extra']['room_id']   ?? '';
    $room_type = $product['extra']['room_type'] ?? '';
    $product['extra']['room_type_display'] = fn_novoton_holidays_format_room_type($room_id, $room_type);
    $product['extra']['room_name'] = $product['extra']['room_name']
        ?? str_replace(['%2b', '%2B'], '+', $room_id);

    if (class_exists(\Tygh\Addons\TravelCore\Services\BookingDisplayService::class)) {
        \Tygh\Addons\TravelCore\Services\BookingDisplayService::addBookingDisplayData($product, $cart, [
            'lang_prefix'          => 'novoton_holidays',
            'json_decoder'         => [JsonDecoder::class, 'decode'],
            'board_name_formatter' => 'fn_novoton_holidays_format_board_name',
            'room_name_formatter'  => function (array $room) {
                $name = $room['room_name'] ?? '';
                if (empty($name)) {
                    $name = str_replace(['%2b', '%2B'], '+', $room['room_id'] ?? '');
                }
                return $name;
            },
        ]);
    }
}

/**
 * Ensure meta variables are never null (prevents "Passing null" errors in meta.tpl).
 */
function _nvt_ensure_meta_variables(): void
{
    $view = \Tygh\Tygh::$app['view'];

    $default_title = __('novoton_holidays.search_results') ?: 'Search Results';

    $meta_vars = [
        'meta_description'  => '',
        'meta_keywords'     => '',
        'page_title'        => $default_title,
        'canonical_url'     => '',
        'og_image'          => '',
        'og_title'          => $default_title,
        'og_description'    => '',
        'og_type'           => 'website',
        'og_url'            => '',
        'og_site_name'      => '',
        'twitter_card'      => '',
        'twitter_title'     => '',
        'twitter_description' => '',
        'twitter_image'     => '',
        'robots'            => '',
        'hreflang_links'    => [],
        'schema_org'        => '',
        'extra_meta'        => '',
        'page_description'  => '',
        'company_name'      => '',
        'site_name'         => '',
        'absolute_uri'      => '',
    ];

    foreach ($meta_vars as $var => $default) {
        if ($view->getTemplateVars($var) === null) {
            $view->assign($var, $default);
        }
    }

    $registry_vars = [
        'navigation.dynamic.meta_description' => '',
        'navigation.dynamic.meta_keywords'    => '',
        'navigation.dynamic.page_title'       => $default_title,
        'runtime.page_title'                  => $default_title,
    ];

    foreach ($registry_vars as $key => $default) {
        if (Registry::get($key) === null) {
            Registry::set($key, $default);
        }
    }
}

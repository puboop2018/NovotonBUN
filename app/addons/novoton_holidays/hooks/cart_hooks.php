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
use Tygh\Addons\NovotonHolidays\Services\GuestDataNormalizer;
use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\NovotonHolidays\Helpers\JsonDecoder;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

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
    $all_bookings = $repo->findByProductIds(
        $product_ids,
        [\Tygh\Addons\NovotonHolidays\Constants::STATUS_PENDING, \Tygh\Addons\NovotonHolidays\Constants::STATUS_CONFIRMED],
        $current_session_id,
        $auth_user_id
    );

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
            _nvt_inject_booking_into_cart_product($product, $booking, $cart, $cart_id);
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
    if (fn_novoton_holidays_is_debug()) {
        fn_log_event('general', 'runtime', [
            'message'             => 'Novoton calculate_cart_items_post',
            'cart_products_count' => count($cart_products),
        ]);
    }

    foreach ($cart_products as $cart_id => &$product) {
        if (empty($product['extra']['novoton_booking'])) {
            continue;
        }

        if (!empty($product['extra']['rooms_data']) && is_string($product['extra']['rooms_data'])) {
            $decoded = json_decode($product['extra']['rooms_data'], true);
            if (is_array($decoded)) {
                $product['extra']['rooms_data'] = $decoded;
                if (isset($cart['products'][$cart_id])) {
                    $cart['products'][$cart_id]['extra']['rooms_data'] = $decoded;
                }
            }
        }
    }
}

/**
 * Hook: checkout page display - add debug info
 */
function fn_novoton_holidays_checkout_pre_dispatch(&$cart, &$auth, $storefront_id = null): void
{
    if (fn_novoton_holidays_is_debug()) {
        \Tygh\Tygh::$app['view']->assign('novoton_checkout_debug', true);
        \Tygh\Tygh::$app['view']->assign('novoton_debug_cart_products', $cart['products'] ?? []);
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
    $product['extra']['guests_data']        = GuestDataNormalizer::toJson($booking['guests_data'] ?? '');
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
 * Populates product_options_value[] with formatted booking details
 * for display in cart, checkout, and order pages.
 */
function fn_novoton_holidays_add_booking_display_data(&$product, $cart = null): void
{
    $date_format = Registry::get('settings.Appearance.date_format') ?: '%d.%m.%Y';

    // Format dates
    $check_in_ts  = !empty($product['extra']['check_in'])  ? strtotime($product['extra']['check_in'])  : false;
    $check_out_ts = !empty($product['extra']['check_out']) ? strtotime($product['extra']['check_out']) : false;
    $check_in_fmt  = ($check_in_ts  !== false) ? fn_date_format($check_in_ts, $date_format)  : '';
    $check_out_fmt = ($check_out_ts !== false) ? fn_date_format($check_out_ts, $date_format) : '';

    $num_rooms  = (int)($product['extra']['num_rooms'] ?? 1);
    $rooms_data = $product['extra']['rooms_data'] ?? [];
    if (is_string($rooms_data)) {
        $rooms_data = JsonDecoder::decode($rooms_data, 'rooms_data');
    }

    // Build guests string
    $adults   = (int)($product['extra']['adults']   ?? 2);
    $children = (int)($product['extra']['children'] ?? 0);

    $guests_str = '';
    if ($num_rooms > 1) {
        $guests_str .= $num_rooms . ' rooms, ';
    }
    $guests_str .= $adults . ' adult' . ($adults > 1 ? 's' : '');

    if ($children > 0) {
        $guests_str .= ', ' . $children . ' child' . ($children > 1 ? 'ren' : '');

        if (!empty($product['extra']['children_ages'])) {
            $ages_str = $product['extra']['children_ages'];
            if (is_array($ages_str)) {
                $ages_str = implode(', ', $ages_str);
            }
            $ages_arr = array_map('trim', explode(',', $ages_str));
            $ages_arr = array_filter($ages_arr, function ($a) { return $a !== '' && $a !== 'age_needed'; });
            if (!empty($ages_arr)) {
                $guests_str .= ' (' . implode(' and ', array_map(function ($a) { return $a . ' y/o'; }, $ages_arr)) . ')';
            }
        }
    }

    // Board + room name
    $board_id   = $product['extra']['board_id'] ?? '';
    $board_name = fn_novoton_holidays_format_board_name($board_id);
    $product['extra']['board_name'] = $board_name;

    $room_id   = $product['extra']['room_id']   ?? '';
    $room_type = $product['extra']['room_type'] ?? '';
    $product['extra']['room_type_display'] = fn_novoton_holidays_format_room_type($room_id, $room_type);

    // Build product_options_value for display
    $product['product_options_value'] = [];

    // Package
    if (!empty($product['extra']['package_name'])) {
        $product['product_options_value'][] = [
            'option_name' => __('novoton_holidays.package'),
            'value'       => $product['extra']['package_name'],
        ];
    }

    // Dates
    $product['product_options_value'][] = [
        'option_name' => __('novoton_holidays.dates'),
        'value'       => $check_in_fmt . ' → ' . $check_out_fmt . ' (' . ($product['extra']['nights'] ?? 7) . ' ' . __('novoton_holidays.nights') . ')',
    ];

    // Room info
    $room_name = _nvt_build_room_display($product, $num_rooms, $rooms_data);
    $product['product_options_value'][] = [
        'option_name' => __('novoton_holidays.room'),
        'value'       => $room_name,
    ];

    // Board/Meal plan
    $board_display = _nvt_build_board_display($board_name, $num_rooms, $rooms_data);
    $product['product_options_value'][] = [
        'option_name' => __('novoton_holidays.board'),
        'value'       => $board_display,
    ];

    // Guests
    $product['product_options_value'][] = [
        'option_name' => __('novoton_holidays.guests'),
        'value'       => $guests_str,
    ];

    // Per-room breakdown
    if ($num_rooms > 1 && !empty($rooms_data)) {
        foreach ($rooms_data as $idx => $room) {
            $room_num    = $idx + 1;
            $room_guests = (int)($room['adults'] ?? 2) . ' adults';
            if (!empty($room['children']) && $room['children'] > 0) {
                $room_guests .= ', ' . $room['children'] . ' children';
                if (!empty($room['childrenAges'])) {
                    $ages = array_filter($room['childrenAges'], function ($a) { return $a !== null && $a !== ''; });
                    if (!empty($ages)) {
                        $room_guests .= ' (' . implode(', ', $ages) . ' y/o)';
                    }
                }
            }
            $product['product_options_value'][] = [
                'option_name' => 'Room ' . $room_num,
                'value'       => $room_guests,
            ];
        }
    }

    // Holder name
    if (!empty($product['extra']['holder_name']) || !empty($product['extra']['guest_names'])) {
        $product['product_options_value'][] = [
            'option_name' => __('novoton_holidays.holder'),
            'value'       => $product['extra']['holder_name'] ?? $product['extra']['guest_names'],
        ];
    }

    $product['is_hotel_booking'] = true;

    if (empty($product['product_options'])) {
        $product['product_options'] = [];
    }
}

/**
 * Build room display string, handling multi-room with different types.
 */
function _nvt_build_room_display(array $product, int $num_rooms, array $rooms_data): string
{
    $room_name = $product['extra']['room_name']
        ?? str_replace(['%2b', '%2B'], '+', $product['extra']['room_id'] ?? '');

    if ($num_rooms <= 1 || empty($rooms_data)) {
        return $room_name;
    }

    $room_types = [];
    $has_different = false;
    $first = null;

    foreach ($rooms_data as $room) {
        $id = $room['room_id'] ?? $room['room_name'] ?? '';
        if ($first === null) {
            $first = $id;
        } elseif ($id !== $first) {
            $has_different = true;
        }
        $room_types[] = $room['room_name'] ?? str_replace(['%2b', '%2B'], '+', $room['room_id'] ?? $room_name);
    }

    return $has_different
        ? implode(', ', $room_types)
        : $num_rooms . 'x ' . $room_name;
}

/**
 * Build board display string, handling multi-room with different boards.
 */
function _nvt_build_board_display(string $board_name, int $num_rooms, array $rooms_data): string
{
    if ($num_rooms <= 1 || empty($rooms_data)) {
        return $board_name;
    }

    $boards = [];
    $has_different = false;
    $first = null;

    foreach ($rooms_data as $room) {
        $board = $room['board_name'] ?? fn_novoton_holidays_format_board_name($room['board_id'] ?? '');
        if (!empty($board)) {
            if ($first === null) {
                $first = $board;
            } elseif ($board !== $first) {
                $has_different = true;
            }
            $boards[] = $board;
        }
    }

    return $has_different ? implode(', ', $boards) : $board_name;
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

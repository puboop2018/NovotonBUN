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

use Tygh\Addons\NovotonHolidays\Helpers\JsonDecoder;
use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\NovotonHolidays\Services\PriceInfoFormatter;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Registry;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

use Tygh\Addons\TravelCore\TravelConstants;

/**
 * Hook: Format cart product info for hotel bookings
 *
 * @param array<string, mixed> $product
 * @param array<string, mixed> $cart
 * @param array<string, mixed> $auth
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
 *
 * @param array<string, mixed> $cart
 * @param array<string, mixed> $cart_products
 * @param array<string, mixed> $auth
 */
function fn_novoton_holidays_calculate_cart_items(&$cart, &$cart_products, $auth): void
{
    if (empty($cart_products)) {
        return;
    }

    $product_ids = TypeCoerce::toIntList(array_column($cart_products, 'product_id'));

    $repo = Container::getInstance()->bookingOwnershipRepository();
    $auth_user_id = PriceInfoFormatter::toInt($auth['user_id'] ?? 0);
    $current_session_id = session_id() ?: '';
    $default_statuses = [TravelConstants::STATUS_PENDING, TravelConstants::STATUS_CONFIRMED];
    $all_bookings = $repo->findByProductIds($product_ids, $default_statuses, $current_session_id, $auth_user_id);

    if (empty($all_bookings)) {
        return;
    }

    // Group by product_id
    $bookings_by_product = [];
    foreach ($all_bookings as $booking) {
        if (!is_array($booking)) {
            continue;
        }
        $bProdId = PriceInfoFormatter::toScalar($booking['product_id'] ?? '');
        $bookings_by_product[$bProdId][] = $booking;
    }

    // Assign bookings to cart items (first-come, first-served)
    $used_booking_ids = [];

    foreach ($cart_products as $cart_id => &$product) {
        if (!is_array($product)) {
            continue;
        }
        /** @var array<string, mixed> $product */
        $product_id = PriceInfoFormatter::toScalar($product['product_id'] ?? '');
        /** @var array<string, mixed> $pExtra */
        $pExtra = is_array($product['extra'] ?? null) ? $product['extra'] : [];

        // Already has booking data with ID — keep it
        if (!empty($pExtra['novoton_booking']) && !empty($pExtra['novoton_booking_id'])) {
            fn_novoton_holidays_add_booking_display_data($product, $cart);
            $used_booking_ids[] = PriceInfoFormatter::toInt($pExtra['novoton_booking_id']);
            continue;
        }

        // Find first unused booking for this product
        if (!isset($bookings_by_product[$product_id])) {
            continue;
        }

        foreach ($bookings_by_product[$product_id] as $booking) {
            if (!is_array($booking)) {
                continue;
            }
            /** @var array<string, mixed> $booking */
            $bookingId = PriceInfoFormatter::toInt($booking['booking_id'] ?? 0);
            if (in_array($bookingId, $used_booking_ids, true)) {
                continue;
            }

            $used_booking_ids[] = $bookingId;
            _nvt_inject_booking_into_cart_product($product, $booking, $cart, (string) $cart_id);
            fn_novoton_holidays_add_booking_display_data($product, $cart);
            break;
        }
    }
}

/**
 * Hook: after calculate cart - ensure rooms_data is preserved as array
 *
 * @param array<string, mixed> $cart
 * @param array<string, mixed> $cart_products
 * @param array<string, mixed> $auth
 */
function fn_novoton_holidays_calculate_cart_items_post(&$cart, &$cart_products, $auth): void
{
    // rooms_data decode is handled by travel_core's calculate_cart_items_post hook
    // (novoton products now set travel_booking = true alongside novoton_booking)
    if (fn_novoton_holidays_is_debug()) {
        fn_log_event('general', 'runtime', [
            'message' => 'Novoton calculate_cart_items_post',
            'cart_products_count' => count($cart_products),
        ]);
    }
}

/**
 * Hook: checkout page display - add debug info and price change alerts
 *
 * @param array<string, mixed> $cart
 * @param array<string, mixed> $auth
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
    // Register Smarty modifiers for ALL dispatches.
    // Explicit registration ensures Smarty 5 finds the modifier even if
    // auto-discovery of smarty_modifier_* functions is disabled.
    try {
        \Tygh\Tygh::$app['view']->registerPlugin('modifier', 'json_decode', 'smarty_modifier_json_decode');
    } catch (\Throwable $e) {
        // Silently ignore if already registered or view not available
    }
    if (function_exists('fn_novoton_holidays_register_smarty_modifiers')) {
        fn_novoton_holidays_register_smarty_modifiers();
    }

    $dispatch = $_REQUEST['dispatch'] ?? '';

    // Meta variable null-safety for our frontend controllers only.
    // Admin pages do not render meta.tpl and must not receive array-typed
    // Smarty variables (e.g. hreflang_links) that CS-Cart head templates
    // output as a plain string, which produces literal "Array" in the page.
    if (str_starts_with($dispatch, 'novoton_') && defined('AREA') && AREA === 'C') {
        _nvt_ensure_meta_variables();
    }

    // Frontend CSS for booking-related pages
    if (AREA === 'C') {
        $booking_pages = ['novoton_', 'products.', 'checkout', 'cart'];
        $needs_css = false;
        foreach ($booking_pages as $prefix) {
            if (str_starts_with($dispatch, $prefix)) {
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
 *
 * @param mixed $string
 * @param bool $assoc
 * @return mixed
 */
function smarty_modifier_json_decode($string, $assoc = true)
{
    if (empty($string) || !is_string($string)) {
        return $assoc ? [] : null;
    }
    $result = json_decode($string, $assoc);
    return ($result === null && json_last_error() !== JSON_ERROR_NONE)
        ? ($assoc ? [] : null)
        : $result;
}

// ============================================================================
// CART HELPERS (private-by-convention)
// ============================================================================

/**
 * Inject DB booking fields into a cart product's extra data.
 *
 * @param array<string, mixed> $product
 * @param array<string, mixed> $booking
 * @param array<string, mixed> $cart
 */
function _nvt_inject_booking_into_cart_product(
    array &$product,
    array  $booking,
    array &$cart,
    string $cart_id,
): void {
    $bRoomId = PriceInfoFormatter::toScalar($booking['room_id'] ?? '');
    $bBoardId = PriceInfoFormatter::toScalar($booking['board_id'] ?? '');

    /** @var array<string, mixed> $extra */
    $extra = is_array($product['extra'] ?? null) ? $product['extra'] : [];

    $extra['travel_booking'] = true;
    $extra['novoton_booking'] = true;
    $extra['novoton_booking_id'] = $booking['booking_id'] ?? 0;
    $extra['hotel_id'] = PriceInfoFormatter::toScalar($booking['hotel_id'] ?? '');
    $extra['room_id'] = $bRoomId;
    $extra['room_name'] = fn_novoton_holidays_format_room_type($bRoomId, PriceInfoFormatter::toScalar($booking['room_type'] ?? ''));
    $extra['board_id'] = $bBoardId;
    $extra['board_name'] = fn_novoton_holidays_format_board_name($bBoardId);
    $extra['check_in'] = PriceInfoFormatter::toScalar($booking['check_in'] ?? '');
    $extra['check_out'] = PriceInfoFormatter::toScalar($booking['check_out'] ?? '');
    $extra['nights'] = PriceInfoFormatter::toInt($booking['nights'] ?? 0);
    $extra['adults'] = PriceInfoFormatter::toInt($booking['adults'] ?? 2);
    $extra['children'] = PriceInfoFormatter::toInt($booking['children'] ?? 0);
    $extra['children_ages'] = PriceInfoFormatter::toScalar($booking['children_ages'] ?? '');
    $extra['holder_name'] = PriceInfoFormatter::toScalar($booking['holder_name'] ?? '');
    $extra['guest_names'] = PriceInfoFormatter::toScalar($booking['guest_name'] ?? '');
    $extra['guests_data'] = class_exists(\Tygh\Addons\TravelCore\Services\GuestDataNormalizer::class)
        ? (new \Tygh\Addons\TravelCore\Services\GuestDataNormalizer())->toJson($booking['guests_data'] ?? '')
        : PriceInfoFormatter::toScalar($booking['guests_data'] ?? '');
    $extra['total_price'] = PriceInfoFormatter::toFloat($booking['total_price'] ?? 0);
    $extra['package_name'] = PriceInfoFormatter::toScalar($booking['package_name'] ?? '');
    $extra['num_rooms'] = PriceInfoFormatter::toInt($booking['num_rooms'] ?? 1);

    if (!empty($booking['rooms_data'])) {
        $rooms = json_decode(PriceInfoFormatter::toScalar($booking['rooms_data']), true);
        if (is_array($rooms)) {
            $extra['rooms_data'] = $rooms;
        }
    }

    $product['extra'] = $extra;

    // Sync back to cart session
    if (isset($cart['products'][$cart_id]) && is_array($cart['products'][$cart_id])) {
        $cart['products'][$cart_id]['extra'] = $extra;
    }
}

/**
 * Helper: Add booking display data to a product.
 *
 * Delegates to BookingDisplayService::addBookingDisplayData() with
 * Novoton-specific config (lang prefix, formatters, JSON decoder).
 *
 * @param array<string, mixed> $product
 * @param array<string, mixed>|null $cart
 */
function fn_novoton_holidays_add_booking_display_data(array &$product, ?array $cart = null): void
{
    // Pre-set board/room display names using Novoton formatters
    /** @var array<string, mixed> $bdExtra */
    $bdExtra = is_array($product['extra'] ?? null) ? $product['extra'] : [];
    $board_id = PriceInfoFormatter::toScalar($bdExtra['board_id'] ?? '');
    $product['extra']['board_name'] = fn_novoton_holidays_format_board_name($board_id);

    $room_id = PriceInfoFormatter::toScalar($bdExtra['room_id'] ?? '');
    $room_type = PriceInfoFormatter::toScalar($bdExtra['room_type'] ?? '');
    $product['extra']['room_type_display'] = fn_novoton_holidays_format_room_type($room_id, $room_type);
    $product['extra']['room_name'] = PriceInfoFormatter::toScalar($bdExtra['room_name'] ?? '')
        ?: str_replace(['%2b', '%2B'], '+', $room_id);

    if (class_exists(\Tygh\Addons\TravelCore\Services\BookingDisplayService::class)) {
        \Tygh\Addons\TravelCore\Services\BookingDisplayService::addBookingDisplayData($product, $cart, [
            'lang_prefix' => 'novoton_holidays',
            'json_decoder' => [JsonDecoder::class, 'decode'],
            'board_name_formatter' => 'fn_novoton_holidays_format_board_name',
            'room_name_formatter' => function (array $room) {
                $name = PriceInfoFormatter::toScalar($room['room_name'] ?? '');
                if (empty($name)) {
                    $name = str_replace(['%2b', '%2B'], '+', PriceInfoFormatter::toScalar($room['room_id'] ?? ''));
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
        'meta_description' => '',
        'meta_keywords' => '',
        'page_title' => $default_title,
        'canonical_url' => '',
        'og_image' => '',
        'og_title' => $default_title,
        'og_description' => '',
        'og_type' => 'website',
        'og_url' => '',
        'og_site_name' => '',
        'twitter_card' => '',
        'twitter_title' => '',
        'twitter_description' => '',
        'twitter_image' => '',
        'robots' => '',
        'hreflang_links' => [],
        'schema_org' => '',
        'extra_meta' => '',
        'page_description' => '',
        'company_name' => '',
        'site_name' => '',
        'absolute_uri' => '',
    ];

    foreach ($meta_vars as $var => $default) {
        if ($view->getTemplateVars($var) === null) {
            $view->assign($var, $default);
        }
    }

    $registry_vars = [
        'navigation.dynamic.meta_description' => '',
        'navigation.dynamic.meta_keywords' => '',
        'navigation.dynamic.page_title' => $default_title,
        'runtime.page_title' => $default_title,
    ];

    foreach ($registry_vars as $key => $default) {
        if (Registry::get($key) === null) {
            Registry::set($key, $default);
        }
    }
}

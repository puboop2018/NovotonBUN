<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Order Hook Functions
 *
 * Responsible for:
 *   - place_order: Delegates to BookingSubmissionService
 *   - get_orders_post: Attach booking data to order listings
 *   - get_order_info: Enrich order products with terms, locations, formatted data
 *
 * @package NovotonHolidays
 * @since   3.0.0
 */

use Tygh\Registry;
use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\NovotonHolidays\Services\GuestDataNormalizer;
use Tygh\Addons\NovotonHolidays\Repository\BookingRepository;
use Tygh\Addons\NovotonHolidays\Repository\HotelRepository;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

// ============================================================================
// HOOK: place_order
// ============================================================================

/**
 * Hook: place_order - Send booking to Novoton API after order is placed.
 *
 * Delegates entirely to BookingSubmissionService which encapsulates:
 *   1. DB hydration, room/guest resolution, room grouping
 *   2. API payload construction, DB upsert, API submission
 */
function fn_novoton_holidays_place_order(&$order_id, &$action, &$order_status, &$cart, &$auth): void
{
    Container::getInstance()->bookingSubmissionService()->submitOrder($order_id, $cart);
}

// ============================================================================
// HOOK: get_orders_post
// ============================================================================

/**
 * Hook: after getting orders — attach booking data to order listings.
 *
 * Uses a single batch query (not N+1) to fetch all bookings for all
 * orders in the result set.
 */
function fn_novoton_holidays_get_orders_post($params, &$orders): void
{
    if (empty($orders)) {
        return;
    }

    $order_ids = array_column($orders, 'order_id');
    if (empty($order_ids)) {
        return;
    }

    $repo = Container::getInstance()->bookingRepository();
    $all_bookings = $repo->findByOrderIds($order_ids);

    if (empty($all_bookings)) {
        return;
    }

    $bookings_by_order = [];
    foreach ($all_bookings as $booking) {
        $bookings_by_order[$booking['order_id']][] = $booking;
    }

    foreach ($orders as &$order) {
        if (!empty($order['order_id']) && isset($bookings_by_order[$order['order_id']])) {
            $order['hotel_bookings'] = $bookings_by_order[$order['order_id']];
        }
    }
}

// ============================================================================
// HOOK: get_order_info
// ============================================================================

/**
 * Hook: After getting order info — format Novoton booking terms for display.
 *
 * Enriches order products with:
 *   - Hotel location (city, region, country)
 *   - Formatted dates (CS-Cart date format)
 *   - Payment & cancellation terms (from raw XML or API)
 *   - Guest display names (Last, First format)
 *   - Board display name via BoardType value object
 */
function fn_novoton_holidays_get_order_info(&$order, $additional_data): void
{
    $debugMode = ConfigProvider::isDebugLogging();

    if ($debugMode) {
        fn_set_notification('N', 'DEBUG', 'fn_novoton_holidays_get_order_info hook fired for order #' . ($order['order_id'] ?? '?'));
    }

    if (empty($order['products'])) {
        return;
    }

    $date_format   = Registry::get('settings.Appearance.date_format') ?: '%d %b %Y';
    $currency_code = $order['secondary_currency'] ?? 'EUR';

    // Pre-fetch hotel locations in single query (avoid N+1)
    $hotel_ids = [];
    foreach ($order['products'] as $product) {
        if (!empty($product['extra']['novoton_booking']) && !empty($product['extra']['hotel_id']) && empty($product['extra']['city'])) {
            $hotel_ids[$product['extra']['hotel_id']] = true;
        }
    }
    $hotels_cache = [];
    if (!empty($hotel_ids)) {
        $hotelRepo = Container::getInstance()->hotelRepository();
        $hotels_cache = $hotelRepo->getLocationsByIds(array_keys($hotel_ids));
    }

    foreach ($order['products'] as &$product) {
        if ($debugMode) {
            fn_set_notification('N', 'DEBUG', 'Product extra keys: ' . implode(', ', array_keys($product['extra'] ?? [])));
        }

        if (empty($product['extra']['novoton_booking'])) {
            continue;
        }

        $hotel_id    = $product['extra']['hotel_id']  ?? '';
        $check_in    = $product['extra']['check_in']  ?? '';
        $check_out   = $product['extra']['check_out'] ?? '';
        $total_price = floatval($product['extra']['total_price'] ?? $product['price'] ?? 0);

        // [1] Hotel location
        if (!empty($hotel_id) && empty($product['extra']['city']) && isset($hotels_cache[$hotel_id])) {
            $loc = $hotels_cache[$hotel_id];
            $product['extra']['city']    = $loc['city']    ?? '';
            $product['extra']['region']  = $loc['region']  ?? '';
            $product['extra']['country'] = $loc['country'] ?? '';
        }

        // [2] Formatted dates
        $ci_ts = !empty($check_in)  ? strtotime($check_in)  : false;
        $co_ts = !empty($check_out) ? strtotime($check_out) : false;
        if ($ci_ts !== false) {
            $product['extra']['check_in_formatted']  = fn_date_format($ci_ts, $date_format);
        }
        if ($co_ts !== false) {
            $product['extra']['check_out_formatted'] = fn_date_format($co_ts, $date_format);
        }

        // [3] Payment & cancellation terms
        _nvt_enrich_order_product_terms($product, $hotel_id, $check_in, $check_out, $total_price, $currency_code);

        // [4] Board display name
        $board_id = $product['extra']['board_id'] ?? $product['extra']['board'] ?? '';
        if (!empty($board_id)) {
            $product['extra']['board_display'] = fn_novoton_format_board_name($board_id);
        }

        // [5] Guests data formatting
        _nvt_format_order_guests($product);

        if ($debugMode) {
            $payment_set    = !empty($product['extra']['terms_of_payment_formatted'])      ? 'YES' : 'NO';
            $payment_amounts = !empty($product['extra']['terms_of_payment_with_amounts'])   ? 'YES' : 'NO';
            $cancel_set     = !empty($product['extra']['terms_of_cancellation_formatted']) ? 'YES' : 'NO';
            fn_set_notification('N', 'DEBUG', "terms_of_payment_formatted: {$payment_set}, with_amounts: {$payment_amounts}, cancellation: {$cancel_set}");
        }
    }
}

// ============================================================================
// DISPLAY HELPERS (used by get_order_info hook)
// ============================================================================

/**
 * Enrich an order product with formatted payment and cancellation terms.
 *
 * Terms are persisted in novoton_bookings at booking creation time.
 * Falls back to a DB lookup by booking_id if not in cart extra.
 * No live API call is made — terms are a snapshot from booking time.
 */
function _nvt_enrich_order_product_terms(
    array &$product,
    string $hotel_id,
    string $check_in,
    string $check_out,
    float  $total_price,
    string $currency_code
): void {
    $payment_raw  = $product['extra']['terms_of_payment_raw']      ?? '';
    $payment_text = $product['extra']['terms_of_payment']          ?? '';
    $cancel_raw   = $product['extra']['terms_of_cancellation_raw'] ?? '';
    $cancel_text  = $product['extra']['terms_of_cancellation']     ?? '';

    // Fallback: fetch from novoton_bookings DB record (terms are persisted at booking creation)
    if (empty($payment_raw) && empty($payment_text) && empty($cancel_raw) && empty($cancel_text)) {
        $booking_id = intval($product['extra']['novoton_booking_id'] ?? 0);
        if ($booking_id > 0) {
            $repo = Container::getInstance()->bookingRepository();
            $terms = $repo->getTerms($booking_id);
            if (!empty($terms)) {
                $payment_raw  = $terms['terms_of_payment_raw'] ?? '';
                $cancel_raw   = $terms['terms_of_cancellation_raw'] ?? '';
                $payment_text = $terms['terms_of_payment_formatted'] ?? '';
                $cancel_text  = $terms['terms_of_cancellation_formatted'] ?? '';
            }
        }
    }

    // Format payment terms
    if (!empty($payment_raw) && $total_price > 0) {
        $product['extra']['terms_of_payment_with_amounts'] = fn_novoton_format_payment_terms_with_amounts(
            $payment_raw, $total_price, $currency_code
        );
        $product['extra']['terms_of_payment_formatted'] = fn_novoton_format_payment_terms($payment_raw);
    } elseif (!empty($payment_raw)) {
        $product['extra']['terms_of_payment_formatted'] = fn_novoton_format_payment_terms($payment_raw);
    } elseif (!empty($payment_text)) {
        $product['extra']['terms_of_payment_formatted'] = $payment_text;
    }

    // Format cancellation terms
    if (!empty($cancel_raw)) {
        $product['extra']['terms_of_cancellation_formatted'] = fn_novoton_format_cancellation_terms($cancel_raw, $check_in);
    } elseif (!empty($cancel_text)) {
        $product['extra']['terms_of_cancellation_formatted'] = $cancel_text;
    }
}

/**
 * Format guests_data on an order product for email/display.
 *
 * Converts api_name (First Last) to display format (Last, First) and
 * marks the holder guest.
 */
function _nvt_format_order_guests(array &$product): void
{
    $guests_data = $product['extra']['guests_data'] ?? null;

    if (empty($guests_data)) {
        return;
    }

    $guests_data = GuestDataNormalizer::normalize($guests_data);
    if (empty($guests_data)) {
        return;
    }

    $formatted   = [];
    $holder_name = $product['extra']['holder_name'] ?? '';
    $is_first    = true;

    foreach ($guests_data as $key => $guest) {
        if (!is_array($guest)) {
            continue;
        }

        $display_name = $guest['display_name'] ?? $guest['name'] ?? '';
        $api_name     = $guest['api_name'] ?? '';

        if (empty($display_name) && !empty($api_name)) {
            $parts = explode(' ', trim($api_name), 2);
            $display_name = count($parts) === 2
                ? $parts[1] . ', ' . $parts[0]
                : $api_name;
        }

        $guest_type = $guest['type'] ?? 'adult';
        $is_holder  = false;

        if ($is_first && $guest_type === 'adult') {
            $is_holder = true;
            $is_first  = false;
        } elseif (!empty($holder_name) && stripos($display_name, $holder_name) !== false) {
            $is_holder = true;
        }

        $formatted[$key] = [
            'display_name' => $display_name,
            'name'         => $guest['name'] ?? $display_name,
            'type'         => $guest_type,
            'age'          => intval($guest['age'] ?? 0),
            'is_holder'    => $is_holder,
            'birthday'     => $guest['birthday'] ?? '',
            'room'         => $guest['room'] ?? 1,
        ];
    }

    $product['extra']['guests_data'] = $formatted;
}

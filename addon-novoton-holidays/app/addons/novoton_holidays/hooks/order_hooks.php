<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Order Hook Functions
 *
 * Responsible for:
 *   - pre_place_order: Real-time API price verification + correction before order
 *   - place_order_post: Delegates to BookingSubmissionService
 *   - get_orders_post: Attach booking data to order listings
 *   - get_order_info: Enrich order products with terms, locations, formatted data
 *
 * @package NovotonHolidays
 * @since   3.0.0
 */

use Tygh\Registry;
use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\PriceInfoFormatter;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Addons\TravelCore\TravelConstants;

// ============================================================================
// HOOK: pre_place_order
// ============================================================================

/**
 * Hook: pre_place_order - Real-time price verification before order is placed.
 *
 * This is the MOST IMPORTANT safety net: just before the user's order is
 * created, we call the room_price API to verify that the price the customer
 * is paying is still valid.
 *
 * CS-Cart signature: fn_set_hook('pre_place_order', $cart, $allow, $product_groups)
 *
 * Scenarios:
 *   - Form price < API price → CORRECT cart price to API price, send admin notification + email
 *   - Form price > API price by > threshold% → ALLOW order, send admin notification + email
 *   - Prices match → ALLOW order silently
 *
 * We never block the order. If the price has increased, we silently update
 * the cart to the correct API price (same as the add_to_cart price floor).
 * The customer's order proceeds without interruption; admin is notified.
 *
 * @param array<string, mixed> $cart
 * @param string $allow
 * @param array<string, mixed> $product_groups
 */
function fn_novoton_holidays_pre_place_order(&$cart, &$allow, &$product_groups): void
{
    $verifier = Container::getInstance()->preOrderPriceVerifier();
    $result = $verifier->verify($cart);

    // Apply price corrections: bump cart prices to the current API price
    $corrections = $result['corrections'];
    if (!empty($corrections) && is_array($cart['products'] ?? null)) {
        foreach ($corrections as $cartId => $correction) {
            if (!is_array($correction) || !isset($cart['products'][$cartId]) || !is_array($cart['products'][$cartId])) {
                continue;
            }

            $newPrice = PriceInfoFormatter::toFloat($correction['api_price'] ?? 0);
            /** @var array<string, mixed> $existingProduct */
            $existingProduct = $cart['products'][$cartId];
            /** @var array<string, mixed> $existingExtra */
            $existingExtra = is_array($existingProduct['extra'] ?? null) ? $existingProduct['extra'] : [];

            // Store the old price for "Old vs New" display before overwriting
            $existingExtra['price_before_correction'] = $existingProduct['price'] ?? null;
            $existingExtra['total_price'] = $newPrice;

            $existingProduct['price']          = $newPrice;
            $existingProduct['base_price']     = $newPrice;
            $existingProduct['original_price'] = $newPrice;
            $existingProduct['extra']          = $existingExtra;
            $cart['products'][$cartId]         = $existingProduct;
        }
    }

    // Send email notifications for any price discrepancies (lower OR higher)
    foreach ($result['notifications'] as $notification) {
        fn_novoton_holidays_send_price_discrepancy_email($notification);
    }
}

// ============================================================================
// HOOK: place_order_post
// ============================================================================

/**
 * Hook: place_order_post - Send booking to Novoton API after order is placed.
 *
 * Must be place_order_post (not place_order) because $order_id is only
 * available after the order record has been created.
 *
 * CS-Cart signature: fn_set_hook('place_order_post', $order_id, $action, $order_status, $cart, $auth)
 *
 * Delegates entirely to BookingSubmissionService which encapsulates:
 *   1. DB hydration, room/guest resolution, room grouping
 *   2. API payload construction, DB upsert, API submission
 *
 * @param int|list<int> $order_id
 * @param string $action
 * @param string $order_status
 * @param array<string, mixed>|null $cart
 * @param array<string, mixed> $auth
 */
function fn_novoton_holidays_place_order_post(&$order_id, &$action, &$order_status, &$cart, &$auth): void
{
    // CS-Cart Multi-Vendor passes $order_id as array (parent + child order IDs).
    // Normalize to the parent (first) order ID for booking submission.
    $resolved_order_id = (int) (is_array($order_id) ? reset($order_id) : $order_id);

    if (empty($resolved_order_id)) {
        return;
    }

    // Primary path: use cart data when available (full booking submission with API call)
    if (is_array($cart) && !empty($cart['products'])) {
        Container::getInstance()->bookingSubmissionService()->submitOrder($resolved_order_id, $cart);
        return;
    }

    // Fallback path: $cart is null/empty (payment callbacks, order status re-triggers).
    // Link any unlinked novoton bookings to this order by looking up the order's products.
    $order_info = fn_get_order_info($resolved_order_id);
    /** @var array<string, mixed> $order_info */
    $order_info = is_array($order_info) ? $order_info : [];
    $oiProducts = is_array($order_info['products'] ?? null) ? $order_info['products'] : [];
    if (empty($oiProducts)) {
        return;
    }

    foreach ($oiProducts as $product) {
        if (!is_array($product)) {
            continue;
        }
        /** @var array<string, mixed> $extra */
        $extra = is_array($product['extra'] ?? null) ? $product['extra'] : [];
        if (empty($extra['novoton_booking']) || empty($extra['novoton_booking_id'])) {
            continue;
        }

        $booking_id = PriceInfoFormatter::toInt($extra['novoton_booking_id']);
        if ($booking_id <= 0) {
            continue;
        }

        // Only link if the booking isn't already linked to an order
        $current_order = PriceInfoFormatter::toInt(db_get_field(
            "SELECT order_id FROM ?:novoton_bookings WHERE booking_id = ?i", $booking_id
        ));

        if ($current_order <= 0) {
            _nvt_booking_repo()->update($booking_id, ['order_id' => $resolved_order_id]);
        }
    }
}

// ============================================================================
// HOOK: get_orders_post
// ============================================================================

/**
 * Hook: after getting orders — attach booking data to order listings.
 *
 * Uses a single batch query (not N+1) to fetch all bookings for all
 * orders in the result set.
 *
 * @param array<string, mixed> $params
 * @param list<array<string, mixed>> $orders
 */
function fn_novoton_holidays_get_orders_post($params, &$orders): void
{
    if (empty($orders)) {
        return;
    }

    /** @var list<int> $order_ids */
    $order_ids = array_values(array_map('intval', array_column($orders, 'order_id')));
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
        $bOrderId = PriceInfoFormatter::toScalar($booking['order_id'] ?? '');
        $bookings_by_order[$bOrderId][] = $booking;
    }

    foreach ($orders as &$order) {
        $oOrderId = PriceInfoFormatter::toScalar($order['order_id'] ?? '');
        if (!empty($oOrderId) && isset($bookings_by_order[$oOrderId])) {
            $order['hotel_bookings'] = $bookings_by_order[$oOrderId];
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
 *
 * @param array<string, mixed> $order
 * @param array<string, mixed> $additional_data
 */
function fn_novoton_holidays_get_order_info(&$order, $additional_data): void
{
    $debugMode = ConfigProvider::isDebugLogging();

    if ($debugMode && defined('AREA') && AREA === 'A') {
        fn_set_notification('N', 'DEBUG', 'fn_novoton_holidays_get_order_info hook fired for order #' . PriceInfoFormatter::toScalar($order['order_id'] ?? '?'));
    }

    $orderProducts = is_array($order['products'] ?? null) ? $order['products'] : [];
    if (empty($orderProducts)) {
        return;
    }

    $date_format   = Registry::get('settings.Appearance.date_format') ?: '%d %b %Y';
    $currency_code = PriceInfoFormatter::toScalar($order['secondary_currency'] ?? TravelConstants::CURRENCY_EUR);

    // Pre-fetch hotel locations in single query (avoid N+1)
    $hotel_ids = [];
    foreach ($orderProducts as $product) {
        if (!is_array($product)) {
            continue;
        }
        /** @var array<string, mixed> $pExtra */
        $pExtra = is_array($product['extra'] ?? null) ? $product['extra'] : [];
        if (!empty($pExtra['novoton_booking']) && !empty($pExtra['hotel_id']) && empty($pExtra['city'])) {
            $hotel_ids[PriceInfoFormatter::toScalar($pExtra['hotel_id'])] = true;
        }
    }
    $hotels_cache = [];
    if (!empty($hotel_ids)) {
        $hotelRepo = Container::getInstance()->hotelRepository();
        $hotels_cache = $hotelRepo->getLocationsByIds(array_keys($hotel_ids));
    }

    foreach ($order['products'] as &$product) {
        if (!is_array($product)) {
            continue;
        }
        /** @var array<string, mixed> $product */
        /** @var array<string, mixed> $extra */
        $extra = is_array($product['extra'] ?? null) ? $product['extra'] : [];
        if ($debugMode && defined('AREA') && AREA === 'A') {
            fn_set_notification('N', 'DEBUG', 'Product extra keys: ' . implode(', ', array_keys($extra)));
        }

        if (empty($extra['novoton_booking'])) {
            continue;
        }

        $hotel_id    = PriceInfoFormatter::toScalar($extra['hotel_id']  ?? '');
        $check_in    = PriceInfoFormatter::toScalar($extra['check_in']  ?? '');
        $check_out   = PriceInfoFormatter::toScalar($extra['check_out'] ?? '');
        $total_price = PriceInfoFormatter::toFloat($extra['total_price'] ?? $product['price'] ?? 0);

        // [1] Hotel location
        if (!empty($hotel_id) && empty($extra['city']) && isset($hotels_cache[$hotel_id])) {
            $loc = is_array($hotels_cache[$hotel_id]) ? $hotels_cache[$hotel_id] : [];
            $extra['city']    = PriceInfoFormatter::toScalar($loc['city']    ?? '');
            $extra['region']  = PriceInfoFormatter::toScalar($loc['region']  ?? '');
            $extra['country'] = PriceInfoFormatter::toScalar($loc['country'] ?? '');
        }

        // [2] Formatted dates
        $ci_ts = !empty($check_in)  ? strtotime($check_in)  : false;
        $co_ts = !empty($check_out) ? strtotime($check_out) : false;
        if ($ci_ts !== false) {
            $extra['check_in_formatted']  = fn_date_format($ci_ts, $date_format);
            // Short dotted format pre-computed in PHP. Templates must NOT call the
            // Smarty |date_format modifier inside the order-details {capture} block:
            // it throws under Smarty 5 / PHP 8.3 and leaves the capture unclosed,
            // surfacing as "Not matching {capture}{/capture}". fn_date_format is the
            // blessed safe path (same one used for *_formatted above).
            $extra['check_in_short']      = fn_date_format($ci_ts, '%d.%m.%Y');
        }
        if ($co_ts !== false) {
            $extra['check_out_formatted'] = fn_date_format($co_ts, $date_format);
            $extra['check_out_short']     = fn_date_format($co_ts, '%d.%m.%Y');
        }

        // [4] Board display name (single-room)
        $board_id = PriceInfoFormatter::toScalar($extra['board_id'] ?? $extra['board'] ?? '');
        if (!empty($board_id)) {
            $extra['board_display']        = fn_novoton_holidays_format_board_name($board_id);
            $extra['board_name_formatted'] = $extra['board_display'];
        }

        // [4b] Room display name (single-room) — pre-formatted so templates need no custom modifiers
        $room_id_raw = PriceInfoFormatter::toScalar($extra['room_id'] ?? '');
        $room_type   = PriceInfoFormatter::toScalar($extra['room_type'] ?? $extra['room_type_code'] ?? '');
        if (!empty($room_id_raw)) {
            $extra['room_name_formatted'] = fn_novoton_holidays_format_room_type($room_id_raw, $room_type);
        }

        // Pre-decode JSON strings so templates can use rooms_data/guests_data as arrays
        // without calling |json_decode modifier (which throws inside Smarty {capture} blocks)
        $rooms_data_raw = $extra['rooms_data'] ?? null;
        if (is_string($rooms_data_raw) && $rooms_data_raw !== '') {
            $decoded = json_decode($rooms_data_raw, true);
            $extra['rooms_data'] = is_array($decoded) ? $decoded : [];
        } elseif (!is_array($rooms_data_raw)) {
            $extra['rooms_data'] = [];
        }

        // Pre-format room/board labels per room so templates use only safe core modifiers
        /** @var list<mixed> $nvt_rooms */
        $nvt_rooms = is_array($extra['rooms_data'] ?? null) ? array_values($extra['rooms_data']) : [];
        foreach ($nvt_rooms as $idx => $nvt_room) {
            if (!is_array($nvt_room)) {
                continue;
            }
            $r_id   = PriceInfoFormatter::toScalar($nvt_room['room_id'] ?? '');
            $r_type = PriceInfoFormatter::toScalar($nvt_room['room_type'] ?? $nvt_room['room_type_code'] ?? '');
            $r_bid  = PriceInfoFormatter::toScalar($nvt_room['board_id'] ?? '');
            if (!empty($r_id)) {
                $nvt_room['room_name_formatted'] = fn_novoton_holidays_format_room_type($r_id, $r_type);
            }
            if (!empty($r_bid)) {
                $nvt_room['board_name_formatted'] = fn_novoton_holidays_format_board_name($r_bid);
            }
            $nvt_rooms[$idx] = $nvt_room;
        }
        $extra['rooms_data'] = $nvt_rooms;
        $guests_data_raw = $extra['guests_data'] ?? null;
        if (is_string($guests_data_raw) && $guests_data_raw !== '') {
            $decoded = json_decode($guests_data_raw, true);
            $extra['guests_data'] = is_array($decoded) ? $decoded : [];
        }

        // Look up the unified travel_booking_id so templates can link directly to travel_bookings.view
        $nvt_id = PriceInfoFormatter::toInt($extra['novoton_booking_id'] ?? 0);
        if ($nvt_id > 0 && empty($extra['travel_booking_id'])) {
            $tb_id = (int) db_get_field(
                "SELECT booking_id FROM ?:travel_bookings WHERE provider = 'novoton' AND provider_booking_id = ?s LIMIT 1",
                (string) $nvt_id
            );
            if ($tb_id > 0) {
                $extra['travel_booking_id'] = $tb_id;
            }
        }

        // Write back local extra mutations before delegating to helpers
        // (helpers also mutate $product['extra'] but via their own narrowed vars).
        $product['extra'] = $extra;

        // [3] Payment & cancellation terms
        _nvt_enrich_order_product_terms($product, $hotel_id, $check_in, $check_out, $total_price, $currency_code);

        // [5] Guests data formatting
        _nvt_format_order_guests($product);

        if ($debugMode && defined('AREA') && AREA === 'A') {
            $payment_set    = !empty($product['extra']['terms_of_payment_formatted'])      ? 'YES' : 'NO';
            $payment_amounts = !empty($product['extra']['terms_of_payment_with_amounts'])   ? 'YES' : 'NO';
            $cancel_set     = !empty($product['extra']['terms_of_cancellation_formatted']) ? 'YES' : 'NO';
            fn_set_notification('N', 'DEBUG', "terms_of_payment_formatted: {$payment_set}, with_amounts: {$payment_amounts}, cancellation: {$cancel_set}");
        }
    }
    unset($product);

    // ── Admin panel notification for failed bookings ──
    // When an admin views an order with failed Novoton bookings, show a warning
    // notification banner so they know immediate attention is required.
    if (defined('AREA') && AREA === 'A' && !empty($order['order_id'])) {
        $repo = Container::getInstance()->bookingRepository();
        $bookings = $repo->findByOrderId(PriceInfoFormatter::toInt($order['order_id']));

        foreach ($bookings as $booking) {
            if (!is_array($booking)) {
                continue;
            }
            if (PriceInfoFormatter::toScalar($booking['status'] ?? '') === TravelConstants::STATUS_FAILED) {
                $hotelName = PriceInfoFormatter::toScalar($booking['hotel_name'] ?? '');
                $dispOrderId = PriceInfoFormatter::toScalar($order['order_id'] ?? '');
                fn_set_notification('W', __('warning'),
                    __('novoton_holidays.booking_api_failed', [
                        '[hotel]' => $hotelName,
                        '[order_id]' => $dispOrderId,
                        '[default]' => 'Novoton booking failed for hotel "' . $hotelName . '" in order #' . $order['order_id'] . '. The API submission did not succeed. Please check and resubmit manually.',
                    ])
                );
                break; // One notification per order is enough
            }
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
 *
 * @param array<string, mixed> $product
 */
function _nvt_enrich_order_product_terms(
    array &$product,
    string $hotel_id,
    string $check_in,
    string $check_out,
    float  $total_price,
    string $currency_code
): void {
    /** @var array<string, mixed> $pExtra */
    $pExtra = is_array($product['extra'] ?? null) ? $product['extra'] : [];
    $payment_raw  = PriceInfoFormatter::toScalar($pExtra['terms_of_payment_raw']      ?? '');
    $payment_text = PriceInfoFormatter::toScalar($pExtra['terms_of_payment']          ?? '');
    $cancel_raw   = PriceInfoFormatter::toScalar($pExtra['terms_of_cancellation_raw'] ?? '');
    $cancel_text  = PriceInfoFormatter::toScalar($pExtra['terms_of_cancellation']     ?? '');

    // Fallback: fetch from novoton_bookings DB record (terms are persisted at booking creation)
    if (empty($payment_raw) && empty($payment_text) && empty($cancel_raw) && empty($cancel_text)) {
        $booking_id = PriceInfoFormatter::toInt($pExtra['novoton_booking_id'] ?? 0);
        if ($booking_id > 0) {
            $repo = Container::getInstance()->bookingRepository();
            $terms = $repo->getTerms($booking_id);
            if (!empty($terms) && is_array($terms)) {
                $payment_raw  = PriceInfoFormatter::toScalar($terms['terms_of_payment_raw'] ?? '');
                $cancel_raw   = PriceInfoFormatter::toScalar($terms['terms_of_cancellation_raw'] ?? '');
                $payment_text = PriceInfoFormatter::toScalar($terms['terms_of_payment_formatted'] ?? '');
                $cancel_text  = PriceInfoFormatter::toScalar($terms['terms_of_cancellation_formatted'] ?? '');
            }
        }
    }

    // Format payment terms
    if (!empty($payment_raw) && $total_price > 0) {
        $pExtra['terms_of_payment_with_amounts'] = fn_novoton_holidays_format_payment_terms_with_amounts(
            $payment_raw, $total_price, $currency_code
        );
        $pExtra['terms_of_payment_formatted'] = fn_novoton_holidays_format_payment_terms($payment_raw);
    } elseif (!empty($payment_raw)) {
        $pExtra['terms_of_payment_formatted'] = fn_novoton_holidays_format_payment_terms($payment_raw);
    } elseif (!empty($payment_text)) {
        $pExtra['terms_of_payment_formatted'] = $payment_text;
    }

    // Format cancellation terms
    if (!empty($cancel_raw)) {
        $pExtra['terms_of_cancellation_formatted'] = fn_novoton_holidays_format_cancellation_terms($cancel_raw, $check_in);
    } elseif (!empty($cancel_text)) {
        $pExtra['terms_of_cancellation_formatted'] = $cancel_text;
    }

    $product['extra'] = $pExtra;
}

/**
 * Format guests_data on an order product for email/display.
 *
 * Delegates to GuestDataService::formatGuestsForOrderDisplay() which handles
 * api_name → display format conversion and holder marking.
 *
 * @param array<string, mixed> $product
 */
function _nvt_format_order_guests(array &$product): void
{
    /** @var array<string, mixed> $fExtra */
    $fExtra = is_array($product['extra'] ?? null) ? $product['extra'] : [];
    $guests_data = $fExtra['guests_data'] ?? null;
    if (empty($guests_data)) {
        return;
    }

    if (!class_exists(\Tygh\Addons\TravelCore\Services\GuestDataService::class)) {
        return;
    }

    $holder_name = PriceInfoFormatter::toScalar($fExtra['holder_name'] ?? '');
    $formatted = \Tygh\Addons\TravelCore\Services\GuestDataService::formatGuestsForOrderDisplay($guests_data, $holder_name);
    if (!empty($formatted)) {
        $fExtra['guests_data'] = $formatted;
        $product['extra'] = $fExtra;
    }
}

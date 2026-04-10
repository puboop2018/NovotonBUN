<?php
declare(strict_types=1);
/**
 * Sphinx Booking Controller — Add to Cart Mode (Hotels)
 *
 * Verifies the offer, creates records in sphinx_bookings and travel_bookings,
 * then adds the product to the CS-Cart cart.
 *
 * @package SphinxHolidays
 * @since   1.0.0
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Addons\SphinxHolidays\Services\CartService;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\Container;

    $cartService = new CartService();

    // Rate limit + input
    if ($rateLimited = $cartService->checkRateLimit()) {
        return $rateLimited;
    }

    $bookingData = $_REQUEST;
    $offer_id = trim($bookingData['offer_id'] ?? '');
    $hotel_id = trim($bookingData['hotel_id'] ?? '');
    $product_id = (int) ($bookingData['product_id'] ?? 0);

    if (empty($offer_id)) {
        fn_set_notification('E', __('error'),
            __('sphinx_holidays.invalid_offer', ['[default]' => 'Invalid offer.']));
        return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
    }

    // Verify offer price with Sphinx API
    try {
        $verifyResult = Container::getApi()->verifyHotelOffer($offer_id);
    } catch (\Throwable $e) {
        fn_log_event('general', 'runtime', ['message' => 'Sphinx add_to_cart verify failed: ' . $e->getMessage()]);
        $verifyResult = null;
    }

    if (empty($verifyResult) || !($verifyResult['available'] ?? false)) {
        fn_set_notification('E', __('error'),
            __('sphinx_holidays.offer_no_longer_available', ['[default]' => 'This offer is no longer available.']));
        return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
    }

    // Price with commission
    $basePrice = (float) ($verifyResult['price'] ?? 0);
    $total_price = $cartService->applyCommission($basePrice);

    if ($total_price <= 0) {
        fn_set_notification('E', __('error'),
            __('sphinx_holidays.price_unavailable', ['[default]' => 'Price not available.']));
        return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
    }

    // Resolve product
    $product_id = $cartService->resolveProductId($hotel_id, $product_id);
    if (empty($product_id)) {
        fn_set_notification('E', __('error'),
            __('sphinx_holidays.product_not_found', ['[default]' => 'Hotel product not found.']));
        return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
    }

    // Guest validation
    $check_in = $verifyResult['check_in'] ?? $bookingData['check_in'] ?? '';
    $parsed_guests = $cartService->parseGuests($bookingData['guests'] ?? [], $check_in);
    if ($parsed_guests === false) {
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.booking_form?' . http_build_query([
            'offer_id' => $offer_id, 'hotel_id' => $hotel_id, 'product_id' => $product_id,
        ])];
    }

    // Extract offer details
    $contact     = $bookingData['contact'] ?? [];
    $hotelName   = $verifyResult['hotel_name'] ?? '';
    $roomName    = $verifyResult['room_name'] ?? $verifyResult['room_type'] ?? '';
    $boardName   = $verifyResult['board_name'] ?? $verifyResult['board_type'] ?? '';
    $boardId     = $verifyResult['board_code'] ?? $boardName;
    $roomId      = $verifyResult['room_code'] ?? $roomName;
    $check_out   = $verifyResult['check_out'] ?? $bookingData['check_out'] ?? '';
    $adults      = (int) ($verifyResult['adults'] ?? $bookingData['adults'] ?? 2);
    $children    = (int) ($verifyResult['children'] ?? $bookingData['children'] ?? 0);
    $currency    = ConfigProvider::getDefaultCurrency();

    $nights = 0;
    if (!empty($check_in) && !empty($check_out)) {
        $ci_ts = strtotime($check_in);
        $co_ts = strtotime($check_out);
        if ($ci_ts !== false && $co_ts !== false) {
            $nights = (int) round(($co_ts - $ci_ts) / 86400);
        }
    }

    $all_child_ages = [];
    foreach ($parsed_guests['guests_data'] as $guest) {
        if (($guest['type'] ?? '') === 'child' && isset($guest['age'])) {
            $all_child_ages[] = (int) $guest['age'];
        }
    }
    $children_ages = !empty($all_child_ages) ? implode(',', $all_child_ages) : ($bookingData['children_ages'] ?? '');

    $rooms_data = [[
        'room_id' => $roomId, 'room_name' => $roomName, 'room_type_display' => $roomName,
        'board_id' => $boardId, 'board_name' => $boardName,
        'adults' => $adults, 'children' => $children, 'childrenAges' => $all_child_ages,
        'price' => $total_price,
    ]];

    // Build + persist booking record
    $booking_record = $cartService->buildBaseBookingRecord(
        $product_id, $hotel_id, $offer_id, $hotelName,
        $parsed_guests, $contact, $basePrice, $total_price, $currency, $verifyResult
    );
    $booking_record += [
        'room_id'       => $roomId,
        'room_type'     => $roomName,
        'board_id'      => $boardId,
        'check_in'      => $check_in,
        'check_out'     => $check_out,
        'nights'        => $nights,
        'adults'        => $adults,
        'children'      => $children,
        'children_ages' => $children_ages,
        'num_rooms'     => 1,
        'rooms_data'    => json_encode($rooms_data, JSON_UNESCAPED_UNICODE),
    ];

    $booking_id = $cartService->upsertBooking(
        $booking_record, $hotel_id, $check_in, $check_out, $parsed_guests['holder_name']
    );

    // Assemble cart extras
    $product_extra = [
        'travel_booking' => true, 'sphinx_booking' => true,
        'travel_booking_id' => $booking_id, 'travel_provider' => 'sphinx',
        'hotel_id' => $hotel_id, 'hotel_name' => $hotelName, 'offer_id' => $offer_id,
        'room_id' => $roomId, 'room_name' => $roomName, 'room_type_display' => $roomName,
        'board_id' => $boardId, 'board_name' => $boardName,
        'check_in' => $check_in, 'check_out' => $check_out, 'nights' => $nights,
        'adults' => $adults, 'children' => $children, 'children_ages' => $children_ages,
        'num_rooms' => 1, 'rooms_data' => $rooms_data,
        'guest_names' => $parsed_guests['guest_list'], 'holder_name' => $parsed_guests['holder_name'],
        'guests_data' => json_encode($parsed_guests['guests_data'], JSON_UNESCAPED_UNICODE),
        'contact_email' => $contact['email'] ?? '', 'contact_phone' => $contact['phone'] ?? '',
        'total_price' => $total_price, 'currency' => $currency,
    ];

    return $cartService->addToCartAndRedirect(
        $product_id, $total_price, $currency, $product_extra,
        __('sphinx_holidays.added_to_cart', ['[default]' => 'Hotel booking added to cart.'])
    );

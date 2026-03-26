<?php
declare(strict_types=1);
/**
 * Sphinx Booking Controller — Add to Cart Mode
 *
 * Verifies the offer, creates records in sphinx_bookings and travel_bookings,
 * then adds the product to the CS-Cart cart.
 *
 * @package SphinxHolidays
 * @since   1.0.0
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Tygh;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\TravelCore\Services\CommissionCalculator;
use Tygh\Addons\TravelCore\Services\CurrencyService;
use Tygh\Addons\TravelCore\TravelConstants;

    $bookingData = $_REQUEST;
    $offer_id = trim($bookingData['offer_id'] ?? '');
    $hotel_id = trim($bookingData['hotel_id'] ?? '');
    $product_id = (int)($bookingData['product_id'] ?? 0);

    if (empty($offer_id)) {
        fn_set_notification('E', __('error'), __('sphinx_holidays.invalid_offer', ['[default]' => 'Invalid offer.']));
        return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
    }

    // Verify offer price
    try {
        $api = Container::getApi();
        $verifyResult = $api->verifyHotelOffer($offer_id);
    } catch (\Throwable $e) {
        fn_log_event('general', 'runtime', ['message' => 'Sphinx add_to_cart verify failed: ' . $e->getMessage()]);
        $verifyResult = null;
    }

    if (empty($verifyResult) || !($verifyResult['available'] ?? false)) {
        fn_set_notification('E', __('error'),
            __('sphinx_holidays.offer_no_longer_available', ['[default]' => 'This offer is no longer available.']));
        return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
    }

    // Calculate final price with commission
    $commission = ConfigProvider::getCommission();
    $roundPrices = ConfigProvider::shouldRoundPrices();
    $apiPrice = (float)($verifyResult['price'] ?? 0);
    $basePrice = $apiPrice;

    if ($commission > 0 && $apiPrice > 0) {
        $calculator = new CommissionCalculator($commission, $roundPrices);
        $apiPrice = $calculator->apply($apiPrice);
    }

    $total_price = $apiPrice;
    if ($total_price <= 0) {
        fn_set_notification('E', __('error'), __('sphinx_holidays.price_unavailable', ['[default]' => 'Price not available.']));
        return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
    }

    // Resolve product_id
    if (empty($product_id) && !empty($hotel_id)) {
        $product_id = (int)db_get_field("SELECT product_id FROM ?:products WHERE product_code = ?s", \Tygh\Addons\SphinxHolidays\Services\ConfigProvider::getProductCodePrefix() . $hotel_id);
    }
    if (empty($product_id)) {
        $product_id = (int)($bookingData['product_id'] ?? 0);
    }
    if (empty($product_id)) {
        fn_set_notification('E', __('error'), __('sphinx_holidays.product_not_found', ['[default]' => 'Hotel product not found.']));
        return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
    }

    // Parse guest data
    $guests = is_array($bookingData['guests'] ?? null) ? $bookingData['guests'] : [];
    $contact = $bookingData['contact'] ?? [];
    $check_in = $verifyResult['check_in'] ?? $bookingData['check_in'] ?? '';
    $parsed_guests = _sphinx_parse_and_validate_guests($guests, $check_in);
    if ($parsed_guests === false) {
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.booking_form?' . http_build_query([
            'offer_id' => $offer_id, 'hotel_id' => $hotel_id, 'product_id' => $product_id,
        ])];
    }

    $guests_data = $parsed_guests['guests_data'] ?? [];
    $guest_list = $parsed_guests['guest_list'] ?? '';
    $holder_name = $parsed_guests['holder_name'] ?? '';

    $all_child_ages = [];
    foreach ($guests_data as $guest) {
        if (isset($guest['type']) && $guest['type'] == 'child' && isset($guest['age'])) {
            $all_child_ages[] = (int)$guest['age'];
        }
    }
    $children_ages = !empty($all_child_ages) ? implode(',', $all_child_ages) : ($bookingData['children_ages'] ?? '');

    // Extract offer details
    $hotelName = $verifyResult['hotel_name'] ?? '';
    $roomName = $verifyResult['room_name'] ?? $verifyResult['room_type'] ?? '';
    $boardName = $verifyResult['board_name'] ?? $verifyResult['board_type'] ?? '';
    $boardId = $verifyResult['board_code'] ?? $boardName;
    $roomId = $verifyResult['room_code'] ?? $roomName;
    $check_out = $verifyResult['check_out'] ?? $bookingData['check_out'] ?? '';
    $adults = (int)($verifyResult['adults'] ?? $bookingData['adults'] ?? 2);
    $children = (int)($verifyResult['children'] ?? $bookingData['children'] ?? 0);
    $nights = 0;
    if (!empty($check_in) && !empty($check_out)) {
        $nights = (int)round((strtotime($check_out) - strtotime($check_in)) / 86400);
    }

    // Create booking records
    $auth = Tygh::$app['session']['auth'] ?? [];
    $user_id = !empty($auth['user_id']) ? (int)$auth['user_id'] : 0;
    $session_id = session_id();
    $currency = ConfigProvider::getDefaultCurrency();

    $rooms_data = [[
        'room_id' => $roomId, 'room_name' => $roomName, 'room_type_display' => $roomName,
        'board_id' => $boardId, 'board_name' => $boardName,
        'adults' => $adults, 'children' => $children, 'childrenAges' => $all_child_ages,
        'price' => $total_price,
    ]];

    $repo = Container::getBookingRepository();
    $existing_booking_id = $repo->findRecentUnassigned($hotel_id, $check_in, $check_out, $holder_name);

    $booking_record = [
        'order_id' => 0, 'user_id' => $user_id, 'session_id' => $session_id,
        'product_id' => $product_id, 'hotel_id' => $hotel_id, 'hotel_name' => $hotelName,
        'offer_id' => $offer_id, 'room_id' => $roomId, 'room_type' => $roomName,
        'board_id' => $boardId, 'check_in' => $check_in, 'check_out' => $check_out,
        'nights' => $nights, 'adults' => $adults, 'children' => $children,
        'children_ages' => $children_ages, 'num_rooms' => 1,
        'rooms_data' => json_encode($rooms_data),
        'guest_name' => $guest_list, 'holder_name' => $holder_name,
        'guest_email' => $contact['email'] ?? '', 'guest_phone' => $contact['phone'] ?? '',
        'guests_data' => json_encode($guests_data),
        'base_price' => $basePrice, 'total_price' => $total_price,
        'currency' => $currency, 'status' => TravelConstants::STATUS_PENDING,
        'api_response' => json_encode($verifyResult),
    ];

    if ($existing_booking_id !== null) {
        $repo->update($existing_booking_id, $booking_record);
        $booking_id = $existing_booking_id;
    } else {
        $booking_record['created_at'] = date('Y-m-d H:i:s');
        $booking_id = $repo->create($booking_record);
    }

    // Add to CS-Cart cart
    $cart = &Tygh::$app['session']['cart'];
    $auth = &Tygh::$app['session']['auth'];
    if (empty($cart)) { fn_clear_cart($cart); }

    $product_extra = [
        'travel_booking' => true, 'sphinx_booking' => true,
        'travel_booking_id' => $booking_id, 'travel_provider' => 'sphinx',
        'hotel_id' => $hotel_id, 'hotel_name' => $hotelName, 'offer_id' => $offer_id,
        'room_id' => $roomId, 'room_name' => $roomName, 'room_type_display' => $roomName,
        'board_id' => $boardId, 'board_name' => $boardName,
        'check_in' => $check_in, 'check_out' => $check_out, 'nights' => $nights,
        'adults' => $adults, 'children' => $children, 'children_ages' => $children_ages,
        'num_rooms' => 1, 'rooms_data' => $rooms_data,
        'guest_names' => $guest_list, 'holder_name' => $holder_name,
        'guests_data' => json_encode($guests_data),
        'contact_email' => $contact['email'] ?? '', 'contact_phone' => $contact['phone'] ?? '',
        'total_price' => $total_price, 'currency' => $currency,
    ];

    $cart_id = fn_generate_cart_id($product_id, $product_extra);

    $primaryCurrency = defined('CART_PRIMARY_CURRENCY') ? CART_PRIMARY_CURRENCY : 'EUR';
    $currencyService = new CurrencyService($currency);
    $cart_price = $currencyService->convertFromApiCurrency($total_price, $primaryCurrency);

    $cart['products'][$cart_id] = [
        'product_id' => $product_id, 'amount' => 1,
        'price' => $cart_price, 'base_price' => $cart_price, 'original_price' => $cart_price,
        'extra' => $product_extra, 'stored_price' => 'Y',
    ];

    fn_calculate_cart_content($cart, $auth, 'S', true, 'F', true);
    fn_save_cart_content($cart, $auth['user_id'] ?? 0);

    fn_set_notification('N', __('notice'), __('sphinx_holidays.added_to_cart', ['[default]' => 'Hotel booking added to cart.']));
    return [CONTROLLER_STATUS_REDIRECT, 'checkout.cart'];

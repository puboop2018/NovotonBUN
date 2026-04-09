<?php
declare(strict_types=1);
/**
 * Sphinx Booking Controller — Package Add to Cart Mode
 *
 * Optionally customizes the package (adds optional services),
 * then creates booking records and adds to CS-Cart cart.
 *
 * Flow: verify → customize (optional) → add to cart → book at order placement
 *
 * @package SphinxHolidays
 * @since   1.2.0
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Tygh;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\TravelCore\Services\CommissionCalculator;
use Tygh\Addons\TravelCore\Services\CurrencyService;
use Tygh\Addons\TravelCore\TravelConstants;

    // --- Security: Rate limiting ---
    $security = Container::getSecurityService();
    $auth = Tygh::$app['session']['auth'] ?? [];
    $rate_limit_id = !empty($auth['user_id']) ? (string)$auth['user_id'] : session_id();
    if (!$security->checkBookingRateLimit($rate_limit_id)) {
        fn_set_notification('E', __('error'), __('sphinx_holidays.rate_limit_exceeded', ['[default]' => 'Too many booking requests. Please try again later.']));
        return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
    }

    $bookingData = $_REQUEST;
    $offer_id = trim($bookingData['offer_id'] ?? '');
    $hotel_id = trim($bookingData['hotel_id'] ?? '');

    if (empty($offer_id)) {
        fn_set_notification('E', __('error'), __('sphinx_holidays.invalid_offer', ['[default]' => 'Invalid offer.']));
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.package_search'];
    }

    // --- Security: Duplicate booking prevention ---
    $repo = Container::getBookingRepository();
    $pendingDuplicate = $repo->findPendingDuplicateByOffer($offer_id, TravelConstants::STATUS_PENDING);
    if ($pendingDuplicate !== null) {
        fn_set_notification('W', __('warning'),
            __('sphinx_holidays.duplicate_booking', ['[default]' => 'A booking for this offer is already pending.']));
        return [CONTROLLER_STATUS_REDIRECT, 'checkout.cart'];
    }

    $api = Container::getApi();

    // Optional: customize package with selected services
    $selected_services = $bookingData['services'] ?? [];
    $customized = null;
    if (!empty($selected_services) && is_array($selected_services)) {
        try {
            $customized = $api->customizePackage([
                'offer_id' => $offer_id,
                'service_codes' => array_values($selected_services),
            ]);
            if (!empty($customized['data'])) {
                $customized = $customized['data'];
            }
        } catch (\Throwable $e) {
            fn_log_event('general', 'runtime', ['message' => 'Sphinx package customize failed: ' . $e->getMessage()]);
            // Continue without customization
        }
    }

    // Use customized pricing if available
    $total_price = (float)($customized['pricing']['selling_price'] ?? $bookingData['total_price'] ?? 0);
    $basePrice = (float)($customized['pricing']['supplier_price'] ?? $bookingData['base_price'] ?? $total_price);
    $priceCurrency = $customized['pricing']['currency'] ?? $bookingData['currency'] ?? ConfigProvider::getDefaultCurrency();

    // Apply commission
    $commission = ConfigProvider::getCommission();
    $roundPrices = ConfigProvider::shouldRoundPrices();
    if ($commission > 0 && $total_price > 0) {
        $calculator = new CommissionCalculator($commission, $roundPrices);
        $total_price = $calculator->apply($total_price);
    }

    if ($total_price <= 0) {
        fn_set_notification('E', __('error'), __('sphinx_holidays.price_unavailable', ['[default]' => 'Price not available.']));
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.package_search'];
    }

    // Resolve product_id — packages use SPX{hotel_id} product code
    $product_id = (int)($bookingData['product_id'] ?? 0);
    if (empty($product_id) && !empty($hotel_id)) {
        $product_id = (int)db_get_field("SELECT product_id FROM ?:products WHERE product_code = ?s", \Tygh\Addons\SphinxHolidays\Services\ConfigProvider::getProductCodePrefix() . $hotel_id);
    }
    if (empty($product_id)) {
        fn_set_notification('E', __('error'), __('sphinx_holidays.product_not_found', ['[default]' => 'Package product not found.']));
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.package_search'];
    }

    // Parse guest data — sanitize
    $guests = is_array($bookingData['guests'] ?? null) ? $security->sanitizeGuestData($bookingData['guests']) : [];
    $contact = $bookingData['contact'] ?? [];
    $check_in = trim($bookingData['check_in'] ?? '');
    $check_out = trim($bookingData['check_out'] ?? '');
    $parsed_guests = \Tygh\Addons\TravelCore\Services\GuestDataService::parseAndValidateGuests($guests, $check_in, 'sphinx');
    if ($parsed_guests === false) {
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.package_booking_form?' . http_build_query([
            'offer_id' => $offer_id,
        ])];
    }

    $guests_data = $parsed_guests['guests_data'] ?? [];
    $guest_list = $parsed_guests['guest_list'] ?? '';
    $holder_name = $parsed_guests['holder_name'] ?? '';

    // Extract details
    $hotelName = trim($bookingData['hotel_name'] ?? '');
    $roomName = trim($bookingData['room_name'] ?? '');
    $boardName = trim($bookingData['board_name'] ?? '');
    $transport_type = trim($bookingData['transport_type'] ?? '');
    $nights = (int)($bookingData['nights'] ?? 0);
    $adults = (int)($bookingData['adults'] ?? 2);
    $children = (int)($bookingData['children'] ?? 0);
    $children_ages = trim($bookingData['children_ages'] ?? '');
    $num_rooms = max(1, (int)($bookingData['num_rooms'] ?? 1));

    if (empty($nights) && !empty($check_in) && !empty($check_out)) {
        $ci_ts = strtotime($check_in);
        $co_ts = strtotime($check_out);
        if ($ci_ts !== false && $co_ts !== false) {
            $nights = (int)round(($co_ts - $ci_ts) / 86400);
        }
    }

    // Parse rooms data from form
    $rooms_data = [];
    if (!empty($bookingData['rooms_json'])) {
        $rooms_data = json_decode($bookingData['rooms_json'], true) ?: [];
    }
    if (empty($rooms_data)) {
        $rooms_data = [['room_id' => '', 'room_name' => $roomName, 'adults' => $adults, 'children' => $children]];
    }

    // Create booking records
    $user_id = !empty($auth['user_id']) ? (int)$auth['user_id'] : 0;
    $session_id = session_id();

    $repo = Container::getBookingRepository();
    $existing_booking_id = $repo->findRecentUnassigned($hotel_id, $check_in, $check_out, $holder_name);

    $booking_record = [
        'order_id' => 0, 'user_id' => $user_id, 'session_id' => $session_id,
        'product_id' => $product_id, 'hotel_id' => $hotel_id, 'hotel_name' => $hotelName,
        'offer_id' => $offer_id, 'room_id' => $rooms_data[0]['room_id'] ?? '', 'room_type' => 'package',
        'board_id' => $boardName, 'check_in' => $check_in, 'check_out' => $check_out,
        'nights' => $nights, 'adults' => $adults, 'children' => $children,
        'children_ages' => $children_ages, 'num_rooms' => $num_rooms,
        'rooms_data' => json_encode($rooms_data, JSON_UNESCAPED_UNICODE),
        'guest_name' => $guest_list, 'holder_name' => $holder_name,
        'guest_email' => $contact['email'] ?? '', 'guest_phone' => $contact['phone'] ?? '',
        'guests_data' => json_encode($guests_data, JSON_UNESCAPED_UNICODE),
        'base_price' => $basePrice, 'total_price' => $total_price,
        'currency' => $priceCurrency, 'status' => TravelConstants::STATUS_PENDING,
        'api_response' => json_encode($customized ?? $bookingData, JSON_UNESCAPED_UNICODE),
    ];

    if ($existing_booking_id !== null) {
        $repo->update($existing_booking_id, $booking_record);
        $booking_id = $existing_booking_id;
    } else {
        $booking_record['created_at'] = date('Y-m-d H:i:s');
        $booking_id = $repo->create($booking_record);
    }

    // Collect selected service codes for the book call
    $service_codes = [];
    if (!empty($selected_services) && is_array($selected_services)) {
        $service_codes = array_values($selected_services);
    }

    // Add to CS-Cart cart
    $cart = &Tygh::$app['session']['cart'];
    $auth = &Tygh::$app['session']['auth'];
    if (empty($cart)) { fn_clear_cart($cart); }

    $product_extra = [
        'travel_booking' => true, 'sphinx_booking' => true,
        'travel_booking_id' => $booking_id, 'travel_provider' => 'sphinx',
        'booking_type' => 'package',
        'hotel_id' => $hotel_id, 'hotel_name' => $hotelName, 'offer_id' => $offer_id,
        'room_id' => $rooms_data[0]['room_id'] ?? '', 'room_name' => $roomName,
        'board_id' => $boardName, 'board_name' => $boardName,
        'check_in' => $check_in, 'check_out' => $check_out, 'nights' => $nights,
        'adults' => $adults, 'children' => $children, 'children_ages' => $children_ages,
        'num_rooms' => $num_rooms, 'rooms_data' => $rooms_data,
        'transport_type' => $transport_type,
        'guest_names' => $guest_list, 'holder_name' => $holder_name,
        'guests_data' => json_encode($guests_data, JSON_UNESCAPED_UNICODE),
        'contact_email' => $contact['email'] ?? '', 'contact_phone' => $contact['phone'] ?? '',
        'total_price' => $total_price, 'currency' => $priceCurrency,
        'additional_services' => $service_codes,
    ];

    $cart_id = fn_generate_cart_id($product_id, $product_extra);

    $primaryCurrency = defined('CART_PRIMARY_CURRENCY') ? CART_PRIMARY_CURRENCY : 'EUR';
    $currencyService = new CurrencyService($priceCurrency);
    $cart_price = $currencyService->convertFromApiCurrency($total_price, $primaryCurrency);

    $cart['products'][$cart_id] = [
        'product_id' => $product_id, 'amount' => 1,
        'price' => $cart_price, 'base_price' => $cart_price, 'original_price' => $cart_price,
        'extra' => $product_extra, 'stored_price' => 'Y',
    ];

    fn_calculate_cart_content($cart, $auth, 'S', true, 'F', true);
    fn_save_cart_content($cart, $auth['user_id'] ?? 0);

    fn_set_notification('N', __('notice'), __('sphinx_holidays.package_added_to_cart', ['[default]' => 'Package booking added to cart.']));
    return [CONTROLLER_STATUS_REDIRECT, 'checkout.cart'];

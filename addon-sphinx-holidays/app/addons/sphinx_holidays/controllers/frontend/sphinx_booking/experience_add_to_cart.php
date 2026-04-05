<?php
declare(strict_types=1);
/**
 * Sphinx Booking Controller — Experience Add to Cart Mode
 *
 * Creates booking records and adds to CS-Cart cart.
 * No customize step for experiences (simpler than circuits).
 *
 * @package SphinxHolidays
 * @since   1.1.0
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
    $experience_id = (int)($bookingData['experience_id'] ?? 0);

    if (empty($offer_id) || empty($experience_id)) {
        fn_set_notification('E', __('error'), __('sphinx_holidays.invalid_offer', ['[default]' => 'Invalid offer.']));
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.experience_search'];
    }

    // --- Security: Duplicate booking prevention ---
    $pendingDuplicate = db_get_field(
        "SELECT booking_id FROM ?:sphinx_bookings WHERE offer_id = ?s AND order_id > 0 AND status = ?s LIMIT 1",
        $offer_id, TravelConstants::STATUS_PENDING
    );
    if ($pendingDuplicate) {
        fn_set_notification('W', __('warning'),
            __('sphinx_holidays.duplicate_booking', ['[default]' => 'A booking for this offer is already pending.']));
        return [CONTROLLER_STATUS_REDIRECT, 'checkout.cart'];
    }

    // Commission
    $total_price = (float)($bookingData['total_price'] ?? 0);
    $basePrice = (float)($bookingData['base_price'] ?? $total_price);
    $currency = trim($bookingData['currency'] ?? ConfigProvider::getDefaultCurrency());

    $commission = ConfigProvider::getCommission();
    $roundPrices = ConfigProvider::shouldRoundPrices();
    if ($commission > 0 && $total_price > 0) {
        $calculator = new CommissionCalculator($commission, $roundPrices);
        $total_price = $calculator->apply($total_price);
    }

    if ($total_price <= 0) {
        fn_set_notification('E', __('error'), __('sphinx_holidays.price_unavailable', ['[default]' => 'Price not available.']));
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.experience_search'];
    }

    // Resolve product_id — experiences use SPX{experience_id} product code
    $product_id = (int)($bookingData['product_id'] ?? 0);
    if (empty($product_id)) {
        $product_id = (int)db_get_field("SELECT product_id FROM ?:products WHERE product_code = ?s", \Tygh\Addons\SphinxHolidays\Services\ConfigProvider::getProductCodePrefix() . $experience_id);
    }
    if (empty($product_id)) {
        fn_set_notification('E', __('error'), __('sphinx_holidays.product_not_found', ['[default]' => 'Experience product not found.']));
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.experience_search'];
    }

    // Parse guest data — sanitize
    $guests = is_array($bookingData['guests'] ?? null) ? $security->sanitizeGuestData($bookingData['guests']) : [];
    $contact = $bookingData['contact'] ?? [];
    $departure_date = trim($bookingData['departure_date'] ?? '');
    $parsed_guests = _sphinx_parse_and_validate_guests($guests, $departure_date);
    if ($parsed_guests === false) {
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.experience_booking_form?' . http_build_query([
            'experience_id' => $experience_id, 'departure_date' => $departure_date,
        ])];
    }

    $guests_data = $parsed_guests['guests_data'] ?? [];
    $guest_list = $parsed_guests['guest_list'] ?? '';
    $holder_name = $parsed_guests['holder_name'] ?? '';

    // Extract details
    $title = trim($bookingData['title'] ?? '');
    $duration_days = (int)($bookingData['duration_days'] ?? 0);
    $adults = (int)($bookingData['adults'] ?? 1);
    $children = (int)($bookingData['children'] ?? 0);
    $children_ages = trim($bookingData['children_ages'] ?? '');

    // Create booking records
    $user_id = !empty($auth['user_id']) ? (int)$auth['user_id'] : 0;
    $session_id = session_id();

    $repo = Container::getBookingRepository();
    $existing_booking_id = $repo->findRecentUnassigned((string)$experience_id, $departure_date, '', $holder_name);

    $booking_record = [
        'order_id' => 0, 'user_id' => $user_id, 'session_id' => $session_id,
        'product_id' => $product_id, 'hotel_id' => (string)$experience_id, 'hotel_name' => $title,
        'offer_id' => $offer_id, 'room_id' => '', 'room_type' => 'experience',
        'board_id' => '', 'check_in' => $departure_date,
        'check_out' => !empty($departure_date) && $duration_days > 0
            ? date('Y-m-d', strtotime($departure_date . " + {$duration_days} days")) : $departure_date,
        'nights' => max(0, $duration_days - 1), 'adults' => $adults, 'children' => $children,
        'children_ages' => $children_ages, 'num_rooms' => 1,
        'rooms_data' => json_encode([]),
        'guest_name' => $guest_list, 'holder_name' => $holder_name,
        'guest_email' => $contact['email'] ?? '', 'guest_phone' => $contact['phone'] ?? '',
        'guests_data' => json_encode($guests_data, JSON_UNESCAPED_UNICODE),
        'base_price' => $basePrice, 'total_price' => $total_price,
        'currency' => $currency, 'status' => TravelConstants::STATUS_PENDING,
        'api_response' => json_encode($bookingData, JSON_UNESCAPED_UNICODE),
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
        'booking_type' => 'experience',
        'hotel_id' => (string)$experience_id, 'hotel_name' => $title, 'offer_id' => $offer_id,
        'check_in' => $departure_date, 'check_out' => $booking_record['check_out'],
        'nights' => $booking_record['nights'],
        'adults' => $adults, 'children' => $children, 'children_ages' => $children_ages,
        'guest_names' => $guest_list, 'holder_name' => $holder_name,
        'guests_data' => json_encode($guests_data, JSON_UNESCAPED_UNICODE),
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

    fn_set_notification('N', __('notice'), __('sphinx_holidays.experience_added_to_cart', ['[default]' => 'Experience booking added to cart.']));
    return [CONTROLLER_STATUS_REDIRECT, 'checkout.cart'];

<?php
declare(strict_types=1);
/**
 * Novoton Booking Controller — Edit Booking Mode
 * Extracted from novoton_booking.php for maintainability.
 * Included by the main controller when $mode == "edit_booking".
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Registry;
use Tygh\Tygh;
use Tygh\Addons\TravelCore\Services\GuestDataNormalizer;
use Tygh\Addons\TravelCore\Services\CurrencyService;
use Tygh\Addons\NovotonHolidays\Helpers\JsonDecoder;

    $booking_id = (int)($_REQUEST['booking_id'] ?? 0);
    $cart_id = $_REQUEST['cart_id'] ?? '';
    
    if (empty($booking_id)) {
        fn_set_notification('E', __('error'), __('novoton_holidays.invalid_booking_data'));
        return [CONTROLLER_STATUS_REDIRECT, 'checkout.cart'];
    }
    
    // Get booking record — verify ownership (user_id or session_id)
    $auth = Tygh::$app['session']['auth'] ?? [];
    $current_user_id = !empty($auth['user_id']) ? (int)($auth['user_id']) : 0;
    $current_session_id = Tygh::$app['session']->getID();

    $booking_record = db_get_row(
        "SELECT * FROM ?:novoton_bookings WHERE booking_id = ?i AND (user_id = ?i OR session_id = ?s)",
        $booking_id, $current_user_id, $current_session_id
    );

    if (empty($booking_record)) {
        fn_set_notification('E', __('error'), __('novoton_holidays.invalid_booking_data'));
        return [CONTROLLER_STATUS_REDIRECT, 'checkout.cart'];
    }
    
    // Also try to get data from cart session (more up-to-date)
    $cart = &Tygh::$app['session']['cart'];
    $cart_item = null;
    if (!empty($cart_id) && !empty($cart['products'][$cart_id])) {
        $cart_item = $cart['products'][$cart_id];
    }
    
    // Get hotel info
    $hotel_info = db_get_row(
        "SELECT * FROM ?:novoton_hotels WHERE hotel_id = ?s",
        $booking_record['hotel_id']
    );
    
    // Get hotel name - try multiple sources
    $hotel_name = $hotel_info['hotel_name'] ?? '';
    if (empty($hotel_name)) {
        // Try from booking record
        $hotel_name = $booking_record['hotel_name'] ?? '';
    }
    if (empty($hotel_name) && !empty($booking_record['product_id'])) {
        // Try from product name
        $hotel_name = db_get_field("SELECT product FROM ?:product_descriptions WHERE product_id = ?i AND lang_code = ?s", 
            $booking_record['product_id'], CART_LANGUAGE);
    }
    if (empty($hotel_name)) {
        $hotel_name = 'Hotel #' . $booking_record['hotel_id'];
    }
    
    // Build booking data for form - prefer cart data over database
    $rooms_data = [];
    $guests_data = [];
    
    // Try cart first, then database
    if ($cart_item && !empty($cart_item['extra']['rooms_data'])) {
        $rooms_data = JsonDecoder::decode($cart_item['extra']['rooms_data'], 'edit_booking:cart_rooms_data');
    }
    if (empty($rooms_data)) {
        $rooms_data = JsonDecoder::decode($booking_record['rooms_data'] ?? '', 'edit_booking:db_rooms_data');
    }
    
    if ($cart_item && !empty($cart_item['extra']['guests_data'])) {
        $guests_data = (new GuestDataNormalizer())->normalize($cart_item['extra']['guests_data']);
    }
    if (empty($guests_data)) {
        $guests_data = (new GuestDataNormalizer())->normalize($booking_record['guests_data'] ?? '');
    }
    
    // Ensure dob field is in DD/MM/YYYY format for each guest (template expects this format)
    foreach ($guests_data as $key => &$guest) {
        if (empty($guest['dob']) && !empty($guest['birthday'])) {
            // Convert YYYY-MM-DD to DD/MM/YYYY
            $ts = strtotime($guest['birthday']);
            if ($ts) {
                $guest['dob'] = date('d/m/Y', $ts);
            }
        }
    }
    unset($guest);
    
    $booking = [
        'hotel_id' => $booking_record['hotel_id'],
        'room_id' => $booking_record['room_id'],
        'board_id' => $booking_record['board_id'],
        'check_in' => $booking_record['check_in'],
        'check_out' => $booking_record['check_out'],
        'nights' => $booking_record['nights'],
        'adults' => $booking_record['adults'],
        'children' => $booking_record['children'],
        'children_ages' => $booking_record['children_ages'],
        'total_price' => $booking_record['total_price'],
        'package_name' => $booking_record['package_name'],
        'num_rooms' => $booking_record['num_rooms'] ?: 1,
        'rooms_data' => $rooms_data,
        'guests_data' => $guests_data,
    ];
    
    // Ensure rooms_data is not empty for single room bookings
    if (empty($booking['rooms_data'])) {
        // Parse children ages
        $children_ages_arr = [];
        if (!empty($booking['children_ages'])) {
            $children_ages_arr = array_map('intval', array_filter(explode(',', $booking['children_ages']), function($v) { return $v !== ''; }));
        }
        
        // Create default rooms_data
        $booking['rooms_data'] = [
            [
                'room_id' => $booking_record['room_id'],
                'room_name' => fn_novoton_holidays_format_room_type($booking_record['room_id']),
                'room_type_display' => fn_novoton_holidays_format_room_type($booking_record['room_id']),
                'board_id' => $booking_record['board_id'],
                'board_name' => fn_novoton_holidays_format_board_name($booking_record['board_id']),
                'adults' => (int)($booking_record['adults']),
                'children' => (int)($booking_record['children']),
                'childrenAges' => $children_ages_arr,
                'price' => (float)($booking_record['total_price'])
            ]
        ];
    }
    
    // Parse children ages
    $children_ages_array = [];
    if (!empty($booking['children_ages'])) {
        $children_ages_array = array_map('intval', array_filter(explode(',', $booking['children_ages']), function($v) { return $v !== ''; }));
    }
    $booking['children_ages_array'] = $children_ages_array;
    
    // Get package name
    $package_name = $booking_record['package_name'];
    if (empty($package_name) && !empty($booking_record['hotel_id'])) {
        // V3: Get first package from novoton_hotel_packages table
        $first_pkg = db_get_field(
            "SELECT package_name FROM ?:novoton_hotel_packages WHERE hotel_id = ?s ORDER BY package_name LIMIT 1",
            $booking_record['hotel_id']
        );
        if (!empty($first_pkg)) {
            $package_name = $first_pkg;
        }
    }
    
    // Get hotel stars
    $hotel_stars = '';
    if (!empty($hotel_info['star_rating'])) {
        $hotel_stars = str_repeat('★', (int)($hotel_info['star_rating']));
    }
    
    // V3: Get all packages from novoton_hotel_packages table
    $all_packages = [];
    $db_packages = db_get_array(
        "SELECT package_id, package_name FROM ?:novoton_hotel_packages WHERE hotel_id = ?s ORDER BY package_name",
        $booking_record['hotel_id']
    );
    if (!empty($db_packages)) {
        foreach ($db_packages as $pkg) {
            $all_packages[] = [
                'IdCont' => $pkg['package_id'],
                'PackageName' => $pkg['package_name']
            ];
        }
    }
    
    // Assign to view
    Tygh::$app['view']->assign('booking_data', $booking);
    $novoton_display_currency = CurrencyService::getDisplayCurrency();
    $currencies = \Tygh\Registry::get('currencies');
    $novoton_display_coefficient = (float) ($currencies[$novoton_display_currency]['coefficient'] ?? 1.0);
    $novoton_display_symbol = $currencies[$novoton_display_currency]['symbol'] ?? $novoton_display_currency;

    Tygh::$app['view']->assign('novoton_display_currency', $novoton_display_currency);
    Tygh::$app['view']->assign('novoton_display_coefficient', $novoton_display_coefficient);
    Tygh::$app['view']->assign('novoton_display_symbol', $novoton_display_symbol);
    Tygh::$app['view']->assign('booking_id', $booking_id);
    Tygh::$app['view']->assign('cart_id', $cart_id);
    Tygh::$app['view']->assign('is_edit_mode', true);
    Tygh::$app['view']->assign('product_id', $booking_record['product_id']);
    Tygh::$app['view']->assign('hotel_name', $hotel_name);
    Tygh::$app['view']->assign('hotel_city', $hotel_info['city'] ?? $booking_record['hotel_city'] ?? '');
    Tygh::$app['view']->assign('hotel_region', $hotel_info['region'] ?? '');
    Tygh::$app['view']->assign('hotel_country', $hotel_info['country'] ?? $booking_record['hotel_country'] ?? 'BULGARIA');
    Tygh::$app['view']->assign('hotel_stars', $hotel_stars);
    Tygh::$app['view']->assign('package_name', $package_name);
    Tygh::$app['view']->assign('hotel_all_packages', $all_packages);
    Tygh::$app['view']->assign('auth', Tygh::$app['session']['auth'] ?? []);
    
    // Page setup
    $page_title = __('novoton_holidays.edit_booking');
    Tygh::$app['view']->assign('page_title', $page_title);
    Registry::set('navigation.dynamic.page_title', $page_title);
    fn_add_breadcrumb($page_title);
    
    // Use booking_form template for edit mode
    // Template will be rendered automatically by CS-Cart

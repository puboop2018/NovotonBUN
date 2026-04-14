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
use Tygh\Addons\NovotonHolidays\Services\PriceInfoFormatter;
use Tygh\Addons\TravelCore\Services\GuestDataNormalizer;
use Tygh\Addons\TravelCore\Services\CurrencyService;
use Tygh\Addons\NovotonHolidays\Helpers\JsonDecoder;

    $booking_id = PriceInfoFormatter::toInt($_REQUEST['booking_id'] ?? 0);
    $cart_id = PriceInfoFormatter::toScalar($_REQUEST['cart_id'] ?? '');

    if (empty($booking_id)) {
        fn_set_notification('E', __('error'), __('novoton_holidays.invalid_booking_data'));
        return [CONTROLLER_STATUS_REDIRECT, 'checkout.cart'];
    }

    // Get booking record — verify ownership (user_id or session_id)
    /** @var array<string, mixed> $auth */
    $auth = is_array(Tygh::$app['session']['auth'] ?? null) ? Tygh::$app['session']['auth'] : [];
    $current_user_id = PriceInfoFormatter::toInt($auth['user_id'] ?? 0);
    $current_session_id = Tygh::$app['session']->getID();

    $bookingRepo = _nvt_booking_repo();
    /** @var array<string, mixed>|null $booking_record */
    $booking_record = $bookingRepo->findByIdWithOwnership($booking_id, $current_user_id, $current_session_id);

    if (empty($booking_record) || !is_array($booking_record)) {
        fn_set_notification('E', __('error'), __('novoton_holidays.invalid_booking_data'));
        return [CONTROLLER_STATUS_REDIRECT, 'checkout.cart'];
    }

    // Also try to get data from cart session (more up-to-date)
    $cart = &Tygh::$app['session']['cart'];
    /** @var array<string, mixed>|null $cart_item */
    $cart_item = null;
    if (!empty($cart_id) && is_array($cart) && !empty($cart['products'][$cart_id]) && is_array($cart['products'][$cart_id])) {
        $cart_item = $cart['products'][$cart_id];
    }

    // Get hotel info
    $hotelRepo = _nvt_hotel_repo();
    $brHotelId = PriceInfoFormatter::toScalar($booking_record['hotel_id'] ?? '');
    /** @var array<string, mixed>|null $hotel_info */
    $hotel_info = $hotelRepo->findById($brHotelId);

    // Get hotel name - try multiple sources
    $hotel_name = is_array($hotel_info) ? PriceInfoFormatter::toScalar($hotel_info['hotel_name'] ?? '') : '';
    if (empty($hotel_name)) {
        // Try from booking record
        $hotel_name = PriceInfoFormatter::toScalar($booking_record['hotel_name'] ?? '');
    }
    if (empty($hotel_name) && !empty($booking_record['product_id'])) {
        // Try from product name
        $hotel_name = PriceInfoFormatter::toScalar(db_get_field("SELECT product FROM ?:product_descriptions WHERE product_id = ?i AND lang_code = ?s",
            PriceInfoFormatter::toInt($booking_record['product_id']), CART_LANGUAGE));
    }
    if (empty($hotel_name)) {
        $hotel_name = 'Hotel #' . $brHotelId;
    }

    // Build booking data for form
    $rooms_data = [];
    $guests_data = [];

    // Rooms: try cart first (may have user selections), then database
    if ($cart_item !== null && !empty($cart_item['extra']['rooms_data'] ?? null)) {
        /** @var array<string, mixed> $cartExtra */
        $cartExtra = is_array($cart_item['extra']) ? $cart_item['extra'] : [];
        $rooms_data = JsonDecoder::decode(PriceInfoFormatter::toScalar($cartExtra['rooms_data'] ?? ''), 'edit_booking:cart_rooms_data');
    }
    if (empty($rooms_data)) {
        $rooms_data = JsonDecoder::decode(PriceInfoFormatter::toScalar($booking_record['rooms_data'] ?? ''), 'edit_booking:db_rooms_data');
    }

    // Guests: ALWAYS read from DB — it's the single source of truth.
    // The cart copy is unreliable: fn_calculate_cart_content() can overwrite it,
    // session expiry loses it, and cart_id hash changes can make it stale.
    // update_booking.php writes to DB first (line 127), so DB always has the
    // latest guest data regardless of cart state.
    $guests_data = (new GuestDataNormalizer())->normalize($booking_record['guests_data'] ?? '');
    
    // Ensure dob field is in DD/MM/YYYY format for each guest (template expects this format)
    foreach ($guests_data as $key => &$guest) {
        if (!is_array($guest)) {
            continue;
        }
        if (empty($guest['dob']) && !empty($guest['birthday'])) {
            // Convert YYYY-MM-DD to DD/MM/YYYY
            $ts = strtotime(PriceInfoFormatter::toScalar($guest['birthday']));
            if ($ts) {
                $guest['dob'] = date('d/m/Y', $ts);
            }
        }
    }
    unset($guest);
    
    $booking = [
        'hotel_id' => $brHotelId,
        'room_id' => PriceInfoFormatter::toScalar($booking_record['room_id'] ?? ''),
        'board_id' => PriceInfoFormatter::toScalar($booking_record['board_id'] ?? ''),
        'check_in' => PriceInfoFormatter::toScalar($booking_record['check_in'] ?? ''),
        'check_out' => PriceInfoFormatter::toScalar($booking_record['check_out'] ?? ''),
        'nights' => PriceInfoFormatter::toInt($booking_record['nights'] ?? 0),
        'adults' => PriceInfoFormatter::toInt($booking_record['adults'] ?? 2),
        'children' => PriceInfoFormatter::toInt($booking_record['children'] ?? 0),
        'children_ages' => PriceInfoFormatter::toScalar($booking_record['children_ages'] ?? ''),
        'total_price' => PriceInfoFormatter::toFloat($booking_record['total_price'] ?? 0),
        'package_name' => PriceInfoFormatter::toScalar($booking_record['package_name'] ?? ''),
        'num_rooms' => PriceInfoFormatter::toInt($booking_record['num_rooms'] ?? 0) ?: 1,
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
        $brRoomId = PriceInfoFormatter::toScalar($booking_record['room_id'] ?? '');
        $brBoardId = PriceInfoFormatter::toScalar($booking_record['board_id'] ?? '');
        $booking['rooms_data'] = [
            [
                'room_id' => $brRoomId,
                'room_name' => fn_novoton_holidays_format_room_type($brRoomId),
                'room_type_display' => fn_novoton_holidays_format_room_type($brRoomId),
                'board_id' => $brBoardId,
                'board_name' => fn_novoton_holidays_format_board_name($brBoardId),
                'adults' => PriceInfoFormatter::toInt($booking_record['adults'] ?? 2),
                'children' => PriceInfoFormatter::toInt($booking_record['children'] ?? 0),
                'childrenAges' => $children_ages_arr,
                'price' => PriceInfoFormatter::toFloat($booking_record['total_price'] ?? 0)
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
    $package_name = PriceInfoFormatter::toScalar($booking_record['package_name'] ?? '');
    if (empty($package_name) && !empty($brHotelId)) {
        // V3: Get first package from novoton_hotel_packages table
        $packageRepo = \Tygh\Addons\NovotonHolidays\Services\Container::getInstance()->hotelPackageRepository();
        $first_pkg = $packageRepo->getFirstPackageName($brHotelId);
        if (!empty($first_pkg)) {
            $package_name = $first_pkg;
        }
    }

    // Get hotel stars
    $hotel_stars = '';
    if (is_array($hotel_info) && !empty($hotel_info['star_rating'])) {
        $hotel_stars = str_repeat('★', PriceInfoFormatter::toInt($hotel_info['star_rating']));
    }

    // V3: Get all packages from novoton_hotel_packages table
    $all_packages = [];
    if (!isset($packageRepo)) {
        $packageRepo = \Tygh\Addons\NovotonHolidays\Services\Container::getInstance()->hotelPackageRepository();
    }
    $db_packages = $packageRepo->getPackageIdNamePairs($brHotelId);
    if (!empty($db_packages)) {
        foreach ($db_packages as $pkg) {
            if (!is_array($pkg)) {
                continue;
            }
            $all_packages[] = [
                'IdCont' => PriceInfoFormatter::toScalar($pkg['package_id'] ?? ''),
                'PackageName' => PriceInfoFormatter::toScalar($pkg['package_name'] ?? '')
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

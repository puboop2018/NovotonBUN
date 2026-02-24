<?php
declare(strict_types=1);
/**
 * Novoton Booking Controller — Booking Form Mode
 * Extracted from novoton_booking.php for maintainability.
 * Included by the main controller when $mode == "booking_form".
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Registry;
use Tygh\Tygh;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\PriceInfoService;
use Tygh\Addons\NovotonHolidays\Services\RoomPriceService;

    $bookingData = $_REQUEST;
    
    // Fix room_id: PHP URL decoding converts + to space, restore it
    // Pattern: "DBL 2 1)" should be "DBL 2+1)"
    if (!empty($bookingData['room_id'])) {
        $bookingData['room_id'] = preg_replace('/(\d)\s+(\d)/', '$1+$2', $bookingData['room_id']);
    }
    
    // Check if this is a multi-room booking
    $is_multi_room = !empty($bookingData['multi_room']) || !empty($bookingData['rooms_data']);
    
    // Parse rooms_data if it's a string (from form submission)
    $rooms_data = [];
    if (!empty($bookingData['rooms_data'])) {
        $rooms_data = is_string($bookingData['rooms_data']) 
            ? json_decode(urldecode($bookingData['rooms_data']), true) 
            : $bookingData['rooms_data'];
        if (!is_array($rooms_data)) {
            $rooms_data = [];
        }
        // Fix room_id in each room (+ converted to space by URL decoding)
        foreach ($rooms_data as &$room) {
            if (!empty($room['room_id'])) {
                $room['room_id'] = preg_replace('/(\d)\s+(\d)/', '$1+$2', $room['room_id']);
            }
        }
        unset($room);
    }
    
    // For multi-room, derive room_id from rooms_data if not directly provided
    if ($is_multi_room && empty($bookingData['room_id']) && !empty($rooms_data[0]['room_id'])) {
        $bookingData['room_id'] = $rooms_data[0]['room_id'];
        $bookingData['board_id'] = $rooms_data[0]['board_id'] ?? 'AI';
    }
    
    // Validate required data
    if (empty($bookingData['hotel_id']) || empty($bookingData['check_in']) || empty($bookingData['check_out'])) {
        fn_set_notification('E', __('error'), __('novoton_holidays.invalid_booking_data') . ' (missing hotel_id, check_in, or check_out)');
        return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
    }
    
    // For non-multi-room, room_id is required
    if (!$is_multi_room && empty($bookingData['room_id'])) {
        fn_set_notification('E', __('error'), __('novoton_holidays.invalid_booking_data') . ' (missing room_id)');
        return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
    }
    
    // Get product and hotel info
    $prefix = ConfigProvider::getFirstProductCodePrefix();
    $product_code = $prefix . $bookingData['hotel_id'];
    
    $product_id = db_get_field(
        "SELECT product_id FROM ?:products WHERE product_code = ?s",
        $product_code
    );
    
    // Get hotel info from novoton_hotels table
    $hotel_info = db_get_row(
        "SELECT * FROM ?:novoton_hotels WHERE hotel_id = ?s",
        $bookingData['hotel_id']
    );
    
    // If hotel not found in novoton_hotels, try to get name from product
    if (empty($hotel_info['hotel_name']) && !empty($product_id)) {
        $product_name = db_get_field(
            "SELECT product FROM ?:product_descriptions WHERE product_id = ?i AND lang_code = ?s",
            $product_id,
            CART_LANGUAGE
        );
        if (!empty($product_name)) {
            if (empty($hotel_info)) {
                $hotel_info = [];
            }
            $hotel_info['hotel_name'] = $product_name;
        }
    }
    
    // Fallback: if still no hotel name, use hotel_id
    if (empty($hotel_info['hotel_name'])) {
        $hotel_info['hotel_name'] = 'Hotel #' . $bookingData['hotel_id'];
    }
    
    // Prepare booking data for template
    $booking = [
        'hotel_id' => $bookingData['hotel_id'],
        'room_id' => $bookingData['room_id'],
        'board_id' => $bookingData['board_id'] ?? 'BB',
        'check_in' => $bookingData['check_in'],
        'check_out' => $bookingData['check_out'],
        'nights' => (int)($bookingData['nights'] ?? 7),
        'adults' => (int)($bookingData['adults'] ?? 2),
        'children' => (int)($bookingData['children'] ?? 0),
        'total_price' => (float)($bookingData['total_price'] ?? $bookingData['price'] ?? 0),
        'children_ages' => $bookingData['children_ages'] ?? '',
        'package_name' => $bookingData['package_name'] ?? '',
        'num_rooms' => (int)($bookingData['num_rooms'] ?? 1),
        'rooms_data' => []
    ];
    
    // Parse rooms_data if provided
    if (!empty($bookingData['rooms_data'])) {
        $rooms_data = is_string($bookingData['rooms_data']) ? json_decode($bookingData['rooms_data'], true) : $bookingData['rooms_data'];
        if (is_array($rooms_data)) {
            // Fix room_id in each room (+ converted to space by URL decoding)
            foreach ($rooms_data as &$rm) {
                if (!empty($rm['room_id'])) {
                    $rm['room_id'] = preg_replace('/(\d)\s+(\d)/', '$1+$2', $rm['room_id']);
                }
            }
            unset($rm);
            $booking['rooms_data'] = $rooms_data;
            $booking['num_rooms'] = count($rooms_data);
        }
    }
    
    // If no rooms_data, create default based on adults/children
    if (empty($booking['rooms_data'])) {
        $children_ages_arr = [];
        if (!empty($booking['children_ages'])) {
            $children_ages_arr = is_string($booking['children_ages']) 
                ? array_map('intval', array_filter(explode(',', $booking['children_ages']), function($v) { return $v !== ''; }))
                : (array)$booking['children_ages'];
        }
        
        // Z3: Format board name consistently
        $formatted_board_name = fn_novoton_holidays_format_board_name($booking['board_id']);
        if ($formatted_board_name === $booking['board_id'] && !empty($bookingData['board_name'])) {
            // If format function didn't change it, try the passed board_name
            $formatted_board_name = fn_novoton_holidays_format_board_name($bookingData['board_name']);
        }
        
        $booking['rooms_data'] = [
            [
                'room_id' => $booking['room_id'],
                'room_name' => $bookingData['room_name'] ?? $booking['room_id'],
                'board_id' => $booking['board_id'],
                'board_name' => $formatted_board_name,
                'adults' => $booking['adults'],
                'children' => $booking['children'],
                'childrenAges' => $children_ages_arr,
                'price' => $booking['total_price']
            ]
        ];
    }
    
    // Parse children ages if string (for legacy support)
    $children_ages_array = [];
    if (!empty($booking['children_ages'])) {
        if (is_string($booking['children_ages'])) {
            $children_ages_array = array_map('intval', array_filter(explode(',', $booking['children_ages']), function($v) { return $v !== ''; }));
        } else {
            $children_ages_array = (array)$booking['children_ages'];
        }
    }
    $booking['children_ages_array'] = $children_ages_array;
    
    // Get package name - only from passed parameters (tied to specific room/price)
    $package_name = '';
    
    // First check rooms_data (from multi-room booking)
    if (!empty($booking['rooms_data'][0]['package_name'])) {
        $package_name = $booking['rooms_data'][0]['package_name'];
    }
    // Then check URL parameter (from single room booking)
    elseif (!empty($bookingData['package_name'])) {
        $package_name = $bookingData['package_name'];
    }
    // Decode URL encoding (e.g., %2b -> +)
    $package_name = urldecode($package_name);
    // Do NOT fall back to database - package_name must come from room_price result
    
    // Get hotel stars
    $hotel_stars = '';
    if (!empty($hotel_info['star_rating'])) {
        $hotel_stars = str_repeat('★', (int)($hotel_info['star_rating']));
    } elseif (!empty($hotel_info['hotel_name'])) {
        // Try to extract stars from hotel name (e.g. "Hotel Name ****")
        if (preg_match('/(\*+)/', $hotel_info['hotel_name'], $matches)) {
            $hotel_stars = $matches[1];
        }
    }
    
    // V3: Get all packages from novoton_hotel_packages table
    $all_packages = [];
    $db_packages = db_get_array(
        "SELECT package_id, package_name FROM ?:novoton_hotel_packages WHERE hotel_id = ?s ORDER BY package_name",
        $booking['hotel_id']
    );
    if (!empty($db_packages)) {
        foreach ($db_packages as $pkg) {
            $all_packages[] = [
                'IdCont' => $pkg['package_id'],
                'PackageName' => $pkg['package_name']
            ];
        }
    }

    // Get age categories and room limits from hotel_data JSON or API
    $age_categories = [];
    $room_limits = [];

    // V3: Try to get from hotel_data JSON first
    if (!empty($hotel_info['hotel_data'])) {
        $hotelData = json_decode($hotel_info['hotel_data'], true);
        if (!empty($hotelData['ages'])) {
            $ages = isset($hotelData['ages']['IdAge']) ? [$hotelData['ages']] : $hotelData['ages'];
            foreach ($ages as $age) {
                $age_categories[] = [
                    'id' => $age['IdAge'] ?? '',
                    'is_child' => ($age['fAge'] ?? '0') === '1',
                    'from_year' => (float)($age['FromYear'] ?? 0),
                    'to_year' => (float)($age['ToYear'] ?? 99)
                ];
            }
        }
        if (!empty($hotelData['rooms'])) {
            $rooms_db = isset($hotelData['rooms']['IdRoom']) ? [$hotelData['rooms']] : $hotelData['rooms'];
            foreach ($rooms_db as $r) {
                $rid = $r['IdRoom'] ?? $r['id'] ?? '';
                if ($rid) $room_limits[$rid] = $r;
            }
        }
    }
    
    // If not in DB, fetch from API
    if ((empty($age_categories) || empty($room_limits)) && !empty($booking['hotel_id'])) {
        $api = fn_novoton_holidays_get_api();
        if ($api) {
            $hotelInfoResponse = $api->getHotelInfo($booking['hotel_id']);
            if ($hotelInfoResponse && isset($hotelInfoResponse->hotels->hotel)) {
                $h = $hotelInfoResponse->hotels->hotel;
                
                // Parse age categories
                if (isset($h->age)) {
                    $ages = isset($h->age->IdAge) ? [$h->age] : $h->age;
                    foreach ($ages as $age) {
                        $age_data = [
                            'id' => (string)($age->IdAge ?? ''),
                            'is_child' => ((string)($age->fAge ?? '0')) === '1',
                            'from_year' => (float)((string)($age->FromYear ?? 0)),
                            'to_year' => (float)((string)($age->ToYear ?? 99))
                        ];
                        $age_categories[] = $age_data;
                    }
                }
                
                // Parse room limits
                if (isset($h->rooms)) {
                    $rooms = isset($h->rooms->IdRoom) ? [$h->rooms] : $h->rooms;
                    foreach ($rooms as $room) {
                        $room_id = (string)($room->IdRoom ?? '');
                        $room_limits[$room_id] = [
                            'id' => $room_id,
                            'type' => (string)($room->Type ?? ''),
                            'rb' => (int)((string)($room->RB ?? 2)),
                            'eb' => (int)((string)($room->EB ?? 0)),
                            'max_adults' => (int)((string)($room->maxADT ?? 4)),
                            'max_children' => (int)((string)($room->maxCHD ?? 2)),
                            'min_pax' => (int)((string)($room->minPAX ?? 1))
                        ];
                    }
                }
                
                // V3: Age and room data is already stored in hotel_data JSON via hotelinfo sync
                // No separate caching needed - data will be fetched fresh from API or hotel_data
            }
        }
    }
    
    // Add age categories and room limits to booking data for JavaScript
    $booking['age_categories'] = $age_categories;
    $current_room_id = $booking['room_id'];
    $booking['current_room_limits'] = $room_limits[$current_room_id] ?? [
        'max_adults' => 4,
        'max_children' => 2,
        'min_pax' => 1,
        'rb' => 2,
        'eb' => 2
    ];
    
    // Assign to view
    Tygh::$app['view']->assign('booking_data', $booking);
    $novoton_display_currency = RoomPriceService::getDisplayCurrency();
    $currencies = \Tygh\Registry::get('currencies');
    $novoton_display_coefficient = (float) ($currencies[$novoton_display_currency]['coefficient'] ?? 1.0);
    $novoton_display_symbol = $currencies[$novoton_display_currency]['symbol'] ?? $novoton_display_currency;

    Tygh::$app['view']->assign('novoton_display_currency', $novoton_display_currency);
    Tygh::$app['view']->assign('novoton_display_coefficient', $novoton_display_coefficient);
    Tygh::$app['view']->assign('novoton_display_symbol', $novoton_display_symbol);
    Tygh::$app['view']->assign('product_id', $product_id);
    Tygh::$app['view']->assign('hotel_name', $hotel_info['hotel_name'] ?? 'Hotel');
    Tygh::$app['view']->assign('hotel_city', $hotel_info['city'] ?? '');
    Tygh::$app['view']->assign('hotel_region', $hotel_info['region'] ?? '');
    Tygh::$app['view']->assign('hotel_country', $hotel_info['country'] ?? 'BULGARIA');
    Tygh::$app['view']->assign('hotel_stars', $hotel_stars);
    Tygh::$app['view']->assign('package_name', $package_name);
    Tygh::$app['view']->assign('hotel_all_packages', $all_packages);
    Tygh::$app['view']->assign('auth', Tygh::$app['session']['auth'] ?? []);

    // Calendar prices: per-date approximate total for the cheapest room
    // Uses guest count to calculate realistic per-night totals
    $calendar_prices_json = '{}';
    $calendar_prices_currency = $novoton_display_currency;
    if (ConfigProvider::isShowCalendarPrices() && !empty($bookingData['hotel_id'])) {
        $calendar_adults = max(1, (int)($booking['adults'] ?? 2));
        $priceInfoService = new PriceInfoService();
        $calendarData = $priceInfoService->getCalendarPrices($bookingData['hotel_id'], $novoton_display_currency, $calendar_adults);
        if (!empty($calendarData['prices'])) {
            $calendar_prices_json = json_encode($calendarData['prices'], JSON_UNESCAPED_UNICODE);
            $calendar_prices_currency = $calendarData['currency'];
        }
    }
    Tygh::$app['view']->assign('calendar_prices_json', $calendar_prices_json);
    Tygh::$app['view']->assign('calendar_prices_currency', $calendar_prices_currency);
    Tygh::$app['view']->assign('show_calendar_prices', ConfigProvider::isShowCalendarPrices() ? 'Y' : 'N');

    // Terms are now fetched directly from API at checkout (Option A)
    // No need to pass through booking form

    // Page setup
    $page_title = __('novoton_holidays.complete_booking');
    Tygh::$app['view']->assign('page_title', $page_title);
    Registry::set('navigation.dynamic.page_title', $page_title);
    fn_add_breadcrumb($page_title);

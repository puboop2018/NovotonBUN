<?php
declare(strict_types=1);
/**
 * Novoton Booking Controller — Add to Cart Mode
 * Extracted from novoton_booking.php for maintainability.
 * Included by the main controller when $mode == "add_to_cart".
 */
if (!defined('BOOTSTRAP')) { die('Access denied'); }


    // --- Security: Rate limiting ---
    $security = _nvt_get_security_service();
    $auth = Tygh::$app['session']['auth'] ?? [];
    $rate_limit_id = !empty($auth['user_id']) ? (string)$auth['user_id'] : Tygh::$app['session']->getID();
    if (!$security->checkBookingRateLimit($rate_limit_id)) {
        $security->logSecurityEvent('rate_limit_exceeded', ['mode' => 'add_to_cart', 'identifier' => $rate_limit_id]);
        fn_set_notification('E', __('error'), 'Too many booking requests. Please try again later.');
        return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
    }

    $bookingData = $_REQUEST;

    // Fix room_id: PHP URL decoding converts + to space, restore it
    // Pattern: "DBL 2 1)" should be "DBL 2+1)"
    if (!empty($bookingData['room_id'])) {
        $bookingData['room_id'] = preg_replace('/(\d)\s+(\d)/', '$1+$2', $bookingData['room_id']);
    }

    // --- Security: Validate booking data via SecurityService ---
    $validation = $security->validateBookingData($bookingData);
    if (!$validation['valid']) {
        $security->logSecurityEvent('booking_validation_failed', [
            'mode' => 'add_to_cart',
            'errors' => $validation['errors'],
            'hotel_id' => $bookingData['hotel_id'] ?? ''
        ]);
        fn_set_notification('E', __('error'), __('novoton_holidays.invalid_booking_data'));
        return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
    }

    // Validate room_id is also present (not checked by SecurityService)
    if (empty($bookingData['room_id'])) {
        fn_set_notification('E', __('error'), __('novoton_holidays.invalid_booking_data'));
        return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
    }
    
    // Get product ID from hotel ID
    $prefix = ConfigProvider::getFirstProductCodePrefix();
    $product_code = $prefix . $bookingData['hotel_id'];
    
    $product_id = db_get_field(
        "SELECT product_id FROM ?:products WHERE product_code = ?s",
        $product_code
    );
    
    if (empty($product_id)) {
        // Try the product_id from form
        $product_id = intval($bookingData['product_id'] ?? 0);
    }
    
    if (empty($product_id)) {
        fn_set_notification('E', __('error'), __('novoton_holidays.product_not_found'));
        return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
    }
    
    // Get hotel info using repository
    $hotel_info = _nvt_hotel_repo()->findById($bookingData['hotel_id']);
    
    // Process guest information — sanitize via SecurityService
    $guests = is_array($bookingData['guests'] ?? null) ? $security->sanitizeGuestData($bookingData['guests']) : [];
    $contact = $bookingData['contact'] ?? [];
    $special_requests = strip_tags(mb_substr(trim($bookingData['special_requests'] ?? ''), 0, 2000));
    
    // Parse guests (no full DOB validation needed at add_to_cart, that happens in update_booking)
    $parsed_guests = _nvt_parse_and_validate_guests($guests, '', 0, '');
    $guests_data = $parsed_guests['guests_data'] ?? [];
    $guest_names = $parsed_guests['guest_names'] ?? [];
    $guest_list = $parsed_guests['guest_list'] ?? '';
    $holder_name = $parsed_guests['holder_name'] ?? '';
    
    // Get children ages from guests_data (more reliable than form hidden field)
    $all_child_ages = [];
    foreach ($guests_data as $guest) {
        if (isset($guest['type']) && $guest['type'] == 'child' && isset($guest['age'])) {
            $all_child_ages[] = intval($guest['age']);
        }
    }
    $children_ages = !empty($all_child_ages) ? implode(',', $all_child_ages) : ($bookingData['children_ages'] ?? '');
    
    // Get package name
    $package_name = $bookingData['package_name'] ?? '';
    if (empty($package_name) && !empty($bookingData['hotel_id'])) {
        // V3: Get first package from novoton_hotel_packages table
        $first_pkg = db_get_field(
            "SELECT package_name FROM ?:novoton_hotel_packages WHERE hotel_id = ?s ORDER BY package_name LIMIT 1",
            $bookingData['hotel_id']
        );
        if (!empty($first_pkg)) {
            $package_name = $first_pkg;
        }
    }
    
    // Get total price (from form or recalculate)
    $total_price = floatval($bookingData['total_price'] ?? 0);
    
    // Always call API to get terms and verify price (Option A: fetch terms at checkout)
    // IMPORTANT: Include children ages for correct price calculation
    $priceParams = [
        'hotel_id' => $bookingData['hotel_id'],
        'room_id' => $bookingData['room_id'],
        'board_id' => $bookingData['board_id'] ?? '',
        'star_rating' => '',
        'check_in' => $bookingData['check_in'],
        'check_out' => $bookingData['check_out'],
        'adults' => intval($bookingData['adults'] ?? 2),
        'children' => $all_child_ages  // Include children ages from guest form
    ];
    
    $priceData = fn_novoton_get_api()->getRoomPrice($priceParams);

    // A80: Server-side price validation - safety net
    // If we have children and API returns no data, abort booking
    // This prevents bookings with incorrect prices when room doesn't accept certain child ages
    if (!$priceData || !isset($priceData->Price)) {
        fn_log_event('general', 'runtime', [
            'message' => 'Novoton add_to_cart: PRICE VERIFICATION FAILED - API returned no price',
            'hotel_id' => $bookingData['hotel_id'],
            'room_id' => $bookingData['room_id'],
            'children_ages' => $all_child_ages,
            'adults' => intval($bookingData['adults'] ?? 2)
        ]);

        fn_set_notification('E', __('error'), __('novoton_holidays.price_verification_failed', [
            '[default]' => 'Price verification failed. The booking cannot proceed. Please go back and try refreshing the price, or contact support.'
        ]));

        // Build return URL to booking form with all parameters
        $return_params = [
            'hotel_id' => $bookingData['hotel_id'],
            'product_id' => $product_id,
            'check_in' => $bookingData['check_in'],
            'check_out' => $bookingData['check_out'],
            'nights' => $bookingData['nights'] ?? '',
            'adults' => $bookingData['adults'] ?? 2,
            'children' => $bookingData['children'] ?? 0,
            'children_ages' => $children_ages,
            'rooms' => $bookingData['num_rooms'] ?? 1
        ];
        $return_url = 'novoton_booking.form?' . http_build_query($return_params);

        return [CONTROLLER_STATUS_REDIRECT, $return_url];
    }

    // Initialize terms
    $terms_of_payment = '';
    $terms_of_cancellation = '';
    $remark = '';
    $important = '';
    $base_price = 0; // API price before commission

    if ($priceData) {
        // Update price if we got one from API
        if (isset($priceData->Price)) {
            $rawPrice = floatval((string)$priceData->Price);
            $base_price = $rawPrice;
            $api_price = fn_novoton_get_api()->applyCommission($rawPrice);
            // ALWAYS use API price when children are involved (ages affect pricing)
            // Also use if we don't have one, or if it's different
            if (!empty($all_child_ages) || $total_price <= 0 || abs($total_price - $api_price) > 0.01) {
                $total_price = $api_price;
            }
        }

        // Extract terms from API response using xpath (more reliable than direct property access)
        if ($priceData instanceof \SimpleXMLElement) {
            $termsPayment = $priceData->xpath('//TermsOfPayment');
            $termsCancellation = $priceData->xpath('//TermsOfCancellation');

            if (!empty($termsPayment[0])) {
                $terms_of_payment = $termsPayment[0]->asXML();
            }
            if (!empty($termsCancellation[0])) {
                $terms_of_cancellation = $termsCancellation[0]->asXML();
            }
        }

        // Extract remark and important info
        if (isset($priceData->remark)) {
            $remark = (string)$priceData->remark;
        }
        if (isset($priceData->Important)) {
            $important = (string)$priceData->Important;
        }
    }
    
    if ($total_price <= 0) {
        fn_set_notification('E', __('error'), __('novoton_holidays.price_unavailable'));
        return [CONTROLLER_STATUS_REDIRECT, 'products.view?product_id=' . $product_id];
    }
    
    // Calculate nights
    $nights = intval($bookingData['nights'] ?? 0);
    if ($nights <= 0) {
        $check_in_ts = strtotime($bookingData['check_in']);
        $check_out_ts = strtotime($bookingData['check_out']);
        $nights = ($check_out_ts - $check_in_ts) / 86400;
    }
    
    // Format board name for display
    $board_id = $bookingData['board_id'] ?? 'BB';
    $board_name = fn_novoton_format_board_name($board_id);
    
    // Parse rooms_data
    $num_rooms = intval($bookingData['num_rooms'] ?? 1);
    $rooms_data = [];
    if (!empty($bookingData['rooms_data'])) {
        $rooms_data = is_string($bookingData['rooms_data']) ? json_decode($bookingData['rooms_data'], true) : $bookingData['rooms_data'];
        if (!is_array($rooms_data)) {
            $rooms_data = [];
        }
        // Fix room_id in each room (+ converted to space by URL decoding)
        foreach ($rooms_data as &$rm) {
            if (!empty($rm['room_id'])) {
                $rm['room_id'] = preg_replace('/(\d)\s+(\d)/', '$1+$2', $rm['room_id']);
            }
        }
        unset($rm);
    }
    
    // If rooms_data is still empty, create default with complete info
    if (empty($rooms_data)) {
        $children_ages_arr = [];
        if (!empty($bookingData['children_ages'])) {
            $children_ages_arr = is_string($bookingData['children_ages']) 
                ? array_map('intval', array_filter(explode(',', $bookingData['children_ages']), function($v) { return $v !== ''; }))
                : (array)$bookingData['children_ages'];
        }
        $rooms_data = [
            [
                'room_id' => $bookingData['room_id'],
                'room_name' => fn_novoton_format_room_type($bookingData['room_id']),
                'room_type_display' => fn_novoton_format_room_type($bookingData['room_id']),
                'board_id' => $board_id,
                'board_name' => $board_name,
                'adults' => intval($bookingData['adults'] ?? 2),
                'children' => intval($bookingData['children'] ?? 0),
                'childrenAges' => $children_ages_arr,
                'price' => floatval($bookingData['total_price'] ?? 0)
            ]
        ];
        $num_rooms = 1;
    }
    
    // Add children_ages_str and room_type_display to each room for Smarty display
    // Also sync children ages from guest form back to rooms_data
    foreach ($rooms_data as $room_idx => &$room) {
        $room_num = $room_idx + 1;
        
        // Collect children ages from guests_data for this room
        $child_ages_for_room = [];
        foreach ($guests_data as $key => $guest) {
            if (isset($guest['room']) && $guest['room'] == $room_num && $guest['type'] == 'child') {
                $child_ages_for_room[] = intval($guest['age']);
            }
        }
        
        // If we have ages from guest form, update rooms_data
        if (!empty($child_ages_for_room)) {
            $room['childrenAges'] = $child_ages_for_room;
        }
        
        if (!empty($room['childrenAges']) && is_array($room['childrenAges'])) {
            // Filter out null values and format
            $valid_ages = array_filter($room['childrenAges'], function($age) { return $age !== null && $age !== ''; });
            $room['children_ages_str'] = !empty($valid_ages) ? implode(', ', $valid_ages) . ' ' . __('novoton_holidays.years_old') : '';
        } else {
            $room['children_ages_str'] = '';
        }
        // Ensure room_type_display is set (translated room name)
        if (empty($room['room_type_display']) && !empty($room['room_id'])) {
            $room['room_type_display'] = fn_novoton_format_room_type($room['room_id']);
            $room['room_name'] = fn_novoton_format_room_type($room['room_id']);
        }
    }
    unset($room);
    
    // Check if similar booking already exists (same hotel, dates, holder, no order yet)
    // This prevents duplicates from form resubmissions
    $existing_booking_id = db_get_field(
        "SELECT booking_id FROM ?:novoton_bookings 
         WHERE order_id = 0 
         AND hotel_id = ?s 
         AND check_in = ?s 
         AND check_out = ?s 
         AND holder_name = ?s
         AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
         LIMIT 1",
        $bookingData['hotel_id'],
        $bookingData['check_in'],
        $bookingData['check_out'],
        $holder_name
    );
    
    // Extract room_id and room_type from rooms_data for database columns
    // This ensures the columns are populated even for multi-room bookings
    $room_ids_for_db = [];
    $room_types_for_db = [];
    $total_adults = 0;
    $total_children = 0;
    
    foreach ($rooms_data as $room) {
        if (!empty($room['room_id'])) {
            $room_ids_for_db[] = $room['room_id'];
        }
        if (!empty($room['room_name'])) {
            $room_types_for_db[] = $room['room_name'];
        } elseif (!empty($room['room_type_display'])) {
            $room_types_for_db[] = $room['room_type_display'];
        } elseif (!empty($room['room_id'])) {
            $room_types_for_db[] = fn_novoton_format_room_type($room['room_id']);
        }
        $total_adults += intval($room['adults'] ?? 0);
        $total_children += intval($room['children'] ?? 0);
    }
    
    // Fallback to bookingData if rooms_data didn't have room_id
    if (empty($room_ids_for_db) && !empty($bookingData['room_id'])) {
        $room_ids_for_db[] = $bookingData['room_id'];
        $room_types_for_db[] = fn_novoton_format_room_type($bookingData['room_id']);
    }
    
    // Use totals from rooms_data if available, otherwise from bookingData
    if ($total_adults == 0) {
        $total_adults = intval($bookingData['adults'] ?? 2);
    }
    if ($total_children == 0) {
        $total_children = intval($bookingData['children'] ?? 0);
    }
    
    $room_id_column = implode(', ', $room_ids_for_db);
    $room_type_column = implode(', ', $room_types_for_db);
    
    if ($existing_booking_id) {
        // Update existing booking instead of creating new one
        $booking_record = [
            'room_id' => $room_id_column,
            'room_type' => $room_type_column,
            'adults' => $total_adults,
            'children' => $total_children,
            'rooms_data' => json_encode($rooms_data),
            'guest_name' => $guest_list,
            'guests_data' => GuestDataNormalizer::toJson($guests_data),
            'base_price' => $base_price,
            'total_price' => $total_price,
            'guest_email' => $contact['email'] ?? '',
            'api_request' => json_encode([
                'guests' => $guests_data,
                'contact' => $contact,
                'rooms_data' => $rooms_data
            ])
        ];
        // Update user_id if now logged in
        $auth = Tygh::$app['session']['auth'] ?? [];
        if (!empty($auth['user_id'])) {
            $booking_record['user_id'] = intval($auth['user_id']);
        }
        // A79: Use BookingRepository for update
        _nvt_booking_repo()->update($existing_booking_id, $booking_record);
        $booking_id = $existing_booking_id;
    } else {
        // Get current user and session info
        $auth = Tygh::$app['session']['auth'] ?? [];
        $user_id = !empty($auth['user_id']) ? intval($auth['user_id']) : 0;
        $session_id = session_id();
        
        // Create new booking record in database
        $booking_record = [
            'order_id' => 0, // Will be updated when order is placed
            'user_id' => $user_id,
            'session_id' => $session_id,
            'product_id' => $product_id,
            'hotel_id' => $bookingData['hotel_id'],
            'hotel_name' => $hotel_info['hotel_name'] ?? '',
            'package_name' => $package_name,
            'room_id' => $room_id_column,
            'room_type' => $room_type_column,
            'board_id' => $board_id,
            'check_in' => $bookingData['check_in'],
            'check_out' => $bookingData['check_out'],
            'nights' => $nights,
            'adults' => $total_adults,
            'children' => $total_children,
            'children_ages' => $children_ages,
            'num_rooms' => $num_rooms,
            'rooms_data' => json_encode($rooms_data),
            'guest_name' => $guest_list,
            'holder_name' => $holder_name,
            'guest_email' => '',  // Will be set from order at checkout
            'guest_phone' => $contact['phone'] ?? '',
            'guests_data' => GuestDataNormalizer::toJson($guests_data),
            'base_price' => $base_price,
            'total_price' => $total_price,
            'currency' => RoomPriceService::getApiCurrency(),
            'status' => 'pending',
            'special_requests' => $special_requests,
            'notes' => $special_requests,
            'api_request' => json_encode([
                'guests' => $guests_data,
                'contact' => $contact,
                'rooms_data' => $rooms_data
            ])
        ];
        
        // A79: Use BookingRepository for create
        $booking_id = _nvt_booking_repo()->create($booking_record);
    }
    
    // Add to cart with booking details
    $product = [
        'product_id' => $product_id,
        'amount' => 1,
        'extra' => [
            'novoton_booking' => true,
            'novoton_booking_id' => $booking_id,
            'hotel_id' => $bookingData['hotel_id'],
            'hotel_name' => $hotel_info['hotel_name'] ?? '',
            'hotel_city' => $hotel_info['city'] ?? '',
            'hotel_region' => $hotel_info['region'] ?? '',
            'hotel_country' => $hotel_info['country'] ?? '',
            'package_name' => $package_name,
            'room_id' => $bookingData['room_id'],
            'room_name' => str_replace(['%2b', '%2B'], '+', $bookingData['room_id']),
            'room_type_display' => fn_novoton_format_room_type($bookingData['room_id']),
            'board_id' => $board_id,
            'board_name' => $board_name,
            'check_in' => $bookingData['check_in'],
            'check_out' => $bookingData['check_out'],
            'nights' => $nights,
            'adults' => intval($bookingData['adults'] ?? 2),
            'children' => intval($bookingData['children'] ?? 0),
            'children_ages' => $children_ages,
            'num_rooms' => intval($num_rooms),  // Explicitly cast to int
            'rooms_data' => $rooms_data,
            'guest_names' => $guest_list,
            'holder_name' => $holder_name,
            'guests_data' => GuestDataNormalizer::toJson($guests_data),
            'contact_email' => $contact['email'] ?? '',
            'contact_phone' => $contact['phone'] ?? '',
            'special_requests' => $special_requests,
            'terms_of_payment' => fn_novoton_format_payment_terms($terms_of_payment),
            'terms_of_cancellation' => fn_novoton_format_cancellation_terms($terms_of_cancellation, $bookingData['check_in']),
            'terms_of_payment_raw' => $terms_of_payment,
            'terms_of_cancellation_raw' => $terms_of_cancellation,
            'remark' => $remark,
            'important' => $important,
            'total_price' => $total_price,
            'currency' => RoomPriceService::getApiCurrency(),
        ]
    ];
    
    // Set the price directly (override product price)
    $product['price'] = $total_price;
    
    // Add to cart
    $cart = &Tygh::$app['session']['cart'];
    $auth = &Tygh::$app['session']['auth'];
    
    // Initialize cart if needed
    if (empty($cart)) {
        fn_clear_cart($cart);
    }
    
    // Generate unique cart_id for this booking
    $cart_id = fn_generate_cart_id($product_id, $product['extra']);
    
    // Add product to cart
    $cart['products'][$cart_id] = [
        'product_id' => $product_id,
        'amount' => 1,
        'price' => $total_price,
        'base_price' => $total_price,
        'original_price' => $total_price,
        'extra' => $product['extra'],
        'stored_price' => 'Y'  // Important: use our calculated price
    ];
    
    // Recalculate cart
    fn_calculate_cart_content($cart, $auth, 'S', true, 'F', true);
    fn_save_cart_content($cart, $auth['user_id'] ?? 0);
    
    fn_set_notification('N', __('notice'), __('novoton_holidays.added_to_cart'));
    
    return [CONTROLLER_STATUS_REDIRECT, 'checkout.cart'];

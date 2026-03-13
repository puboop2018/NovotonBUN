<?php
declare(strict_types=1);
/**
 * Novoton Booking Controller — Update Booking Mode
 * Extracted from novoton_booking.php for maintainability.
 * Included by the main controller when $mode == "update_booking".
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Tygh;
use Tygh\Addons\NovotonHolidays\Services\GuestDataNormalizer;

    $security = _nvt_get_security_service();
    $bookingData = $_REQUEST;
    $booking_id = (int)($bookingData['booking_id'] ?? 0);
    $cart_id = $bookingData['cart_id'] ?? '';

    if (empty($booking_id)) {
        fn_set_notification('E', __('error'), __('novoton_holidays.invalid_booking_data'));
        return [CONTROLLER_STATUS_REDIRECT, 'checkout.cart'];
    }

    // Verify booking ownership before allowing update
    $auth = Tygh::$app['session']['auth'] ?? [];
    $current_user_id = !empty($auth['user_id']) ? (int)($auth['user_id']) : 0;
    $current_session_id = Tygh::$app['session']->getID();

    $ownership_check = db_get_field(
        "SELECT booking_id FROM ?:novoton_bookings WHERE booking_id = ?i AND (user_id = ?i OR session_id = ?s)",
        $booking_id, $current_user_id, $current_session_id
    );
    if (empty($ownership_check)) {
        $security->logSecurityEvent('unauthorized_booking_update', [
            'booking_id' => $booking_id,
            'user_id' => $current_user_id,
            'session_id' => $current_session_id
        ]);
        fn_set_notification('E', __('error'), __('novoton_holidays.invalid_booking_data'));
        return [CONTROLLER_STATUS_REDIRECT, 'checkout.cart'];
    }

    // Process guest information — sanitize via SecurityService
    $guests = is_array($bookingData['guests'] ?? null) ? $security->sanitizeGuestData($bookingData['guests']) : [];
    $raw_contact = $bookingData['contact'] ?? [];
    $contact = [
        'email' => filter_var(trim($raw_contact['email'] ?? ''), FILTER_SANITIZE_EMAIL),
        'phone' => preg_replace('/[^\d\s\+\-\(\)]/', '', trim($raw_contact['phone'] ?? '')),
    ];
    // Get check-in date for age validation
    $existing_for_checkin = _nvt_booking_repo()->findById($booking_id);
    $check_in_for_validation = $existing_for_checkin['check_in'] ?? '';
    
    // Parse and validate guests (returns false if validation fails)
    $parsed_guests = _nvt_parse_and_validate_guests($guests, $check_in_for_validation, $booking_id, $cart_id);
    if ($parsed_guests === false) {
        return [CONTROLLER_STATUS_REDIRECT, "novoton_booking.edit_booking?booking_id={$booking_id}&cart_id={$cart_id}"];
    }
    
    // Extract parsed data
    $guests_data = $parsed_guests['guests_data'];
    $guest_names = $parsed_guests['guest_names'];
    $guest_list = $parsed_guests['guest_list'];
    $holder_name = $parsed_guests['holder_name'];
    
    // Update booking record
    // First get existing booking data to rebuild api_request - use cached value if same booking
    $existing_booking = ($existing_for_checkin && $existing_for_checkin['booking_id'] == $booking_id)
        ? $existing_for_checkin
        : _nvt_booking_repo()->findById($booking_id);

    if (empty($existing_booking)) {
        fn_set_notification('E', __('error'), __('novoton_holidays.invalid_booking_data'));
        return [CONTROLLER_STATUS_REDIRECT, 'checkout.cart'];
    }

    // Build api_request with updated guest names - use api_name for Novoton XML
    $api_guests = [];
    foreach ($guests_data as $key => $guest) {
        $api_guests[] = [
            'name' => $guest['api_name'] ?? $guest['name'],  // Use api_name (First Last) for API
            'birthday' => $guest['birthday'] ?? '',
            'age' => $guest['age'],
            'type' => $guest['type'],
            'room' => $guest['room']
        ];
    }
    
    // Rebuild api_request with updated guests
    $api_request = [
        'hotel_id' => $existing_booking['hotel_id'],
        'package_name' => $existing_booking['package_name'],
        'check_in' => $existing_booking['check_in'],
        'check_out' => $existing_booking['check_out'],
        'room_id' => $existing_booking['room_id'],
        'board_id' => $existing_booking['board_id'],
        'holder' => $holder_name,
        'guests' => $api_guests,
        'order_num' => $existing_booking['order_id'] ?: '',
        'remark' => '',
        'comment' => ''
    ];
    
    // If multi-room, parse rooms_data and add rooms to api_request
    if (!empty($existing_booking['rooms_data'])) {
        $rooms_data = json_decode($existing_booking['rooms_data'], true);
        if ($rooms_data && count($rooms_data) > 1) {
            $api_rooms = [];
            foreach ($rooms_data as $room_idx => $room) {
                $room_num = $room_idx + 1;
                $room_guests = [];
                foreach ($api_guests as $guest) {
                    if (isset($guest['room']) && $guest['room'] == $room_num) {
                        $room_guests[] = $guest;
                    }
                }
                $api_rooms[] = [
                    'room_id' => $room['room_id'] ?? '',
                    'board_id' => $room['board_id'] ?? '',
                    'guests' => $room_guests
                ];
            }
            $api_request['rooms'] = $api_rooms;
        }
    }
    
    db_query(
        "UPDATE ?:novoton_bookings SET 
         guest_name = ?s, holder_name = ?s, guest_email = ?s, guest_phone = ?s,
         guests_data = ?s, api_request = ?s
         WHERE booking_id = ?i",
        $guest_list, $holder_name, $contact['email'] ?? '', $contact['phone'] ?? '',
        GuestDataNormalizer::toJson($guests_data), json_encode($api_request), $booking_id
    );

    // Update cart item if cart_id provided
    if (!empty($cart_id)) {
        $cart = &Tygh::$app['session']['cart'];
        if (isset($cart['products'][$cart_id])) {
            $cart['products'][$cart_id]['extra']['guest_names'] = $guest_list;
            $cart['products'][$cart_id]['extra']['holder_name'] = $holder_name;
            $cart['products'][$cart_id]['extra']['guests_data'] = GuestDataNormalizer::toJson($guests_data);
            $cart['products'][$cart_id]['extra']['contact_email'] = $contact['email'] ?? '';
            $cart['products'][$cart_id]['extra']['contact_phone'] = $contact['phone'] ?? '';
            // Recalculate and save cart
            $auth = &Tygh::$app['session']['auth'];
            fn_calculate_cart_content($cart, $auth, 'S', true, 'F', true);
            fn_save_cart_content($cart, $auth['user_id'] ?? 0);
        }
    }
    
    fn_set_notification('N', __('success'), __('novoton_holidays.booking_updated'));
    
    return [CONTROLLER_STATUS_REDIRECT, 'checkout.cart'];

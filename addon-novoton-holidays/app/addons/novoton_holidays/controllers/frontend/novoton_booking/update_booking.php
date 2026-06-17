<?php
declare(strict_types=1);
/**
 * Novoton Booking Controller — Update Booking Mode
 * Extracted from novoton_booking.php for maintainability.
 * Included by the main controller when $mode == "update_booking".
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Tygh;
use Tygh\Addons\NovotonHolidays\Services\PriceInfoFormatter;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\GuestDataNormalizer;

    $security = _nvt_get_security_service();
    /** @var array<string, mixed> $bookingData */
    $bookingData = $_REQUEST;
    $booking_id = PriceInfoFormatter::toInt($bookingData['booking_id'] ?? 0);
    $cart_id = PriceInfoFormatter::toScalar($bookingData['cart_id'] ?? '');

    if (empty($booking_id)) {
        fn_set_notification('E', __('error'), __('novoton_holidays.invalid_booking_data'));
        return [CONTROLLER_STATUS_REDIRECT, 'checkout.cart'];
    }

    // Verify booking ownership before allowing update
    // CS-Cart's session is an ArrayAccess container object; a plain (non-reference)
    // local binds the same object handle, so offset reads below operate on the
    // live session exactly as direct `Tygh::$app['session'][...]` access would.
    $session = Tygh::$app['session'];
    if (!is_array($session) && !$session instanceof \ArrayAccess) {
        $session = [];
    }
    $auth = TypeCoerce::toStringMap($session['auth'] ?? null);
    $current_user_id = PriceInfoFormatter::toInt($auth['user_id'] ?? 0);
    $current_session_id = (is_object($session) && method_exists($session, 'getID'))
        ? TypeCoerce::toString($session->getID())
        : '';

    $ownershipRepo = _nvt_booking_ownership_repo();
    $ownership_check = $ownershipRepo->checkOwnership($booking_id, $current_user_id, $current_session_id);
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
    $guests = is_array($bookingData['guests'] ?? null) ? $security->sanitizeGuestData(TypeCoerce::toStringMap($bookingData['guests'])) : [];
    $raw_contact = is_array($bookingData['contact'] ?? null) ? $bookingData['contact'] : [];
    $contact = [
        'email' => filter_var(trim(PriceInfoFormatter::toScalar($raw_contact['email'] ?? '')), FILTER_SANITIZE_EMAIL),
        'phone' => preg_replace('/[^\d\s+()-]/', '', trim(PriceInfoFormatter::toScalar($raw_contact['phone'] ?? ''))),
    ];
    // Get check-in date for age validation
    /** @var array<string, mixed>|null $existing_for_checkin */
    $existing_for_checkin = _nvt_booking_repo()->findById($booking_id);
    $check_in_for_validation = is_array($existing_for_checkin) ? PriceInfoFormatter::toScalar($existing_for_checkin['check_in'] ?? '') : '';
    
    // Parse and validate guests (returns false if validation fails)
    $parsed_guests = \Tygh\Addons\TravelCore\Services\GuestDataService::parseAndValidateGuests($guests, $check_in_for_validation, 'novoton');
    if ($parsed_guests === false) {
        return [CONTROLLER_STATUS_REDIRECT, "novoton_booking.edit_booking?booking_id={$booking_id}&cart_id={$cart_id}"];
    }
    
    // Extract parsed data
    $guests_data = TypeCoerce::toStringMap($parsed_guests['guests_data'] ?? []);
    $guest_names = $parsed_guests['guest_names'];
    $guest_list = $parsed_guests['guest_list'];
    $holder_name = $parsed_guests['holder_name'];
    
    // Update booking record
    // First get existing booking data to rebuild api_request - use cached value if same booking
    /** @var array<string, mixed>|null $existing_booking */
    $existing_booking = (is_array($existing_for_checkin) && PriceInfoFormatter::toInt($existing_for_checkin['booking_id'] ?? 0) === $booking_id)
        ? $existing_for_checkin
        : _nvt_booking_repo()->findById($booking_id);

    if (empty($existing_booking)) {
        fn_set_notification('E', __('error'), __('novoton_holidays.invalid_booking_data'));
        return [CONTROLLER_STATUS_REDIRECT, 'checkout.cart'];
    }

    // Build api_request with updated guest names - use api_name for Novoton XML
    $api_guests = [];
    foreach ($guests_data as $key => $guest) {
        if (!is_array($guest)) {
            continue;
        }
        $api_guests[] = [
            'name' => PriceInfoFormatter::toScalar($guest['api_name'] ?? $guest['name'] ?? ''),  // Use api_name (First Last) for API
            'birthday' => PriceInfoFormatter::toScalar($guest['birthday'] ?? ''),
            'age' => PriceInfoFormatter::toInt($guest['age'] ?? 0),
            'type' => PriceInfoFormatter::toScalar($guest['type'] ?? ''),
            'room' => PriceInfoFormatter::toInt($guest['room'] ?? 1)
        ];
    }

    // Rebuild api_request with updated guests
    $api_request = [
        'hotel_id' => PriceInfoFormatter::toScalar($existing_booking['hotel_id'] ?? ''),
        'package_name' => PriceInfoFormatter::toScalar($existing_booking['package_name'] ?? ''),
        'check_in' => PriceInfoFormatter::toScalar($existing_booking['check_in'] ?? ''),
        'check_out' => PriceInfoFormatter::toScalar($existing_booking['check_out'] ?? ''),
        'room_id' => PriceInfoFormatter::toScalar($existing_booking['room_id'] ?? ''),
        'board_id' => PriceInfoFormatter::toScalar($existing_booking['board_id'] ?? ''),
        'holder' => $holder_name,
        'guests' => $api_guests,
        'order_num' => PriceInfoFormatter::toScalar($existing_booking['order_id'] ?? ''),
        'remark' => '',
        'comment' => ''
    ];
    
    // If multi-room, parse rooms_data and add rooms to api_request
    if (!empty($existing_booking['rooms_data'])) {
        $rooms_data = json_decode(PriceInfoFormatter::toScalar($existing_booking['rooms_data']), true);
        if (is_array($rooms_data) && count($rooms_data) > 1) {
            $api_rooms = [];
            foreach ($rooms_data as $room_idx => $room) {
                if (!is_array($room)) {
                    continue;
                }
                $room_num = (is_int($room_idx) ? $room_idx : 0) + 1;
                $room_guests = [];
                foreach ($api_guests as $guest) {
                    if ($guest['room'] === $room_num) {
                        $room_guests[] = $guest;
                    }
                }
                $api_rooms[] = [
                    'room_id' => PriceInfoFormatter::toScalar($room['room_id'] ?? ''),
                    'board_id' => PriceInfoFormatter::toScalar($room['board_id'] ?? ''),
                    'guests' => $room_guests
                ];
            }
            $api_request['rooms'] = $api_rooms;
        }
    }
    
    // Route through repository to sync travel_bookings
    try {
        _nvt_booking_repo()->update($booking_id, [
            'guest_name' => $guest_list,
            'holder_name' => $holder_name,
            'guest_email' => $contact['email'] ?: '',
            'guest_phone' => $contact['phone'] ?? '',
            'guests_data' => (new GuestDataNormalizer())->toJson($guests_data),
            'api_request' => json_encode($api_request, JSON_UNESCAPED_UNICODE),
        ]);
    } catch (\Throwable $e) {
        fn_log_event('general', 'runtime', [
            'message' => 'Novoton booking update failed: ' . $e->getMessage(),
            'booking_id' => $booking_id,
        ]);
        fn_set_notification('E', __('error'), __('novoton_holidays.booking_update_failed', [
            '[default]' => 'Could not save guest details. Please try again.',
        ]));
        return [CONTROLLER_STATUS_REDIRECT, "novoton_booking.edit_booking?booking_id={$booking_id}&cart_id={$cart_id}"];
    }

    // Update cart item if cart_id provided
    if (!empty($cart_id)) {
        // Narrow the session array once so the reference binds below see a
        // typed array shape (CS-Cart's reference-based cart flow needs live
        // refs into Tygh::$app['session'] for fn_save_cart_content /
        // fn_calculate_cart_content to persist their mutations).
        $session = is_array(Tygh::$app['session'] ?? null) ? Tygh::$app['session'] : [];
        $session['cart'] = is_array($session['cart'] ?? null) ? $session['cart'] : [];
        $session['auth'] = is_array($session['auth'] ?? null) ? $session['auth'] : [];
        Tygh::$app['session'] = $session;

        $cart = &Tygh::$app['session']['cart'];
        $cart['products'] = is_array($cart['products'] ?? null) ? $cart['products'] : [];

        // Find the cart item — try exact cart_id first, then fall back to
        // searching by booking_id (handles cases where cart was rebuilt
        // and the cart_id hash changed, e.g. after session expiry)
        $target_cart_id = null;
        if (isset($cart['products'][$cart_id])) {
            $target_cart_id = $cart_id;
        } else {
            // Fallback: find cart item by novoton_booking_id
            $cartProducts = $cart['products'];
            foreach ($cartProducts as $cid => $item) {
                if (!is_array($item)) {
                    continue;
                }
                $itemExtra = is_array($item['extra'] ?? null) ? $item['extra'] : [];
                if (!empty($itemExtra['novoton_booking_id']) && PriceInfoFormatter::toInt($itemExtra['novoton_booking_id']) === $booking_id) {
                    $target_cart_id = $cid;
                    break;
                }
            }
        }

        if ($target_cart_id !== null) {
            // Bind a live reference into the target product's extra bag so the
            // mutations below persist on the session cart, then ensure the
            // nested 'extra' slot is an array before writing keyed values.
            $target_product = &$cart['products'][$target_cart_id];
            if (!is_array($target_product)) {
                $target_product = [];
            }
            $target_product['extra'] = is_array($target_product['extra'] ?? null) ? $target_product['extra'] : [];
            $target_extra = &$target_product['extra'];
            $target_extra['guest_names'] = $guest_list;
            $target_extra['holder_name'] = $holder_name;
            $target_extra['guests_data'] = (new GuestDataNormalizer())->toJson($guests_data);
            $target_extra['contact_email'] = $contact['email'] ?: '';
            $target_extra['contact_phone'] = $contact['phone'] ?? '';
            unset($target_product, $target_extra);

            // Persist extras to DB BEFORE recalculating — fn_calculate_cart_content()
            // reloads product data from the stored cart, which would overwrite the
            // extras we just set if they haven't been saved first.
            $auth = &Tygh::$app['session']['auth'];
            $authUserId = PriceInfoFormatter::toInt($auth['user_id'] ?? 0);
            fn_save_cart_content($cart, $authUserId);

            // Now recalculate (reloads extras from DB — our saved values survive)
            fn_calculate_cart_content($cart, $auth, 'S', true, 'F', true);
            fn_save_cart_content($cart, $authUserId);
        }
    }
    
    fn_set_notification('N', __('success'), __('novoton_holidays.booking_updated'));
    
    return [CONTROLLER_STATUS_REDIRECT, 'checkout.cart'];

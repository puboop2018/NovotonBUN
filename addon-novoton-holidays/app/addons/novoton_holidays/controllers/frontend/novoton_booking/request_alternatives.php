<?php
declare(strict_types=1);
/**
 * Novoton Booking Controller — Request Alternatives Mode
 * Extracted from novoton_booking.php for maintainability.
 * Included by the main controller when $mode == "request_alternatives".
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Tygh;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;

    $security = _nvt_get_security_service();

    // --- Rate limiting ---
    // CS-Cart's session is an ArrayAccess container object; a plain (non-reference)
    // local binds the same object handle, so offset reads below operate on the
    // live session exactly as direct `Tygh::$app['session'][...]` access would.
    $session = Tygh::$app['session'];
    if (!is_array($session) && !$session instanceof \ArrayAccess) {
        $session = [];
    }
    $auth = TypeCoerce::toStringMap($session['auth'] ?? []);
    $session_id = is_object($session) && method_exists($session, 'getID')
        ? TypeCoerce::toString($session->getID())
        : '';
    $rate_id = !empty($auth['user_id']) ? TypeCoerce::toString($auth['user_id']) : $session_id;
    if (!$security->checkBookingRateLimit($rate_id)) {
        $security->logSecurityEvent('rate_limit_exceeded', ['mode' => 'request_alternatives', 'identifier' => $rate_id]);
        fn_set_notification('E', __('error'), 'Too many requests. Please try again later.');
        return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
    }

    // --- Validate and sanitize search params ---
    $sanitized = $security->validateSearchParams(TypeCoerce::toStringMap($_REQUEST));

    $hotel_id = TypeCoerce::toString($sanitized['hotel_id'] ?? '');
    $check_in = TypeCoerce::toString($sanitized['check_in'] ?? '');
    $nights = TypeCoerce::toInt($sanitized['nights'] ?? 7);

    // Delegate to AlternativeRequestService
    $altService = _nvt_alternative_request_service();
    $result = $altService->submitAlternativeBookingRequest([
        'hotel_id' => $hotel_id,
        'hotel_name' => strip_tags(mb_substr(RequestCoerce::string($_REQUEST, 'hotel_name'), 0, 200)),
        'check_in' => $check_in,
        'check_out' => $sanitized['check_out'] ?? ($_REQUEST['check_out'] ?? ''),
        'nights' => $nights,
        'adults' => $sanitized['adults'] ?? 2,
        'children' => $sanitized['children'] ?? 0,
        'num_rooms' => $sanitized['rooms'] ?? 1,
        'contact_email' => RequestCoerce::string($_REQUEST, 'contact_email'),
        'contact_phone' => RequestCoerce::string($_REQUEST, 'contact_phone'),
        'notes' => strip_tags(mb_substr(RequestCoerce::string($_REQUEST, 'notes'), 0, 1000)),
    ]);

    if (!empty($result['error']) && $result['error'] === 'missing_required_fields') {
        fn_set_notification('E', __('error'), __('novoton_holidays.missing_required_fields'));
        return [CONTROLLER_STATUS_REDIRECT, fn_url('novoton_booking.search?hotel_id=' . urlencode($hotel_id))];
    }

    if (!empty($result['error']) && $result['error'] === 'invalid_hotel') {
        fn_set_notification('E', __('error'), __('novoton_holidays.invalid_hotel'));
        return [CONTROLLER_STATUS_REDIRECT, fn_url('novoton_booking.search')];
    }

    if (!empty($result['error']) && $result['error'] === 'invalid_email') {
        fn_set_notification('E', __('error'), __('novoton_holidays.invalid_email'));
        return [CONTROLLER_STATUS_REDIRECT, fn_url('novoton_booking.search?hotel_id=' . urlencode($hotel_id))];
    }

    // Show appropriate notification
    if (!empty($result['message'])) {
        fn_set_notification('N', __('notice'), __('novoton_holidays.' . $result['message']));
    }

    return [CONTROLLER_STATUS_REDIRECT, fn_url('novoton_booking.search?hotel_id=' . urlencode($hotel_id) . '&check_in=' . urlencode($check_in) . '&nights=' . (int)$nights)];

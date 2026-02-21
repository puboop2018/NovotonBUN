<?php
declare(strict_types=1);
/**
 * Novoton Booking Controller — Request Alternatives Mode
 * Extracted from novoton_booking.php for maintainability.
 * Included by the main controller when $mode == "request_alternatives".
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

    $security = _nvt_get_security_service();

    // --- Rate limiting ---
    $auth = Tygh::$app['session']['auth'] ?? [];
    $rate_id = !empty($auth['user_id']) ? (string)$auth['user_id'] : Tygh::$app['session']->getID();
    if (!$security->checkBookingRateLimit($rate_id)) {
        $security->logSecurityEvent('rate_limit_exceeded', ['mode' => 'request_alternatives', 'identifier' => $rate_id]);
        fn_set_notification('E', __('error'), 'Too many requests. Please try again later.');
        return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
    }

    // --- Validate and sanitize search params ---
    $sanitized = $security->validateSearchParams($_REQUEST);

    $hotel_id = $sanitized['hotel_id'] ?? '';
    $check_in = $sanitized['check_in'] ?? '';
    $nights = $sanitized['nights'] ?? 7;

    // Delegate to AlternativeRequestService
    $altService = _nvt_alternative_request_service();
    $result = $altService->submitAlternativeBookingRequest([
        'hotel_id' => $hotel_id,
        'hotel_name' => strip_tags(mb_substr(trim($_REQUEST['hotel_name'] ?? ''), 0, 200)),
        'check_in' => $check_in,
        'check_out' => $sanitized['check_out'] ?? ($_REQUEST['check_out'] ?? ''),
        'nights' => $nights,
        'adults' => $sanitized['adults'] ?? 2,
        'children' => $sanitized['children'] ?? 0,
        'num_rooms' => $sanitized['rooms'] ?? 1,
        'contact_email' => trim($_REQUEST['contact_email'] ?? ''),
        'contact_phone' => trim($_REQUEST['contact_phone'] ?? ''),
        'notes' => $_REQUEST['notes'] ?? '',
    ]);

    if (!empty($result['error']) && $result['error'] === 'missing_required_fields') {
        fn_set_notification('E', __('error'), __('novoton_holidays.missing_required_fields'));
        return [CONTROLLER_STATUS_REDIRECT, fn_url('novoton_booking.search?hotel_id=' . urlencode($hotel_id))];
    }

    if (!empty($result['error']) && $result['error'] === 'invalid_email') {
        fn_set_notification('E', __('error'), __('novoton_holidays.invalid_email'));
        return [CONTROLLER_STATUS_REDIRECT, fn_url('novoton_booking.search?hotel_id=' . urlencode($hotel_id))];
    }

    // Show appropriate notification
    if (!empty($result['message'])) {
        fn_set_notification('N', __('notice'), __('novoton_holidays.' . $result['message']));
    }

    return [CONTROLLER_STATUS_REDIRECT, fn_url('novoton_booking.search?hotel_id=' . $hotel_id . '&check_in=' . $check_in . '&nights=' . $nights)];

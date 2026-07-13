<?php

declare(strict_types=1);
/**
 * Sphinx Booking Controller — Request Alternatives Mode (internal-only).
 *
 * Stores a "contact me when this hotel becomes available" request in the
 * SHARED travel_alternative_requests registry and notifies the store's
 * orders department. Unlike novoton's flow, NO request is sent to the
 * provider API — sphinx availability requests exist purely for internal
 * follow-up by staff.
 *
 * @package SphinxHolidays
 * @since   1.5.0
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\AlternativeRequestRepository;
use Tygh\Addons\TravelCore\Services\AlternativeRequestNotifier;
use Tygh\Tygh;

$hotel_id = RequestCoerce::string($_REQUEST, 'hotel_id');
$check_in = RequestCoerce::string($_REQUEST, 'check_in');
$back_url = 'sphinx_booking.search'
    . '?hotel_id=' . urlencode($hotel_id)
    . '&product_id=' . RequestCoerce::int($_REQUEST, 'product_id')
    . '&check_in=' . urlencode($check_in)
    . '&check_out=' . urlencode(RequestCoerce::string($_REQUEST, 'check_out'))
    . '&adults=' . max(1, RequestCoerce::int($_REQUEST, 'adults', 2))
    . '&children=' . max(0, RequestCoerce::int($_REQUEST, 'children'))
    . '&children_ages=' . urlencode(RequestCoerce::string($_REQUEST, 'children_ages'))
    . '&rooms=' . max(1, RequestCoerce::int($_REQUEST, 'rooms', 1));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    return [CONTROLLER_STATUS_REDIRECT, $back_url];
}

// Honeypot: humans never see the field; bots fill everything. Pretend success.
if (RequestCoerce::string($_REQUEST, 'website') !== '') {
    return [CONTROLLER_STATUS_REDIRECT, $back_url];
}

// Per-session cooldown — a public form that writes to the DB and emails staff.
$session = Tygh::$app['session'];
$sessionUsable = is_array($session) || $session instanceof \ArrayAccess;
$lastRequestAt = $sessionUsable ? TypeCoerce::toInt($session['sphinx_alt_request_at'] ?? 0) : 0;
if ($lastRequestAt > 0 && time() - $lastRequestAt < 60) {
    fn_set_notification('W', __('notice'), __('travel_core.request_too_soon', ['[default]' => 'Please wait a moment before sending another request.']));
    return [CONTROLLER_STATUS_REDIRECT, $back_url];
}

$contact_email = trim(RequestCoerce::string($_REQUEST, 'contact_email'));
if ($hotel_id === '' || $contact_email === '' || filter_var($contact_email, FILTER_VALIDATE_EMAIL) === false) {
    fn_set_notification('E', __('error'), __('travel_core.request_invalid_email', ['[default]' => 'Please provide a valid e-mail address.']));
    return [CONTROLLER_STATUS_REDIRECT, $back_url];
}

$auth = $sessionUsable ? TypeCoerce::toStringMap($session['auth'] ?? []) : [];
$check_out = RequestCoerce::string($_REQUEST, 'check_out');
$product_id = max(0, RequestCoerce::int($_REQUEST, 'product_id'));

$request = [
    'provider' => 'sphinx',
    'user_id' => !empty($auth['user_id']) ? TypeCoerce::toInt($auth['user_id']) : null,
    'hotel_id' => mb_substr($hotel_id, 0, 100),
    'hotel_name' => strip_tags(mb_substr(RequestCoerce::string($_REQUEST, 'hotel_name'), 0, 200)),
    'product_id' => $product_id > 0 ? $product_id : null,
    'check_in' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $check_in) === 1 ? $check_in : null,
    'check_out' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $check_out) === 1 ? $check_out : null,
    'nights' => max(0, RequestCoerce::int($_REQUEST, 'nights')),
    'num_rooms' => max(1, RequestCoerce::int($_REQUEST, 'rooms', 1)),
    'adults' => max(1, RequestCoerce::int($_REQUEST, 'adults', 2)),
    'children' => max(0, RequestCoerce::int($_REQUEST, 'children')),
    'children_ages' => mb_substr(RequestCoerce::string($_REQUEST, 'children_ages'), 0, 100),
    'contact_email' => mb_substr($contact_email, 0, 255),
    'contact_phone' => mb_substr(trim(RequestCoerce::string($_REQUEST, 'contact_phone')), 0, 50),
    'notes' => strip_tags(mb_substr(RequestCoerce::string($_REQUEST, 'notes'), 0, 1000)),
    // Internal-only follow-up: there is deliberately no provider API request
    // behind this record, so it starts in the manual-handling status.
    'status' => 'pending_manual',
];

try {
    (new AlternativeRequestRepository())->create($request);
    (new AlternativeRequestNotifier())->notifyAdmin($request);

    if ($sessionUsable) {
        $session['sphinx_alt_request_at'] = time();
    }

    fn_set_notification('N', __('notice'), __('travel_core.request_received', ['[default]' => 'Your request was recorded. We will contact you shortly.']));
} catch (\Throwable $e) {
    fn_log_event('general', 'runtime', ['message' => 'Sphinx alternative request failed: ' . $e->getMessage()]);
    fn_set_notification('E', __('error'), __('travel_core.request_failed', ['[default]' => 'Could not record your request. Please try again.']));
}

return [CONTROLLER_STATUS_REDIRECT, $back_url];

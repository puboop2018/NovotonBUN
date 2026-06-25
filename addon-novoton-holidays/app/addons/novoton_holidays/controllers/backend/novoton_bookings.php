<?php
declare(strict_types=1);
/**
 * Novoton Bookings Admin Controller (DEPRECATED)
 *
 * This controller is kept for backward compatibility. All booking management
 * is now handled by the unified travel_bookings controller via
 * BookingAdminProviderInterface::handleAction().
 *
 * All routes redirect to travel_bookings with the appropriate provider context.
 *
 * @deprecated Use dispatch=travel_bookings.* instead
 */

use Tygh\Tygh;
use Tygh\Addons\TravelCore\Services\TravelProviderRegistry;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

if (fn_allowed_for('MULTIVENDOR') || (defined('RESTRICTED_ADMIN') && RESTRICTED_ADMIN)) {
    return [CONTROLLER_STATUS_DENIED];
}

/**
 * Validate return_url to prevent open redirects.
 *
 * @param string $url
 * @return string
 */
function _nvt_validate_return_url($url)
{
    if (empty($url)) {
        return '';
    }
    $parsed = parse_url($url);
    if (!empty($parsed['scheme']) || !empty($parsed['host'])) {
        if (!empty($parsed['host']) && $parsed['host'] !== ($_SERVER['HTTP_HOST'] ?? '')) {
            return '';
        }
    }
    return $url;
}

// ── POST: delegate to unified controller via BookingAdminProvider ──

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adminProvider = TravelProviderRegistry::getBookingAdminProvider('novoton');

    if ($adminProvider !== null) {
        $result = $adminProvider->handleAction(TypeCoerce::toString($mode), TypeCoerce::toStringMap($_REQUEST));

        if (!empty($result['notification'])) {
            $n = $result['notification'];
            fn_set_notification($n['type'], $n['title'], $n['message']);
        }

        $redirect = $result['redirect'];
        return [CONTROLLER_STATUS_REDIRECT, $redirect];
    }

    return [CONTROLLER_STATUS_REDIRECT, 'travel_bookings.manage&provider=novoton'];
}

// ── GET: redirect to unified controller ──

if ($mode === 'manage') {
    return [CONTROLLER_STATUS_REDIRECT, 'travel_bookings.manage&provider=novoton'];
}

if ($mode === 'view') {
    $novBookingId = RequestCoerce::int($_REQUEST, 'booking_id');
    // novoton_bookings.booking_id ≠ travel_bookings.booking_id (separate auto-increments).
    // The travel_bookings row stores the novoton ID in provider_booking_id.
    $travelBookingId = TypeCoerce::toInt(db_get_field(
        "SELECT booking_id FROM ?:travel_bookings WHERE provider = 'novoton' AND provider_booking_id = ?s",
        (string) $novBookingId
    ));
    if ($travelBookingId > 0) {
        return [CONTROLLER_STATUS_REDIRECT, 'travel_bookings.view&booking_id=' . $travelBookingId];
    }
    fn_set_notification('W', __('warning'), 'Booking #' . $novBookingId . ' has no unified record yet.');
    return [CONTROLLER_STATUS_REDIRECT, 'travel_bookings.manage&provider=novoton'];
}

if ($mode === 'alternatives') {
    $novBookingId = RequestCoerce::int($_REQUEST, 'booking_id');
    $travelBookingId = TypeCoerce::toInt(db_get_field(
        "SELECT booking_id FROM ?:travel_bookings WHERE provider = 'novoton' AND provider_booking_id = ?s",
        (string) $novBookingId
    ));
    if ($travelBookingId > 0) {
        return [CONTROLLER_STATUS_REDIRECT, 'travel_bookings.view&booking_id=' . $travelBookingId . '&tab=alternatives'];
    }
    return [CONTROLLER_STATUS_REDIRECT, 'travel_bookings.manage&provider=novoton'];
}

if ($mode === 'order_tab') {
    // AJAX content for order page tab — keep functional for backward compat
    $order_id = RequestCoerce::int($_REQUEST, 'order_id');

    if ($order_id > 0) {
        $bookings = fn_novoton_holidays_get_order_bookings($order_id);

        foreach ($bookings as &$booking) {
            if (!empty($booking['guests_data'])) {
                $booking['guests'] = (new \Tygh\Addons\TravelCore\Services\GuestDataNormalizer())->normalize(TypeCoerce::toString($booking['guests_data']));
            }
            if (!empty($booking['alternatives_data'])) {
                $booking['alternatives'] = json_decode(TypeCoerce::toString($booking['alternatives_data']), true);
            }
        }

        /** @var \Smarty $view */
        $view = Tygh::$app['view'];
        $view->assign('bookings', $bookings);
        $view->assign('order_id', $order_id);
    }
}

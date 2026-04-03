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
use Tygh\Addons\TravelCore\Services\GuestDataNormalizer;
use Tygh\Addons\TravelCore\Services\TravelProviderRegistry;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

if (fn_allowed_for('MULTIVENDOR') || (defined('RESTRICTED_ADMIN') && RESTRICTED_ADMIN)) {
    return [CONTROLLER_STATUS_DENIED];
}

/**
 * Validate return_url to prevent open redirects.
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
        $result = $adminProvider->handleAction($mode, $_REQUEST);

        if (!empty($result['notification'])) {
            $n = $result['notification'];
            fn_set_notification($n['type'] ?? 'N', $n['title'] ?? '', $n['message'] ?? '');
        }

        $redirect = $result['redirect'] ?? 'travel_bookings.manage&provider=novoton';
        return [CONTROLLER_STATUS_REDIRECT, $redirect];
    }

    return [CONTROLLER_STATUS_REDIRECT, 'travel_bookings.manage&provider=novoton'];
}

// ── GET: redirect to unified controller ──

if ($mode === 'manage') {
    return [CONTROLLER_STATUS_REDIRECT, 'travel_bookings.manage&provider=novoton'];
}

if ($mode === 'view') {
    $bookingId = (int) ($_REQUEST['booking_id'] ?? 0);
    return [CONTROLLER_STATUS_REDIRECT, 'travel_bookings.view&booking_id=' . $bookingId];
}

if ($mode === 'alternatives') {
    $bookingId = (int) ($_REQUEST['booking_id'] ?? 0);
    return [CONTROLLER_STATUS_REDIRECT, 'travel_bookings.view&booking_id=' . $bookingId . '&tab=alternatives'];
}

if ($mode === 'order_tab') {
    // AJAX content for order page tab — keep functional for backward compat
    $order_id = (int) ($_REQUEST['order_id'] ?? 0);

    if ($order_id > 0) {
        $bookings = fn_novoton_holidays_get_order_bookings($order_id);

        foreach ($bookings as &$booking) {
            if (!empty($booking['guests_data'])) {
                $booking['guests'] = (new \Tygh\Addons\TravelCore\Services\GuestDataNormalizer())->normalize($booking['guests_data']);
            }
            if (!empty($booking['alternatives_data'])) {
                $booking['alternatives'] = json_decode($booking['alternatives_data'], true);
            }
        }

        Tygh::$app['view']->assign('bookings', $bookings);
        Tygh::$app['view']->assign('order_id', $order_id);
    }
}

<?php
declare(strict_types=1);
/**
 * Travel Core - Admin Booking Management Controller
 *
 * Provides a unified admin view of all travel bookings across providers.
 * Reads from the shared `travel_bookings` table.
 *
 * Modes:
 *   - manage (default): List all bookings with filters
 *   - view: View a single booking's details
 *
 * @package TravelCore
 * @since 1.0.0
 */

use Tygh\Tygh;
use Tygh\Registry;
use Tygh\Addons\TravelCore\Services\TravelProviderRegistry;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

if (fn_allowed_for('MULTIVENDOR') || (defined('RESTRICTED_ADMIN') && RESTRICTED_ADMIN)) {
    return [CONTROLLER_STATUS_DENIED];
}

// CS-Cart auto-sets $mode from dispatch URL (e.g., dispatch=travel_bookings.manage → $mode = 'manage')
// Do NOT overwrite $mode from $_REQUEST — that causes 404 errors.

if ($mode === 'manage') {
    // Filters
    $params = [
        'provider'  => $_REQUEST['provider'] ?? '',
        'status'    => $_REQUEST['status'] ?? '',
        'order_id'  => (int)($_REQUEST['order_id'] ?? 0),
        'hotel_name'=> $_REQUEST['hotel_name'] ?? '',
        'date_from' => $_REQUEST['date_from'] ?? '',
        'date_to'   => $_REQUEST['date_to'] ?? '',
        'page'      => (int)($_REQUEST['page'] ?? 1),
        'items_per_page' => (int)($_REQUEST['items_per_page'] ?? 20),
    ];

    $condition = '';
    if (!empty($params['provider'])) {
        $condition .= db_quote(" AND tb.provider = ?s", $params['provider']);
    }
    if (!empty($params['status'])) {
        $condition .= db_quote(" AND tb.status = ?s", $params['status']);
    }
    if (!empty($params['order_id'])) {
        $condition .= db_quote(" AND tb.order_id = ?i", $params['order_id']);
    }
    if (!empty($params['hotel_name'])) {
        $escapedName = addcslashes($params['hotel_name'], '%_\\');
        $condition .= db_quote(" AND tb.hotel_name LIKE ?l", '%' . $escapedName . '%');
    }
    if (!empty($params['date_from'])) {
        $condition .= db_quote(" AND tb.check_in >= ?s", $params['date_from']);
    }
    if (!empty($params['date_to'])) {
        $condition .= db_quote(" AND tb.check_in <= ?s", $params['date_to']);
    }

    $total = (int)db_get_field(
        "SELECT COUNT(*) FROM ?:travel_bookings tb WHERE 1 ?p",
        $condition
    );

    $limit = $params['items_per_page'];
    $offset = ($params['page'] - 1) * $limit;

    $bookings = db_get_array(
        "SELECT tb.* FROM ?:travel_bookings tb
         WHERE 1 ?p
         ORDER BY tb.created_at DESC
         LIMIT ?i, ?i",
        $condition, $offset, $limit
    );

    // Get registered providers for filter dropdown
    $providers = TravelProviderRegistry::all();

    Tygh::$app['view']->assign('bookings', $bookings);
    Tygh::$app['view']->assign('search', $params);
    Tygh::$app['view']->assign('total_items', $total);
    Tygh::$app['view']->assign('providers', $providers);

} elseif ($mode === 'view') {
    $booking_id = (int)($_REQUEST['booking_id'] ?? 0);

    if (empty($booking_id)) {
        return [CONTROLLER_STATUS_NO_PAGE];
    }

    $booking = db_get_row(
        "SELECT * FROM ?:travel_bookings WHERE booking_id = ?i",
        $booking_id
    );

    if (empty($booking)) {
        return [CONTROLLER_STATUS_NO_PAGE];
    }

    Tygh::$app['view']->assign('booking', $booking);
}

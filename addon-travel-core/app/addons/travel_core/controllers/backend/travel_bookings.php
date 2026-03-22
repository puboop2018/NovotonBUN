<?php
declare(strict_types=1);
/**
 * Travel Core - Unified Booking Management Controller
 *
 * Provides a unified admin view of all travel bookings across providers.
 * Reads from the shared `travel_bookings` table and enriches each booking
 * with provider-specific display data and actions via BookingAdminProviderInterface.
 *
 * Modes:
 *   - manage (default): List all bookings with filters
 *   - view: View a single booking's details
 *   - bulk_check_status (POST): Trigger status sync for a provider
 *   - check_status (POST): Check status of a single booking
 *
 * @package TravelCore
 * @since 1.0.0
 */

use Tygh\Tygh;
use Tygh\Registry;
use Tygh\Addons\TravelCore\TravelConstants;
use Tygh\Addons\TravelCore\Services\TravelProviderRegistry;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

if (fn_allowed_for('MULTIVENDOR') || (defined('RESTRICTED_ADMIN') && RESTRICTED_ADMIN)) {
    return [CONTROLLER_STATUS_DENIED];
}

// CS-Cart auto-sets $mode from dispatch URL (e.g., dispatch=travel_bookings.manage → $mode = 'manage')
// Do NOT overwrite $mode from $_REQUEST — that causes 404 errors.

/**
 * Enrich a booking row with provider-specific display data and actions.
 *
 * Uses the BookingAdminProviderInterface registered for the booking's provider.
 */
function _travel_bookings_enrich(array $booking): array
{
    $providerName = $booking['provider'] ?? '';
    if (empty($providerName)) {
        return $booking;
    }

    $adminProvider = TravelProviderRegistry::getBookingAdminProvider($providerName);
    if ($adminProvider === null) {
        return $booking;
    }

    $providerBookingId = (string) ($booking['provider_booking_id'] ?? $booking['booking_id'] ?? '');
    if (empty($providerBookingId)) {
        return $booking;
    }

    // Get provider-specific display data (status labels, refs, etc.)
    $booking['provider_display'] = $adminProvider->getDisplayData($providerBookingId);

    // Get available actions (check status, alternatives, etc.)
    $booking['provider_actions'] = $adminProvider->getAvailableActions($booking);

    // Get link to provider's own detailed view
    $booking['provider_view_url'] = $adminProvider->getProviderViewUrl($providerBookingId);

    return $booking;
}

// ── POST modes ──

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($mode === 'bulk_check_status') {
        $provider = $_REQUEST['provider'] ?? '';
        if (!empty($provider)) {
            $providerInfo = TravelProviderRegistry::get($provider);
            if ($providerInfo && !empty($providerInfo['status_sync_callback'])) {
                $result = call_user_func($providerInfo['status_sync_callback']);
                if (is_array($result)) {
                    fn_set_notification('N', __('notice'),
                        "Status sync for {$provider}: {$result['checked']} checked, {$result['changed']} changed."
                    );
                }
            } else {
                fn_set_notification('W', __('warning'), "Provider '{$provider}' does not support status sync.");
            }
        }
        return [CONTROLLER_STATUS_REDIRECT, 'travel_bookings.manage'];
    }

    if ($mode === 'check_status') {
        $booking_id = (int)($_REQUEST['booking_id'] ?? 0);
        if ($booking_id > 0) {
            $booking = db_get_row("SELECT provider, provider_booking_id FROM ?:travel_bookings WHERE booking_id = ?i", $booking_id);
            if ($booking) {
                $providerInfo = TravelProviderRegistry::get($booking['provider']);
                if ($providerInfo && !empty($providerInfo['single_status_callback'])) {
                    $result = call_user_func($providerInfo['single_status_callback'], $booking_id);
                    if (!empty($result['changed'])) {
                        fn_set_notification('N', __('notice'), "Status updated: {$result['old_status']} → {$result['new_status']}");
                    } else {
                        fn_set_notification('N', __('notice'), 'No status change detected.');
                    }
                } else {
                    // Fallback: try BookingAdminProvider directly
                    $adminProvider = TravelProviderRegistry::getBookingAdminProvider($booking['provider']);
                    if ($adminProvider) {
                        $pbId = (string) ($booking['provider_booking_id'] ?? $booking_id);
                        $result = $adminProvider->checkStatus($pbId);
                        if (!empty($result['changed'])) {
                            fn_set_notification('N', __('notice'), "Status updated: {$result['old_status']} → {$result['new_status']}");
                        } else {
                            fn_set_notification('N', __('notice'), $result['error'] ?? 'No status change detected.');
                        }
                    }
                }
            }
        }
        return [CONTROLLER_STATUS_REDIRECT, 'travel_bookings.manage'];
    }
}

// ── GET modes ──

if ($mode === 'manage') {
    // Filters
    $params = [
        'provider'       => $_REQUEST['provider'] ?? '',
        'status'         => $_REQUEST['status'] ?? '',
        'order_id'       => (int)($_REQUEST['order_id'] ?? 0),
        'hotel_name'     => $_REQUEST['hotel_name'] ?? '',
        'date_from'      => $_REQUEST['date_from'] ?? '',
        'date_to'        => $_REQUEST['date_to'] ?? '',
        'sort_by'        => $_REQUEST['sort_by'] ?? 'created_at',
        'sort_order'     => ($_REQUEST['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc',
        'page'           => (int)($_REQUEST['page'] ?? 1),
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
        $condition .= db_quote(" AND tb.hotel_name LIKE ?l", '%' . $params['hotel_name'] . '%');
    }
    if (!empty($params['date_from'])) {
        $condition .= db_quote(" AND tb.check_in >= ?s", $params['date_from']);
    }
    if (!empty($params['date_to'])) {
        $condition .= db_quote(" AND tb.check_in <= ?s", $params['date_to']);
    }

    // Sorting — whitelist allowed columns
    $allowedSortBy = ['created_at', 'order_id', 'check_in', 'total_price', 'hotel_name', 'status'];
    $sortBy = in_array($params['sort_by'], $allowedSortBy, true) ? $params['sort_by'] : 'created_at';
    $sortOrder = $params['sort_order'] === 'asc' ? 'ASC' : 'DESC';

    $total = (int)db_get_field(
        "SELECT COUNT(*) FROM ?:travel_bookings tb WHERE 1 ?p",
        $condition
    );

    $limit = $params['items_per_page'];
    $offset = ($params['page'] - 1) * $limit;

    $bookings = db_get_array(
        "SELECT tb.* FROM ?:travel_bookings tb
         WHERE 1 ?p
         ORDER BY tb.{$sortBy} {$sortOrder}
         LIMIT ?i, ?i",
        $condition, $offset, $limit
    );

    // Enrich each booking with provider-specific display data and actions
    foreach ($bookings as &$booking) {
        $booking = _travel_bookings_enrich($booking);
    }
    unset($booking);

    // Get registered providers for filter dropdown
    $providers = TravelProviderRegistry::all();

    // Status options for filter dropdown
    $statuses = [
        TravelConstants::STATUS_PENDING,
        TravelConstants::STATUS_CONFIRMED,
        TravelConstants::STATUS_CANCELLED,
        TravelConstants::STATUS_FAILED,
    ];

    // Sort order toggle for column headers
    $sort_order_toggle = ($params['sort_order'] === 'asc') ? 'desc' : 'asc';

    Tygh::$app['view']->assign('bookings', $bookings);
    Tygh::$app['view']->assign('search', $params);
    Tygh::$app['view']->assign('total_items', $total);
    Tygh::$app['view']->assign('providers', $providers);
    Tygh::$app['view']->assign('statuses', $statuses);
    Tygh::$app['view']->assign('sort_order_toggle', $sort_order_toggle);

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

    // Enrich with provider-specific data
    $booking = _travel_bookings_enrich($booking);

    // Decode guests JSON for display
    if (!empty($booking['guests_json'])) {
        $decoded = json_decode($booking['guests_json'], true);
        if (is_array($decoded)) {
            $booking['guests_decoded'] = $decoded;
        }
    }

    // Get order info if linked
    if (!empty($booking['order_id']) && (int) $booking['order_id'] > 0) {
        $order = fn_get_order_info((int) $booking['order_id']);
        Tygh::$app['view']->assign('order', $order);
    }

    Tygh::$app['view']->assign('booking', $booking);
}

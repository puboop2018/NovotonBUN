<?php
declare(strict_types=1);
/**
 * Travel Core - Unified Admin Booking Management Controller
 *
 * Provides a unified admin view of all travel bookings across providers
 * (Novoton, Sphinx, etc.) via the shared `travel_bookings` table.
 *
 * Provider-specific display data and actions are delegated through
 * BookingAdminProviderInterface implementations registered in TravelProviderRegistry.
 *
 * Modes:
 *   GET:
 *   - manage (default): List all bookings with filters/sorting/pagination
 *   - view: View a single booking with provider-specific detail panel
 *
 *   POST:
 *   - check_status: Check booking status via provider API
 *   - bulk_check_status: Check all non-terminal bookings for a provider
 *
 * @package TravelCore
 * @since 1.1.0
 */

use Tygh\Tygh;
use Tygh\Registry;
use Tygh\Addons\TravelCore\Services\TravelProviderRegistry;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

$mode = $_REQUEST['mode'] ?? 'manage';

// ── POST actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($mode === 'check_status') {
        $booking_id = (int) ($_REQUEST['booking_id'] ?? 0);

        if ($booking_id > 0) {
            $booking = db_get_row("SELECT * FROM ?:travel_bookings WHERE booking_id = ?i", $booking_id);

            if ($booking && !empty($booking['provider'])) {
                $adminProvider = TravelProviderRegistry::getAdminProvider($booking['provider']);
                if ($adminProvider !== null) {
                    $result = $adminProvider->checkStatus($booking['provider_booking_id'] ?? '');
                    if (!empty($result['changed'])) {
                        fn_set_notification('N', __('notice'),
                            "Status updated: {$result['old_status']} → {$result['new_status']}");
                    } elseif (!empty($result['error'])) {
                        fn_set_notification('W', __('warning'), "Status check failed: {$result['error']}");
                    } else {
                        fn_set_notification('N', __('notice'), 'No status change detected.');
                    }
                } else {
                    fn_set_notification('W', __('warning'), 'No admin provider registered for: ' . $booking['provider']);
                }
            }
        }

        return [CONTROLLER_STATUS_OK, 'travel_bookings.manage'];
    }

    if ($mode === 'bulk_check_status') {
        $provider = $_REQUEST['provider'] ?? '';
        $checked = 0;
        $changed = 0;

        if (!empty($provider)) {
            $adminProvider = TravelProviderRegistry::getAdminProvider($provider);
            if ($adminProvider !== null) {
                $bookings = db_get_array(
                    "SELECT booking_id, provider_booking_id FROM ?:travel_bookings
                     WHERE provider = ?s AND status NOT IN ('cancelled', 'failed')
                       AND order_id > 0 AND created_at > DATE_SUB(NOW(), INTERVAL 90 DAY)",
                    $provider
                );

                foreach ($bookings as $b) {
                    $checked++;
                    $result = $adminProvider->checkStatus($b['provider_booking_id']);
                    if (!empty($result['changed'])) {
                        $changed++;
                    }
                }
            }
        }

        fn_set_notification('N', __('notice'), "Checked {$checked} bookings, {$changed} status changes.");
        return [CONTROLLER_STATUS_OK, 'travel_bookings.manage'];
    }

    if ($mode === 'retry_booking') {
        $booking_id = (int) ($_REQUEST['booking_id'] ?? 0);

        if ($booking_id > 0) {
            $booking = db_get_row("SELECT * FROM ?:travel_bookings WHERE booking_id = ?i", $booking_id);

            if ($booking && $booking['provider'] === 'sphinx') {
                // Delegate retry to Sphinx BookingRetryService
                if (class_exists(\Tygh\Addons\SphinxHolidays\Services\BookingRetryService::class)) {
                    $api = \Tygh\Addons\SphinxHolidays\Services\Container::getApi();
                    $repo = \Tygh\Addons\SphinxHolidays\Services\Container::getBookingRepository();
                    $retryService = new \Tygh\Addons\SphinxHolidays\Services\BookingRetryService($api, $repo);
                    $result = $retryService->retry((int) $booking['provider_booking_id']);

                    if ($result['success']) {
                        fn_set_notification('N', __('notice'), $result['message']);
                    } else {
                        fn_set_notification('W', __('warning'), $result['message']);
                    }
                } else {
                    fn_set_notification('W', __('warning'), 'Sphinx addon not available for retry.');
                }
            } else {
                fn_set_notification('W', __('warning'), 'Retry is only available for Sphinx bookings.');
            }
        }

        return [CONTROLLER_STATUS_OK, 'travel_bookings.manage'];
    }
}

// ── GET modes ──

if ($mode === 'manage') {
    // Filters
    $params = [
        'provider'   => $_REQUEST['provider'] ?? '',
        'status'     => $_REQUEST['status'] ?? '',
        'order_id'   => (int) ($_REQUEST['order_id'] ?? 0),
        'hotel_name' => $_REQUEST['hotel_name'] ?? '',
        'date_from'  => $_REQUEST['date_from'] ?? '',
        'date_to'    => $_REQUEST['date_to'] ?? '',
        'page'       => max(1, (int) ($_REQUEST['page'] ?? 1)),
        'items_per_page' => (int) (Registry::get('settings.Appearance.admin_elements_per_page') ?: 20),
    ];

    // Sorting (whitelist)
    $allowed_sort = ['booking_id', 'order_id', 'check_in', 'created_at', 'hotel_name', 'total_price', 'status'];
    $sort_by = (!empty($_REQUEST['sort_by']) && in_array($_REQUEST['sort_by'], $allowed_sort, true))
        ? $_REQUEST['sort_by'] : 'created_at';
    $sort_order = (!empty($_REQUEST['sort_order']) && strtolower($_REQUEST['sort_order']) === 'asc')
        ? 'ASC' : 'DESC';
    $params['sort_by'] = $sort_by;
    $params['sort_order'] = strtolower($sort_order);
    $sort_order_toggle = ($sort_order === 'ASC') ? 'desc' : 'asc';

    // Build WHERE clause
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

    // Count
    $total = (int) db_get_field(
        "SELECT COUNT(*) FROM ?:travel_bookings tb WHERE 1 ?p",
        $condition
    );

    $limit = $params['items_per_page'];
    $offset = ($params['page'] - 1) * $limit;

    // Fetch bookings
    $bookings = db_get_array(
        "SELECT tb.* FROM ?:travel_bookings tb
         WHERE 1 ?p
         ORDER BY tb.{$sort_by} {$sort_order}
         LIMIT ?i, ?i",
        $condition, $offset, $limit
    );

    // Enrich bookings with provider-specific display data
    foreach ($bookings as &$booking) {
        $booking['provider_display'] = [];
        $booking['provider_actions'] = [];
        $booking['provider_view_url'] = null;

        if (!empty($booking['provider'])) {
            $adminProvider = TravelProviderRegistry::getAdminProvider($booking['provider']);
            if ($adminProvider !== null && !empty($booking['provider_booking_id'])) {
                $booking['provider_display'] = $adminProvider->getDisplayData($booking['provider_booking_id']);
                $booking['provider_actions'] = $adminProvider->getAvailableActions($booking);
                $booking['provider_view_url'] = $adminProvider->getProviderViewUrl($booking['provider_booking_id']);
            }
        }

        // Decode guests_json for display
        if (!empty($booking['guests_json'])) {
            $guests = json_decode($booking['guests_json'], true);
            if (is_array($guests)) {
                $booking['guests_decoded'] = $guests;
            }
        }
    }
    unset($booking);

    // Get registered providers for filter dropdown
    $providers = TravelProviderRegistry::all();

    // Available statuses for filter
    $statuses = ['pending', 'confirmed', 'cancelled', 'failed'];

    Tygh::$app['view']->assign('bookings', $bookings);
    Tygh::$app['view']->assign('search', $params);
    Tygh::$app['view']->assign('total_items', $total);
    Tygh::$app['view']->assign('providers', $providers);
    Tygh::$app['view']->assign('statuses', $statuses);
    Tygh::$app['view']->assign('sort_order_toggle', $sort_order_toggle);

} elseif ($mode === 'view') {
    $booking_id = (int) ($_REQUEST['booking_id'] ?? 0);

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
    $booking['provider_display'] = [];
    $booking['provider_actions'] = [];
    $booking['provider_view_url'] = null;

    if (!empty($booking['provider'])) {
        $adminProvider = TravelProviderRegistry::getAdminProvider($booking['provider']);
        if ($adminProvider !== null && !empty($booking['provider_booking_id'])) {
            $booking['provider_display'] = $adminProvider->getDisplayData($booking['provider_booking_id']);
            $booking['provider_actions'] = $adminProvider->getAvailableActions($booking);
            $booking['provider_view_url'] = $adminProvider->getProviderViewUrl($booking['provider_booking_id']);
        }
    }

    // Decode JSON fields
    if (!empty($booking['guests_json'])) {
        $guests = json_decode($booking['guests_json'], true);
        if (is_array($guests)) {
            $booking['guests_decoded'] = $guests;
        }
    }

    // Get linked order info
    $order = null;
    if (!empty($booking['order_id'])) {
        $order = fn_get_order_info((int) $booking['order_id']);
    }

    Tygh::$app['view']->assign('booking', $booking);
    Tygh::$app['view']->assign('order', $order);
}

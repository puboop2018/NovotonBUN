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
 *   - provider_action (POST): Delegate provider-specific action via handleAction()
 *
 * @package TravelCore
 * @since 1.0.0
 */

use Tygh\Tygh;
use Tygh\Registry;
use Tygh\Addons\TravelCore\TravelConstants;
use Tygh\Addons\TravelCore\Services\TravelProviderRegistry;
use Tygh\Addons\TravelCore\Repository\TravelBookingRepository;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

/** @var \Tygh\Addons\TravelCore\Repository\TravelBookingRepository $bookingRepo */
$bookingRepo = new TravelBookingRepository();

if (fn_allowed_for('MULTIVENDOR') || (defined('RESTRICTED_ADMIN') && RESTRICTED_ADMIN)) {
    return [CONTROLLER_STATUS_DENIED];
}

// CS-Cart auto-sets $mode from dispatch URL (e.g., dispatch=travel_bookings.manage → $mode = 'manage')
// Do NOT overwrite $mode from $_REQUEST — that causes 404 errors.

/**
 * Enrich a booking row with provider-specific display data and actions.
 *
 * Uses the BookingAdminProviderInterface registered for the booking's provider.
 *
 * @param array<string, mixed> $booking
 * @return array<string, mixed>
 */
function _travel_bookings_enrich(array $booking): array
{
    $providerName = TypeCoerce::toString($booking['provider'] ?? '');
    if (empty($providerName)) {
        return $booking;
    }

    $adminProvider = TravelProviderRegistry::getBookingAdminProvider($providerName);
    if ($adminProvider === null) {
        return $booking;
    }

    $providerBookingId = TypeCoerce::toString($booking['provider_booking_id'] ?? $booking['booking_id'] ?? '');
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
        $provider = RequestCoerce::string($_REQUEST, 'provider');
        if (!empty($provider)) {
            $providerInfo = TravelProviderRegistry::get($provider);
            if ($providerInfo !== null && !empty($providerInfo['status_sync_callback'])) {
                $result = call_user_func($providerInfo['status_sync_callback']);
                if (is_array($result)) {
                    $checked = TypeCoerce::toString($result['checked'] ?? $result['processed'] ?? 0);
                    $changed = TypeCoerce::toString($result['changed'] ?? $result['updated'] ?? 0);
                    fn_set_notification('N', __('notice'),
                        "Status sync for {$provider}: {$checked} checked, {$changed} changed."
                    );
                }
            } else {
                fn_set_notification('W', __('warning'), "Provider '{$provider}' does not support status sync.");
            }
        }
        return [CONTROLLER_STATUS_REDIRECT, 'travel_bookings.manage'];
    }

    if ($mode === 'check_status') {
        $booking_id = RequestCoerce::int($_REQUEST, 'booking_id');
        if ($booking_id > 0) {
            $booking = $bookingRepo->getProviderInfo($booking_id);
            if ($booking) {
                $bookingProvider = TypeCoerce::toString($booking['provider'] ?? '');
                $providerInfo = TravelProviderRegistry::get($bookingProvider);
                try {
                    if ($providerInfo !== null && !empty($providerInfo['single_status_callback'])) {
                        $result = call_user_func($providerInfo['single_status_callback'], $booking_id);
                        $resultMap = is_array($result) ? $result : [];
                        if (!empty($resultMap['changed'])) {
                            $oldStatus = TypeCoerce::toString($resultMap['old_status'] ?? '');
                            $newStatus = TypeCoerce::toString($resultMap['new_status'] ?? '');
                            fn_set_notification('N', __('notice'), "Status updated: {$oldStatus} → {$newStatus}");
                        } else {
                            fn_set_notification('N', __('notice'), 'No status change detected.');
                        }
                    } else {
                        // Fallback: try BookingAdminProvider directly
                        $adminProvider = TravelProviderRegistry::getBookingAdminProvider($bookingProvider);
                        if ($adminProvider) {
                            $pbId = TypeCoerce::toString($booking['provider_booking_id'] ?? $booking_id);
                            $result = $adminProvider->checkStatus($pbId);
                            if (!empty($result['changed'])) {
                                $oldStatus = $result['old_status'];
                                $newStatus = $result['new_status'];
                                fn_set_notification('N', __('notice'), "Status updated: {$oldStatus} → {$newStatus}");
                            } else {
                                fn_set_notification('N', __('notice'), $result['error'] ?? 'No status change detected.');
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    fn_set_notification('E', __('error'), 'Status check failed: ' . $e->getMessage());
                    fn_log_event('general', 'runtime', [
                        'message' => 'Booking status check failed',
                        'booking_id' => $booking_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
        return [CONTROLLER_STATUS_REDIRECT, 'travel_bookings.manage'];
    }

    // Provider-specific actions delegated via BookingAdminProviderInterface::handleAction()
    if ($mode === 'provider_action') {
        $provider = RequestCoerce::string($_REQUEST, 'provider');
        $providerAction = RequestCoerce::string($_REQUEST, 'provider_action');

        if (!empty($provider) && !empty($providerAction)) {
            $adminProvider = TravelProviderRegistry::getBookingAdminProvider($provider);
            if ($adminProvider !== null) {
                /** @var array<string, mixed> $_REQUEST */
                $result = $adminProvider->handleAction($providerAction, $_REQUEST);

                if (!empty($result['notification'])) {
                    $n = $result['notification'];
                    fn_set_notification($n['type'], $n['title'], $n['message']);
                }

                $redirect = $result['redirect'];
                return [CONTROLLER_STATUS_REDIRECT, $redirect];
            } else {
                fn_set_notification('W', __('warning'), "No admin provider registered for '{$provider}'.");
            }
        }

        return [CONTROLLER_STATUS_REDIRECT, 'travel_bookings.manage'];
    }
}

// ── GET modes ──

if ($mode === 'manage') {
    // Filters
    $params = [
        'provider'       => RequestCoerce::string($_REQUEST, 'provider'),
        'status'         => RequestCoerce::string($_REQUEST, 'status'),
        'order_id'       => RequestCoerce::int($_REQUEST, 'order_id'),
        'hotel_name'     => RequestCoerce::string($_REQUEST, 'hotel_name'),
        'date_from'      => RequestCoerce::string($_REQUEST, 'date_from'),
        'date_to'        => RequestCoerce::string($_REQUEST, 'date_to'),
        'sort_by'        => RequestCoerce::string($_REQUEST, 'sort_by', 'created_at'),
        'sort_order'     => RequestCoerce::string($_REQUEST, 'sort_order', 'desc') === 'asc' ? 'asc' : 'desc',
        'page'           => RequestCoerce::int($_REQUEST, 'page', 1),
        'items_per_page' => RequestCoerce::int($_REQUEST, 'items_per_page', 20),
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

    // Sorting — explicit column mapping prevents interpolation
    $sortColumnMap = [
        'created_at'  => 'tb.created_at',
        'order_id'    => 'tb.order_id',
        'check_in'    => 'tb.check_in',
        'total_price' => 'tb.total_price',
        'hotel_name'  => 'tb.hotel_name',
        'status'      => 'tb.status',
    ];
    $sortColumn = $sortColumnMap[$params['sort_by']] ?? 'tb.created_at';
    $sortOrder = $params['sort_order'] === 'asc' ? 'ASC' : 'DESC';

    $limit = $params['items_per_page'];
    $offset = ($params['page'] - 1) * $limit;

    $paginatedResult = $bookingRepo->getPaginated($condition, $sortColumn, $sortOrder, $offset, $limit);
    $total = $paginatedResult['total'];
    $bookings = $paginatedResult['items'];

    // Enrich each booking with provider-specific display data and actions.
    // Also pre-format dates in PHP — the list template wraps its rows in
    // {capture name="mainbox"} and the Smarty |date_format modifier throws under
    // Smarty 5 / PHP 8.3, surfacing as "Not matching {capture}{/capture}".
    foreach ($bookings as &$booking) {
        $booking = _travel_bookings_enrich($booking);
        $ci = TypeCoerce::toString($booking['check_in'] ?? '');
        $co = TypeCoerce::toString($booking['check_out'] ?? '');
        $ci_ts = $ci !== '' ? strtotime($ci) : false;
        $co_ts = $co !== '' ? strtotime($co) : false;
        $booking['check_in_short']  = $ci_ts !== false ? fn_date_format($ci_ts, '%d.%m.%Y') : '';
        $booking['check_out_short'] = $co_ts !== false ? fn_date_format($co_ts, '%d.%m.%Y') : '';
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

    /** @var \Smarty $view */
    $view = Tygh::$app['view'];
    $view->assign('bookings', $bookings);
    $view->assign('search', $params);
    $view->assign('total_items', $total);
    $view->assign('providers', $providers);
    $view->assign('statuses', $statuses);
    $view->assign('sort_order_toggle', $sort_order_toggle);

} elseif ($mode === 'view') {
    $booking_id = RequestCoerce::int($_REQUEST, 'booking_id');

    if (empty($booking_id)) {
        return [CONTROLLER_STATUS_NO_PAGE];
    }

    $booking = $bookingRepo->getById($booking_id);

    if (empty($booking)) {
        return [CONTROLLER_STATUS_NO_PAGE];
    }

    // Enrich with provider-specific data
    $booking = _travel_bookings_enrich($booking);

    // Decode guests JSON for display
    $guestsJson = TypeCoerce::toString($booking['guests_json'] ?? '');
    if (!empty($guestsJson)) {
        $decoded = json_decode($guestsJson, true);
        if (is_array($decoded)) {
            $booking['guests_decoded'] = $decoded;
        }
    }

    // Pre-format check-in/check-out dates in PHP. The view template must NOT use
    // the Smarty |date_format modifier inside its {capture name="mainbox"} block:
    // under Smarty 5 / PHP 8.3 it throws, leaving the capture unclosed and
    // surfacing as the masked "Not matching {capture}{/capture}" crash.
    // fn_date_format is the blessed safe path.
    $ci = TypeCoerce::toString($booking['check_in'] ?? '');
    $co = TypeCoerce::toString($booking['check_out'] ?? '');
    $ci_ts = $ci !== '' ? strtotime($ci) : false;
    $co_ts = $co !== '' ? strtotime($co) : false;
    $booking['check_in_short']  = $ci_ts !== false ? fn_date_format($ci_ts, '%d.%m.%Y') : '';
    $booking['check_out_short'] = $co_ts !== false ? fn_date_format($co_ts, '%d.%m.%Y') : '';

    // Build provider-display rows in PHP so the template needs no PHP-function
    // calls (is_array/json_encode). Under Smarty 5 a PHP function call inside the
    // {capture name="mainbox"} block fails and surfaces as the masked
    // "Not matching {capture}{/capture}" crash. We pre-flatten each value here:
    //   - arrays → pretty-printed JSON, flagged is_pre so the template wraps in <pre>
    //   - scalars → plain string
    $providerDisplay = is_array($booking['provider_display'] ?? null) ? $booking['provider_display'] : [];
    $providerDisplayRows = [];
    foreach ($providerDisplay as $field => $value) {
        // Skip internal fields used for rendering elsewhere
        if ($field === 'status_label' || $field === 'provider_ref') {
            continue;
        }
        $isArr = is_array($value);
        $providerDisplayRows[] = [
            'label'   => ucfirst(str_replace('_', ' ', (string) $field)),
            'is_pre'  => $isArr,
            'display' => $isArr
                ? (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                : TypeCoerce::toString($value),
        ];
    }
    $booking['provider_display_rows'] = $providerDisplayRows;

    // Pre-format the total price in PHP so the template carries no non-builtin
    // Smarty modifiers inside its {capture} block.
    $booking['total_price_formatted'] = number_format(
        TypeCoerce::toFloat($booking['total_price'] ?? 0),
        2
    );

    // Get order info if linked
    $orderId = TypeCoerce::toInt($booking['order_id'] ?? 0);
    if ($orderId > 0) {
        $order = fn_get_order_info($orderId);
        /** @var \Smarty $view */
        $view = Tygh::$app['view'];
        $view->assign('order', $order);
    }

    // Pre-format dates — Smarty |date_format throws inside {capture} under Smarty 5 / PHP 8.3
    $ci_ts = !empty($booking['check_in'])  ? strtotime(TypeCoerce::toString($booking['check_in']))  : false;
    $co_ts = !empty($booking['check_out']) ? strtotime(TypeCoerce::toString($booking['check_out'])) : false;
    $booking['check_in_short']  = $ci_ts !== false ? fn_date_format($ci_ts, '%d.%m.%Y') : '';
    $booking['check_out_short'] = $co_ts !== false ? fn_date_format($co_ts, '%d.%m.%Y') : '';

    // Pre-format total price — |number_format modifier also throws inside {capture}
    $booking['total_price_formatted'] = number_format(TypeCoerce::toFloat($booking['total_price'] ?? 0), 2);

    // Pre-flatten provider_display into simple rows — avoids is_array()/json_encode in template
    $providerDisplay = is_array($booking['provider_display'] ?? null) ? $booking['provider_display'] : [];
    $providerDisplayRows = [];
    foreach ($providerDisplay as $field => $value) {
        if ($field === 'status_label' || $field === 'provider_ref') {
            continue;
        }
        $isArr = is_array($value);
        $providerDisplayRows[] = [
            'label'   => ucfirst(str_replace('_', ' ', (string) $field)),
            'is_pre'  => $isArr,
            'display' => $isArr
                ? (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                : TypeCoerce::toString($value),
        ];
    }
    $booking['provider_display_rows'] = $providerDisplayRows;

    // Get provider-specific tabs
    $providerTabs = [];
    $providerName = TypeCoerce::toString($booking['provider'] ?? '');
    if (!empty($providerName)) {
        $adminProvider = TravelProviderRegistry::getBookingAdminProvider($providerName);
        if ($adminProvider !== null) {
            $providerTabs = $adminProvider->getProviderTabs($booking);
        }
    }

    /** @var \Smarty $view */
    $view = Tygh::$app['view'];
    $view->assign('booking', $booking);
    $view->assign('provider_tabs', $providerTabs);
}

<?php
declare(strict_types=1);
/**
 * Novoton Bookings Admin Controller
 * Handles booking management, status checking, and alternatives
 * 
 * Path: app/addons/novoton_holidays/controllers/backend/novoton_bookings.php
 */

use Tygh\Registry;
use Tygh\Tygh;
use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\TravelCore\Services\GuestDataNormalizer;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

// Default mode - redirect to manage
if (empty($mode)) {
    $mode = 'manage';
}

/**
 * Validate return_url to prevent open redirects.
 * Only allows relative URLs or same-origin URLs.
 */
function _nvt_validate_return_url($url)
{
    if (empty($url)) {
        return '';
    }
    // Block absolute URLs with a different host
    $parsed = parse_url($url);
    if (!empty($parsed['scheme']) || !empty($parsed['host'])) {
        // Absolute URL — only allow if same host
        if (!empty($parsed['host']) && $parsed['host'] !== ($_SERVER['HTTP_HOST'] ?? '')) {
            return '';
        }
    }
    return $url;
}

// Check permissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Check reservation status
    if ($mode === 'resinfo') {
        $booking_id = isset($_REQUEST['booking_id']) ? (int)($_REQUEST['booking_id']) : 0;
        
        $results = fn_novoton_holidays_check_reservation_status($booking_id);
        
        fn_set_notification('N', __('notice'), __('novoton_holidays.status_checked'));

        $return_url = _nvt_validate_return_url($_REQUEST['return_url'] ?? '');
        if (!empty($return_url)) {
            return array(CONTROLLER_STATUS_REDIRECT, $return_url);
        }
        return array(CONTROLLER_STATUS_OK, 'novoton_bookings.manage');
    }
    
    // Request alternatives
    if ($mode === 'request_alternatives') {
        $booking_id = isset($_REQUEST['booking_id']) ? (int)($_REQUEST['booking_id']) : 0;
        
        if ($booking_id > 0) {
            $result = fn_novoton_holidays_request_alternatives($booking_id);

            if (!empty($result['success'])) {
                fn_set_notification('N', __('notice'), __('novoton_holidays.alternatives_found', ['[count]' => 1]));
            } else {
                fn_set_notification('W', __('warning'), __('novoton_holidays.no_alternatives'));
            }
        }
        
        $return_url = _nvt_validate_return_url($_REQUEST['return_url'] ?? '');
        if (!empty($return_url)) {
            return array(CONTROLLER_STATUS_REDIRECT, $return_url);
        }
        return array(CONTROLLER_STATUS_OK, 'novoton_bookings.manage');
    }
    
    // Check all ASK bookings (bulk)
    if ($mode === 'check_all_status') {
        $results = fn_novoton_holidays_cron_resinfo();
        
        fn_set_notification('N', __('notice'), 
            __('novoton_holidays.bulk_status_checked', [
                '[checked]' => $results['checked'] ?? 0,
                '[changed]' => $results['changed'] ?? 0
            ])
        );
        
        return array(CONTROLLER_STATUS_OK, 'novoton_bookings.manage');
    }
    
    // Cleanup orphan bookings (no order_id and older than 24 hours)
    // With single source of truth architecture, orphans are simply abandoned cart bookings
    if ($mode === 'cleanup_orphans') {
        $bookingRepo = Container::getInstance()->bookingRepository();
        $count = $bookingRepo->countOrphans(24);

        if ($count > 0) {
            $bookingRepo->deleteOrphans(24);
            fn_set_notification('N', __('notice'),
                "Cleaned up {$count} orphan booking(s) older than 24 hours.");
        } else {
            fn_set_notification('N', __('notice'),
                "No orphan bookings to clean up.");
        }

        return array(CONTROLLER_STATUS_OK, 'novoton_bookings.manage');
    }
    
    // Update Novoton ID manually (Issue 7.8)
    if ($mode === 'update_novoton_id') {
        $booking_id = isset($_REQUEST['booking_id']) ? (int)($_REQUEST['booking_id']) : 0;
        $novoton_invoice_id = isset($_REQUEST['novoton_invoice_id']) ? preg_replace('/[^a-zA-Z0-9\-_]/', '', trim($_REQUEST['novoton_invoice_id'])) : '';
        
        if ($booking_id > 0) {
            $bookingRepo = Container::getInstance()->bookingRepository();
            $bookingRepo->update($booking_id, ['novoton_invoice_id' => $novoton_invoice_id]);
            
            fn_set_notification('N', __('notice'), 'Novoton ID updated');
            
            // If ID provided, check status
            if (!empty($novoton_invoice_id)) {
                fn_novoton_holidays_check_reservation_status($booking_id);
            }
        }
        
        return array(CONTROLLER_STATUS_REDIRECT, 'novoton_bookings.view?booking_id=' . $booking_id);
    }
}

// GET modes
if ($mode === 'manage') {
    $bookingRepo = Container::getInstance()->bookingRepository();

    // List all bookings from novoton_bookings (single source of truth)
    $allowed_params = ['page', 'sort_by', 'sort_order', 'status', 'order_id', 'hotel_id', 'novoton_status', 'check_in_from', 'check_in_to', 'show_orphans'];
    $params = array_intersect_key($_REQUEST, array_flip($allowed_params));
    $params['items_per_page'] = Registry::get('settings.Appearance.admin_elements_per_page');

    // Sorting toggle for template (whitelist allowed sort columns)
    $allowed_sort = ['order_id', 'check_in', 'created_at', 'hotel_name', 'status'];
    $sort_by = (!empty($params['sort_by']) && in_array($params['sort_by'], $allowed_sort, true)) ? $params['sort_by'] : 'order_id';
    $sort_order = (!empty($params['sort_order']) && strtolower($params['sort_order']) === 'asc') ? 'ASC' : 'DESC';
    $sort_order_toggle = ($sort_order === 'ASC') ? 'desc' : 'asc';
    Tygh::$app['view']->assign('sort_order_toggle', $sort_order_toggle);

    // Get unified bookings from novoton_bookings table
    $bookings = $bookingRepo->getUnifiedBookings($params);

    // Apply sorting
    if ($sort_by === 'check_in') {
        usort($bookings, function($a, $b) use ($sort_order) {
            $cmp = strcmp($a['check_in'] ?? '', $b['check_in'] ?? '');
            return $sort_order === 'ASC' ? $cmp : -$cmp;
        });
    } elseif ($sort_by === 'created_at') {
        usort($bookings, function($a, $b) use ($sort_order) {
            $cmp = strcmp($a['created_at'] ?? '', $b['created_at'] ?? '');
            return $sort_order === 'ASC' ? $cmp : -$cmp;
        });
    } else {
        // Default: order_id DESC
        usort($bookings, function($a, $b) use ($sort_order) {
            $cmp = ((int)($a['order_id'] ?? 0)) <=> ((int)($b['order_id'] ?? 0));
            return $sort_order === 'ASC' ? $cmp : -$cmp;
        });
    }

    // Simple pagination
    $total = count($bookings);
    $params['total_items'] = $total;
    $page = max(1, (int)($params['page'] ?? 1));
    $per_page = (int)($params['items_per_page'] ?? 30);
    $offset = ($page - 1) * $per_page;
    $bookings = array_slice($bookings, $offset, $per_page);

    Tygh::$app['view']->assign('bookings', $bookings);
    Tygh::$app['view']->assign('search', $params);

} elseif ($mode === 'view') {
    // View single booking details
    $booking_id = isset($_REQUEST['booking_id']) ? (int)($_REQUEST['booking_id']) : 0;

    if ($booking_id > 0) {
        $bookingRepo = Container::getInstance()->bookingRepository();
        $booking = $bookingRepo->findById($booking_id);
        
        if ($booking) {
            // Parse JSON fields
            if (!empty($booking['guests_data'])) {
                $booking['guests'] = (new GuestDataNormalizer())->normalize($booking['guests_data']);
            }
            if (!empty($booking['alternatives_data'])) {
                $booking['alternatives'] = json_decode($booking['alternatives_data'], true);
            }
            if (!empty($booking['api_request'])) {
                $booking['api_request_data'] = json_decode($booking['api_request'], true);
            }
            if (!empty($booking['api_response'])) {
                $booking['api_response_data'] = json_decode($booking['api_response'], true);
            }
            
            // Get order info
            $order = fn_get_order_info($booking['order_id']);
            
            Tygh::$app['view']->assign('booking', $booking);
            Tygh::$app['view']->assign('order', $order);
        }
    }
    
} elseif ($mode === 'alternatives') {
    // View alternatives for a booking
    $booking_id = isset($_REQUEST['booking_id']) ? (int)($_REQUEST['booking_id']) : 0;
    
    if ($booking_id > 0) {
        $bookingRepo = Container::getInstance()->bookingRepository();
        $hotelRepo = Container::getInstance()->hotelRepository();
        $booking = $bookingRepo->findById($booking_id);

        if ($booking) {
            $alternatives = fn_novoton_holidays_get_alternatives($booking_id);

            // Enrich alternatives with hotel names
            if ($alternatives) {
                foreach ($alternatives as &$alt) {
                    $hotel = $hotelRepo->findBasicById($alt['hotel_id']);
                    if ($hotel) {
                        $alt['hotel_name'] = $hotel['hotel_name'];
                        $alt['hotel_city'] = $hotel['city'];
                        $alt['hotel_country'] = $hotel['country'];
                    }
                }
            }
            
            Tygh::$app['view']->assign('booking', $booking);
            Tygh::$app['view']->assign('alternatives', $alternatives);
        }
    }
    
} elseif ($mode === 'order_tab') {
    // AJAX content for order page tab
    $order_id = isset($_REQUEST['order_id']) ? (int)($_REQUEST['order_id']) : 0;
    
    if ($order_id > 0) {
        $bookings = fn_novoton_holidays_get_order_bookings($order_id);
        
        foreach ($bookings as &$booking) {
            if (!empty($booking['guests_data'])) {
                $booking['guests'] = (new GuestDataNormalizer())->normalize($booking['guests_data']);
            }
            if (!empty($booking['alternatives_data'])) {
                $booking['alternatives'] = json_decode($booking['alternatives_data'], true);
            }
        }
        
        Tygh::$app['view']->assign('bookings', $bookings);
        Tygh::$app['view']->assign('order_id', $order_id);
    }
}

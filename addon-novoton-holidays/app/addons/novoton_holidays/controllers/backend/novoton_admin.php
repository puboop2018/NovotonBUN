<?php
declare(strict_types=1);
/**
 * Novoton Admin Controller
 * Path: app/addons/novoton_holidays/controllers/backend/novoton_admin.php
 */

use Tygh\Registry;
use Tygh\Tygh;
use Tygh\Addons\NovotonHolidays\PriceInfoSync;
use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;


if (!defined('BOOTSTRAP')) { exit('Access denied'); }

if (fn_allowed_for('MULTIVENDOR') || (defined('RESTRICTED_ADMIN') && RESTRICTED_ADMIN)) {
    return [CONTROLLER_STATUS_DENIED];
}

// Update prices manually
if ($mode === 'update_prices') {
    
    if (!empty($_REQUEST['single_product']) && !empty($_REQUEST['product_id'])) {
        // Update single product
        $productId = RequestCoerce::int($_REQUEST, 'product_id');
        
        $sync = new PriceInfoSync();
        $stats = [
            'total' => 1,
            'updated' => [],
            'failed' => [],
            'no_data' => [],
            'missing' => []
        ];
        
        $success = $sync->syncProductPrices($productId, $stats);
        
        if ($success) {
            fn_set_notification('N', __('notice'), __('novoton_holidays.product_updated_successfully'));
        } else {
            fn_set_notification('W', __('warning'), __('novoton_holidays.product_update_failed'));
        }
        
        return [CONTROLLER_STATUS_REDIRECT, 'products.update?product_id=' . $productId];
        
    } else {
        // Update all products - use progress bar
        
        Registry::set('runtime.controller', 'novoton_admin');
        Registry::set('runtime.mode', 'update_prices_progress');
        
        // Start the sync process
        fn_set_progress('init', __('novoton_holidays.updating_prices'));
        
        $sync = new PriceInfoSync();
        
        try {
            $stats = $sync->syncAllProducts();

            fn_set_progress('finish');

            // Show summary
            $updatedList = is_array($stats['updated'] ?? null) ? $stats['updated'] : [];
            $failedList = is_array($stats['failed'] ?? null) ? $stats['failed'] : [];
            $noDataList = is_array($stats['no_data'] ?? null) ? $stats['no_data'] : [];
            $message = __('novoton_holidays.sync_completed') . ': ';
            $message .= count($updatedList) . ' ' . __('novoton_holidays.updated') . ', ';
            $message .= count($failedList) . ' ' . __('novoton_holidays.failed') . ', ';
            $message .= count($noDataList) . ' ' . __('novoton_holidays.no_data');
            
            fn_set_notification('N', __('notice'), $message);
            
        } catch (Exception $e) {
            fn_set_progress('error', $e->getMessage());
            fn_set_notification('E', __('error'), __('novoton_holidays.sync_failed') . ': ' . $e->getMessage());
        }
        
        return [CONTROLLER_STATUS_REDIRECT, 'addons.update?addon=novoton_holidays&selected_section=sync'];
    }
}

// View sync logs
if ($mode === 'sync_logs') {

    $syncLogRepo = Container::getInstance()->syncLogRepository();
    $logs = $syncLogRepo->findRecent(50);

    /** @var \Smarty $view */
    $view = Tygh::$app['view'];
    $view->assign('sync_logs', $logs);
}

// View bookings
if ($mode === 'bookings') {

    $condition = '';

    $reqOrderId = RequestCoerce::int($_REQUEST, 'order_id');
    if (!empty($reqOrderId)) {
        $condition .= db_quote(" AND b.order_id = ?i", $reqOrderId);
    }

    $reqStatus = RequestCoerce::string($_REQUEST, 'status');
    $allowed_statuses = ['pending', 'confirmed', 'cancelled', 'failed', 'ASK', 'Good', 'XX'];
    if (!empty($reqStatus) && in_array($reqStatus, $allowed_statuses, true)) {
        $condition .= db_quote(" AND b.status = ?s", $reqStatus);
    }

    $reqDateFrom = RequestCoerce::string($_REQUEST, 'date_from');
    if (!empty($reqDateFrom) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $reqDateFrom)) {
        $condition .= db_quote(" AND b.check_in >= ?s", $reqDateFrom);
    }

    $reqDateTo = RequestCoerce::string($_REQUEST, 'date_to');
    if (!empty($reqDateTo) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $reqDateTo)) {
        $condition .= db_quote(" AND b.check_in <= ?s", $reqDateTo);
    }

    $bookingRepo = Container::getInstance()->bookingRepository();
    $bookings = $bookingRepo->findForAdminList($condition, 500);

    /** @var \Smarty $view */
    $view = Tygh::$app['view'];
    $view->assign('bookings', $bookings);
    $view->assign('search', array_intersect_key($_REQUEST, array_flip(['order_id', 'status', 'date_from', 'date_to', 'dispatch'])));
}

// View booking details
if ($mode === 'booking_details') {

    $bookingId = RequestCoerce::int($_REQUEST, 'booking_id');

    if ($bookingId <= 0) {
        fn_set_notification('E', __('error'), __('novoton_holidays.booking_not_found'));
        return [CONTROLLER_STATUS_REDIRECT, 'novoton_admin.bookings'];
    }

    $bookingRepo = Container::getInstance()->bookingRepository();
    $booking = $bookingRepo->findWithOrderDetails($bookingId);

    if ($booking) {
        // Get invoice from Novoton
        $api = _nvt_api();

        try {
            $invoiceId = TypeCoerce::toString($booking['novoton_invoice_id'] ?? '');
            $invoice = $api->reservations()->getInvoiceXml($invoiceId);
            $booking['invoice'] = $invoice;
        } catch (Exception $e) {
            fn_set_notification('W', __('warning'), __('novoton_holidays.failed_to_get_invoice'));
        }

        /** @var \Smarty $view */
        $view = Tygh::$app['view'];
        $view->assign('booking', $booking);
    } else {
        fn_set_notification('E', __('error'), __('novoton_holidays.booking_not_found'));
        return [CONTROLLER_STATUS_REDIRECT, 'novoton_admin.bookings'];
    }
}

// Download log file
if ($mode === 'download_log') {
    
    $logFile = RequestCoerce::string($_REQUEST, 'log_file');

    // Security: Sanitize filename to prevent path traversal
    $logFile = basename($logFile); // Remove any path components
    $logFile = (string) preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $logFile); // Only allow safe characters

    if (empty($logFile)) {
        fn_set_notification('E', __('error'), __('novoton_holidays.invalid_log_file'));
        return [CONTROLLER_STATUS_REDIRECT, 'novoton_admin.sync_logs'];
    }

    $filesDir = TypeCoerce::toString(fn_get_files_dir_path());
    $logPath = $filesDir . 'novoton_logs/' . $logFile;

    // Security: Verify the resolved path is within the expected directory
    $realLogPath = realpath($logPath);
    $expectedDir = realpath($filesDir . 'novoton_logs/');
    
    if ($realLogPath === false || $expectedDir === false || !str_starts_with($realLogPath, $expectedDir)) {
        fn_set_notification('E', __('error'), __('novoton_holidays.log_file_not_found'));
        return [CONTROLLER_STATUS_REDIRECT, 'novoton_admin.sync_logs'];
    }
    
    if (file_exists($realLogPath)) {
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="' . $logFile . '"');
        header('Content-Length: ' . filesize($realLogPath));
        readfile($realLogPath);
        exit;
    } else {
        fn_set_notification('E', __('error'), __('novoton_holidays.log_file_not_found'));
        return [CONTROLLER_STATUS_REDIRECT, 'novoton_admin.sync_logs'];
    }
}

// Export bookings
if ($mode === 'export_bookings') {
    
    $bookingRepo = Container::getInstance()->bookingRepository();
    $bookings = $bookingRepo->findAllForExport();
    
    // Create CSV
    $csv = "Booking ID,Order ID,Hotel Name,Room Type,Check-in,Check-out,Adults,Children,Price,Currency,Status,Email,Created\n";
    
    foreach ($bookings as $booking) {
        $num_children = TypeCoerce::toInt($booking['children'] ?? 0);
        $csv .= implode(',', [
            TypeCoerce::toString($booking['novoton_invoice_id'] ?? ''),
            TypeCoerce::toString($booking['order_id'] ?? ''),
            '"' . str_replace('"', '""', TypeCoerce::toString($booking['hotel_name'] ?? '')) . '"',
            '"' . str_replace('"', '""', TypeCoerce::toString($booking['room_type'] ?? '')) . '"',
            TypeCoerce::toString($booking['check_in'] ?? ''),
            TypeCoerce::toString($booking['check_out'] ?? ''),
            TypeCoerce::toString($booking['adults'] ?? ''),
            (string) $num_children,
            TypeCoerce::toString($booking['total_price'] ?? ''),
            TypeCoerce::toString($booking['currency'] ?? ''),
            TypeCoerce::toString($booking['status'] ?? ''),
            '"' . str_replace('"', '""', TypeCoerce::toString($booking['email'] ?? '')) . '"',
            TypeCoerce::toString($booking['created_at'] ?? '')
        ]) . "\n";
    }
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="novoton_bookings_' . date('Y-m-d') . '.csv"');
    echo $csv;
    exit;
}

// Test API connection
if ($mode === 'test_api') {
    
    $api = _nvt_api();
    
    try {
        $resorts = $api->destinations()->getResortList('BULGARIA');
        
        if ($resorts && isset($resorts->Resort)) {
            fn_set_notification('N', __('notice'), __('novoton_holidays.api_connection_successful'));
        } else {
            fn_set_notification('W', __('warning'), __('novoton_holidays.api_connection_no_data'));
        }
    } catch (Exception $e) {
        fn_set_notification('E', __('error'), __('novoton_holidays.api_connection_failed') . ': ' . $e->getMessage());
    }
    
    return [CONTROLLER_STATUS_REDIRECT, 'addons.update?addon=novoton_holidays&selected_section=api'];
}

// ================================================
// A73: AJAX handler for running cron tasks from admin
// ================================================
if ($mode === 'run_cron') {
    header('Content-Type: application/json');
    
    // Accept 'cron_mode' or legacy 'task' parameter for the cron job to run
    $cron_mode_raw = RequestCoerce::string($_REQUEST, 'cron_mode') ?: RequestCoerce::string($_REQUEST, 'task');
    // Sanitize: only alphanumeric and underscores
    $cron_mode = (string) preg_replace('/[^a-z0-9_]/', '', strtolower($cron_mode_raw));
    $allowed_modes = [
        'hotel_list', 'room_price', 'list_facilities', 'resort_list',
        'add_hotels_as_products', 'offers_update',
        'resinfo', 'alternative_rs', 'alternative_rs_bookings', 'notify_alternatives',
        'cleanup', 'expire_requests'
    ];
    
    if (!in_array($cron_mode, $allowed_modes, true)) {
        echo json_encode(['success' => false, 'error' => 'Invalid cron mode']);
        exit;
    }
    
    // Build parameters
    $params = ['mode' => $cron_mode];
    $reqCountry = RequestCoerce::string($_REQUEST, 'country');
    if (!empty($reqCountry)) {
        $params['country'] = strtoupper($reqCountry);
    }
    $reqLimit = RequestCoerce::int($_REQUEST, 'limit');
    if (!empty($reqLimit)) {
        $params['limit'] = $reqLimit;
    }
    $reqDays = RequestCoerce::int($_REQUEST, 'days');
    if (!empty($reqDays)) {
        $params['days'] = $reqDays;
    }
    
    try {
        $api     = _nvt_api();
        $service = _nvt_admin_cron_service();

        // Capture output via callback instead of ob_start()
        $outputLines = [];
        $service->setOutputCallback(function (string $msg) use (&$outputLines) {
            $outputLines[] = rtrim($msg, "\n");
        });

        // Execute based on mode
        switch ($cron_mode) {
            case 'hotel_list':
                $result = $service->syncHotels();
                break;

            case 'room_price':
                $result = $service->checkPrices();
                break;

            case 'resort_list':
                $country = TypeCoerce::toString($params['country'] ?? 'BULGARIA');
                $result = fn_novoton_holidays_sync_resorts_list($country);
                break;

            case 'list_facilities':
                $result = $service->syncFacilities();
                break;

            case 'add_hotels_as_products':
                $limit = TypeCoerce::toInt($params['limit'] ?? 50);
                if (!empty($params['country'])) {
                    $countries = [strtoupper(TypeCoerce::toString($params['country']))];
                } else {
                    $countries = \Tygh\Addons\NovotonHolidays\Services\ConfigProvider::getSelectedCountries();
                }
                $result = $service->addProducts($countries, $limit);
                break;

            case 'offers_update':
                $country = TypeCoerce::toString($params['country'] ?? 'BULGARIA');
                $result = $service->checkOffers($country);
                break;

            case 'resinfo':
                $result = fn_novoton_holidays_cron_resinfo();
                break;

            case 'alternative_rs':
                $result = $service->checkAlternatives('requests');
                break;

            case 'alternative_rs_bookings':
                $result = $service->checkAlternatives('bookings');
                break;

            case 'notify_alternatives':
                $result = $service->notifyAlternatives();
                break;

            case 'cleanup':
                $result = $service->cleanup();
                break;

            case 'expire_requests':
                $days = TypeCoerce::toInt($params['days'] ?? 30);
                $result = $service->expireRequests($days);
                break;

            default:
                $result = ['success' => false, 'message' => 'Unknown mode'];
        }

        $output = implode("\n", $outputLines);

        $resultMessage = TypeCoerce::toString($result['message'] ?? '');
        echo json_encode([
            'success' => true,
            'output'  => $output . "\n" . ($resultMessage !== '' ? $resultMessage : (string) json_encode($result)),
        ]);

    } catch (Exception $e) {
        $output = implode("\n", $outputLines ?? []);
        echo json_encode([
            'success' => false,
            'error'   => $e->getMessage(),
            'output'  => $output,
        ]);
    }

    exit;
}


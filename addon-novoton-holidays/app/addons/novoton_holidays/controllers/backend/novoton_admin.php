<?php
declare(strict_types=1);
/**
 * Novoton Admin Controller
 * Path: app/addons/novoton_holidays/controllers/backend/novoton_admin.php
 */

use Tygh\Registry;
use Tygh\Tygh;
use Tygh\Addons\NovotonHolidays\PriceInfoSync;


if (!defined('BOOTSTRAP')) { exit('Access denied'); }

if (fn_allowed_for('MULTIVENDOR') || (defined('RESTRICTED_ADMIN') && RESTRICTED_ADMIN)) {
    return [CONTROLLER_STATUS_DENIED];
}

// Bulk-apply SEO templates to all linked products
if ($mode === 'bulk_seo_apply') {
    if (function_exists('set_time_limit')) { set_time_limit(0); }
    fn_set_progress('init', __('travel_core.seo_bulk_apply_progress'));

    $fetcher = static fn(int $offset, int $batch): array => db_get_array(
        "SELECT hotel_id, product_id, hotel_name, city, country, region,
                star_rating, hotel_type, property_type, latitude, longitude
         FROM ?:novoton_hotels
         WHERE product_id IS NOT NULL AND product_id > 0
         LIMIT ?i, ?i",
        $offset, $batch
    );

    $builder = static fn(array $hotel): array =>
        \Tygh\Addons\NovotonHolidays\Helpers\ProductFactory::buildNovotonPlaceholders(
            $hotel, $hotel['hotel_name'] ?? ''
        );

    $result = fn_travel_core_seo_bulk_apply('novoton_holidays', $fetcher, $builder);

    fn_set_progress('finish');
    fn_set_notification('N', __('notice'),
        str_replace(['[updated]', '[total]'], [$result['updated'], $result['total']],
            __('travel_core.seo_bulk_apply_done'))
    );
    return [CONTROLLER_STATUS_REDIRECT, 'addons.update?addon=novoton_holidays&selected_sub_section=novoton_holidays_seo_templates&selected_section=settings'];
}

// Update prices manually
if ($mode === 'update_prices') {
    
    if (!empty($_REQUEST['single_product']) && !empty($_REQUEST['product_id'])) {
        // Update single product
        $productId = (int)($_REQUEST['product_id']);
        
        $sync = new PriceInfoSync();
        $stats = [
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
            $message = __('novoton_holidays.sync_completed') . ': ';
            $message .= count($stats['updated']) . ' ' . __('novoton_holidays.updated') . ', ';
            $message .= count($stats['failed']) . ' ' . __('novoton_holidays.failed') . ', ';
            $message .= count($stats['no_data']) . ' ' . __('novoton_holidays.no_data');
            
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
    
    $logs = db_get_array(
        "SELECT * FROM ?:novoton_sync_log ORDER BY sync_date DESC LIMIT 50"
    );
    
    Tygh::$app['view']->assign('sync_logs', $logs);
}

// View bookings
if ($mode === 'bookings') {

    $condition = '';

    if (!empty($_REQUEST['order_id'])) {
        $condition .= db_quote(" AND b.order_id = ?i", (int)$_REQUEST['order_id']);
    }

    $allowed_statuses = ['pending', 'confirmed', 'cancelled', 'failed', 'ASK', 'Good', 'XX'];
    if (!empty($_REQUEST['status']) && in_array($_REQUEST['status'], $allowed_statuses, true)) {
        $condition .= db_quote(" AND b.status = ?s", $_REQUEST['status']);
    }

    if (!empty($_REQUEST['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_REQUEST['date_from'])) {
        $condition .= db_quote(" AND b.check_in >= ?s", $_REQUEST['date_from']);
    }

    if (!empty($_REQUEST['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_REQUEST['date_to'])) {
        $condition .= db_quote(" AND b.check_in <= ?s", $_REQUEST['date_to']);
    }
    
    $bookings = db_get_array(
        "SELECT b.booking_id, b.order_id, b.hotel_id, b.hotel_name, b.room_type, 
                b.check_in, b.check_out, b.nights, b.adults, b.children, 
                b.total_price, b.currency, b.status, b.novoton_status, b.created_at,
                o.status as order_status, o.email 
         FROM ?:novoton_bookings b
         LEFT JOIN ?:orders o ON b.order_id = o.order_id
         WHERE 1=1 $condition
         ORDER BY b.created_at DESC
         LIMIT 500"
    );
    
    Tygh::$app['view']->assign('bookings', $bookings);
    Tygh::$app['view']->assign('search', array_intersect_key($_REQUEST, array_flip(['order_id', 'status', 'date_from', 'date_to', 'dispatch'])));
}

// View booking details
if ($mode === 'booking_details') {

    $bookingId = (int)($_REQUEST['booking_id'] ?? 0);

    if ($bookingId <= 0) {
        fn_set_notification('E', __('error'), __('novoton_holidays.booking_not_found'));
        return [CONTROLLER_STATUS_REDIRECT, 'novoton_admin.bookings'];
    }

    $booking = db_get_row(
        "SELECT b.*, o.*, p.product 
         FROM ?:novoton_bookings b
         LEFT JOIN ?:orders o ON b.order_id = o.order_id
         LEFT JOIN ?:products p ON b.product_id = p.product_id
         WHERE b.booking_id = ?i",
        $bookingId
    );
    
    if ($booking) {
        // Get invoice from Novoton
        $api = _nvt_api();
        
        try {
            $invoice = $api->getInvoiceXml($booking['novoton_invoice_id']);
            $booking['invoice'] = $invoice;
        } catch (Exception $e) {
            fn_set_notification('W', __('warning'), __('novoton_holidays.failed_to_get_invoice'));
        }
        
        Tygh::$app['view']->assign('booking', $booking);
    } else {
        fn_set_notification('E', __('error'), __('novoton_holidays.booking_not_found'));
        return [CONTROLLER_STATUS_REDIRECT, 'novoton_admin.bookings'];
    }
}

// Download log file
if ($mode === 'download_log') {
    
    $logFile = $_REQUEST['log_file'] ?? '';
    
    // Security: Sanitize filename to prevent path traversal
    $logFile = basename($logFile); // Remove any path components
    $logFile = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $logFile); // Only allow safe characters
    
    if (empty($logFile)) {
        fn_set_notification('E', __('error'), __('novoton_holidays.invalid_log_file'));
        return [CONTROLLER_STATUS_REDIRECT, 'novoton_admin.sync_logs'];
    }
    
    $logPath = fn_get_files_dir_path() . 'novoton_logs/' . $logFile;
    
    // Security: Verify the resolved path is within the expected directory
    $realLogPath = realpath($logPath);
    $expectedDir = realpath(fn_get_files_dir_path() . 'novoton_logs/');
    
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
    
    $bookings = db_get_array(
        "SELECT b.*, o.email, o.status as order_status 
         FROM ?:novoton_bookings b
         LEFT JOIN ?:orders o ON b.order_id = o.order_id
         ORDER BY b.created_at DESC"
    );
    
    // Create CSV
    $csv = "Booking ID,Order ID,Hotel Name,Room Type,Check-in,Check-out,Adults,Children,Price,Currency,Status,Email,Created\n";
    
    foreach ($bookings as $booking) {
        $num_children = (int)($booking['children'] ?? 0);
        $csv .= implode(',', [
            $booking['novoton_invoice_id'] ?? '',
            $booking['order_id'],
            '"' . str_replace('"', '""', $booking['hotel_name'] ?? '') . '"',
            '"' . str_replace('"', '""', $booking['room_type'] ?? '') . '"',
            $booking['check_in'],
            $booking['check_out'],
            $booking['adults'],
            $num_children,
            $booking['total_price'],
            $booking['currency'],
            $booking['status'],
            '"' . str_replace('"', '""', $booking['email'] ?? '') . '"',
            $booking['created_at']
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
        $resorts = $api->getResortList('BULGARIA');
        
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
    $cron_mode = $_REQUEST['cron_mode'] ?? ($_REQUEST['task'] ?? '');
    // Sanitize: only alphanumeric and underscores
    $cron_mode = preg_replace('/[^a-z0-9_]/', '', strtolower($cron_mode));
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
    if (!empty($_REQUEST['country'])) {
        $params['country'] = strtoupper($_REQUEST['country']);
    }
    if (!empty($_REQUEST['limit'])) {
        $params['limit'] = (int)($_REQUEST['limit']);
    }
    if (!empty($_REQUEST['days'])) {
        $params['days'] = (int)($_REQUEST['days']);
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
                $country = $params['country'] ?? 'BULGARIA';
                $result = fn_novoton_holidays_sync_resorts_list($country);
                break;

            case 'list_facilities':
                $result = $service->syncFacilities();
                break;

            case 'add_hotels_as_products':
                $limit = (int) ($params['limit'] ?? 50);
                if (!empty($params['country'])) {
                    $countries = [strtoupper($params['country'])];
                } else {
                    $countries = \Tygh\Addons\NovotonHolidays\Services\ConfigProvider::getSelectedCountries();
                }
                $result = $service->addProducts($countries, $limit);
                break;

            case 'offers_update':
                $country = $params['country'] ?? 'BULGARIA';
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
                $days = (int) ($params['days'] ?? 30);
                $result = $service->expireRequests($days);
                break;

            default:
                $result = ['success' => false, 'message' => 'Unknown mode'];
        }

        $output = implode("\n", $outputLines);

        echo json_encode([
            'success' => true,
            'output'  => $output . "\n" . ($result['message'] ?? json_encode($result)),
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


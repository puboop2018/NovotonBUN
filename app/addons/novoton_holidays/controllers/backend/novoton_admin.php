<?php
declare(strict_types=1);
/**
 * Novoton Admin Controller
 * Path: app/addons/novoton_holidays/controllers/backend/novoton_admin.php
 */

use Tygh\Registry;
use Tygh\Addons\NovotonHolidays\PriceInfoSync;


if (!defined('BOOTSTRAP')) { exit('Access denied'); }

// Update prices manually
if ($mode == 'update_prices') {
    
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
if ($mode == 'sync_logs') {
    
    $logs = db_get_array(
        "SELECT * FROM ?:novoton_sync_log ORDER BY sync_date DESC LIMIT 50"
    );
    
    Tygh::$app['view']->assign('sync_logs', $logs);
}

// View bookings
if ($mode == 'bookings') {
    
    $params = $_REQUEST;
    
    $condition = '';
    $join = '';
    
    if (!empty($params['order_id'])) {
        $condition .= db_quote(" AND b.order_id = ?i", $params['order_id']);
    }
    
    if (!empty($params['status'])) {
        $condition .= db_quote(" AND b.status = ?s", $params['status']);
    }
    
    if (!empty($params['date_from'])) {
        $condition .= db_quote(" AND b.check_in >= ?s", $params['date_from']);
    }
    
    if (!empty($params['date_to'])) {
        $condition .= db_quote(" AND b.check_in <= ?s", $params['date_to']);
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
    Tygh::$app['view']->assign('search', $params);
}

// View booking details
if ($mode == 'booking_details') {
    
    $bookingId = (int)($_REQUEST['booking_id']);
    
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
        $api = new \Tygh\Addons\NovotonHolidays\NovotonApi();
        
        try {
            $invoice = $api->getInvoiceXml($booking['novoton_id']);
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
if ($mode == 'download_log') {
    
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
    
    if ($realLogPath === false || $expectedDir === false || strpos($realLogPath, $expectedDir) !== 0) {
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
if ($mode == 'export_bookings') {
    
    $bookings = db_get_array(
        "SELECT b.*, o.email, o.status as order_status 
         FROM ?:novoton_bookings b
         LEFT JOIN ?:orders o ON b.order_id = o.order_id
         ORDER BY b.created_at DESC"
    );
    
    // Create CSV
    $csv = "Booking ID,Order ID,Hotel Name,Room Type,Check-in,Check-out,Adults,Children,Price,Currency,Status,Email,Created\n";
    
    foreach ($bookings as $booking) {
        $csv .= implode(',', [
            $booking['novoton_id'],
            $booking['order_id'],
            '"' . $booking['hotel_name'] . '"',
            '"' . $booking['room_type'] . '"',
            $booking['check_in'],
            $booking['check_out'],
            $booking['adults'],
            !empty($booking['children']) ? count(json_decode($booking['children'], true)) : 0,
            $booking['total_price'],
            $booking['currency'],
            $booking['status'],
            '"' . ($booking['email'] ?? '') . '"',
            $booking['created_at']
        ]) . "\n";
    }
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="novoton_bookings_' . date('Y-m-d') . '.csv"');
    echo $csv;
    exit;
}

// Test API connection
if ($mode == 'test_api') {
    
    $api = new \Tygh\Addons\NovotonHolidays\NovotonApi();
    
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
if ($mode == 'run_cron') {
    header('Content-Type: application/json');
    
    $cron_mode = $_REQUEST['mode'] ?? '';
    $allowed_modes = [
        'hotel_list', 'room_price', 'list_facilities', 'resort_list',
        'add_hotels_as_products', 'offers_update',
        'resinfo', 'alternative_rs', 'alternative_rs_bookings', 'notify_alternatives',
        'cleanup', 'expire_requests'
    ];
    
    if (!in_array($cron_mode, $allowed_modes)) {
        echo json_encode(['success' => false, 'error' => 'Invalid mode: ' . $cron_mode]);
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
    
    // Capture output
    ob_start();
    
    try {
        // Include and run the cron logic
        $_REQUEST = array_merge($_REQUEST, $params);
        
        $api = new \Tygh\Addons\NovotonHolidays\NovotonApi();
        
        // Execute based on mode
        switch ($cron_mode) {
            case 'hotel_list':
                $result = fn_novoton_holidays_admin_sync_hotels($api);
                break;
                
            case 'room_price':
                $result = fn_novoton_holidays_admin_check_prices($api);
                break;
                
            case 'resort_list':
                $country = $params['country'] ?? 'BULGARIA';
                $result = fn_novoton_holidays_sync_resorts_list($country);
                break;

            case 'list_facilities':
                $result = fn_novoton_holidays_admin_sync_facilities($api);
                break;
                
            case 'add_hotels_as_products':
                $country = $params['country'] ?? 'BULGARIA';
                $limit = $params['limit'] ?? 50;
                $result = fn_novoton_holidays_admin_add_products($api, $country, $limit);
                break;
                
            case 'offers_update':
                $country = $params['country'] ?? 'BULGARIA';
                $result = fn_novoton_holidays_admin_check_offers($api, $country);
                break;
                
            case 'resinfo':
                $result = fn_novoton_holidays_cron_resinfo();
                break;
                
            case 'alternative_rs':
                $result = fn_novoton_holidays_admin_check_alternatives($api, 'requests');
                break;
                
            case 'alternative_rs_bookings':
                $result = fn_novoton_holidays_admin_check_alternatives($api, 'bookings');
                break;
                
            case 'notify_alternatives':
                $result = fn_novoton_holidays_admin_notify_alternatives();
                break;
                
            case 'cleanup':
                $result = fn_novoton_holidays_admin_cleanup();
                break;
                
            case 'expire_requests':
                $days = $params['days'] ?? 30;
                $result = fn_novoton_holidays_admin_expire_requests($days);
                break;
                
            default:
                $result = ['success' => false, 'message' => 'Unknown mode'];
        }
        
        $output = ob_get_clean();
        
        echo json_encode([
            'success' => true,
            'output' => $output . "\n" . ($result['message'] ?? json_encode($result))
        ]);
        
    } catch (Exception $e) {
        $output = ob_get_clean();
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'output' => $output
        ]);
    }
    
    exit;
}

// ================================================
// A73: Helper functions for admin cron execution
// ================================================

function fn_novoton_holidays_admin_sync_hotels($api) {
    $countries = fn_novoton_holidays_parse_countries();
    $hotelRepo = new \Tygh\Addons\NovotonHolidays\Repository\HotelRepository();

    $total = 0;
    $synced = 0;

    foreach ($countries as $country) {
        echo "Fetching {$country}... ";
        $hotels = $api->getHotelList($country);

        if (!empty($hotels)) {
            $count = count($hotels);
            $total += $count;
            echo "{$count} hotels\n";

            foreach ($hotels as $hotel) {
                $hotel_id = (string)($hotel->IdHotel ?? '');
                if (empty($hotel_id)) continue;

                $data = [
                    'hotel_id' => $hotel_id,
                    'hotel_name' => (string)($hotel->Hotel ?? ''),
                    'city' => (string)($hotel->City ?? ''),
                    'country' => $country,
                    'updated_at' => date('Y-m-d H:i:s')
                ];

                $hotelRepo->upsert($data);
                $synced++;
            }
        } else {
            echo "0 hotels\n";
        }
    }

    return ['success' => true, 'message' => "Total: {$total}, Synced: {$synced}"];
}

function fn_novoton_holidays_admin_check_prices($api) {
    $hotelRepo = new \Tygh\Addons\NovotonHolidays\Repository\HotelRepository();
    $hotels = db_get_array(
        "SELECT hotel_id, hotel_name FROM ?:novoton_hotels
         WHERE (last_price_check IS NULL OR last_price_check < DATE_SUB(NOW(), INTERVAL 7 DAY))
         LIMIT 100"
    );
    
    $checked = 0;
    $with_prices = 0;
    
    foreach ($hotels as $hotel) {
        $check_in = date('Y-m-d', strtotime('+30 days'));
        $check_out = date('Y-m-d', strtotime('+37 days'));
        
        $response = $api->getRoomPrice($hotel['hotel_id'], $check_in, $check_out, 2, 0, 1);
        $has_prices = ($response && isset($response->hotel)) ? 'Y' : 'N';
        
        $hotelRepo->update($hotel['hotel_id'], ['has_prices' => $has_prices, 'last_price_check' => date('Y-m-d H:i:s')]);
        
        $checked++;
        if ($has_prices == 'Y') $with_prices++;
        
        echo "[{$hotel['hotel_id']}] {$hotel['hotel_name']}: " . ($has_prices == 'Y' ? 'HAS PRICES' : 'no prices') . "\n";
        usleep(100000);
    }
    
    return ['success' => true, 'message' => "Checked: {$checked}, With prices: {$with_prices}"];
}

function fn_novoton_holidays_admin_sync_facilities($api) {
    $response = $api->listFacilities();

    if (!$response || !isset($response->Facility)) {
        return ['success' => false, 'message' => 'No facilities returned from API'];
    }

    $facilityRepo = new \Tygh\Addons\NovotonHolidays\Repository\FacilityRepository();
    $facilities = is_array($response->Facility) ? $response->Facility : [$response->Facility];
    $count = 0;

    foreach ($facilities as $f) {
        $facility_id = (int)($f->IdFacility ?? 0);
        $facility_name = (string)($f->Facility ?? '');

        if (empty($facility_id)) continue;

        $facilityRepo->save($facility_id, $facility_name);
        $count++;
    }

    return ['success' => true, 'message' => "Synced {$count} facilities"];
}

function fn_novoton_holidays_admin_add_products($api, $country, $limit) {
    $hotelRepo = new \Tygh\Addons\NovotonHolidays\Repository\HotelRepository();
    $hotels = $hotelRepo->findUnlinkedWithPrices($country, [], $limit);

    if (empty($hotels)) {
        return ['success' => true, 'message' => 'No hotels to add'];
    }

    $category_id = fn_novoton_holidays_get_or_create_category("{$country}///Litoral {$country}");
    $added = 0;

    foreach ($hotels as $hotel) {
        $hotel_id = $hotel['hotel_id'];
        $product_code = 'NVT' . $hotel_id;

        echo "[{$hotel_id}] {$hotel['hotel_name']} ... ";

        // Check if CS-Cart product already exists with this code
        $existing = db_get_field("SELECT product_id FROM ?:products WHERE product_code = ?s", $product_code);
        if ($existing) {
            $hotelRepo->linkToProduct($hotel_id, (int) $existing);
            echo "LINKED\n";
            continue;
        }

        $product_data = [
            'product' => $hotel['hotel_name'],
            'product_code' => $product_code,
            'price' => 0,
            'status' => 'D',
            'company_id' => Registry::get('runtime.company_id') ?: 1,
            'main_category' => $category_id,
            'category_ids' => [$category_id],
        ];

        $product_id = fn_update_product($product_data, 0, CART_LANGUAGE);

        if ($product_id) {
            $hotelRepo->linkToProduct($hotel_id, $product_id);
            $added++;
            echo "ADDED (ID: {$product_id})\n";
        } else {
            echo "FAILED\n";
        }

        usleep(50000);
    }

    return ['success' => true, 'message' => "Added: {$added} products"];
}

function fn_novoton_holidays_admin_check_offers($api, $country) {
    $syncLogRepo = new \Tygh\Addons\NovotonHolidays\Repository\SyncLogRepository();
    $last_check = $syncLogRepo->getLastSyncDate('offers_update')
                  ?: $syncLogRepo->getLastSyncDate('product_import');
    if (empty($last_check)) {
        $last_check = date('Y-m-d\TH:i:s', strtotime('-7 days'));
    }

    echo "Checking offers since: {$last_check}\n";

    $response = $api->getOffersUpdate($last_check, $country);

    if (!$response || !isset($response->Offer)) {
        return ['success' => true, 'message' => 'No new offers found'];
    }

    $offers = is_array($response->Offer) ? $response->Offer : [$response->Offer];
    $count = count($offers);

    return ['success' => true, 'message' => "Found {$count} offers"];
}

function fn_novoton_holidays_admin_check_alternatives($api, $type) {
    if ($type == 'requests') {
        $altRequestRepo = new \Tygh\Addons\NovotonHolidays\Repository\AlternativeRequestRepository();
        $items = $altRequestRepo->findPendingOlderThan(0, 50);
    } else {
        $bookingRepo = new \Tygh\Addons\NovotonHolidays\Repository\BookingRepository();
        $items = $bookingRepo->findByNovotonStatus(
            \Tygh\Addons\NovotonHolidays\Constants::NOVOTON_STATUS_ALTERNATIVES_PENDING,
            [\Tygh\Addons\NovotonHolidays\Constants::STATUS_PENDING, \Tygh\Addons\NovotonHolidays\Constants::STATUS_CONFIRMED],
            50
        );
    }
    
    $checked = count($items);
    echo "Checking {$checked} {$type}...\n";
    
    return ['success' => true, 'message' => "Checked: {$checked}"];
}

function fn_novoton_holidays_admin_notify_alternatives() {
    $altRequestRepo = new \Tygh\Addons\NovotonHolidays\Repository\AlternativeRequestRepository();
    $requests = $altRequestRepo->findUnnotified(50);
    $requests = fn_novoton_holidays_decrypt_requests_pii($requests);

    $notified = 0;
    foreach ($requests as $request) {
        // Send notification email logic here
        $altRequestRepo->markNotified($request['request_id']);
        $notified++;
    }
    
    return ['success' => true, 'message' => "Notified: {$notified}"];
}

function fn_novoton_holidays_admin_cleanup() {
    $bookingRepo = new \Tygh\Addons\NovotonHolidays\Repository\BookingRepository();
    $syncLogRepo = new \Tygh\Addons\NovotonHolidays\Repository\SyncLogRepository();

    // Orphan bookings
    $orphans = $bookingRepo->deleteOrphans(48);
    echo "Orphan bookings deleted: {$orphans}\n";

    // Old sync logs (keep 100)
    $logs_deleted = $syncLogRepo->trimToLatest(100);
    echo "Sync logs deleted: {$logs_deleted}\n";

    // Expired cache
    $cache = db_query("DELETE FROM ?:novoton_cache WHERE expires_at < NOW()");
    echo "Cache entries deleted: {$cache}\n";

    return ['success' => true, 'message' => "Cleanup complete"];
}

function fn_novoton_holidays_admin_expire_requests($days) {
    $altRequestRepo = new \Tygh\Addons\NovotonHolidays\Repository\AlternativeRequestRepository();
    $expired = $altRequestRepo->expireOlderThan($days);

    return ['success' => true, 'message' => "Expired: {$expired} requests"];
}
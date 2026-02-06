<?php
/**
 * Novoton Holidays - Tools Controller
 * 
 * Test modes, diagnostics, and CSV exports.
 * Split from novoton_holidays.php for maintainability.
 * 
 * Modes:
 * - test_api: Test API connection
 * - test_formats: Test room/board formatting
 * - test_product: Test product data
 * - test_hotel_list: Test hotel list API
 * - test_room_price: Test room price API
 * - test_search: Test search API
 * - test_hotel_request: Test hotel info request
 * - test_alternative_rs: Test alternative search
 * - test_facilities: Test facilities sync
 * - export_hotel_features_csv: Generate features CSV
 * - download_hotel_features_csv: Download CSV file
 * - get_hotel_features_csv: Get CSV content
 * 
 * @package NovotonHolidays
 * @since 2.8.0
 */

use Tygh\Registry;
use Tygh\Addons\NovotonHolidays\NovotonApi;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

// Ensure API class is loaded
$src_dir = Registry::get('config.dir.addons') . 'novoton_holidays/src/';
if (!class_exists('Tygh\Addons\NovotonHolidays\NovotonApi') && file_exists($src_dir . 'NovotonApi.php')) {
    require_once($src_dir . 'NovotonApi.php');
}

/**
 * Mode: export_hotel_features_csv
 * Generate CSV file with star ratings for CS-Cart import
 */
if ($mode == 'export_hotel_features_csv') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }
    
    try {
        $result = fn_novoton_generate_hotel_features_csv();
        
        if ($result['success']) {
            fn_set_notification('N', __('notice'), "Hotel features CSV generated!<br>File: {$result['filename']}<br>Hotels: {$result['count']}");
        } else {
            fn_set_notification('E', __('error'), "Failed: " . ($result['error'] ?? 'Unknown error'));
        }
    } catch (Exception $e) {
        fn_set_notification('E', __('error'), "Exception: " . $e->getMessage());
    }
    
    return [CONTROLLER_STATUS_REDIRECT, 'addons.update&addon=novoton_holidays'];
}

/**
 * Mode: download_hotel_features_csv
 * Download the generated CSV file
 */
if ($mode == 'download_hotel_features_csv') {
    $file = $_REQUEST['file'] ?? '';
    
    if (empty($file)) {
        fn_set_notification('E', __('error'), 'No file specified');
        return [CONTROLLER_STATUS_REDIRECT, 'addons.update&addon=novoton_holidays'];
    }
    
    // Security: only allow files from novoton_reports directory
    $dir = fn_get_files_dir_path() . 'novoton_reports/';
    $file_path = $dir . basename($file);
    
    if (!file_exists($file_path)) {
        fn_set_notification('E', __('error'), 'File not found');
        return [CONTROLLER_STATUS_REDIRECT, 'addons.update&addon=novoton_holidays'];
    }
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . basename($file) . '"');
    header('Content-Length: ' . filesize($file_path));
    readfile($file_path);
    exit;
}

/**
 * Mode: test_api
 * Test API connection and credentials
 */
if ($mode == 'test_api') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }
    
    header('Content-Type: text/plain; charset=utf-8');
    
    echo "========================================\n";
    echo "   NOVOTON API CONNECTION TEST\n";
    echo "========================================\n\n";
    
    $addon_settings = Registry::get('addons.novoton_holidays') ?? [];
    
    echo "Configuration:\n";
    echo "- API URL: " . ($addon_settings['api_url'] ?? 'NOT SET') . "\n";
    echo "- API User: " . ($addon_settings['api_user'] ?? 'NOT SET') . "\n";
    echo "- API Password: " . (empty($addon_settings['api_password']) ? 'NOT SET' : '***SET***') . "\n";
    echo "- API Key: " . (empty($addon_settings['api_key']) ? 'NOT SET' : '***SET***') . "\n";
    $countries = $addon_settings['selected_countries'] ?? 'BULGARIA';
    if (is_array($countries)) {
        // Extract country names from array where value is 'Y'
        $country_names = [];
        foreach ($countries as $key => $value) {
            if ($value === 'Y' || $value === '1') {
                $country_names[] = $key;
            } elseif (is_string($value) && strlen($value) > 2) {
                $country_names[] = $value;
            }
        }
        $countries_display = !empty($country_names) ? implode(', ', $country_names) : 'BULGARIA';
    } else {
        $countries_display = $countries;
    }
    echo "- Selected Countries: " . $countries_display . "\n\n";
    
    try {
        $api = new NovotonApi();
        echo "API instance created successfully.\n\n";
        
        echo "Testing getHotelList('BULGARIA', '%', '%', '%')...\n";
        $result = $api->getHotelList('BULGARIA', '%', '%', '%');
        
        // Check if we got a valid response (SimpleXMLElement or array)
        if (!empty($result)) {
            // Count hotels - iterate like cron does
            $count = 0;
            $sample = null;
            foreach ($result as $hotel) {
                if (!$sample) $sample = $hotel;
                $count++;
            }
            
            if ($count > 0) {
                echo "SUCCESS! Retrieved {$count} hotels.\n\n";
                
                echo "Sample hotel:\n";
                echo "- ID: " . (string)($sample->IdHotel ?? 'N/A') . "\n";
                echo "- Name: " . (string)($sample->Hotel ?? 'N/A') . "\n";
                echo "- City: " . (string)($sample->City ?? 'N/A') . "\n";
            } else {
                echo "Response received but no hotels found.\n";
                echo "Raw response type: " . gettype($result) . "\n";
                if ($result instanceof \SimpleXMLElement) {
                    echo "XML root element: " . $result->getName() . "\n";
                    echo "Children count: " . $result->count() . "\n";
                }
            }
        } else {
            echo "No hotels returned or invalid response.\n";
            $lastRequest = $api->getLastRequestFormatted();
            echo "Last request:\n" . (is_array($lastRequest) ? print_r($lastRequest, true) : $lastRequest) . "\n";
            echo "Last error: " . $api->getLastError() . "\n";
            echo "Last HTTP code: " . $api->lastHttpCode . "\n";
            
            // Show raw response for debugging
            $rawResponse = $api->lastResponseRaw ?? '';
            if (!empty($rawResponse)) {
                echo "\nRaw response (first 500 chars):\n";
                echo substr($rawResponse, 0, 500) . "\n";
            }
        }
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
    
    exit;
}

/**
 * Mode: test_formats
 * Test room type and board name formatting
 */
if ($mode == 'test_formats') {
    header('Content-Type: text/html; charset=utf-8');
    
    echo '<h2>Room Type Formatting Tests</h2>';
    echo '<table border="1" cellpadding="5">';
    echo '<tr><th>Input</th><th>Output</th></tr>';
    
    $test_rooms = ['DBL', 'DBL 2+1', 'DBL%202%2B1', 'SGL', 'TRP', 'STUDIO', 'APT', 'FAM 2+2'];
    foreach ($test_rooms as $room) {
        echo '<tr><td>' . htmlspecialchars($room) . '</td><td>' . fn_novoton_format_room_type($room) . '</td></tr>';
    }
    echo '</table>';
    
    echo '<h2>Board Name Formatting Tests</h2>';
    echo '<table border="1" cellpadding="5">';
    echo '<tr><th>Input</th><th>Output</th></tr>';
    
    $test_boards = ['AI', 'HB', 'FB', 'BB', 'RO', 'UAI', 'ALL INCL'];
    foreach ($test_boards as $board) {
        echo '<tr><td>' . htmlspecialchars($board) . '</td><td>' . fn_novoton_format_board_name($board) . '</td></tr>';
    }
    echo '</table>';
    
    exit;
}

/**
 * Mode: test_hotel_list
 * Test hotel list API call
 */
if ($mode == 'test_hotel_list') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }
    
    header('Content-Type: text/html; charset=utf-8');
    
    $country = $_REQUEST['country'] ?? 'BULGARIA';
    $limit = intval($_REQUEST['limit'] ?? 10);
    
    echo '<h2>Hotel List Test - ' . htmlspecialchars($country) . '</h2>';
    
    try {
        $api = new NovotonApi();
        $result = $api->getHotelList($country);
        
        if ($result && isset($result->Hotel)) {
            $hotels = $result->Hotel;
            $total = count($hotels);
            echo "<p>Total hotels: {$total}</p>";
            
            echo '<table border="1" cellpadding="5">';
            echo '<tr><th>ID</th><th>Name</th><th>City</th><th>Stars</th><th>Type</th></tr>';
            
            $count = 0;
            foreach ($hotels as $hotel) {
                if ($count >= $limit) break;
                echo '<tr>';
                echo '<td>' . ($hotel['HotelId'] ?? '') . '</td>';
                echo '<td>' . ($hotel['HotelName'] ?? '') . '</td>';
                echo '<td>' . ($hotel['City'] ?? '') . '</td>';
                echo '<td>' . ($hotel['Stars'] ?? '') . '</td>';
                echo '<td>' . ($hotel['HotelType'] ?? '') . '</td>';
                echo '</tr>';
                $count++;
            }
            echo '</table>';
        } else {
            echo '<p style="color:red">No results or error</p>';
            echo '<pre>' . htmlspecialchars($api->getLastError()) . '</pre>';
        }
    } catch (Exception $e) {
        echo '<p style="color:red">Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
    }
    
    exit;
}

/**
 * Mode: test_room_price
 * Test room price API call
 */
if ($mode == 'test_room_price') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }
    
    header('Content-Type: text/html; charset=utf-8');
    
    $hotel_id = $_REQUEST['hotel_id'] ?? '';
    $room_id = $_REQUEST['room_id'] ?? '';
    $board_id = $_REQUEST['board_id'] ?? 'AI';
    $check_in = $_REQUEST['check_in'] ?? date('Y-m-d', strtotime('+30 days'));
    $check_out = $_REQUEST['check_out'] ?? date('Y-m-d', strtotime('+37 days'));
    $adults = intval($_REQUEST['adults'] ?? 2);
    
    echo '<h2>Room Price Test</h2>';
    
    echo '<form method="get">';
    echo '<input type="hidden" name="dispatch" value="novoton_tools.test_room_price">';
    echo '<p>Hotel ID: <input name="hotel_id" value="' . htmlspecialchars($hotel_id) . '"></p>';
    echo '<p>Room ID: <input name="room_id" value="' . htmlspecialchars($room_id) . '"></p>';
    echo '<p>Board ID: <input name="board_id" value="' . htmlspecialchars($board_id) . '"></p>';
    echo '<p>Check In: <input type="date" name="check_in" value="' . htmlspecialchars($check_in) . '"></p>';
    echo '<p>Check Out: <input type="date" name="check_out" value="' . htmlspecialchars($check_out) . '"></p>';
    echo '<p>Adults: <input type="number" name="adults" value="' . $adults . '"></p>';
    echo '<p><button type="submit">Test</button></p>';
    echo '</form>';
    
    if (!empty($hotel_id)) {
        try {
            $api = new NovotonApi();
            
            $params = [
                'hotel_id' => $hotel_id,
                'room_id' => $room_id,
                'board_id' => $board_id,
                'check_in' => $check_in,
                'check_out' => $check_out,
                'adults' => $adults,
                'children' => 0,
            ];
            
            echo '<h3>Request Parameters:</h3>';
            echo '<pre>' . print_r($params, true) . '</pre>';
            
            $result = $api->getRoomPrice($params);
            
            echo '<h3>Response:</h3>';
            if ($result) {
                echo '<pre>' . htmlspecialchars(print_r($result, true)) . '</pre>';
                
                if (isset($result->Price)) {
                    $price = floatval($result->Price);
                    $with_commission = $api->applyCommission($price);
                    echo "<p><strong>Price: €{$price} (with commission: €{$with_commission})</strong></p>";
                }
            } else {
                echo '<p style="color:red">No result</p>';
            }
            
            echo '<h3>Raw Response:</h3>';
            echo '<pre>' . htmlspecialchars($api->getLastResponse()) . '</pre>';
            
        } catch (Exception $e) {
            echo '<p style="color:red">Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
    }
    
    exit;
}

/**
 * Mode: test_search
 * Test availability search API
 */
if ($mode == 'test_search') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }
    
    header('Content-Type: text/html; charset=utf-8');
    
    $hotel_id = $_REQUEST['hotel_id'] ?? '';
    $check_in = $_REQUEST['check_in'] ?? date('Y-m-d', strtotime('+30 days'));
    $check_out = $_REQUEST['check_out'] ?? date('Y-m-d', strtotime('+37 days'));
    $adults = intval($_REQUEST['adults'] ?? 2);
    $children = intval($_REQUEST['children'] ?? 0);
    
    echo '<h2>Search Availability Test</h2>';
    
    echo '<form method="get">';
    echo '<input type="hidden" name="dispatch" value="novoton_tools.test_search">';
    echo '<p>Hotel ID (optional): <input name="hotel_id" value="' . htmlspecialchars($hotel_id) . '"></p>';
    echo '<p>Check In: <input type="date" name="check_in" value="' . htmlspecialchars($check_in) . '"></p>';
    echo '<p>Check Out: <input type="date" name="check_out" value="' . htmlspecialchars($check_out) . '"></p>';
    echo '<p>Adults: <input type="number" name="adults" value="' . $adults . '"></p>';
    echo '<p>Children: <input type="number" name="children" value="' . $children . '"></p>';
    echo '<p><button type="submit">Search</button></p>';
    echo '</form>';
    
    if (!empty($_REQUEST['check_in'])) {
        try {
            $api = new NovotonApi();
            
            $params = [
                'check_in' => $check_in,
                'check_out' => $check_out,
                'adults' => $adults,
                'children' => $children,
            ];
            
            if (!empty($hotel_id)) {
                $params['hotel_id'] = $hotel_id;
            }
            
            echo '<h3>Searching...</h3>';
            $results = $api->searchAvailability($params);
            
            if (!empty($results)) {
                echo '<p>Found ' . count($results) . ' results</p>';
                echo '<table border="1" cellpadding="5">';
                echo '<tr><th>Hotel</th><th>Room</th><th>Board</th><th>Price</th></tr>';
                
                $count = 0;
                foreach ($results as $result) {
                    if ($count >= 20) break;
                    echo '<tr>';
                    echo '<td>' . htmlspecialchars($result['hotel_name'] ?? $result['hotel_id'] ?? '') . '</td>';
                    echo '<td>' . htmlspecialchars($result['room_id'] ?? '') . '</td>';
                    echo '<td>' . htmlspecialchars($result['board_id'] ?? '') . '</td>';
                    echo '<td>€' . number_format($result['price'] ?? 0, 2) . '</td>';
                    echo '</tr>';
                    $count++;
                }
                echo '</table>';
            } else {
                echo '<p>No availability found</p>';
            }
            
        } catch (Exception $e) {
            echo '<p style="color:red">Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
    }
    
    exit;
}

/**
 * Mode: test_facilities
 * Test facilities sync
 */
if ($mode == 'test_facilities') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }
    
    header('Content-Type: text/html; charset=utf-8');
    
    echo '<h2>Facilities Sync Test</h2>';
    
    $result = fn_novoton_sync_facilities_list();
    
    if ($result['success']) {
        echo '<p style="color:green">Success!</p>';
        echo '<p>Total: ' . $result['total'] . '</p>';
        echo '<p>Added: ' . $result['added'] . '</p>';
        echo '<p>Updated: ' . $result['updated'] . '</p>';
    } else {
        echo '<p style="color:red">Failed: ' . ($result['error'] ?? 'Unknown error') . '</p>';
    }
    
    // Show current facilities
    $facilities = db_get_array("SELECT * FROM ?:novoton_facilities ORDER BY facility_name_en LIMIT 50");
    
    echo '<h3>Current Facilities (first 50):</h3>';
    echo '<table border="1" cellpadding="5">';
    echo '<tr><th>ID</th><th>Name (EN)</th><th>Name (RO)</th></tr>';
    foreach ($facilities as $f) {
        echo '<tr>';
        echo '<td>' . $f['facility_id'] . '</td>';
        echo '<td>' . htmlspecialchars($f['facility_name_en']) . '</td>';
        echo '<td>' . htmlspecialchars($f['facility_name_ro']) . '</td>';
        echo '</tr>';
    }
    echo '</table>';
    
    exit;
}

/**
 * Mode: test_hotel_request
 * Diagnostic page for hotel info request
 */
if ($mode == 'test_hotel_request') {
    $hotel_id = $_REQUEST['hotel_id'] ?? '';
    
    Tygh::$app['view']->assign('hotel_id', $hotel_id);
    
    if (!empty($hotel_id)) {
        try {
            $api = new NovotonApi();
            
            // Get hotel info
            $hotel_info = $api->getHotelInfo($hotel_id);
            $last_request = $api->getLastRequestFormatted();
            $last_response = $api->getLastResponse();
            
            // Get hotel description
            $hotel_desc = $api->getHotelDescription($hotel_id, 'UK', true);
            
            Tygh::$app['view']->assign('hotel_info', $hotel_info);
            Tygh::$app['view']->assign('hotel_desc', $hotel_desc);
            Tygh::$app['view']->assign('last_request', $last_request);
            Tygh::$app['view']->assign('last_response', $last_response);
            
        } catch (Exception $e) {
            Tygh::$app['view']->assign('error', $e->getMessage());
        }
    }
}

/**
 * Mode: test_alternative_rs
 * Test alternative search functionality
 */
if ($mode == 'test_alternative_rs') {
    $hotel_id = $_REQUEST['hotel_id'] ?? '';
    $check_in = $_REQUEST['check_in'] ?? date('Y-m-d', strtotime('+30 days'));
    $check_out = $_REQUEST['check_out'] ?? date('Y-m-d', strtotime('+37 days'));
    
    Tygh::$app['view']->assign('hotel_id', $hotel_id);
    Tygh::$app['view']->assign('check_in', $check_in);
    Tygh::$app['view']->assign('check_out', $check_out);
    
    if (!empty($_REQUEST['search']) && !empty($hotel_id)) {
        try {
            $api = new NovotonApi();
            
            $params = [
                'hotel_id' => $hotel_id,
                'check_in' => $check_in,
                'check_out' => $check_out,
                'adults' => intval($_REQUEST['adults'] ?? 2),
                'children' => intval($_REQUEST['children'] ?? 0),
            ];
            
            $results = $api->searchAvailability($params);
            
            Tygh::$app['view']->assign('results', $results);
            Tygh::$app['view']->assign('last_request', $api->getLastRequestFormatted());
            
        } catch (Exception $e) {
            Tygh::$app['view']->assign('error', $e->getMessage());
        }
    }
}

/**
 * Mode: test_product
 * Test single product price data retrieval
 * Usage: admin.php?dispatch=novoton_tools.test_product&product_code=NVT1603
 */
if ($mode == 'test_product') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }
    
    header('Content-Type: text/plain; charset=utf-8');
    
    $product_code = $_REQUEST['product_code'] ?? 'NVT1603';
    
    echo "========================================\n";
    echo "TEST SINGLE PRODUCT: $product_code\n";
    echo "========================================\n\n";
    
    // Get product
    $product = db_get_row(
        "SELECT p.product_id, p.product_code, pd.product 
         FROM ?:products AS p
         LEFT JOIN ?:product_descriptions AS pd ON p.product_id = pd.product_id AND pd.lang_code = ?s
         WHERE p.product_code = ?s",
        CART_LANGUAGE,
        $product_code
    );
    
    if (!$product) {
        echo "Product not found: $product_code\n";
        exit;
    }
    
    echo "Product ID: {$product['product_id']}\n";
    echo "Product Name: {$product['product']}\n";
    echo "Product Code: {$product['product_code']}\n\n";
    
    // Extract hotel ID (numeric part of product code)
    preg_match('/\d+/', $product['product_code'], $matches);
    $hotel_id = $matches[0] ?? null;
    
    if (!$hotel_id) {
        echo "Could not extract hotel ID from product code\n";
        exit;
    }
    
    echo "Extracted Hotel ID: $hotel_id\n\n";
    
    try {
        $api = new NovotonApi();
        echo "NovotonApi loaded\n\n";
        
        // Test 1: Get hotel info
        echo "=== STEP 1: Get Hotel Info ===\n";
        $hotelInfo = $api->getHotelInfo($hotel_id);
        
        if (!$hotelInfo) {
            echo "getHotelInfo() returned FALSE\n";
            echo "Check var/logs/main.log for errors\n";
            exit;
        }
        
        echo "Hotel info retrieved successfully\n";
        $hotelData = json_decode(json_encode($hotelInfo), true);
        
        // Check for packages
        if (isset($hotelData['packages'])) {
            echo "Packages found\n";
            
            $packages = isset($hotelData['packages']['IdCont']) ? [$hotelData['packages']] : $hotelData['packages'];
            echo "Package count: " . count($packages) . "\n\n";
            
            foreach ($packages as $idx => $pkg) {
                echo "  " . ($idx + 1) . ". " . ($pkg['PackageName'] ?? 'N/A') . "\n";
            }
        }
        
        // Check rooms
        if (isset($hotelData['rooms'])) {
            echo "\n=== ROOMS ===\n";
            $rooms = isset($hotelData['rooms']['IdRoom']) ? [$hotelData['rooms']] : $hotelData['rooms'];
            foreach ($rooms as $idx => $room) {
                if ($idx >= 5) break;
                echo "  - " . ($room['IdRoom'] ?? 'N/A') . " (Max: " . ($room['maxADT'] ?? '?') . " adults)\n";
            }
        }
        
        // Check boards
        if (isset($hotelData['board'])) {
            echo "\n=== BOARDS ===\n";
            $boards = isset($hotelData['board']['IdBoard']) ? [$hotelData['board']] : $hotelData['board'];
            foreach ($boards as $idx => $board) {
                if ($idx >= 5) break;
                $boardId = is_array($board) ? ($board['IdBoard'] ?? '') : (string)$board;
                echo "  - {$boardId}\n";
            }
        }
        
        // Test price data (V3: stored in novoton_hotel_packages)
        echo "\n=== STEP 2: Check Package Data ===\n";
        $packages_count = db_get_field(
            "SELECT COUNT(*) FROM ?:novoton_hotel_packages WHERE hotel_id = ?s",
            $hotel_id
        );
        echo "Packages in database: $packages_count\n";

        if ($packages_count > 0) {
            $packages = db_get_array(
                "SELECT package_name, has_early_booking, min_price, synced_at
                 FROM ?:novoton_hotel_packages WHERE hotel_id = ?s ORDER BY package_name",
                $hotel_id
            );
            echo "\nStored packages:\n";
            foreach ($packages as $pkg) {
                echo "  - {$pkg['package_name']}: min €{$pkg['min_price']}, " .
                     "early_booking={$pkg['has_early_booking']}, synced={$pkg['synced_at']}\n";
            }
        } else {
            echo "No packages found. Run sync_hotels cron to populate.\n";
        }
        
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
    
    exit;
}

/**
 * Mode: cron_export_hotel_features
 * Cron job to export hotel features CSV
 * URL: admin.php?dispatch=novoton_tools.cron_export_hotel_features&access_key=YOUR_KEY
 */
if ($mode == 'cron_export_hotel_features') {
    $expected_key = Registry::get('addons.novoton_holidays.cron_access_key') ?? '';
    $provided_key = $_REQUEST['access_key'] ?? '';
    
    header('Content-Type: text/plain; charset=utf-8');
    
    if (empty($expected_key)) {
        echo "[ERROR] Cron API key not configured.\n";
        exit;
    }
    
    if ($provided_key !== $expected_key) {
        echo "[ERROR] Invalid API key.\n";
        exit;
    }
    
    echo "=== NOVOTON Star Ratings CSV Export - " . date('Y-m-d H:i:s') . " ===\n";
    
    $result = fn_novoton_generate_hotel_features_csv();
    
    if ($result['success']) {
        echo "Status: SUCCESS\n";
        echo "File: " . ($result['file_path'] ?? $result['filename'] ?? 'N/A') . "\n";
        echo "Hotels: {$result['count']}\n";
        echo "[OK] CSV ready for CS-Cart import.\n";
    } else {
        echo "Status: FAILED\n";
        echo "Error: " . ($result['error'] ?? 'Unknown error') . "\n";
    }
    
    exit;
}

/**
 * Mode: get_hotel_features_csv
 * Direct download with API key authentication (for external access)
 * URL: admin.php?dispatch=novoton_tools.get_hotel_features_csv&access_key=YOUR_KEY
 */
if ($mode == 'get_hotel_features_csv') {
    $expected_key = Registry::get('addons.novoton_holidays.cron_access_key') ?? '';
    $provided_key = $_REQUEST['access_key'] ?? '';
    
    if (empty($expected_key) || $provided_key !== $expected_key) {
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: text/plain');
        echo "Access denied. Invalid or missing API key.";
        exit;
    }
    
    $export_dir = fn_get_files_dir_path() . 'novoton_exports/';
    $file_path = $export_dir . 'hotel_features_import.csv';
    
    if (!file_exists($file_path)) {
        header('HTTP/1.1 404 Not Found');
        header('Content-Type: text/plain');
        echo "CSV file not found. Please generate it first using:\n";
        echo "admin.php?dispatch=novoton_tools.cron_export_hotel_features&access_key=YOUR_ACCESS_KEY";
        exit;
    }
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="hotel_features_import.csv"');
    header('Content-Length: ' . filesize($file_path));
    header('Cache-Control: no-cache, must-revalidate');
    readfile($file_path);
    exit;
}

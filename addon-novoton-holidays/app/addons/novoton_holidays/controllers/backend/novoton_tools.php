<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Tools Controller
 *
 * Test modes, diagnostics, and CSV exports.
 * Split from novoton_holidays.php for maintainability.
 *
 * v3.1.0: Delegates data-fetching to DiagnosticsService;
 *         the controller is now a thin presentation layer.
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
 * - export_hotel_features_csv: Generate and download features CSV
 * - get_hotel_features_csv: Get CSV content
 *
 * @package NovotonHolidays
 * @since 2.8.0
 */

use Tygh\Tygh;
use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\NovotonApi;
use Tygh\Addons\NovotonHolidays\Services\DiagnosticsService;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\Container;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

/**
 * Mode: export_hotel_features_csv
 * Generate and immediately download CSV file with hotel features
 */
if ($mode == 'export_hotel_features_csv') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    try {
        ob_start();
        $result = fn_novoton_holidays_generate_hotel_features_csv();
        ob_end_clean();

        if ($result['success'] && !empty($result['file_path']) && file_exists($result['file_path'])) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="novoton_hotel_features.csv"');
            header('Content-Length: ' . filesize($result['file_path']));
            readfile($result['file_path']);
            exit;
        }

        fn_set_notification('E', __('error'), "Failed: " . ($result['error'] ?? 'Unknown error'));
    } catch (Exception $e) {
        ob_end_clean();
        fn_set_notification('E', __('error'), "Exception: " . $e->getMessage());
    }

    return [CONTROLLER_STATUS_REDIRECT, 'novoton_holidays.manage'];
}

/**
 * Mode: export_hotel_features_xml
 * Generate XML file with hotel features for CS-Cart import
 */
if ($mode == 'export_hotel_features_xml') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    try {
        ob_start();
        $result = fn_novoton_holidays_generate_hotel_features_xml();
        ob_end_clean();

        if ($result['success']) {
            $download_url = fn_url('novoton_holidays.download_hotel_features_xml');
            fn_set_notification('N', __('notice'), "Hotel features XML generated! Hotels: {$result['count']}<br><a href=\"{$download_url}\" style=\"color:#0057b8;font-weight:600;text-decoration:underline;\">Download novoton_hotel_features.xml</a>");
        } else {
            fn_set_notification('E', __('error'), "Failed: " . ($result['error'] ?? 'Unknown error'));
        }
    } catch (Exception $e) {
        ob_end_clean();
        fn_set_notification('E', __('error'), "Exception: " . $e->getMessage());
    }

    return [CONTROLLER_STATUS_REDIRECT, 'addons.update&addon=novoton_holidays'];
}

/**
 * Mode: download_hotel_features_xml
 * Download the generated XML file (static filename)
 */
if ($mode == 'download_hotel_features_xml') {
    $file_path = fn_get_files_dir_path() . 'novoton_reports/novoton_hotel_features.xml';

    if (!file_exists($file_path)) {
        fn_set_notification('E', __('error'), 'No XML export found. Please generate one first.');
        return [CONTROLLER_STATUS_REDIRECT, 'addons.update&addon=novoton_holidays'];
    }

    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="novoton_hotel_features.xml"');
    header('Content-Length: ' . filesize($file_path));
    readfile($file_path);
    exit;
}

/**
 * Mode: test_api
 * Test API connection and credentials — delegates to DiagnosticsService
 */
if ($mode == 'test_api') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    header('Content-Type: text/plain; charset=utf-8');

    $diag = Container::getInstance()->diagnosticsService();
    $result = $diag->testApiConnection();

    echo "========================================\n";
    echo "   NOVOTON API CONNECTION TEST\n";
    echo "========================================\n\n";

    echo "Configuration:\n";
    echo "- API URL: " . $result['config']['api_url'] . "\n";
    echo "- API User: " . $result['config']['api_user'] . "\n";
    echo "- API Password: " . ($result['config']['api_password_set'] ? '***SET***' : 'NOT SET') . "\n";
    echo "- API Key: " . ($result['config']['api_key_set'] ? '***SET***' : 'NOT SET') . "\n";
    echo "- Selected Countries: " . $result['config']['selected_countries'] . "\n\n";

    if ($result['success']) {
        echo "API instance created successfully.\n\n";
        echo "Testing getHotelList('BULGARIA', '%', '%', '%')...\n";
        echo "SUCCESS! " . $result['message'] . "\n\n";

        if ($result['sample_hotel']) {
            echo "Sample hotel:\n";
            echo "- ID: " . $result['sample_hotel']['id'] . "\n";
            echo "- Name: " . $result['sample_hotel']['name'] . "\n";
            echo "- City: " . $result['sample_hotel']['city'] . "\n";
        }
    } else {
        echo $result['message'] . "\n";
        if (!empty($result['error'])) {
            echo "Error: " . $result['error'] . "\n";
        }
        if (!empty($result['last_request'])) {
            echo "Last request:\n" . (is_array($result['last_request']) ? print_r($result['last_request'], true) : $result['last_request']) . "\n";
        }
        if (!empty($result['last_http_code'])) {
            echo "Last HTTP code: " . $result['last_http_code'] . "\n";
        }
        if (!empty($result['raw_response_preview'])) {
            echo "\nRaw response (first 500 chars):\n" . $result['raw_response_preview'] . "\n";
        }
    }

    exit;
}

/**
 * Mode: test_formats
 * Test room type and board name formatting (pure presentation, no service needed)
 */
if ($mode == 'test_formats') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    header('Content-Type: text/html; charset=utf-8');

    echo '<h2>Room Type Formatting Tests</h2>';
    echo '<table border="1" cellpadding="5">';
    echo '<tr><th>Input</th><th>Output</th></tr>';

    $test_rooms = ['DBL', 'DBL 2+1', 'DBL%202%2B1', 'SGL', 'TRP', 'STUDIO', 'APT', 'FAM 2+2'];
    foreach ($test_rooms as $room) {
        echo '<tr><td>' . htmlspecialchars($room) . '</td><td>' . fn_novoton_holidays_format_room_type($room) . '</td></tr>';
    }
    echo '</table>';

    echo '<h2>Board Name Formatting Tests</h2>';
    echo '<table border="1" cellpadding="5">';
    echo '<tr><th>Input</th><th>Output</th></tr>';

    $test_boards = ['AI', 'HB', 'FB', 'BB', 'RO', 'UAI', 'ALL INCL'];
    foreach ($test_boards as $board) {
        echo '<tr><td>' . htmlspecialchars($board) . '</td><td>' . fn_novoton_holidays_format_board_name($board) . '</td></tr>';
    }
    echo '</table>';

    exit;
}

/**
 * Mode: test_hotel_list
 * Test hotel list API call — delegates to DiagnosticsService
 */
if ($mode == 'test_hotel_list') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    header('Content-Type: text/html; charset=utf-8');

    $country = $_REQUEST['country'] ?? 'BULGARIA';
    $limit = (int)($_REQUEST['limit'] ?? 10);

    echo '<h2>Hotel List Test - ' . htmlspecialchars($country) . '</h2>';

    $diag = Container::getInstance()->diagnosticsService();
    $result = $diag->testHotelList($country, $limit);

    if ($result['success']) {
        echo "<p>Total hotels: {$result['total']}</p>";
        echo '<table border="1" cellpadding="5">';
        echo '<tr><th>ID</th><th>Name</th><th>City</th><th>Stars</th><th>Type</th></tr>';
        foreach ($result['hotels'] as $hotel) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($hotel['id']) . '</td>';
            echo '<td>' . htmlspecialchars($hotel['name']) . '</td>';
            echo '<td>' . htmlspecialchars($hotel['city']) . '</td>';
            echo '<td>' . htmlspecialchars($hotel['stars']) . '</td>';
            echo '<td>' . htmlspecialchars($hotel['type']) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    } else {
        echo '<p style="color:red">No results or error</p>';
        if (!empty($result['error'])) {
            echo '<pre>' . htmlspecialchars($result['error']) . '</pre>';
        }
    }

    exit;
}

/**
 * Mode: test_room_price
 * Test room price API call — delegates to DiagnosticsService
 */
if ($mode == 'test_room_price') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    header('Content-Type: text/html; charset=utf-8');

    $hotel_id = $_REQUEST['hotel_id'] ?? '';
    $room_id = $_REQUEST['room_id'] ?? '';
    $board_id = $_REQUEST['board_id'] ?? 'AI';
    $check_in = $_REQUEST['check_in'] ?? date('Y-m-d', strtotime('+' . Constants::DEFAULT_CHECKIN_DAYS_AHEAD . ' days'));
    $check_out = $_REQUEST['check_out'] ?? date('Y-m-d', strtotime('+' . (Constants::DEFAULT_CHECKIN_DAYS_AHEAD + Constants::DEFAULT_STAY_NIGHTS) . ' days'));
    $adults = (int)($_REQUEST['adults'] ?? 2);

    echo '<h2>Room Price Test</h2>';

    echo '<form method="get">';
    echo '<input type="hidden" name="dispatch" value="novoton_holidays.test_room_price">';
    echo '<p>Hotel ID: <input name="hotel_id" value="' . htmlspecialchars($hotel_id) . '"></p>';
    echo '<p>Room ID: <input name="room_id" value="' . htmlspecialchars($room_id) . '"></p>';
    echo '<p>Board ID: <input name="board_id" value="' . htmlspecialchars($board_id) . '"></p>';
    echo '<p>Check In: <input type="date" name="check_in" value="' . htmlspecialchars($check_in) . '"></p>';
    echo '<p>Check Out: <input type="date" name="check_out" value="' . htmlspecialchars($check_out) . '"></p>';
    echo '<p>Adults: <input type="number" name="adults" value="' . $adults . '"></p>';
    echo '<p><button type="submit">Test</button></p>';
    echo '</form>';

    if (!empty($hotel_id)) {
        $diag = Container::getInstance()->diagnosticsService();
        $result = $diag->testRoomPrice([
            'hotel_id' => $hotel_id,
            'room_id' => $room_id,
            'board_id' => $board_id,
            'check_in' => $check_in,
            'check_out' => $check_out,
            'adults' => $adults,
        ]);

        echo '<h3>Request Parameters:</h3>';
        echo '<pre>' . htmlspecialchars(print_r($result['params'], true)) . '</pre>';

        echo '<h3>Response:</h3>';
        if ($result['success']) {
            echo '<pre>' . htmlspecialchars(print_r($result['result'], true)) . '</pre>';

            if ($result['price'] > 0) {
                echo '<p><strong>Price: &euro;' . htmlspecialchars((string)$result['price']) . ' (with commission: &euro;' . htmlspecialchars((string)$result['price_with_commission']) . ')</strong></p>';
            }
        } else {
            echo '<p style="color:red">' . htmlspecialchars($result['error'] ?: 'No result') . '</p>';
        }

        if (!empty($result['raw_response'])) {
            echo '<h3>Raw Response:</h3>';
            echo '<pre>' . htmlspecialchars($result['raw_response']) . '</pre>';
        }
    }

    exit;
}

/**
 * Mode: test_search
 * Test availability search API — delegates to DiagnosticsService
 */
if ($mode == 'test_search') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    header('Content-Type: text/html; charset=utf-8');

    $hotel_id = $_REQUEST['hotel_id'] ?? '';
    $check_in = $_REQUEST['check_in'] ?? date('Y-m-d', strtotime('+' . Constants::DEFAULT_CHECKIN_DAYS_AHEAD . ' days'));
    $check_out = $_REQUEST['check_out'] ?? date('Y-m-d', strtotime('+' . (Constants::DEFAULT_CHECKIN_DAYS_AHEAD + Constants::DEFAULT_STAY_NIGHTS) . ' days'));
    $adults = (int)($_REQUEST['adults'] ?? 2);
    $children = (int)($_REQUEST['children'] ?? 0);

    echo '<h2>Search Availability Test</h2>';

    echo '<form method="get">';
    echo '<input type="hidden" name="dispatch" value="novoton_holidays.test_search">';
    echo '<p>Hotel ID (optional): <input name="hotel_id" value="' . htmlspecialchars($hotel_id) . '"></p>';
    echo '<p>Check In: <input type="date" name="check_in" value="' . htmlspecialchars($check_in) . '"></p>';
    echo '<p>Check Out: <input type="date" name="check_out" value="' . htmlspecialchars($check_out) . '"></p>';
    echo '<p>Adults: <input type="number" name="adults" value="' . $adults . '"></p>';
    echo '<p>Children: <input type="number" name="children" value="' . $children . '"></p>';
    echo '<p><button type="submit">Search</button></p>';
    echo '</form>';

    if (!empty($_REQUEST['check_in'])) {
        $diag = Container::getInstance()->diagnosticsService();
        $result = $diag->testSearch([
            'hotel_id' => $hotel_id,
            'check_in' => $check_in,
            'check_out' => $check_out,
            'adults' => $adults,
            'children' => $children,
        ]);

        if (!empty($result['error'])) {
            echo '<p style="color:red">Error: ' . htmlspecialchars($result['error']) . '</p>';
        } elseif ($result['count'] > 0) {
            echo '<p>Found ' . $result['count'] . ' results</p>';
            echo '<table border="1" cellpadding="5">';
            echo '<tr><th>Hotel</th><th>Room</th><th>Board</th><th>Price</th></tr>';

            $count = 0;
            foreach ($result['results'] as $item) {
                if ($count >= 20) break;
                echo '<tr>';
                echo '<td>' . htmlspecialchars($item['hotel_name'] ?? $item['hotel_id'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($item['room_id'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($item['board_id'] ?? '') . '</td>';
                echo '<td>&euro;' . number_format($item['price'] ?? 0, 2) . '</td>';
                echo '</tr>';
                $count++;
            }
            echo '</table>';
        } else {
            echo '<p>No availability found</p>';
        }
    }

    exit;
}

/**
 * Mode: test_facilities
 * Test facilities sync — delegates to DiagnosticsService
 */
if ($mode == 'test_facilities') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    header('Content-Type: text/html; charset=utf-8');

    echo '<h2>Facilities Sync Test</h2>';

    $diag = Container::getInstance()->diagnosticsService();
    $result = $diag->testFacilities();

    if ($result['success']) {
        echo '<p style="color:green">Success!</p>';
        echo '<p>Total: ' . ($result['result']['total'] ?? 0) . '</p>';
        echo '<p>Added: ' . ($result['result']['added'] ?? 0) . '</p>';
        echo '<p>Updated: ' . ($result['result']['updated'] ?? 0) . '</p>';
    } else {
        echo '<p style="color:red">Failed: ' . htmlspecialchars($result['error'] ?: 'Unknown error') . '</p>';
    }

    echo '<h3>Current Facilities (first 50):</h3>';
    echo '<table border="1" cellpadding="5">';
    echo '<tr><th>ID</th><th>Name (EN)</th><th>Name (RO)</th></tr>';
    foreach ($result['facilities'] as $f) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars((string)$f['facility_id']) . '</td>';
        echo '<td>' . htmlspecialchars($f['facility_name_en']) . '</td>';
        echo '<td>' . htmlspecialchars($f['facility_name_ro']) . '</td>';
        echo '</tr>';
    }
    echo '</table>';

    exit;
}

/**
 * Mode: test_hotel_request
 * Diagnostic page for hotel info request (uses Smarty templates, kept as-is)
 */
if ($mode == 'test_hotel_request') {
    $hotel_id = $_REQUEST['hotel_id'] ?? '';

    // Pass all form values back to template so they persist after submission (sanitized for XSS)
    Tygh::$app['view']->assign('hotel_id', htmlspecialchars($hotel_id, ENT_QUOTES, 'UTF-8'));
    Tygh::$app['view']->assign('package_name', htmlspecialchars($_REQUEST['package_name'] ?? '', ENT_QUOTES, 'UTF-8'));
    Tygh::$app['view']->assign('check_in', htmlspecialchars($_REQUEST['check_in'] ?? '', ENT_QUOTES, 'UTF-8'));
    Tygh::$app['view']->assign('check_out', htmlspecialchars($_REQUEST['check_out'] ?? '', ENT_QUOTES, 'UTF-8'));
    Tygh::$app['view']->assign('adults', htmlspecialchars($_REQUEST['adults'] ?? '2', ENT_QUOTES, 'UTF-8'));
    Tygh::$app['view']->assign('room_id', htmlspecialchars($_REQUEST['room_id'] ?? '', ENT_QUOTES, 'UTF-8'));
    Tygh::$app['view']->assign('board_id', htmlspecialchars($_REQUEST['board_id'] ?? '', ENT_QUOTES, 'UTF-8'));
    Tygh::$app['view']->assign('holder', htmlspecialchars($_REQUEST['holder'] ?? '', ENT_QUOTES, 'UTF-8'));

    if (!empty($hotel_id)) {
        try {
            $api = new NovotonApi();

            $hotel_info = $api->getHotelInfo($hotel_id);
            $last_request = $api->getLastRequestFormatted();
            $last_response = $api->getLastResponse();

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
 * Test alternative search (uses Smarty templates, kept as-is)
 */
if ($mode == 'test_alternative_rs') {
    $hotel_id = $_REQUEST['hotel_id'] ?? '';
    $id_num = $_REQUEST['id_num'] ?? '';
    $check_in = $_REQUEST['check_in'] ?? date('Y-m-d', strtotime('+' . Constants::DEFAULT_CHECKIN_DAYS_AHEAD . ' days'));
    $check_out = $_REQUEST['check_out'] ?? date('Y-m-d', strtotime('+' . (Constants::DEFAULT_CHECKIN_DAYS_AHEAD + Constants::DEFAULT_STAY_NIGHTS) . ' days'));

    Tygh::$app['view']->assign('hotel_id', htmlspecialchars($hotel_id, ENT_QUOTES, 'UTF-8'));
    Tygh::$app['view']->assign('id_num', htmlspecialchars($id_num, ENT_QUOTES, 'UTF-8'));
    Tygh::$app['view']->assign('check_in', htmlspecialchars($check_in, ENT_QUOTES, 'UTF-8'));
    Tygh::$app['view']->assign('check_out', htmlspecialchars($check_out, ENT_QUOTES, 'UTF-8'));

    if (!empty($_REQUEST['search']) && !empty($hotel_id)) {
        try {
            $api = new NovotonApi();

            $params = [
                'hotel_id' => $hotel_id,
                'check_in' => $check_in,
                'check_out' => $check_out,
                'adults' => (int)($_REQUEST['adults'] ?? 2),
                'children' => (int)($_REQUEST['children'] ?? 0),
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
 * Test single product price data — delegates to DiagnosticsService
 */
if ($mode == 'test_product') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    header('Content-Type: text/plain; charset=utf-8');

    $product_code = $_REQUEST['product_code'] ?? '';
    if ($product_code === '') {
        echo "ERROR: product_code parameter is required.\n";
        exit;
    }

    echo "========================================\n";
    echo "TEST SINGLE PRODUCT: $product_code\n";
    echo "========================================\n\n";

    $diag = Container::getInstance()->diagnosticsService();
    $result = $diag->testProduct($product_code);

    if (!$result['product']) {
        echo $result['error'] . "\n";
        exit;
    }

    echo "Product ID: {$result['product']['product_id']}\n";
    echo "Product Name: {$result['product']['product']}\n";
    echo "Product Code: {$result['product']['product_code']}\n\n";
    echo "Extracted Hotel ID: {$result['hotel_id']}\n\n";

    if (!$result['success']) {
        echo $result['error'] . "\n";
        exit;
    }

    echo "=== STEP 1: Get Hotel Info ===\n";
    echo "Hotel info retrieved successfully\n";

    if (!empty($result['hotel_info']['packages'])) {
        echo "Packages found\n";
        echo "Package count: " . count($result['hotel_info']['packages']) . "\n\n";
        foreach ($result['hotel_info']['packages'] as $idx => $pkgName) {
            echo "  " . ($idx + 1) . ". " . $pkgName . "\n";
        }
    }

    if (!empty($result['hotel_info']['rooms'])) {
        echo "\n=== ROOMS ===\n";
        foreach ($result['hotel_info']['rooms'] as $room) {
            echo "  - " . $room['id'] . " (Max: " . $room['max_adults'] . " adults)\n";
        }
    }

    if (!empty($result['hotel_info']['boards'])) {
        echo "\n=== BOARDS ===\n";
        foreach ($result['hotel_info']['boards'] as $boardId) {
            echo "  - {$boardId}\n";
        }
    }

    echo "\n=== STEP 2: Check Package Data ===\n";
    echo "Packages in database: " . count($result['packages_db']) . "\n";

    if (!empty($result['packages_db'])) {
        echo "\nStored packages:\n";
        foreach ($result['packages_db'] as $pkg) {
            echo "  - {$pkg['package_name']}: min EUR{$pkg['min_price']}, " .
                 "early_booking={$pkg['has_early_booking']}, synced={$pkg['synced_at']}\n";
        }
    } else {
        echo "No packages found. Run sync_hotels cron to populate.\n";
    }

    exit;
}

/**
 * Mode: cron_export_hotel_features
 * Cron job to export hotel features CSV
 */
if ($mode == 'cron_export_hotel_features') {
    $expected_key = ConfigProvider::getCronAccessKey();
    $provided_key = $_REQUEST['access_key'] ?? '';

    header('Content-Type: text/plain; charset=utf-8');

    if (empty($expected_key)) {
        echo "[ERROR] Cron API key not configured.\n";
        exit;
    }

    if (!hash_equals($expected_key, $provided_key)) {
        echo "[ERROR] Invalid API key.\n";
        exit;
    }

    echo "=== NOVOTON Star Ratings CSV Export - " . date('Y-m-d H:i:s') . " ===\n";

    $result = fn_novoton_holidays_generate_hotel_features_csv();

    if ($result['success']) {
        echo "Status: SUCCESS\n";
        echo "File: " . ($result['file_path'] ?? $result['filename'] ?? 'N/A') . "\n";
        echo "Hotels: {$result['count']}\n";
        echo "[Good] CSV ready for CS-Cart import.\n";
    } else {
        echo "Status: FAILED\n";
        echo "Error: " . ($result['error'] ?? 'Unknown error') . "\n";
    }

    exit;
}

/**
 * Mode: get_hotel_features_csv
 * Direct download with API key authentication
 */
if ($mode == 'get_hotel_features_csv') {
    $expected_key = ConfigProvider::getCronAccessKey();
    $provided_key = $_REQUEST['access_key'] ?? '';

    if (empty($expected_key) || !hash_equals($expected_key, $provided_key)) {
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
        echo "admin.php?dispatch=novoton_holidays.cron_export_hotel_features&access_key=YOUR_ACCESS_KEY";
        exit;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="hotel_features_import.csv"');
    header('Content-Length: ' . filesize($file_path));
    header('Cache-Control: no-cache, must-revalidate');
    readfile($file_path);
    exit;
}

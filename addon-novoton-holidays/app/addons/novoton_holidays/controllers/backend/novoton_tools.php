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
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\NovotonHolidays\Services\DiagnosticsService;
use Tygh\Addons\NovotonHolidays\Services\PriceInfoFormatter;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

/** @var \Smarty $view */
$view = Tygh::$app['view'];

/**
 * Mode: export_hotel_features_csv
 * Generate and immediately download CSV file with hotel features
 */
if ($mode === 'export_hotel_features_csv') {
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

        fn_set_notification('E', __('error'), "Failed: " . $result['error']);
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
if ($mode === 'export_hotel_features_xml') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    try {
        ob_start();
        $result = fn_novoton_holidays_generate_hotel_features_xml();
        ob_end_clean();

        if (TypeCoerce::toBool($result['success'])) {
            $download_url = TypeCoerce::toString(fn_url('novoton_holidays.download_hotel_features_xml'));
            $xmlCount = TypeCoerce::toInt($result['count']);
            fn_set_notification('N', __('notice'), "Hotel features XML generated! Hotels: {$xmlCount}<br><a href=\"{$download_url}\" style=\"color:#0057b8;font-weight:600;text-decoration:underline;\">Download novoton_hotel_features.xml</a>");
        } else {
            fn_set_notification('E', __('error'), "Failed: " . TypeCoerce::toString($result['error'] ?? 'Unknown error'));
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
if ($mode === 'download_hotel_features_xml') {
    $file_path = TypeCoerce::toString(fn_get_files_dir_path()) . 'novoton_reports/novoton_hotel_features.xml';

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
if ($mode === 'test_api') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    header('Content-Type: text/plain; charset=utf-8');

    $diag = Container::getInstance()->diagnosticsService();
    $result = $diag->testApiConnection();
    $config = TypeCoerce::toStringMap($result['config']);
    $sampleHotel = TypeCoerce::toStringMap($result['sample_hotel'] ?? null);

    echo "========================================\n";
    echo "   NOVOTON API CONNECTION TEST\n";
    echo "========================================\n\n";

    echo "Configuration:\n";
    echo '- API URL: ' . TypeCoerce::toString($config['api_url'] ?? '') . "\n";
    echo '- API User: ' . TypeCoerce::toString($config['api_user'] ?? '') . "\n";
    echo '- API Password: ' . (!empty($config['api_password_set']) ? '***SET***' : 'NOT SET') . "\n";
    echo '- API Key: ' . (!empty($config['api_key_set']) ? '***SET***' : 'NOT SET') . "\n";
    echo '- Selected Countries: ' . TypeCoerce::toString($config['selected_countries'] ?? '') . "\n\n";

    if (!empty($result['success'])) {
        echo "API instance created successfully.\n\n";
        echo "Testing getHotelList('BULGARIA', '%', '%', '%')...\n";
        echo 'SUCCESS! ' . TypeCoerce::toString($result['message']) . "\n\n";

        if ($sampleHotel !== []) {
            echo "Sample hotel:\n";
            echo '- ID: ' . TypeCoerce::toString($sampleHotel['id'] ?? '') . "\n";
            echo '- Name: ' . TypeCoerce::toString($sampleHotel['name'] ?? '') . "\n";
            echo '- City: ' . TypeCoerce::toString($sampleHotel['city'] ?? '') . "\n";
        }
    } else {
        echo TypeCoerce::toString($result['message']) . "\n";
        if (!empty($result['error'])) {
            echo 'Error: ' . TypeCoerce::toString($result['error']) . "\n";
        }
        if (!empty($result['last_request'])) {
            $lastReq = $result['last_request'];
            $lastReqStr = is_array($lastReq)
                ? (string) json_encode($lastReq, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                : TypeCoerce::toString($lastReq);
            echo "Last request:\n" . $lastReqStr . "\n";
        }
        if (!empty($result['last_http_code'])) {
            echo 'Last HTTP code: ' . TypeCoerce::toString($result['last_http_code']) . "\n";
        }
        if (!empty($result['raw_response_preview'])) {
            echo "\nRaw response (first 500 chars):\n" . TypeCoerce::toString($result['raw_response_preview']) . "\n";
        }
    }

    exit;
}

/**
 * Mode: test_formats
 * Test room type and board name formatting (pure presentation, no service needed)
 */
if ($mode === 'test_formats') {
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
if ($mode === 'test_hotel_list') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    header('Content-Type: text/html; charset=utf-8');

    $country = RequestCoerce::string($_REQUEST, 'country', 'BULGARIA');
    $limit = RequestCoerce::int($_REQUEST, 'limit', 10);

    echo '<h2>Hotel List Test - ' . htmlspecialchars($country) . '</h2>';

    $diag = Container::getInstance()->diagnosticsService();
    $result = $diag->testHotelList($country, $limit);

    if (!empty($result['success'])) {
        $rTotal = PriceInfoFormatter::toInt($result['total']);
        echo "<p>Total hotels: {$rTotal}</p>";
        echo '<table border="1" cellpadding="5">';
        echo '<tr><th>ID</th><th>Name</th><th>City</th><th>Stars</th><th>Type</th></tr>';
        $rHotels = $result['hotels'];
        foreach ($rHotels as $hotel) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars(PriceInfoFormatter::toScalar($hotel['id'] ?? '')) . '</td>';
            echo '<td>' . htmlspecialchars(PriceInfoFormatter::toScalar($hotel['name'] ?? '')) . '</td>';
            echo '<td>' . htmlspecialchars(PriceInfoFormatter::toScalar($hotel['city'] ?? '')) . '</td>';
            echo '<td>' . htmlspecialchars(PriceInfoFormatter::toScalar($hotel['stars'] ?? '')) . '</td>';
            echo '<td>' . htmlspecialchars(PriceInfoFormatter::toScalar($hotel['type'] ?? '')) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    } else {
        echo '<p style="color:red">No results or error</p>';
        if (!empty($result['error'])) {
            echo '<pre>' . htmlspecialchars(PriceInfoFormatter::toScalar($result['error'])) . '</pre>';
        }
    }

    exit;
}

/**
 * Mode: test_room_price
 * Test room price API call — delegates to DiagnosticsService
 */
if ($mode === 'test_room_price') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    header('Content-Type: text/html; charset=utf-8');

    $hotel_id = RequestCoerce::string($_REQUEST, 'hotel_id', '');
    $room_id = RequestCoerce::string($_REQUEST, 'room_id', '');
    $board_id = RequestCoerce::string($_REQUEST, 'board_id', 'AI');
    $check_in = RequestCoerce::string($_REQUEST, 'check_in', date('Y-m-d', strtotime('+' . Constants::DEFAULT_CHECKIN_DAYS_AHEAD . ' days')));
    $check_out = RequestCoerce::string($_REQUEST, 'check_out', date('Y-m-d', strtotime('+' . (Constants::DEFAULT_CHECKIN_DAYS_AHEAD + Constants::DEFAULT_STAY_NIGHTS) . ' days')));
    $adults = RequestCoerce::int($_REQUEST, 'adults', 2);

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
        echo '<pre>' . htmlspecialchars((string) json_encode($result['params'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';

        echo '<h3>Response:</h3>';
        if ($result['success']) {
            echo '<pre>' . htmlspecialchars((string) json_encode($result['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';

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
if ($mode === 'test_search') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    header('Content-Type: text/html; charset=utf-8');

    $hotel_id = RequestCoerce::string($_REQUEST, 'hotel_id', '');
    $check_in = RequestCoerce::string($_REQUEST, 'check_in', date('Y-m-d', strtotime('+' . Constants::DEFAULT_CHECKIN_DAYS_AHEAD . ' days')));
    $check_out = RequestCoerce::string($_REQUEST, 'check_out', date('Y-m-d', strtotime('+' . (Constants::DEFAULT_CHECKIN_DAYS_AHEAD + Constants::DEFAULT_STAY_NIGHTS) . ' days')));
    $adults = RequestCoerce::int($_REQUEST, 'adults', 2);
    $children = RequestCoerce::int($_REQUEST, 'children', 0);

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
                echo '<td>' . htmlspecialchars(PriceInfoFormatter::toScalar($item['hotel_name'] ?? $item['hotel_id'] ?? '')) . '</td>';
                echo '<td>' . htmlspecialchars(PriceInfoFormatter::toScalar($item['room_id'] ?? '')) . '</td>';
                echo '<td>' . htmlspecialchars(PriceInfoFormatter::toScalar($item['board_id'] ?? '')) . '</td>';
                echo '<td>&euro;' . number_format(PriceInfoFormatter::toFloat($item['price'] ?? 0), 2) . '</td>';
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
if ($mode === 'test_facilities') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    header('Content-Type: text/html; charset=utf-8');

    echo '<h2>Facilities Sync Test</h2>';

    $diag = Container::getInstance()->diagnosticsService();
    $result = $diag->testFacilities();

    if ($result['success']) {
        echo '<p style="color:green">Success!</p>';
        echo '<p>Total: ' . TypeCoerce::toString($result['result']['total'] ?? 0) . '</p>';
        echo '<p>Added: ' . TypeCoerce::toString($result['result']['added'] ?? 0) . '</p>';
        echo '<p>Updated: ' . TypeCoerce::toString($result['result']['updated'] ?? 0) . '</p>';
    } else {
        echo '<p style="color:red">Failed: ' . htmlspecialchars($result['error'] ?: 'Unknown error') . '</p>';
    }

    echo '<h3>Current Facilities (first 50):</h3>';
    echo '<table border="1" cellpadding="5">';
    echo '<tr><th>ID</th><th>Name (EN)</th><th>Name (RO)</th></tr>';
    foreach ($result['facilities'] as $f) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars(TypeCoerce::toString($f['facility_id'])) . '</td>';
        echo '<td>' . htmlspecialchars(PriceInfoFormatter::toScalar($f['facility_name_en'])) . '</td>';
        echo '<td>' . htmlspecialchars(PriceInfoFormatter::toScalar($f['facility_name_ro'])) . '</td>';
        echo '</tr>';
    }
    echo '</table>';

    exit;
}

/**
 * Mode: test_hotel_request
 * Diagnostic page for hotel info request (uses Smarty templates, kept as-is)
 */
if ($mode === 'test_hotel_request') {
    $hotel_id = RequestCoerce::string($_REQUEST, 'hotel_id');

    // Pass all form values back to template so they persist after submission (sanitized for XSS)
    $view->assign('hotel_id', htmlspecialchars($hotel_id, ENT_QUOTES, 'UTF-8'));
    $view->assign('package_name', htmlspecialchars(RequestCoerce::string($_REQUEST, 'package_name', ''), ENT_QUOTES, 'UTF-8'));
    $view->assign('check_in', htmlspecialchars(RequestCoerce::string($_REQUEST, 'check_in', ''), ENT_QUOTES, 'UTF-8'));
    $view->assign('check_out', htmlspecialchars(RequestCoerce::string($_REQUEST, 'check_out', ''), ENT_QUOTES, 'UTF-8'));
    $view->assign('adults', htmlspecialchars(RequestCoerce::string($_REQUEST, 'adults', '2'), ENT_QUOTES, 'UTF-8'));
    $view->assign('room_id', htmlspecialchars(RequestCoerce::string($_REQUEST, 'room_id', ''), ENT_QUOTES, 'UTF-8'));
    $view->assign('board_id', htmlspecialchars(RequestCoerce::string($_REQUEST, 'board_id', ''), ENT_QUOTES, 'UTF-8'));
    $view->assign('holder', htmlspecialchars(RequestCoerce::string($_REQUEST, 'holder', ''), ENT_QUOTES, 'UTF-8'));

    if (!empty($hotel_id)) {
        try {
            $api = new NovotonApi();

            $hotels = $api->hotels();
            $hotel_info = $hotels->getHotelInfo($hotel_id);
            $last_request = $api->getLastRequestFormatted();
            $last_response = $api->getLastResponse();

            $hotel_desc = $hotels->getHotelDescription($hotel_id, 'UK', true);

            $view->assign('hotel_info', $hotel_info);
            $view->assign('hotel_desc', $hotel_desc);
            $view->assign('last_request', $last_request);
            $view->assign('last_response', $last_response);

        } catch (Exception $e) {
            $view->assign('error', $e->getMessage());
        }
    }
}

/**
 * Mode: test_alternative_rs
 * Test alternative search (uses Smarty templates, kept as-is)
 */
if ($mode === 'test_alternative_rs') {
    $hotel_id = RequestCoerce::string($_REQUEST, 'hotel_id');
    $id_num = RequestCoerce::string($_REQUEST, 'id_num');
    $check_in = RequestCoerce::string($_REQUEST, 'check_in', date('Y-m-d', strtotime('+' . Constants::DEFAULT_CHECKIN_DAYS_AHEAD . ' days')));
    $check_out = RequestCoerce::string($_REQUEST, 'check_out', date('Y-m-d', strtotime('+' . (Constants::DEFAULT_CHECKIN_DAYS_AHEAD + Constants::DEFAULT_STAY_NIGHTS) . ' days')));

    $view->assign('hotel_id', htmlspecialchars($hotel_id, ENT_QUOTES, 'UTF-8'));
    $view->assign('id_num', htmlspecialchars($id_num, ENT_QUOTES, 'UTF-8'));
    $view->assign('check_in', htmlspecialchars($check_in, ENT_QUOTES, 'UTF-8'));
    $view->assign('check_out', htmlspecialchars($check_out, ENT_QUOTES, 'UTF-8'));

    if (!empty($_REQUEST['search']) && !empty($hotel_id)) {
        try {
            $api = new NovotonApi();

            $params = [
                'hotel_id' => $hotel_id,
                'check_in' => $check_in,
                'check_out' => $check_out,
                'adults' => RequestCoerce::int($_REQUEST, 'adults', 2),
                'children' => RequestCoerce::int($_REQUEST, 'children', 0),
            ];

            $results = $api->availability()->searchAvailability($params);

            $view->assign('results', $results);
            $view->assign('last_request', $api->getLastRequestFormatted());

        } catch (Exception $e) {
            $view->assign('error', $e->getMessage());
        }
    }
}

/**
 * Mode: test_product
 * Test single product price data — delegates to DiagnosticsService
 */
if ($mode === 'test_product') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    header('Content-Type: text/plain; charset=utf-8');

    $product_code = RequestCoerce::string($_REQUEST, 'product_code');
    if ($product_code === '') {
        echo "ERROR: product_code parameter is required.\n";
        exit;
    }

    echo "========================================\n";
    echo "TEST SINGLE PRODUCT: $product_code\n";
    echo "========================================\n\n";

    $diag = Container::getInstance()->diagnosticsService();
    $result = $diag->testProduct($product_code);
    $product = TypeCoerce::toStringMap($result['product'] ?? null);
    $hotelInfo = TypeCoerce::toStringMap($result['hotel_info'] ?? []);
    $packagesDb = TypeCoerce::toRowList($result['packages_db']);

    if ($product === []) {
        echo TypeCoerce::toString($result['error']) . "\n";
        exit;
    }

    echo 'Product ID: ' . TypeCoerce::toString($product['product_id'] ?? '') . "\n";
    echo 'Product Name: ' . TypeCoerce::toString($product['product'] ?? '') . "\n";
    echo 'Product Code: ' . TypeCoerce::toString($product['product_code'] ?? '') . "\n\n";
    echo 'Extracted Hotel ID: ' . TypeCoerce::toString($result['hotel_id']) . "\n\n";

    if (empty($result['success'])) {
        echo TypeCoerce::toString($result['error']) . "\n";
        exit;
    }

    echo "=== STEP 1: Get Hotel Info ===\n";
    echo "Hotel info retrieved successfully\n";

    $hotelPackages = TypeCoerce::toList($hotelInfo['packages'] ?? []);
    if ($hotelPackages !== []) {
        echo "Packages found\n";
        echo 'Package count: ' . count($hotelPackages) . "\n\n";
        foreach ($hotelPackages as $idx => $pkgName) {
            echo '  ' . (TypeCoerce::toInt($idx) + 1) . '. ' . TypeCoerce::toString($pkgName) . "\n";
        }
    }

    $hotelRooms = TypeCoerce::toRowList($hotelInfo['rooms'] ?? []);
    if ($hotelRooms !== []) {
        echo "\n=== ROOMS ===\n";
        foreach ($hotelRooms as $room) {
            echo '  - ' . TypeCoerce::toString($room['id'] ?? '')
                . ' (Max: ' . TypeCoerce::toString($room['max_adults'] ?? '') . " adults)\n";
        }
    }

    $hotelBoards = TypeCoerce::toList($hotelInfo['boards'] ?? []);
    if ($hotelBoards !== []) {
        echo "\n=== BOARDS ===\n";
        foreach ($hotelBoards as $boardId) {
            echo '  - ' . TypeCoerce::toString($boardId) . "\n";
        }
    }

    echo "\n=== STEP 2: Check Package Data ===\n";
    echo 'Packages in database: ' . count($packagesDb) . "\n";

    if ($packagesDb !== []) {
        echo "\nStored packages:\n";
        foreach ($packagesDb as $pkg) {
            echo '  - ' . TypeCoerce::toString($pkg['package_name'] ?? '')
                . ': min EUR' . TypeCoerce::toString($pkg['min_price'] ?? '')
                . ', early_booking=' . TypeCoerce::toString($pkg['has_early_booking'] ?? '')
                . ', synced=' . TypeCoerce::toString($pkg['synced_at'] ?? '') . "\n";
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
if ($mode === 'cron_export_hotel_features') {
    $expected_key = ConfigProvider::getCronAccessKey();
    $provided_key = RequestCoerce::string($_REQUEST, 'access_key');

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
        echo "File: " . $result['file_path'] . "\n";
        echo "Hotels: {$result['count']}\n";
        echo "[Good] CSV ready for CS-Cart import.\n";
    } else {
        echo "Status: FAILED\n";
        echo "Error: " . $result['error'] . "\n";
    }

    exit;
}

/**
 * Mode: get_hotel_features_csv
 * Direct download with API key authentication
 */
if ($mode === 'get_hotel_features_csv') {
    $expected_key = ConfigProvider::getCronAccessKey();
    $provided_key = RequestCoerce::string($_REQUEST, 'access_key');

    if (empty($expected_key) || !hash_equals($expected_key, $provided_key)) {
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: text/plain');
        echo "Access denied. Invalid or missing API key.";
        exit;
    }

    $export_dir = TypeCoerce::toString(fn_get_files_dir_path()) . 'novoton_exports/';
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

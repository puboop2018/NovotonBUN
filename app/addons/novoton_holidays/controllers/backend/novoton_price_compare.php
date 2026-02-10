<?php
/**
 * Novoton Price Comparison Tool
 *
 * Compares prices calculated from priceinfo data with room_price API response.
 * Use this to verify the PriceInfoCalculation algorithm accuracy.
 *
 * Modes:
 * - (default): Show comparison form
 * - compare: Run comparison and show results
 *
 * @package NovotonHolidays
 * @since 3.0.0
 */

use Tygh\Registry;
use Tygh\Tygh;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

// Load required classes
$src_dir = Registry::get('config.dir.addons') . 'novoton_holidays/src/';
$services_dir = Registry::get('config.dir.addons') . 'novoton_holidays/services/';

if (!class_exists('Tygh\Addons\NovotonHolidays\NovotonApi') && file_exists($src_dir . 'NovotonApi.php')) {
    require_once($src_dir . 'NovotonApi.php');
}
if (!class_exists('Tygh\Addons\NovotonHolidays\Services\PriceInfoCalculation') && file_exists($services_dir . 'PriceInfoCalculation.php')) {
    require_once($services_dir . 'PriceInfoCalculation.php');
}

use Tygh\Addons\NovotonHolidays\NovotonApi;
use Tygh\Addons\NovotonHolidays\Services\PriceInfoCalculation;

/**
 * Mode: compare
 * Run price comparison
 */
if ($mode == 'compare') {
    header('Content-Type: text/html; charset=utf-8');

    $hotel_id = $_REQUEST['hotel_id'] ?? '';
    $package_name = $_REQUEST['package_name'] ?? '';
    $room_id = $_REQUEST['room_id'] ?? '';
    $board_id = $_REQUEST['board_id'] ?? '';
    $check_in = $_REQUEST['check_in'] ?? date('Y-m-d', strtotime('+30 days'));
    $nights = intval($_REQUEST['nights'] ?? 7);
    $adults = intval($_REQUEST['adults'] ?? 2);
    $children_ages = $_REQUEST['children_ages'] ?? '';
    $show_debug = isset($_REQUEST['debug']);

    // Parse children ages
    $children_arr = [];
    if (!empty($children_ages)) {
        $children_arr = array_map('floatval', explode(',', $children_ages));
    }

    $check_out = date('Y-m-d', strtotime($check_in . ' + ' . $nights . ' days'));

    echo '<!DOCTYPE html><html><head><title>Price Comparison Result</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
        h1 { color: #003580; margin-bottom: 5px; }
        h2 { color: #555; margin-top: 25px; border-bottom: 2px solid #003580; padding-bottom: 5px; }
        .params { background: #f0f0f0; padding: 15px; border-radius: 6px; margin-bottom: 20px; }
        .params strong { display: inline-block; width: 120px; }
        .result-box { padding: 20px; border-radius: 8px; margin: 15px 0; }
        .result-api { background: #e3f2fd; border: 2px solid #2196f3; }
        .result-calc { background: #e8f5e9; border: 2px solid #4caf50; }
        .result-diff { background: #fff3e0; border: 2px solid #ff9800; }
        .result-match { background: #e8f5e9; border: 2px solid #4caf50; }
        .result-mismatch { background: #ffebee; border: 2px solid #f44336; }
        .price-big { font-size: 32px; font-weight: bold; }
        .price-label { font-size: 14px; color: #666; }
        .comparison-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { padding: 8px 12px; border: 1px solid #ddd; text-align: left; }
        th { background: #f5f5f5; }
        .match { color: green; font-weight: bold; }
        .mismatch { color: red; font-weight: bold; }
        .btn { display: inline-block; padding: 10px 20px; background: #003580; color: white; text-decoration: none; border-radius: 4px; margin-top: 20px; }
        .debug-section { background: #f8f9fa; padding: 15px; border-radius: 6px; margin-top: 20px; font-family: monospace; font-size: 12px; max-height: 400px; overflow-y: auto; }
        pre { white-space: pre-wrap; word-wrap: break-word; }
    </style></head><body><div class="container">';

    echo '<h1>Price Comparison Result</h1>';

    echo '<div class="params">';
    echo "<strong>Hotel ID:</strong> {$hotel_id}<br>";
    echo "<strong>Package:</strong> {$package_name}<br>";
    echo "<strong>Room:</strong> " . rawurldecode($room_id) . "<br>";
    echo "<strong>Board:</strong> {$board_id}<br>";
    echo "<strong>Check-in:</strong> {$check_in}<br>";
    echo "<strong>Nights:</strong> {$nights}<br>";
    echo "<strong>Adults:</strong> {$adults}<br>";
    echo "<strong>Children:</strong> " . (empty($children_arr) ? 'None' : implode(', ', $children_arr) . ' years') . "<br>";
    echo '</div>';

    // 1. Calculate price using PriceInfoCalculation
    echo '<h2>1. Calculated Price (from priceinfo)</h2>';

    $calculator = new PriceInfoCalculation();
    $calculator->setDebug($show_debug);

    $calcResult = $calculator->calculate([
        'hotel_id' => $hotel_id,
        'package_name' => $package_name,
        'room_id' => $room_id,
        'board_id' => $board_id,
        'check_in' => $check_in,
        'nights' => $nights,
        'adults' => $adults,
        'children_ages' => $children_arr,
        'booking_date' => date('Y-m-d')
    ]);

    if ($calcResult['success']) {
        echo '<div class="result-box result-calc">';
        echo '<div class="price-label">Calculated Price (with ' . $calcResult['commission'] . '% commission)</div>';
        echo '<div class="price-big">' . number_format($calcResult['price'], 2) . ' EUR</div>';
        echo '<div class="price-label">Base: ' . number_format($calcResult['price_without_commission'], 2) . ' EUR</div>';
        echo '</div>';

        // Breakdown
        echo '<table>';
        echo '<tr><th>Component</th><th>Amount (EUR)</th></tr>';
        echo '<tr><td>Base Price</td><td>' . number_format($calcResult['breakdown']['base_price'], 2) . '</td></tr>';
        echo '<tr><td>Fees Total</td><td>' . number_format($calcResult['breakdown']['fees']['total_fees'], 2) . '</td></tr>';
        echo '<tr><td>&nbsp;&nbsp;- extras_daily</td><td>' . number_format($calcResult['breakdown']['fees']['extras_daily'], 2) . '</td></tr>';
        echo '<tr><td>&nbsp;&nbsp;- handling_fee</td><td>' . number_format($calcResult['breakdown']['fees']['handling_fee'], 2) . '</td></tr>';
        echo '<tr><td>&nbsp;&nbsp;- extras_single</td><td>' . number_format($calcResult['breakdown']['fees']['extras_single'], 2) . '</td></tr>';
        echo '<tr><td>Applied Discount</td><td>' . $calcResult['breakdown']['applied_discount'] . ' (-' . number_format($calcResult['breakdown']['discount_amount'], 2) . ')</td></tr>';
        echo '<tr style="font-weight:bold;"><td>Total (before commission)</td><td>' . number_format($calcResult['price_without_commission'], 2) . '</td></tr>';
        echo '<tr style="font-weight:bold;"><td>Total (with commission)</td><td>' . number_format($calcResult['price'], 2) . '</td></tr>';
        echo '</table>';
    } else {
        echo '<div class="result-box result-mismatch">';
        echo '<strong>Calculation Error:</strong> ' . htmlspecialchars($calcResult['error'] ?? 'Unknown error');
        echo '</div>';
    }

    // 2. Get price from room_price API
    echo '<h2>2. API Price (room_price)</h2>';

    $api = new NovotonApi();
    $apiResult = $api->getRoomPrice([
        'hotel_id' => $hotel_id,
        'room_id' => $room_id,
        'board_id' => $board_id,
        'check_in' => $check_in,
        'check_out' => $check_out,
        'adults' => $adults,
        'children' => $children_arr,
        'nocache' => true
    ]);

    $apiPrice = 0;
    $apiPriceFound = false;

    if ($apiResult) {
        // Parse API response to find matching room/board price
        $prices = $apiResult->xpath('//Price');
        $idRooms = $apiResult->xpath('//IdRoom');
        $boards = $apiResult->xpath('//Board');

        $numResults = min(count($prices), count($idRooms), count($boards));

        for ($i = 0; $i < $numResults; $i++) {
            $resultPrice = floatval((string)$prices[$i]);
            $resultRoom = rawurldecode((string)$idRooms[$i]);
            $resultBoard = (string)$boards[$i];

            // Match room and board
            $roomMatches = empty($room_id) ||
                           $resultRoom === $room_id ||
                           $resultRoom === rawurldecode($room_id);

            $boardMatches = empty($board_id) ||
                            strcasecmp($resultBoard, $board_id) === 0;

            if ($roomMatches && $boardMatches && $resultPrice > 0) {
                $apiPrice = $resultPrice;
                $apiPriceFound = true;
                break;
            }
        }

        // Fallback to first result if no exact match
        if (!$apiPriceFound && $numResults > 0) {
            $apiPrice = floatval((string)$prices[0]);
            $apiPriceFound = true;
        }
    }

    // Apply commission to API price for fair comparison
    $commission = floatval(Registry::get('addons.novoton_holidays.commission') ?? 0);
    $apiPriceWithCommission = $apiPrice * (1 + $commission / 100);

    if ($apiPriceFound) {
        echo '<div class="result-box result-api">';
        echo '<div class="price-label">API Price (with ' . $commission . '% commission)</div>';
        echo '<div class="price-big">' . number_format($apiPriceWithCommission, 2) . ' EUR</div>';
        echo '<div class="price-label">Base: ' . number_format($apiPrice, 2) . ' EUR</div>';
        echo '</div>';
    } else {
        echo '<div class="result-box result-mismatch">';
        echo '<strong>API Error:</strong> No price found for this room/board combination';
        echo '</div>';
    }

    // 3. Comparison
    echo '<h2>3. Comparison</h2>';

    if ($calcResult['success'] && $apiPriceFound) {
        $calcPrice = $calcResult['price'];
        $difference = $calcPrice - $apiPriceWithCommission;
        $percentDiff = $apiPriceWithCommission > 0 ? ($difference / $apiPriceWithCommission) * 100 : 0;

        $isMatch = abs($difference) < 1; // Within 1 EUR tolerance

        echo '<div class="result-box ' . ($isMatch ? 'result-match' : 'result-mismatch') . '">';
        echo '<div class="comparison-grid">';

        echo '<div>';
        echo '<div class="price-label">Calculated</div>';
        echo '<div class="price-big">' . number_format($calcPrice, 2) . '</div>';
        echo '</div>';

        echo '<div>';
        echo '<div class="price-label">API</div>';
        echo '<div class="price-big">' . number_format($apiPriceWithCommission, 2) . '</div>';
        echo '</div>';

        echo '<div>';
        echo '<div class="price-label">Difference</div>';
        echo '<div class="price-big ' . ($isMatch ? 'match' : 'mismatch') . '">';
        echo ($difference >= 0 ? '+' : '') . number_format($difference, 2);
        echo ' (' . ($percentDiff >= 0 ? '+' : '') . number_format($percentDiff, 1) . '%)';
        echo '</div>';
        echo '</div>';

        echo '</div>';

        if ($isMatch) {
            echo '<p style="text-align:center;color:green;font-weight:bold;margin-top:15px;">PRICES MATCH (within 1 EUR tolerance)</p>';
        } else {
            echo '<p style="text-align:center;color:red;font-weight:bold;margin-top:15px;">PRICES DO NOT MATCH - Algorithm needs adjustment</p>';
        }

        echo '</div>';

        // Comparison without commission
        echo '<h3>Comparison (without commission)</h3>';
        echo '<table>';
        echo '<tr><th></th><th>Calculated</th><th>API</th><th>Difference</th></tr>';
        echo '<tr>';
        echo '<td>Base Price</td>';
        echo '<td>' . number_format($calcResult['price_without_commission'], 2) . '</td>';
        echo '<td>' . number_format($apiPrice, 2) . '</td>';
        $baseDiff = $calcResult['price_without_commission'] - $apiPrice;
        echo '<td class="' . (abs($baseDiff) < 1 ? 'match' : 'mismatch') . '">' . ($baseDiff >= 0 ? '+' : '') . number_format($baseDiff, 2) . '</td>';
        echo '</tr>';
        echo '</table>';
    } else {
        echo '<div class="result-box result-mismatch">';
        echo 'Cannot compare - one or both prices unavailable';
        echo '</div>';
    }

    // Debug section
    if ($show_debug && $calcResult['success'] && !empty($calcResult['debug_log'])) {
        echo '<h2>Debug Log</h2>';
        echo '<div class="debug-section">';
        echo '<pre>' . htmlspecialchars(json_encode($calcResult['debug_log'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
        echo '</div>';

        // Show occupancy structure
        if (!empty($calcResult['occupancy'])) {
            echo '<h3>Occupancy Structure</h3>';
            echo '<div class="debug-section">';
            echo '<pre>' . htmlspecialchars(json_encode($calcResult['occupancy'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
            echo '</div>';
        }
    }

    // API raw response
    if ($show_debug && $apiResult) {
        echo '<h3>API Raw Response</h3>';
        echo '<div class="debug-section">';
        echo '<pre>' . htmlspecialchars($api->getLastResponse()) . '</pre>';
        echo '</div>';
    }

    echo '<a href="' . fn_url('novoton_price_compare.manage') . '" class="btn">&larr; Back to Form</a>';
    echo '</div></body></html>';
    exit;
}

/**
 * Mode: get_packages (AJAX)
 * Return packages for a hotel as JSON
 */
if ($mode == 'get_packages') {
    header('Content-Type: application/json; charset=utf-8');

    $hotel_id = $_REQUEST['hotel_id'] ?? '';

    if (empty($hotel_id)) {
        echo json_encode(['packages' => []]);
        exit;
    }

    $packages = db_get_array(
        "SELECT package_name FROM ?:novoton_hotel_packages
         WHERE hotel_id = ?s AND priceinfo_data IS NOT NULL
         ORDER BY package_name",
        $hotel_id
    );

    echo json_encode(['packages' => $packages]);
    exit;
}

/**
 * Default mode: Show comparison form
 */
if (empty($mode) || $mode == 'manage') {
    // Get list of hotels with packages
    $hotels = db_get_array(
        "SELECT DISTINCT h.hotel_id, h.hotel_name
         FROM ?:novoton_hotels h
         INNER JOIN ?:novoton_hotel_packages p ON h.hotel_id = p.hotel_id
         WHERE p.priceinfo_data IS NOT NULL
         ORDER BY h.hotel_name
         LIMIT 200"
    );

    Tygh::$app['view']->assign('hotels', $hotels);
    Tygh::$app['view']->assign('default_check_in', date('Y-m-d', strtotime('+30 days')));
}

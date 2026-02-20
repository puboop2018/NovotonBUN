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
use Tygh\Addons\NovotonHolidays\Services\ConfigService;

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
    $show_debug = \Tygh\Addons\NovotonHolidays\Services\ConfigService::isDebugMode();

    // Parse children ages
    $children_arr = [];
    if (!empty($children_ages)) {
        $children_arr = array_map('floatval', explode(',', $children_ages));
    }

    $check_out = date('Y-m-d', strtotime($check_in . ' + ' . $nights . ' days'));

    echo '<!DOCTYPE html><html><head><title>Price Comparison Result</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        h1 { color: #003580; margin-bottom: 5px; }
        h2 { color: #003580; margin-top: 30px; border-bottom: 2px solid #003580; padding-bottom: 5px; font-size: 16px; }
        h3 { color: #555; margin-top: 15px; font-size: 14px; }
        .params { background: #f0f4f8; padding: 15px; border-radius: 6px; margin-bottom: 20px; border-left: 4px solid #003580; }
        .params strong { display: inline-block; width: 120px; color: #333; }
        .result-box { padding: 20px; border-radius: 8px; margin: 15px 0; }
        .result-api { background: #e3f2fd; border: 2px solid #2196f3; }
        .result-calc { background: #e8f5e9; border: 2px solid #4caf50; }
        .result-match { background: #e8f5e9; border: 2px solid #4caf50; }
        .result-mismatch { background: #ffebee; border: 2px solid #f44336; }
        .price-big { font-size: 32px; font-weight: bold; }
        .price-label { font-size: 14px; color: #666; }
        .comparison-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; font-size: 13px; }
        th, td { padding: 6px 10px; border: 1px solid #ddd; text-align: left; }
        th { background: #f5f5f5; font-weight: 600; }
        .match { color: green; font-weight: bold; }
        .mismatch { color: red; font-weight: bold; }
        .step { background: #fafafa; border: 1px solid #e0e0e0; border-radius: 6px; padding: 15px; margin: 12px 0; }
        .step-header { display: flex; align-items: center; margin-bottom: 10px; }
        .step-num { background: #003580; color: white; border-radius: 50%; width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center; font-weight: bold; font-size: 13px; margin-right: 10px; flex-shrink: 0; }
        .step-title { font-weight: 600; color: #333; font-size: 14px; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; margin: 0 3px; }
        .badge-rb { background: #e3f2fd; color: #1565c0; }
        .badge-eb { background: #fff3e0; color: #e65100; }
        .badge-adult { background: #e8f5e9; color: #2e7d32; }
        .badge-child { background: #fce4ec; color: #c62828; }
        .badge-free { background: #c8e6c9; color: #1b5e20; }
        .badge-active { background: #4caf50; color: white; }
        .badge-inactive { background: #e0e0e0; color: #757575; }
        .badge-season { padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: bold; margin: 2px; color: white; }
        .season-1 { background: #4caf50; }
        .season-2 { background: #2196f3; }
        .season-3 { background: #ff9800; }
        .season-4 { background: #e91e63; }
        .season-5 { background: #9c27b0; }
        .season-6 { background: #009688; }
        .season-7 { background: #795548; }
        .formula { background: #f5f5f5; border: 1px solid #e0e0e0; border-radius: 4px; padding: 8px 12px; font-family: monospace; font-size: 13px; margin: 8px 0; display: inline-block; }
        .highlight-row { background: #fffde7 !important; }
        .total-row { font-weight: bold; background: #e8eaf6 !important; }
        .zero-value { color: #bbb; }
        .btn { display: inline-block; padding: 10px 20px; background: #003580; color: white; text-decoration: none; border-radius: 4px; margin-top: 20px; }
        .debug-section { background: #f8f9fa; padding: 15px; border-radius: 6px; margin-top: 20px; font-family: monospace; font-size: 12px; max-height: 400px; overflow-y: auto; border: 1px solid #e0e0e0; }
        pre { white-space: pre-wrap; word-wrap: break-word; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .info-note { font-size: 12px; color: #888; margin-top: 4px; }
    </style></head><body><div class="container">';

    echo '<h1>Price Comparison Result</h1>';

    echo '<div class="params">';
    echo "<strong>Hotel ID:</strong> " . htmlspecialchars($hotel_id) . "<br>";
    echo "<strong>Package:</strong> " . htmlspecialchars($package_name) . "<br>";
    echo "<strong>Room:</strong> " . htmlspecialchars(rawurldecode($room_id)) . "<br>";
    echo "<strong>Board:</strong> " . htmlspecialchars($board_id) . "<br>";
    echo "<strong>Check-in:</strong> {$check_in}<br>";
    echo "<strong>Check-out:</strong> {$check_out}<br>";
    echo "<strong>Nights:</strong> {$nights}<br>";
    echo "<strong>Adults:</strong> {$adults}<br>";
    echo "<strong>Children:</strong> " . (empty($children_arr) ? 'None' : implode(', ', $children_arr) . ' years') . "<br>";
    echo "<strong>Booking Date:</strong> " . date('Y-m-d') . "<br>";
    echo '</div>';

    // Run calculation with debug always ON for full step display
    $calculator = new PriceInfoCalculation();
    $calculator->setDebug(true);

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

    if (!$calcResult['success']) {
        echo '<div class="result-box result-mismatch">';
        echo '<strong>Calculation Error:</strong> ' . htmlspecialchars($calcResult['error'] ?? 'Unknown error');
        echo '</div>';
        echo '<a href="' . fn_url('novoton_price_compare.manage') . '" class="btn">&larr; Back to Form</a>';
        echo '</div></body></html>';
        exit;
    }

    // =========================================================================
    // STEP 1: Room Capacity & Age Bands
    // =========================================================================
    echo '<div class="step">';
    echo '<div class="step-header"><span class="step-num">1</span><span class="step-title">Room Capacity &amp; Hotel Age Bands</span></div>';

    $roomCap = $calcResult['room_capacity'] ?? [];
    echo '<div class="grid-2">';

    // Room capacity
    echo '<div>';
    echo '<table>';
    echo '<tr><th colspan="2">Room Capacity</th></tr>';
    echo '<tr><td>Regular Beds (RB)</td><td>' . ($roomCap['RB'] ?? '-') . '</td></tr>';
    echo '<tr><td>Extra Beds (EB)</td><td>' . ($roomCap['EB'] ?? '-') . '</td></tr>';
    echo '<tr><td>Max Adults</td><td>' . ($roomCap['maxADT'] ?? '-') . '</td></tr>';
    echo '<tr><td>Max Children</td><td>' . ($roomCap['maxCHD'] ?? '-') . '</td></tr>';
    echo '<tr><td>Min Persons</td><td>' . ($roomCap['minPAX'] ?? '-') . '</td></tr>';
    echo '</table>';
    echo '</div>';

    // Child age bands
    echo '<div>';
    $ageBands = $calcResult['child_age_bands'] ?? [];
    echo '<table>';
    echo '<tr><th colspan="3">Hotel Child Age Bands</th></tr>';
    echo '<tr><th>Band</th><th>From</th><th>To</th></tr>';
    if (!empty($ageBands)) {
        foreach ($ageBands as $ab) {
            echo '<tr>';
            echo '<td><span class="badge badge-child">' . htmlspecialchars($ab['label']) . '</span></td>';
            echo '<td>' . $ab['from'] . '</td>';
            echo '<td>' . $ab['to'] . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="3" class="zero-value">No hotel-specific age bands (using defaults: 0-1.99, 2-11.99, 12-17.99)</td></tr>';
    }
    echo '</table>';
    echo '</div>';
    echo '</div>'; // grid-2
    echo '</div>'; // step

    // =========================================================================
    // STEP 2: Occupancy Structure
    // =========================================================================
    echo '<div class="step">';
    echo '<div class="step-header"><span class="step-num">2</span><span class="step-title">Occupancy Structure (RB vs EB assignment)</span></div>';

    $occupancy = $calcResult['occupancy'] ?? [];
    echo '<table>';
    echo '<tr><th>#</th><th>Type</th><th>Age Type</th><th>Bed Type</th><th>Details</th></tr>';

    $personNum = 1;
    if (!empty($occupancy['adults'])) {
        foreach ($occupancy['adults'] as $a) {
            $accBadge = ($a['acc_type'] ?? '') === 'REGULAR' ? 'badge-rb' : 'badge-eb';
            $accLabel = ($a['acc_type'] ?? '') === 'REGULAR' ? 'RB' : 'EB';
            echo '<tr>';
            echo '<td>' . $personNum++ . '</td>';
            echo '<td><span class="badge badge-adult">Adult</span></td>';
            echo '<td>' . htmlspecialchars($a['age_type'] ?? 'ADULT') . '</td>';
            echo '<td><span class="badge ' . $accBadge . '">' . $accLabel . '</span></td>';
            echo '<td>Position ' . ($a['position'] ?? $a['index'] ?? '-') . '</td>';
            echo '</tr>';
        }
    }
    if (!empty($occupancy['children'])) {
        foreach ($occupancy['children'] as $c) {
            $accBadge = in_array(($c['acc_type'] ?? ''), ['REGULAR', 'RB']) ? 'badge-rb' : 'badge-eb';
            $accLabel = in_array(($c['acc_type'] ?? ''), ['REGULAR', 'RB']) ? 'RB' : 'EB';
            echo '<tr>';
            echo '<td>' . $personNum++ . '</td>';
            echo '<td><span class="badge badge-child">Child</span></td>';
            echo '<td>' . htmlspecialchars($c['age_type'] ?? '-') . '</td>';
            echo '<td><span class="badge ' . $accBadge . '">' . $accLabel . '</span></td>';
            echo '<td>Age ' . ($c['age'] ?? '?') . ', Position ' . ($c['position'] ?? $c['index'] ?? '-') . '</td>';
            echo '</tr>';
        }
    }
    echo '</table>';
    echo '</div>';

    // =========================================================================
    // STEP 3: Season Mapping
    // =========================================================================
    echo '<div class="step">';
    echo '<div class="step-header"><span class="step-num">3</span><span class="step-title">Season Mapping (date &rarr; season &rarr; price column)</span></div>';

    $seasonsByNight = $calcResult['seasons_by_night'] ?? [];
    if (!empty($seasonsByNight)) {
        echo '<table>';
        echo '<tr><th>Night</th><th>Date</th><th>Season</th><th>Price Column</th></tr>';
        foreach ($seasonsByNight as $idx => $sn) {
            $sNum = $sn['season'] ?? '?';
            echo '<tr>';
            echo '<td>' . ($idx + 1) . '</td>';
            echo '<td>' . ($sn['date'] ?? '-') . '</td>';
            echo '<td><span class="badge badge-season season-' . $sNum . '">Season ' . $sNum . '</span></td>';
            echo '<td><strong>Price' . $sNum . '</strong></td>';
            echo '</tr>';
        }
        echo '</table>';
    } else {
        echo '<p class="zero-value">No season mapping available.</p>';
    }
    echo '</div>';

    // =========================================================================
    // STEP 4: Base Price Calculation
    // =========================================================================
    echo '<div class="step">';
    echo '<div class="step-header"><span class="step-num">4</span><span class="step-title">Base Price Calculation</span></div>';

    $breakdown = $calcResult['breakdown'] ?? [];
    $byNight = $breakdown['base_per_night'] ?? [];
    $byPerson = $breakdown['base_per_person'] ?? [];

    // Per-night breakdown
    if (!empty($byNight)) {
        echo '<h3>Per-Night Breakdown</h3>';
        echo '<table>';
        echo '<tr><th>Night</th><th>Date</th><th>Season</th><th>Price (EUR)</th></tr>';
        $nightTotal = 0;
        foreach ($byNight as $idx => $n) {
            $sNum = $n['season'] ?? '?';
            $nightPrice = $n['price'] ?? 0;
            $nightTotal += $nightPrice;
            echo '<tr>';
            echo '<td>' . ($idx + 1) . '</td>';
            echo '<td>' . ($n['date'] ?? '-') . '</td>';
            echo '<td><span class="badge badge-season season-' . $sNum . '">S' . $sNum . '</span></td>';
            echo '<td>' . number_format($nightPrice, 2) . '</td>';
            echo '</tr>';
        }
        echo '<tr class="total-row"><td colspan="3">Total Base Price</td><td>' . number_format($nightTotal, 2) . '</td></tr>';
        echo '</table>';
    }

    // Per-person totals
    if (!empty($byPerson)) {
        echo '<h3>Per-Person Totals (all nights)</h3>';
        echo '<table>';
        echo '<tr><th>Person</th><th>Total (EUR)</th></tr>';
        foreach ($byPerson as $key => $amount) {
            $label = str_replace('_', ' ', ucfirst($key));
            echo '<tr><td>' . htmlspecialchars($label) . '</td><td>' . number_format($amount, 2) . '</td></tr>';
        }
        echo '</table>';
    }

    echo '<div class="formula">Base Price = ' . number_format($breakdown['base_price'] ?? 0, 2) . ' EUR</div>';
    echo '</div>';

    // =========================================================================
    // STEP 5: Fees / Supplements
    // =========================================================================
    echo '<div class="step">';
    echo '<div class="step-header"><span class="step-num">5</span><span class="step-title">Fees &amp; Supplements</span></div>';

    $fees = $breakdown['fees'] ?? [];
    echo '<table>';
    echo '<tr><th>Fee Type</th><th>Amount (EUR)</th></tr>';

    $feeTypes = [
        'extras_daily' => 'Extras Daily (per-person daily charges)',
        'extras_single' => 'Extras Single (single supplement)',
        'extras_rooms' => 'Extras Rooms (per-room charges)',
        'extras_board' => 'Extras Board (board upgrades)',
        'handling_fee' => 'Handling Fee (tiered by stay length)',
        'company_fee' => 'Company Fee (per-room flat fee)'
    ];
    foreach ($feeTypes as $fKey => $fLabel) {
        $val = $fees[$fKey] ?? 0;
        $cls = ($val == 0) ? ' class="zero-value"' : '';
        echo '<tr' . $cls . '><td>' . $fLabel . '</td><td>' . number_format($val, 2) . '</td></tr>';
    }
    echo '<tr class="total-row"><td>Total Fees</td><td>' . number_format($fees['total_fees'] ?? 0, 2) . '</td></tr>';
    echo '</table>';

    $basePlusFees = ($breakdown['base_price'] ?? 0) + ($fees['total_fees'] ?? 0);
    echo '<div class="formula">Subtotal (Base + Fees) = ' . number_format($breakdown['base_price'] ?? 0, 2) . ' + ' . number_format($fees['total_fees'] ?? 0, 2) . ' = ' . number_format($basePlusFees, 2) . ' EUR</div>';
    echo '</div>';

    // =========================================================================
    // STEP 6: Early Booking Discount
    // =========================================================================
    echo '<div class="step">';
    echo '<div class="step-header"><span class="step-num">6</span><span class="step-title">Early Booking (EB) Discount</span></div>';

    $eb = $breakdown['discounts']['early_booking'] ?? [];
    $ebApplicable = $eb['applicable'] ?? false;

    if ($ebApplicable) {
        echo '<p><span class="badge badge-active">APPLICABLE</span> Discount: <strong>' . ($eb['percent'] ?? 0) . '%</strong></p>';
        $ebBd = $eb['discount_breakdown'] ?? [];
        echo '<table>';
        echo '<tr><th>Component</th><th>Discount (EUR)</th></tr>';
        echo '<tr><td>Base Price discount</td><td>' . number_format($ebBd['base_price'] ?? 0, 2) . '</td></tr>';
        $ebDaily = $ebBd['extras_daily'] ?? 0;
        echo '<tr' . ($ebDaily == 0 ? ' class="zero-value"' : '') . '><td>Extras Daily discount (EBToDaily)</td><td>' . number_format($ebDaily, 2) . '</td></tr>';
        $ebRooms = $ebBd['extras_rooms'] ?? 0;
        echo '<tr' . ($ebRooms == 0 ? ' class="zero-value"' : '') . '><td>Extras Rooms discount (EBToRooms)</td><td>' . number_format($ebRooms, 2) . '</td></tr>';
        $ebBoard = $ebBd['extras_board'] ?? 0;
        echo '<tr' . ($ebBoard == 0 ? ' class="zero-value"' : '') . '><td>Extras Board discount (EBToBoard)</td><td>' . number_format($ebBoard, 2) . '</td></tr>';
        echo '<tr class="total-row"><td>Total EB Discount</td><td>-' . number_format($eb['discount'] ?? 0, 2) . '</td></tr>';
        echo '</table>';
    } else {
        echo '<p><span class="badge badge-inactive">NOT APPLICABLE</span> No early booking discount for this booking.</p>';
    }
    echo '</div>';

    // =========================================================================
    // STEP 7: Reduction (Free Nights)
    // =========================================================================
    echo '<div class="step">';
    echo '<div class="step-header"><span class="step-num">7</span><span class="step-title">Reduction (Free Nights)</span></div>';

    $red = $breakdown['discounts']['reduction'] ?? [];
    $redApplicable = $red['applicable'] ?? false;

    if ($redApplicable) {
        $freeNights = $red['free_nights'] ?? 0;
        $freeIndices = $red['free_night_indices'] ?? [];
        echo '<p><span class="badge badge-active">APPLICABLE</span> Free nights: <strong>' . $freeNights . '</strong></p>';

        // Show which nights are free
        if (!empty($freeIndices) && !empty($byNight)) {
            echo '<table>';
            echo '<tr><th>Night</th><th>Date</th><th>Status</th><th>Value (EUR)</th></tr>';
            foreach ($byNight as $idx => $n) {
                $isFree = in_array($idx, $freeIndices);
                echo '<tr' . ($isFree ? ' class="highlight-row"' : '') . '>';
                echo '<td>' . ($idx + 1) . '</td>';
                echo '<td>' . ($n['date'] ?? '-') . '</td>';
                echo '<td>' . ($isFree ? '<span class="badge badge-free">FREE</span>' : 'Paid') . '</td>';
                echo '<td>' . ($isFree ? '-' . number_format($n['price'] ?? 0, 2) : number_format($n['price'] ?? 0, 2)) . '</td>';
                echo '</tr>';
            }
            echo '</table>';
        }

        $redBd = $red['discount_breakdown'] ?? [];
        echo '<table>';
        echo '<tr><th>Component</th><th>Discount (EUR)</th></tr>';
        echo '<tr><td>Base Price of free nights</td><td>' . number_format($redBd['base_price'] ?? 0, 2) . '</td></tr>';
        $redDaily = $redBd['extras_daily'] ?? 0;
        echo '<tr' . ($redDaily == 0 ? ' class="zero-value"' : '') . '><td>Extras Daily reduction (EXTToDaily)</td><td>' . number_format($redDaily, 2) . '</td></tr>';
        $redRooms = $redBd['extras_rooms'] ?? 0;
        echo '<tr' . ($redRooms == 0 ? ' class="zero-value"' : '') . '><td>Extras Rooms reduction (EXTToRooms)</td><td>' . number_format($redRooms, 2) . '</td></tr>';
        $redBoard = $redBd['extras_board'] ?? 0;
        echo '<tr' . ($redBoard == 0 ? ' class="zero-value"' : '') . '><td>Extras Board reduction (EXTToBoard)</td><td>' . number_format($redBoard, 2) . '</td></tr>';
        echo '<tr class="total-row"><td>Total Reduction Discount</td><td>-' . number_format($red['discount'] ?? 0, 2) . '</td></tr>';
        echo '</table>';
    } else {
        echo '<p><span class="badge badge-inactive">NOT APPLICABLE</span> No free nights reduction for this stay.</p>';
    }
    echo '</div>';

    // =========================================================================
    // STEP 8: Priority Rules & Scenario Evaluation
    // =========================================================================
    echo '<div class="step">';
    echo '<div class="step-header"><span class="step-num">8</span><span class="step-title">Priority Rules &amp; Scenario Evaluation</span></div>';

    $prioRules = $breakdown['priority_rules'] ?? [];
    $scenarios = $prioRules['scenarios'] ?? [];
    $appliedDiscount = $breakdown['applied_discount'] ?? 'none';

    echo '<table>';
    echo '<tr><th>Setting</th><th>Value</th></tr>';
    echo '<tr><td>Priority (combinable?)</td><td>' . ($prioRules['priority'] ?? 'No') . ' <span class="info-note">(' . (($prioRules['priority'] ?? 'No') === 'Yes' ? 'NOT combinable' : 'Combinable') . ')</span></td></tr>';
    echo '<tr><td>PriorityEB (EB forced?)</td><td>' . ($prioRules['priority_eb'] ?? 'No') . '</td></tr>';
    echo '<tr><td>PriorityEXT</td><td>' . ($prioRules['priority_ext'] ?? 'No') . '</td></tr>';
    echo '</table>';

    if (!empty($scenarios)) {
        echo '<h3>Scenario Comparison</h3>';
        echo '<table>';
        echo '<tr><th>Scenario</th><th>Price (EUR)</th><th>Savings</th><th></th></tr>';

        $scenarioLabels = [
            'none' => 'No discount',
            'early_booking' => 'Early Booking only',
            'reduction' => 'Reduction only',
            'combined' => 'Combined (EB + Reduction)'
        ];

        foreach ($scenarioLabels as $sKey => $sLabel) {
            $sVal = $scenarios[$sKey] ?? null;
            if ($sVal === null) continue;
            $isActive = ($sKey === $appliedDiscount);
            $savings = ($scenarios['none'] ?? 0) - $sVal;
            echo '<tr' . ($isActive ? ' class="highlight-row"' : '') . '>';
            echo '<td>' . $sLabel . '</td>';
            echo '<td>' . number_format($sVal, 2) . '</td>';
            echo '<td>' . ($savings > 0 ? '-' . number_format($savings, 2) : '-') . '</td>';
            echo '<td>' . ($isActive ? '<span class="badge badge-active">SELECTED</span>' : '') . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }

    echo '<div class="formula">Selected: ' . htmlspecialchars($appliedDiscount) . ' | Discount amount: -' . number_format($breakdown['discount_amount'] ?? 0, 2) . ' EUR</div>';
    echo '</div>';

    // =========================================================================
    // STEP 9: Commission & Final Price
    // =========================================================================
    echo '<div class="step">';
    echo '<div class="step-header"><span class="step-num">9</span><span class="step-title">Commission &amp; Final Price</span></div>';

    echo '<table>';
    echo '<tr><th>Component</th><th>Amount (EUR)</th></tr>';
    echo '<tr><td>Base Price</td><td>' . number_format($breakdown['base_price'] ?? 0, 2) . '</td></tr>';
    echo '<tr><td>+ Total Fees</td><td>' . number_format($fees['total_fees'] ?? 0, 2) . '</td></tr>';
    echo '<tr><td>- Discount (' . htmlspecialchars($appliedDiscount) . ')</td><td>-' . number_format($breakdown['discount_amount'] ?? 0, 2) . '</td></tr>';
    echo '<tr class="total-row"><td>Price before commission</td><td>' . number_format($calcResult['price_without_commission'], 2) . '</td></tr>';
    echo '<tr><td>Commission (' . $calcResult['commission'] . '%)</td><td>+' . number_format($calcResult['price'] - $calcResult['price_without_commission'], 2) . '</td></tr>';
    echo '<tr class="total-row" style="font-size:16px;"><td>FINAL PRICE</td><td>' . number_format($calcResult['price'], 2) . ' EUR</td></tr>';
    echo '</table>';

    echo '<div class="formula">' . number_format($calcResult['price_without_commission'], 2) . ' &times; (1 + ' . $calcResult['commission'] . '% ) = ' . number_format($calcResult['price'], 2) . ' EUR</div>';
    echo '</div>';

    // =========================================================================
    // API COMPARISON
    // =========================================================================
    echo '<h2>API Price Comparison</h2>';

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
        $prices = $apiResult->xpath('//Price');
        $idRooms = $apiResult->xpath('//IdRoom');
        $boards = $apiResult->xpath('//Board');

        $numResults = min(count($prices), count($idRooms), count($boards));

        for ($i = 0; $i < $numResults; $i++) {
            $resultPrice = floatval((string)$prices[$i]);
            $resultRoom = rawurldecode((string)$idRooms[$i]);
            $resultBoard = (string)$boards[$i];

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

        if (!$apiPriceFound && $numResults > 0) {
            $apiPrice = floatval((string)$prices[0]);
            $apiPriceFound = true;
        }
    }

    $commission = ConfigService::getCommission();
    $apiPriceWithCommission = $apiPrice * (1 + $commission / 100);

    if ($apiPriceFound) {
        $calcPrice = $calcResult['price'];
        $difference = $calcPrice - $apiPriceWithCommission;
        $percentDiff = $apiPriceWithCommission > 0 ? ($difference / $apiPriceWithCommission) * 100 : 0;
        $isMatch = abs($difference) < 1;

        echo '<div class="result-box ' . ($isMatch ? 'result-match' : 'result-mismatch') . '">';
        echo '<div class="comparison-grid">';

        echo '<div>';
        echo '<div class="price-label">Calculated</div>';
        echo '<div class="price-big">' . number_format($calcPrice, 2) . '</div>';
        echo '<div class="price-label">Base: ' . number_format($calcResult['price_without_commission'], 2) . '</div>';
        echo '</div>';

        echo '<div>';
        echo '<div class="price-label">API (room_price)</div>';
        echo '<div class="price-big">' . number_format($apiPriceWithCommission, 2) . '</div>';
        echo '<div class="price-label">Base: ' . number_format($apiPrice, 2) . '</div>';
        echo '</div>';

        echo '<div>';
        echo '<div class="price-label">Difference</div>';
        echo '<div class="price-big ' . ($isMatch ? 'match' : 'mismatch') . '">';
        echo ($difference >= 0 ? '+' : '') . number_format($difference, 2);
        echo ' (' . ($percentDiff >= 0 ? '+' : '') . number_format($percentDiff, 1) . '%)';
        echo '</div>';
        echo '</div>';

        echo '</div>'; // comparison-grid

        if ($isMatch) {
            echo '<p style="text-align:center;color:green;font-weight:bold;margin-top:15px;">PRICES MATCH (within 1 EUR tolerance)</p>';
        } else {
            echo '<p style="text-align:center;color:red;font-weight:bold;margin-top:15px;">PRICES DO NOT MATCH - Check steps above for discrepancies</p>';
        }
        echo '</div>';
    } else {
        echo '<div class="result-box result-mismatch">';
        echo '<strong>API Error:</strong> No price found for this room/board combination';
        echo '</div>';
    }

    // Debug Log (raw JSON, if debug checkbox was checked)
    if ($show_debug && !empty($calcResult['debug_log'])) {
        echo '<h2>Raw Debug Log</h2>';
        echo '<div class="debug-section">';
        echo '<pre>' . htmlspecialchars(json_encode($calcResult['debug_log'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
        echo '</div>';
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
 * Mode: verify
 * Verify season-to-price correlation
 */
if ($mode == 'verify') {
    header('Content-Type: text/html; charset=utf-8');

    $hotel_id = $_REQUEST['hotel_id'] ?? '';
    $package_name = $_REQUEST['package_name'] ?? '';
    $room_id = $_REQUEST['room_id'] ?? '';
    $board_id = $_REQUEST['board_id'] ?? '';
    $check_in = $_REQUEST['check_in'] ?? date('Y-m-d', strtotime('+30 days'));
    $nights = intval($_REQUEST['nights'] ?? 7);

    echo '<!DOCTYPE html><html><head><title>Season-Price Verification</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
        h1 { color: #003580; margin-bottom: 5px; }
        h2 { color: #555; margin-top: 25px; border-bottom: 2px solid #003580; padding-bottom: 5px; }
        .params { background: #f0f0f0; padding: 15px; border-radius: 6px; margin-bottom: 20px; }
        .params strong { display: inline-block; width: 120px; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { padding: 8px 12px; border: 1px solid #ddd; text-align: left; }
        th { background: #003580; color: white; }
        tr:nth-child(even) { background: #f9f9f9; }
        .highlight { background: #fff3cd !important; font-weight: bold; }
        .info-box { background: #e3f2fd; padding: 15px; border-radius: 6px; margin: 15px 0; }
        .btn { display: inline-block; padding: 10px 20px; background: #003580; color: white; text-decoration: none; border-radius: 4px; margin-top: 20px; }
        pre { background: #f5f5f5; padding: 10px; overflow-x: auto; font-size: 11px; }
        .season-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: bold; margin: 2px; }
        .season-1 { background: #4caf50; color: white; }
        .season-2 { background: #2196f3; color: white; }
        .season-3 { background: #ff9800; color: white; }
        .season-4 { background: #e91e63; color: white; }
        .season-5 { background: #9c27b0; color: white; }
    </style></head><body><div class="container">';

    echo '<h1>Season-Price Verification</h1>';

    echo '<div class="params">';
    echo "<strong>Hotel ID:</strong> {$hotel_id}<br>";
    echo "<strong>Package:</strong> {$package_name}<br>";
    echo "<strong>Room:</strong> " . rawurldecode($room_id) . "<br>";
    echo "<strong>Board:</strong> {$board_id}<br>";
    echo "<strong>Check-in:</strong> {$check_in}<br>";
    echo "<strong>Nights:</strong> {$nights}<br>";
    echo '</div>';

    // Load priceinfo
    $priceinfo_json = db_get_field(
        "SELECT priceinfo_data FROM ?:novoton_hotel_packages WHERE hotel_id = ?s AND package_name = ?s",
        $hotel_id,
        $package_name
    );

    if (empty($priceinfo_json)) {
        echo '<div class="info-box" style="background: #ffebee;">No priceinfo data found for this package.</div>';
    } else {
        $calculator = new PriceInfoCalculation();

        // Set priceinfo directly on the parser for verification methods
        $calculator->getParser()->setPriceinfo(json_decode($priceinfo_json, true));

        // 1. Show season mapping
        echo '<h2>1. Season Mapping for Each Night</h2>';
        echo '<div class="info-box">This shows which Price column (Price1, Price2, etc.) is used for each night based on the season date ranges.</div>';

        $mapping = $calculator->verifySeasonPriceMapping($check_in, $nights);

        echo '<p><strong>Total seasons found:</strong> ' . $mapping['total_seasons_found'] . '</p>';

        if (!empty($mapping['seasons_raw'])) {
            echo '<h3>Season Date Ranges</h3>';
            echo '<table>';
            echo '<tr><th>Season #</th><th>From Date</th><th>To Date</th><th>Price Column</th></tr>';
            foreach ($mapping['seasons_raw'] as $season) {
                $seasonNum = $season['Season'] ?? $season['IdSeason'] ?? '?';
                echo '<tr>';
                echo '<td><span class="season-badge season-' . $seasonNum . '">Season ' . $seasonNum . '</span></td>';
                echo '<td>' . ($season['FromDate'] ?? '-') . '</td>';
                echo '<td>' . ($season['ToDate'] ?? '-') . '</td>';
                echo '<td><strong>Price' . $seasonNum . '</strong></td>';
                echo '</tr>';
            }
            echo '</table>';
        }

        echo '<h3>Night-by-Night Mapping</h3>';
        echo '<table>';
        echo '<tr><th>Night #</th><th>Date</th><th>Season</th><th>Price Column</th><th>Date Range</th></tr>';
        foreach ($mapping['night_mapping'] as $night) {
            $seasonClass = 'season-' . $night['season_num'];
            echo '<tr>';
            echo '<td>' . $night['night'] . '</td>';
            echo '<td>' . $night['date'] . '</td>';
            echo '<td><span class="season-badge ' . $seasonClass . '">Season ' . $night['season_num'] . '</span></td>';
            echo '<td><strong>' . $night['price_key'] . '</strong></td>';
            echo '<td>' . $night['matched_range'] . '</td>';
            echo '</tr>';
        }
        echo '</table>';

        // 2. Show sample prices
        echo '<h2>2. Season Prices for Selected Room/Board</h2>';
        echo '<div class="info-box">These are the raw price values from season_price data. Percentages (e.g., "80%") are calculated from the Base code row.</div>';

        $samples = $calculator->getSamplePrices($room_id, $board_id);

        if (empty($samples)) {
            echo '<p><em>No prices found for the selected room/board combination.</em></p>';
        } else {
            // Find all used price columns
            $usedPriceColumns = [];
            foreach ($samples as $sample) {
                for ($i = 1; $i <= 20; $i++) {
                    if (isset($sample['Price' . $i])) {
                        $usedPriceColumns[$i] = true;
                    }
                }
            }
            ksort($usedPriceColumns);

            echo '<table>';
            echo '<tr><th>IdAge</th><th>IdAcc</th><th>Code</th><th>Base</th><th>RoomPrice</th>';
            foreach (array_keys($usedPriceColumns) as $col) {
                echo '<th class="highlight">Price' . $col . '</th>';
            }
            echo '</tr>';

            foreach ($samples as $sample) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars($sample['IdAge']) . '</td>';
                echo '<td>' . htmlspecialchars($sample['IdAcc']) . '</td>';
                echo '<td>' . htmlspecialchars($sample['Code']) . '</td>';
                echo '<td>' . htmlspecialchars($sample['Base']) . '</td>';
                echo '<td>' . htmlspecialchars($sample['RoomPrice']) . '</td>';
                foreach (array_keys($usedPriceColumns) as $col) {
                    $val = $sample['Price' . $col] ?? '-';
                    echo '<td class="highlight">' . htmlspecialchars($val) . '</td>';
                }
                echo '</tr>';
            }
            echo '</table>';
        }

        // 3. Show raw priceinfo structure
        echo '<h2>3. Raw Priceinfo Structure (seasons only)</h2>';
        $priceinfo = json_decode($priceinfo_json, true);
        echo '<pre>' . htmlspecialchars(json_encode($priceinfo['seasons'] ?? [], JSON_PRETTY_PRINT)) . '</pre>';
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
 * Mode: get_rooms (AJAX)
 * Return IdRoom values from hotelinfo for a hotel as JSON
 */
if ($mode == 'get_rooms') {
    header('Content-Type: application/json; charset=utf-8');

    $hotel_id = $_REQUEST['hotel_id'] ?? '';

    if (empty($hotel_id)) {
        echo json_encode(['rooms' => []]);
        exit;
    }

    $hotel_data_json = db_get_field(
        "SELECT hotel_data FROM ?:novoton_hotels WHERE hotel_id = ?s",
        $hotel_id
    );

    $rooms_list = [];

    if (!empty($hotel_data_json)) {
        $hotel_data = json_decode($hotel_data_json, true);
        $rooms = $hotel_data['rooms'] ?? [];

        // Normalize single room entry to array
        if (isset($rooms['IdRoom'])) {
            $rooms = [$rooms];
        }

        foreach ($rooms as $room) {
            $id_room = $room['IdRoom'] ?? '';
            if (!empty($id_room)) {
                $type = $room['Type'] ?? '';
                $rb = $room['RegularBeds'] ?? $room['RB'] ?? '';
                $eb = $room['ExtraBeds'] ?? $room['EB'] ?? '';
                $label = $id_room;
                if (!empty($type) && $type !== $id_room) {
                    $label .= ' (' . $type . ')';
                }
                if ($rb !== '' || $eb !== '') {
                    $label .= ' [RB:' . $rb . ' EB:' . $eb . ']';
                }
                $rooms_list[] = [
                    'id_room' => $id_room,
                    'label' => $label,
                ];
            }
        }
    }

    echo json_encode(['rooms' => $rooms_list]);
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

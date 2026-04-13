<?php
declare(strict_types=1);
/**
 * Novoton Price Comparison Tool
 *
 * Compares prices calculated from priceinfo data with room_price API response.
 * Use this to verify the PriceInfoCalculation algorithm accuracy.
 *
 * Modes:
 * - (default): Show comparison form
 * - compare: Run comparison and show results (includes season verification data)
 * - verify: Deprecated, redirects to compare mode
 *
 * @package NovotonHolidays
 * @since 3.0.0
 */

use Tygh\Tygh;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

if (fn_allowed_for('MULTIVENDOR') || (defined('RESTRICTED_ADMIN') && RESTRICTED_ADMIN)) {
    return [CONTROLLER_STATUS_DENIED];
}

use Tygh\Addons\NovotonHolidays\NovotonApi;
use Tygh\Addons\NovotonHolidays\Services\PriceInfoCalculation;
use Tygh\Addons\NovotonHolidays\Services\PriceInfoFormatter;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;

/**
 * Mode: compare
 * Run price comparison
 */
if ($mode === 'compare') {
    header('Content-Type: text/html; charset=utf-8');

    $hotel_id = $_REQUEST['hotel_id'] ?? '';
    $package_name = $_REQUEST['package_name'] ?? '';
    $room_id = $_REQUEST['room_id'] ?? '';
    $board_id = $_REQUEST['board_id'] ?? '';
    $check_in = $_REQUEST['check_in'] ?? date('Y') . '-07-01';
    $nights = (int)($_REQUEST['nights'] ?? 7);
    $adults = (int)($_REQUEST['adults'] ?? 2);
    $children_ages = $_REQUEST['children_ages'] ?? '';
    $show_debug = ConfigProvider::isDebugLogging();

    // Parse children ages
    $children_arr = [];
    if (!empty($children_ages)) {
        $children_arr = array_map('floatval', explode(',', $children_ages));
    }

    $check_out = date('Y-m-d', (int) strtotime($check_in . ' + ' . $nights . ' days'));

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
        .collapsible-toggle { cursor: pointer; color: #003580; font-size: 13px; user-select: none; margin: 8px 0; display: inline-block; }
        .collapsible-toggle:hover { text-decoration: underline; }
        .collapsible-content { display: none; }
        .collapsible-content.open { display: block; }
        .season-badge-inline { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: bold; margin: 2px; color: white; }
        .correlation-yes { background: #c8e6c9; color: #2e7d32; font-weight: bold; padding: 2px 8px; }
        .correlation-no { background: #ffcdd2; color: #c62828; font-weight: bold; padding: 2px 8px; }
    </style>
    <script>function toggleSection(id){var el=document.getElementById(id);if(el){el.classList.toggle("open");var btn=el.previousElementSibling;if(btn&&btn.classList.contains("collapsible-toggle")){btn.textContent=el.classList.contains("open")?btn.textContent.replace("▶","▼"):btn.textContent.replace("▼","▶");}}}</script>
    </head><body><div class="container">';

    echo '<h1>Price Comparison Result</h1>';

    echo '<div class="params">';
    echo "<strong>Hotel ID:</strong> " . htmlspecialchars($hotel_id) . "<br>";
    echo "<strong>Package:</strong> " . htmlspecialchars($package_name) . "<br>";
    echo "<strong>Room:</strong> " . htmlspecialchars(rawurldecode($room_id)) . "<br>";
    echo "<strong>Board:</strong> " . htmlspecialchars($board_id) . "<br>";
    echo "<strong>Check-in:</strong> " . htmlspecialchars($check_in) . "<br>";
    echo "<strong>Check-out:</strong> " . htmlspecialchars($check_out) . "<br>";
    echo "<strong>Nights:</strong> " . (int)$nights . "<br>";
    echo "<strong>Adults:</strong> " . (int)$adults . "<br>";
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

    // Season Date Ranges (merged from Verify mode Section 1)
    $seasonMapping = $calculator->verifySeasonPriceMapping($check_in, $nights);
    if (!empty($seasonMapping['seasons_raw'])) {
        echo '<span class="collapsible-toggle" onclick="toggleSection(\'season-date-ranges\')">&#9654; Season Date Ranges (' . count($seasonMapping['seasons_raw']) . ' seasons defined)</span>';
        echo '<div id="season-date-ranges" class="collapsible-content">';
        echo '<table>';
        echo '<tr><th>Season #</th><th>From Date</th><th>To Date</th><th>Price Column</th></tr>';
        foreach ($seasonMapping['seasons_raw'] as $season) {
            $seasonNum = $season['Season'] ?? $season['IdSeason'] ?? '?';
            echo '<tr>';
            echo '<td><span class="badge badge-season season-' . $seasonNum . '">Season ' . $seasonNum . '</span></td>';
            echo '<td>' . htmlspecialchars($season['FromDate'] ?? '-') . '</td>';
            echo '<td>' . htmlspecialchars($season['ToDate'] ?? '-') . '</td>';
            echo '<td><strong>Price' . $seasonNum . '</strong></td>';
            echo '</tr>';
        }
        echo '</table>';
        echo '</div>';
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
    $byPersonByNight = $breakdown['base_per_person_per_night'] ?? [];
    $matchedRows = $breakdown['matched_rows'] ?? [];

    // Per-person per-night matrix (main breakdown table)
    if (!empty($byPersonByNight) && !empty($byNight)) {
        $personKeys = array_keys($byPersonByNight);

        echo '<h3>Per-Person Per-Night Breakdown</h3>';
        echo '<div style="overflow-x:auto;">';
        echo '<table>';

        // Header row: Night dates
        echo '<tr><th>Person</th><th>Age Type</th><th>Acc Type</th>';
        foreach ($byNight as $idx => $n) {
            $sNum = $n['season'] ?? '?';
            echo '<th style="text-align:right;"><span class="badge badge-season season-' . $sNum . '">S' . $sNum . '</span><br><small>' . ($n['date'] ?? '') . '</small></th>';
        }
        echo '<th style="text-align:right;font-weight:bold;">Total</th></tr>';

        // One row per person
        foreach ($personKeys as $pKey) {
            $rowInfo = $matchedRows[$pKey] ?? [];
            $personTotal = $byPerson[$pKey] ?? 0;
            $isAdult = str_starts_with((string) $pKey, 'adult_');
            $badgeClass = $isAdult ? 'badge-adult' : 'badge-child';
            $label = str_replace('_', ' ', ucfirst((string) $pKey));

            echo '<tr>';
            echo '<td><span class="badge ' . $badgeClass . '">' . htmlspecialchars($label) . '</span></td>';
            echo '<td><small>' . htmlspecialchars(trim($rowInfo['age_type'] ?? '-')) . '</small></td>';
            echo '<td><small>' . htmlspecialchars($rowInfo['acc_type'] ?? '-') . '</small></td>';

            foreach ($byNight as $idx => $n) {
                $nightPrice = $byPersonByNight[$pKey][$idx] ?? 0;
                $cls = ($nightPrice == 0) ? ' class="zero-value"' : '';
                echo '<td style="text-align:right;"' . $cls . '>' . number_format($nightPrice, 2) . '</td>';
            }
            echo '<td style="text-align:right;font-weight:bold;">' . number_format($personTotal, 2) . '</td>';
            echo '</tr>';
        }

        // Night totals row
        echo '<tr class="total-row"><td colspan="3">Night Total</td>';
        foreach ($byNight as $idx => $n) {
            echo '<td style="text-align:right;">' . number_format($n['price'] ?? 0, 2) . '</td>';
        }
        $basePriceVal = $breakdown['base_price'] ?? 0;
        echo '<td style="text-align:right;">' . number_format($basePriceVal, 2) . '</td>';
        echo '</tr>';

        echo '</table>';
        echo '</div>';

        // Matched season_price row details
        if (!empty($matchedRows)) {
            echo '<h3>Matched Season Price Rows</h3>';
            echo '<p style="font-size:0.9em;color:#666;">Shows which season_price row was matched for each person. '
               . 'If Code &ne; Base, prices are <strong>percentages</strong> of the Base row.</p>';
            echo '<table>';
            echo '<tr><th>Person</th><th>Matched Age Type</th><th>Code</th><th>Base</th><th>Raw Price</th><th>Is %</th><th>RoomPrice</th></tr>';
            foreach ($matchedRows as $pKey => $mRow) {
                $label = str_replace('_', ' ', ucfirst($pKey));
                $isPercent = $mRow['is_percentage'] ?? false;
                echo '<tr>';
                echo '<td>' . htmlspecialchars($label) . '</td>';
                echo '<td>' . htmlspecialchars($mRow['row_age'] ?? '-') . '</td>';
                echo '<td>' . htmlspecialchars($mRow['code'] ?? '-') . '</td>';
                echo '<td>' . htmlspecialchars($mRow['base'] ?? '-') . '</td>';
                echo '<td>' . htmlspecialchars($mRow['raw_price'] ?? '-') . ($isPercent ? '%' : '') . '</td>';
                echo '<td>' . ($isPercent ? '<span class="badge badge-active">Yes</span>' : 'No') . '</td>';
                echo '<td>' . htmlspecialchars($mRow['room_price'] ?? 'No') . '</td>';
                echo '</tr>';
            }
            echo '</table>';
        }
    } else {
        // Fallback: Per-night breakdown (legacy view)
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
    }

    $basePriceVal = $breakdown['base_price'] ?? 0;
    echo '<div class="formula">Base Price = ' . number_format($basePriceVal, 2) . ' EUR</div>';
    if ($basePriceVal == 0) {
        echo '<div style="background:#fff3cd;color:#856404;padding:10px;border-radius:4px;margin-top:8px;">';
        echo '<strong>Base Price is 0.</strong> This usually means no matching season_price rows were found for the selected room/board/occupancy combination. ';
        echo 'Check the <strong>Season Date Ranges</strong> (Step 3) and <strong>All Season Price Rows</strong> below, or enable <strong>Debug</strong> for detailed matching info.';
        echo '</div>';
    }

    // All Season Price Rows for selected room/board (merged from Verify mode Section 2)
    $samplePrices = $calculator->getSamplePrices($room_id, $board_id);
    if (!empty($samplePrices)) {
        echo '<span class="collapsible-toggle" onclick="toggleSection(\'season-price-rows\')">&#9654; All Season Price Rows for Room/Board (' . count($samplePrices) . ' rows)</span>';
        echo '<div id="season-price-rows" class="collapsible-content">';
        echo '<p style="font-size:0.9em;color:#666;">Raw price values from season_price data. If Code &ne; Base, values are percentages of the Base row.</p>';

        // Find used price columns
        $usedPriceCols = [];
        foreach ($samplePrices as $sample) {
            for ($i = 1; $i <= 20; $i++) {
                if (isset($sample['Price' . $i])) {
                    $usedPriceCols[$i] = true;
                }
            }
        }
        ksort($usedPriceCols);

        echo '<div style="overflow-x:auto;">';
        echo '<table>';
        echo '<tr><th>IdAge</th><th>IdAcc</th><th>Code</th><th>Base</th><th>RoomPrice</th>';
        foreach (array_keys($usedPriceCols) as $col) {
            echo '<th style="background:#fff3cd;">Price' . $col . '</th>';
        }
        echo '</tr>';

        foreach ($samplePrices as $sample) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($sample['IdAge']) . '</td>';
            echo '<td>' . htmlspecialchars($sample['IdAcc']) . '</td>';
            echo '<td>' . htmlspecialchars($sample['Code']) . '</td>';
            echo '<td>' . htmlspecialchars($sample['Base']) . '</td>';
            echo '<td>' . htmlspecialchars($sample['RoomPrice']) . '</td>';
            foreach (array_keys($usedPriceCols) as $col) {
                $val = $sample['Price' . $col] ?? '-';
                echo '<td style="background:#fffde7;">' . htmlspecialchars((string)$val) . '</td>';
            }
            echo '</tr>';
        }
        echo '</table>';
        echo '</div>';
        echo '</div>';
    }
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

    // Handling Fee breakdown (per-entry details)
    $feesDetail = $breakdown['fees_detail'] ?? [];
    $hfEntries = $feesDetail['handling_fee_entries'] ?? [];
    if (!empty($hfEntries)) {
        echo '<h3>Handling Fee Breakdown</h3>';
        echo '<p style="font-size:0.9em;color:#666;">Each entry from priceinfo handling_fee. '
           . 'Generic entries (e.g. "ADULT") exclude persons already covered by positional entries (e.g. "3 RD ADULT") to avoid double-counting.</p>';
        echo '<table>';
        echo '<tr><th>#</th><th>IdAge</th><th>Tier</th><th>Price/person</th><th>Count</th><th>Subtotal</th><th>Explanation</th></tr>';
        foreach ($hfEntries as $hfe) {
            if (isset($hfe['skipped'])) {
                echo '<tr class="zero-value"><td>' . ($hfe['entry'] ?? '') . '</td><td>' . htmlspecialchars($hfe['idAge'] ?? '') . '</td>'
                   . '<td colspan="4">Skipped: ' . htmlspecialchars($hfe['skipped']) . ' (dates: ' . ($hfe['fromDate'] ?? '') . ' - ' . ($hfe['toDate'] ?? '') . ')</td><td></td></tr>';
                continue;
            }
            $subtotal = $hfe['subtotal'] ?? 0;
            $cls = ($subtotal == 0) ? ' class="zero-value"' : '';
            echo '<tr' . $cls . '>';
            echo '<td>' . ($hfe['entry'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($hfe['idAge'] ?? '') . '</td>';
            echo '<td style="font-size:0.85em">' . htmlspecialchars($hfe['tier'] ?? '') . '</td>';
            echo '<td>' . number_format($hfe['price'] ?? 0, 2) . '</td>';
            echo '<td>' . ($hfe['count'] ?? 0) . '</td>';
            echo '<td>' . number_format($subtotal, 2) . '</td>';
            echo '<td style="font-size:0.85em">' . htmlspecialchars($hfe['count_method'] ?? '') . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }

    $basePlusFees = ($breakdown['base_price'] ?? 0) + ($fees['total_fees'] ?? 0);
    echo '<div class="formula">Subtotal (Base + Fees) = ' . number_format($breakdown['base_price'] ?? 0, 2) . ' + ' . number_format($fees['total_fees'] ?? 0, 2) . ' = ' . number_format($basePlusFees, 2) . ' EUR</div>';

    // Handling-Fee ↔ Season-Price Age Type Correlation (merged from Verify mode Section 3)
    $seasonAgeTypes = $calculator->collectSeasonPriceAgeTypes($room_id, $board_id);
    $priceinfo = $calculator->getParser()->getPriceinfo() ?? [];
    $handlingFeesRaw = $priceinfo['handling_fee'] ?? [];
    if (isset($handlingFeesRaw['Price1']) || isset($handlingFeesRaw['ToDays'])) {
        $handlingFeesRaw = [$handlingFeesRaw];
    }

    if (!empty($seasonAgeTypes) || !empty($handlingFeesRaw)) {
        echo '<span class="collapsible-toggle" onclick="toggleSection(\'age-type-correlation\')">&#9654; Handling-Fee &harr; Season-Price Age Type Correlation</span>';
        echo '<div id="age-type-correlation" class="collapsible-content">';
        echo '<p style="font-size:0.9em;color:#666;">Handling-fee entries are only applied when their IdAge matches an age type in season_price for this room. '
           . 'Entries with no match are skipped to avoid incorrect charges.</p>';

        if (!empty($seasonAgeTypes)) {
            echo '<p><strong>Age types in season_price:</strong> ';
            foreach ($seasonAgeTypes as $ageType) {
                echo '<span class="season-badge-inline" style="background:#2196f3;">' . htmlspecialchars($ageType) . '</span> ';
            }
            echo '</p>';
        } else {
            echo '<p><em>No season_price age types found for this room/board.</em></p>';
        }

        if (!empty($handlingFeesRaw)) {
            $seasonAgeSet = array_map('strtoupper', array_map('trim', $seasonAgeTypes));
            echo '<table>';
            echo '<tr><th>#</th><th>IdAge</th><th>FromDate</th><th>ToDate</th><th>Price1</th><th>Price2</th><th>Correlates?</th></tr>';
            foreach ($handlingFeesRaw as $idx => $fee) {
                $feeIdAge = trim((string) preg_replace('/\s+/', ' ', $fee['IdAge'] ?? ''));
                $feeKey = trim((string) preg_replace('/\s+BY\s+\d+\s+AD\s*$/i', '', $feeIdAge));
                $feeUpper = strtoupper($feeKey);

                $correlates = empty($seasonAgeSet);
                if (!empty($seasonAgeSet)) {
                    foreach ($seasonAgeSet as $spAge) {
                        if (PriceInfoFormatter::matchAgeType($spAge, $feeUpper)) {
                            $correlates = true;
                            break;
                        }
                    }
                }

                $statusClass = $correlates ? 'correlation-yes' : 'correlation-no';
                $statusText = $correlates ? 'YES' : 'NO - skipped';

                echo '<tr' . (!$correlates ? ' style="opacity:0.6;"' : '') . '>';
                echo '<td>' . $idx . '</td>';
                echo '<td>' . htmlspecialchars($feeIdAge) . '</td>';
                echo '<td>' . htmlspecialchars($fee['FromDate'] ?? '-') . '</td>';
                echo '<td>' . htmlspecialchars($fee['ToDate'] ?? '-') . '</td>';
                echo '<td>' . htmlspecialchars($fee['Price1'] ?? '-') . '</td>';
                echo '<td>' . htmlspecialchars($fee['Price2'] ?? '-') . '</td>';
                echo '<td><span class="' . $statusClass . '">' . $statusText . '</span></td>';
                echo '</tr>';
            }
            echo '</table>';
        }
        echo '</div>';
    }
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
    $apiResult = $api->pricing()->getRoomPrice([
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
        $room_id_decoded = !empty($room_id) ? rawurldecode($room_id) : null;
        $match = fn_novoton_match_price_from_xml($apiResult, $room_id_decoded, $board_id);
        if ($match !== null) {
            $apiPrice = $match['price'];
            $apiPriceFound = true;
        }

        // Fallback: use first available price
        if (!$apiPriceFound) {
            $prices = $apiResult->xpath('//Price');
            if (!empty($prices)) {
                $apiPrice = (float)((string)$prices[0]);
                $apiPriceFound = true;
            }
        }
    }

    $commission = ConfigProvider::getCommission();
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

        // =====================================================================
        // Discrepancy Analysis (only when prices don't match)
        // =====================================================================
        if (!$isMatch) {
            echo '<div class="step" style="border-color:#f44336;">';
            echo '<div class="step-header"><span class="step-num" style="background:#f44336;">!</span><span class="step-title">Discrepancy Analysis</span></div>';

            $calcBasePrice = $breakdown['base_price'] ?? 0;
            $calcFees = $fees['total_fees'] ?? 0;
            $ebPercent = $eb['percent'] ?? 0;
            $calcDiscount = $breakdown['discount_amount'] ?? 0;

            // Reverse-engineer API implied values
            // API price = (API_base * (1 - EB%)) + fees  (if EB applies to base only)
            // API price = API_base * (1 - EB%) + fees
            // => API_base = (API_price - fees) / (1 - EB%)
            $apiImpliedBase = 0;
            $apiBaseDiff = 0;
            if ($ebPercent > 0 && $ebPercent < 100) {
                $apiImpliedBase = round(($apiPrice - $calcFees) / (1 - $ebPercent / 100), 2);
                $apiBaseDiff = $calcBasePrice - $apiImpliedBase;
            }

            echo '<table>';
            echo '<tr><th>Metric</th><th>Calculated</th><th>API (implied)</th><th>Difference</th></tr>';

            echo '<tr>';
            echo '<td>Base Price (before discounts)</td>';
            echo '<td>' . number_format($calcBasePrice, 2) . '</td>';
            if ($apiImpliedBase > 0) {
                echo '<td>' . number_format($apiImpliedBase, 2) . '</td>';
                $cls = abs($apiBaseDiff) < 1 ? 'match' : 'mismatch';
                echo '<td class="' . $cls . '">' . ($apiBaseDiff >= 0 ? '+' : '') . number_format($apiBaseDiff, 2) . '</td>';
            } else {
                echo '<td colspan="2"><em>Cannot reverse-engineer (EB% = 0 or unknown)</em></td>';
            }
            echo '</tr>';

            echo '<tr>';
            echo '<td>Fees (handling + extras)</td>';
            echo '<td>' . number_format($calcFees, 2) . '</td>';
            echo '<td>' . number_format($calcFees, 2) . ' <small>(assumed same)</small></td>';
            echo '<td class="match">0.00</td>';
            echo '</tr>';

            echo '<tr>';
            echo '<td>Early Booking Discount (' . $ebPercent . '%)</td>';
            echo '<td>-' . number_format($calcDiscount, 2) . '</td>';
            if ($apiImpliedBase > 0) {
                $apiImpliedDiscount = round($apiImpliedBase * $ebPercent / 100, 2);
                echo '<td>-' . number_format($apiImpliedDiscount, 2) . '</td>';
                $discDiff = $calcDiscount - $apiImpliedDiscount;
                echo '<td>' . ($discDiff >= 0 ? '+' : '') . number_format($discDiff, 2) . '</td>';
            } else {
                echo '<td colspan="2">-</td>';
            }
            echo '</tr>';

            echo '<tr class="total-row">';
            echo '<td>Final Price</td>';
            echo '<td>' . number_format($calcPrice, 2) . '</td>';
            echo '<td>' . number_format($apiPriceWithCommission, 2) . '</td>';
            echo '<td class="mismatch">' . ($difference >= 0 ? '+' : '') . number_format($difference, 2) . '</td>';
            echo '</tr>';
            echo '</table>';

            // Per-person analysis: show what each person would need to cost
            // for the API price to match
            if ($apiImpliedBase > 0 && abs($apiBaseDiff) >= 1 && !empty($byPerson)) {
                echo '<h3>Per-Person Base Price Analysis</h3>';
                echo '<p style="font-size:0.9em;color:#666;">Compares calculated per-person base prices with what the API implies. '
                   . 'The "API Implied" column is estimated by proportional scaling. '
                   . 'A large difference on a specific person suggests their pricing row or percentage may be wrong.</p>';

                $scaleFactor = ($calcBasePrice > 0) ? $apiImpliedBase / $calcBasePrice : 1;

                echo '<table>';
                echo '<tr><th>Person</th><th>Calculated Base</th><th>API Implied Base</th><th>Difference</th><th>% of Adult Rate</th></tr>';

                // Find first adult's total for reference
                $firstAdultTotal = 0;
                $apiFirstAdultTotal = 0;
                foreach ($byPerson as $pKey => $pAmt) {
                    if (str_starts_with($pKey, 'adult_1')) {
                        $firstAdultTotal = $pAmt;
                        $apiFirstAdultTotal = round($pAmt * $scaleFactor, 2);
                        break;
                    }
                }

                foreach ($byPerson as $pKey => $pAmt) {
                    $label = str_replace('_', ' ', ucfirst($pKey));
                    $apiPAmt = round($pAmt * $scaleFactor, 2);
                    $pDiff = $pAmt - $apiPAmt;
                    $cls = abs($pDiff) < 1 ? 'match' : 'mismatch';

                    $pctOfAdult = '';
                    if ($firstAdultTotal > 0) {
                        $calcPct = round($pAmt / $firstAdultTotal * 100, 1);
                        $pctOfAdult = $calcPct . '%';
                    }

                    echo '<tr>';
                    echo '<td>' . htmlspecialchars($label) . '</td>';
                    echo '<td>' . number_format($pAmt, 2) . '</td>';
                    echo '<td>' . number_format($apiPAmt, 2) . '</td>';
                    echo '<td class="' . $cls . '">' . ($pDiff >= 0 ? '+' : '') . number_format($pDiff, 2) . '</td>';
                    echo '<td>' . $pctOfAdult . '</td>';
                    echo '</tr>';
                }

                $baseDiffAbs = abs($apiBaseDiff);
                echo '<tr class="total-row"><td>Total</td><td>' . number_format($calcBasePrice, 2) . '</td>'
                   . '<td>' . number_format($apiImpliedBase, 2) . '</td>'
                   . '<td class="mismatch">' . ($apiBaseDiff >= 0 ? '+' : '') . number_format($apiBaseDiff, 2) . '</td><td></td></tr>';
                echo '</table>';

                // Hint: if base diff closely matches a person's rate or fraction thereof
                if ($firstAdultTotal > 0 && $baseDiffAbs > 1) {
                    $ratioOfAdult = $baseDiffAbs / $firstAdultTotal;
                    $pctStr = number_format($ratioOfAdult * 100, 1);
                    echo '<div style="background:#fff3cd;color:#856404;padding:12px;border-radius:4px;margin-top:10px;font-size:0.95em;">';
                    echo '<strong>Hint:</strong> The base price difference of <strong>' . number_format($baseDiffAbs, 2)
                       . ' EUR</strong> equals <strong>' . $pctStr . '%</strong> of the first adult\'s rate (' . number_format($firstAdultTotal, 2) . '). ';

                    if (abs($ratioOfAdult - 0.25) < 0.02) {
                        echo 'This suggests <strong>one person</strong> (likely the 3rd adult on a regular bed) should be priced at <strong>75%</strong> of the full adult rate instead of 100%. '
                           . 'Check if the season_price data has a separate row for the 3rd regular bed occupant with a percentage-based price.';
                    } elseif (abs($ratioOfAdult - 0.50) < 0.02) {
                        echo 'This suggests <strong>one person</strong> should be priced at <strong>50%</strong> of the adult rate. '
                           . 'Check for a missing or mismatched child/extra-bed pricing row.';
                    } elseif (abs($ratioOfAdult - 1.0) < 0.02) {
                        echo 'This suggests <strong>one full adult\'s price</strong> is being over-counted. '
                           . 'Check for RoomPrice=Yes vs per-person pricing mismatch.';
                    } else {
                        echo 'Check if any person\'s pricing row is missing or using an incorrect percentage.';
                    }
                    echo '</div>';
                }
            }

            echo '</div>'; // step
        }
    } else {
        echo '<div class="result-box result-mismatch">';
        echo '<strong>API Error:</strong> No price found for this room/board combination';
        echo '</div>';
    }

    // Debug Log (raw JSON, if debug checkbox was checked)
    if ($show_debug && !empty($calcResult['debug_log'])) {
        echo '<h2>Raw Debug Log</h2>';
        echo '<div class="debug-section">';
        echo '<pre>' . htmlspecialchars((string) json_encode($calcResult['debug_log'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
        echo '</div>';
    }

    // API raw response
    if ($show_debug && $apiResult) {
        echo '<h3>API Raw Response</h3>';
        echo '<div class="debug-section">';
        echo '<pre>' . htmlspecialchars($api->getLastResponse()) . '</pre>';
        echo '</div>';
    }

    // Raw Seasons Data (merged from Verify mode Section 4)
    $rawSeasons = $priceinfo['seasons'] ?? [];
    if (!empty($rawSeasons)) {
        echo '<span class="collapsible-toggle" onclick="toggleSection(\'raw-seasons-data\')">&#9654; Raw Seasons Data (JSON)</span>';
        echo '<div id="raw-seasons-data" class="collapsible-content">';
        echo '<div class="debug-section">';
        echo '<pre>' . htmlspecialchars((string) json_encode($rawSeasons, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
        echo '</div>';
        echo '</div>';
    }

    echo '<a href="' . fn_url('novoton_price_compare.manage') . '" class="btn">&larr; Back to Form</a>';
    echo '</div></body></html>';
    exit;
}

/**
 * Mode: verify (deprecated - redirects to compare mode)
 * All verify functionality has been merged into compare mode as collapsible sections.
 */
if ($mode === 'verify') {
    $params = [];
    foreach (['hotel_id', 'package_name', 'room_id', 'board_id', 'check_in', 'nights'] as $param) {
        if (isset($_REQUEST[$param]) && is_string($_REQUEST[$param])) {
            $params[$param] = substr(trim($_REQUEST[$param]), 0, 100);
        }
    }
    $query = !empty($params) ? '&' . http_build_query($params) : '';
    return [CONTROLLER_STATUS_REDIRECT, 'novoton_price_compare.compare' . $query];
}

/**
 * Mode: get_packages (AJAX)
 * Return packages for a hotel as JSON
 */
if ($mode === 'get_packages') {
    header('Content-Type: application/json; charset=utf-8');

    $hotel_id = $_REQUEST['hotel_id'] ?? '';

    if (empty($hotel_id)) {
        echo json_encode(['packages' => []]);
        exit;
    }

    $packageRepo = \Tygh\Addons\NovotonHolidays\Services\Container::getInstance()->hotelPackageRepository();
    $packages = $packageRepo->findPackageNamesWithPriceinfo($hotel_id);

    echo json_encode(['packages' => $packages]);
    exit;
}

/**
 * Mode: get_rooms (AJAX)
 * Return IdRoom values from hotelinfo for a hotel as JSON
 */
if ($mode === 'get_rooms') {
    header('Content-Type: application/json; charset=utf-8');

    $hotel_id = $_REQUEST['hotel_id'] ?? '';

    if (empty($hotel_id)) {
        echo json_encode(['rooms' => []]);
        exit;
    }

    $hotelRepo = \Tygh\Addons\NovotonHolidays\Services\Container::getInstance()->hotelRepository();
    $hotel_data_json = $hotelRepo->getHotelData($hotel_id);

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
if (empty($mode) || $mode === 'manage') {
    // Get list of hotels with packages
    $hotelRepo = \Tygh\Addons\NovotonHolidays\Services\Container::getInstance()->hotelRepository();
    $hotels = $hotelRepo->findWithPriceinfoData(200);

    Tygh::$app['view']->assign('hotels', $hotels);
    Tygh::$app['view']->assign('default_check_in', date('Y') . '-07-01');
}

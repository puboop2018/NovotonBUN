<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Prices Controller
 *
 * Price synchronization and management.
 * Split from novoton_holidays.php for maintainability.
 *
 * Modes:
 * - update_prices: Update product prices from API
 * - check_prices: Check which hotels have active prices (resort-based bulk query)
 * - check_prices_hotel: Check which hotels have active prices (per-hotel query)
 * - room_price: Check room prices for hotels
 * - download_active_prices_csv: Download prices CSV
 * - cron_offers_update: Cron job for offers update
 *
 * @package NovotonHolidays
 * @since 2.8.0
 */

use Tygh\Registry;
use Tygh\Tygh;
use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\NovotonApi;
use Tygh\Addons\NovotonHolidays\Repository\HotelRepository;
use Tygh\Addons\NovotonHolidays\Repository\SyncLogRepository;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\Container;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

/**
 * Mode: update_prices
 * Update product prices from Novoton API
 */
if ($mode == 'update_prices') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }
    
    $product_id = (int)($_REQUEST['product_id'] ?? 0);
    
    if ($product_id > 0) {
        // Update single product
        $result = fn_novoton_holidays_update_product_prices($product_id);
        
        if ($result === true) {
            fn_set_notification('N', __('notice'), 'Price updated successfully');
        } elseif ($result === 'no_data') {
            fn_set_notification('W', __('warning'), 'No price data available for this hotel');
        } else {
            fn_set_notification('E', __('error'), 'Failed to update price');
        }
        
        return [CONTROLLER_STATUS_REDIRECT, 'products.update&product_id=' . $product_id];
    }
    
    // Batch update
    header('Content-Type: text/html; charset=utf-8');
    
    echo '<!DOCTYPE html><html><head><title>Updating Prices</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
        h1 { color: #003580; }
        .log { background: #f8f9fa; padding: 15px; border-radius: 4px; font-family: monospace; font-size: 11px; max-height: 500px; overflow-y: auto; }
        .success { color: green; }
        .error { color: red; }
        .warning { color: orange; }
        .btn { display: inline-block; padding: 10px 20px; background: #003580; color: white; text-decoration: none; border-radius: 4px; margin-top: 20px; }
    </style></head><body><div class="container"><h1>Updating Product Prices</h1><div class="log">';
    
    $limit = (int)($_REQUEST['limit'] ?? 50);
    
    // Get hotels with products
    $hotels = db_get_array(
        "SELECT h.hotel_id, h.hotel_name, h.product_id 
         FROM ?:novoton_hotels h
         WHERE h.product_id > 0
         ORDER BY CASE WHEN h.last_price_check IS NULL THEN 0 ELSE 1 END, h.last_price_check ASC
         LIMIT ?i",
        $limit
    );
    
    echo "Updating prices for " . count($hotels) . " products...<br><br>\n";
    flush();
    
    $updated = 0;
    $failed = 0;
    $no_data = 0;
    
    foreach ($hotels as $hotel) {
        $result = fn_novoton_holidays_update_product_prices($hotel['product_id']);
        
        if ($result === true) {
            echo "<span class='success'>✓ {$hotel['hotel_name']}</span><br>\n";
            $updated++;
        } elseif ($result === 'no_data') {
            echo "<span class='warning'>⚠ {$hotel['hotel_name']} - No data</span><br>\n";
            $no_data++;
        } else {
            echo "<span class='error'>✗ {$hotel['hotel_name']}</span><br>\n";
            $failed++;
        }
        
        if ($updated % 10 == 0) {
            flush();
        }
    }
    
    echo "<br><strong>Summary:</strong><br>";
    echo "Updated: {$updated}<br>";
    echo "No data: {$no_data}<br>";
    echo "Failed: {$failed}<br>";
    
    echo '</div><a href="' . fn_url('novoton_holidays.manage') . '" class="btn">← Back</a>';
    echo '</div></body></html>';
    exit;
}

/**
 * Mode: check_prices
 * Check which hotels have active prices using resort-based bulk queries.
 *
 * Instead of calling room_price per hotel (N API calls), this queries
 * room_price per resort (M API calls where M << N). Uses regex on the
 * raw XML response to extract <IdHotel> values - if a hotel ID appears
 * in the response, it has prices. Much faster and catches ALL hotels
 * the API knows about, not just those in our database.
 */
if ($mode == 'check_prices') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    header('Content-Type: text/html; charset=utf-8');

    // Default dates: July 7 - July 14, use next year if current date is Nov-Dec
    $month_now = (int) date('n');
    $default_year = ($month_now >= 11) ? (int) date('Y') + 1 : (int) date('Y');
    $default_check_in = $default_year . '-07-07';
    $default_check_out = $default_year . '-07-14';

    // Parse countries: support both multi-select array and legacy single string
    $selected_countries = [];
    if (!empty($_REQUEST['countries']) && is_array($_REQUEST['countries'])) {
        $selected_countries = array_map('strtoupper', array_map('trim', $_REQUEST['countries']));
    } elseif (!empty($_REQUEST['country'])) {
        $selected_countries = [strtoupper(trim($_REQUEST['country']))];
    }

    $check_in = $_REQUEST['check_in'] ?? $default_check_in;
    $check_out = $_REQUEST['check_out'] ?? $default_check_out;
    $run = isset($_REQUEST['run']);

    // All available countries from addon constants
    $available_countries = Constants::COUNTRIES;
    // Pre-select addon's configured countries when nothing is selected yet
    if (empty($selected_countries) && !$run) {
        $selected_countries = ConfigProvider::getSelectedCountries();
    }

    echo '<!DOCTYPE html><html><head><title>Checking Hotel Prices</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
        h1 { color: #003580; }
        .log { background: #f8f9fa; padding: 15px; border-radius: 4px; font-family: monospace; font-size: 11px; max-height: 600px; overflow-y: auto; }
        .success { color: green; }
        .error { color: red; }
        .skip { color: #999; }
        .resort-header { color: #003580; font-weight: bold; margin-top: 8px; border-bottom: 1px solid #ddd; padding-bottom: 2px; }
        .country-header { color: #fff; background: #003580; font-weight: bold; margin-top: 12px; padding: 6px 10px; border-radius: 4px; font-size: 13px; }
        .btn { display: inline-block; padding: 10px 20px; background: #003580; color: white; text-decoration: none; border-radius: 4px; margin-top: 20px; }
        .btn-run { background: #28a745; font-size: 14px; border: none; cursor: pointer; color: white; padding: 10px 25px; border-radius: 4px; }
        .progress { margin: 10px 0; padding: 10px; background: #e3f2fd; border-radius: 4px; }
        .form-row { display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 20px; padding: 15px; background: #f0f0f0; border-radius: 6px; }
        .form-group { display: flex; flex-direction: column; gap: 4px; }
        .form-group label { font-size: 12px; font-weight: bold; color: #333; }
        .form-group input, .form-group select { padding: 6px 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; }
        .country-checkboxes { display: flex; flex-wrap: wrap; gap: 6px 14px; }
        .country-checkboxes label { font-size: 12px; font-weight: normal; cursor: pointer; display: flex; align-items: center; gap: 4px; }
        .country-checkboxes input[type="checkbox"] { margin: 0; }
    </style></head><body><div class="container"><h1>Checking Hotel Prices (Resort-based)</h1>';

    // Date / settings form
    $dispatch_url = fn_url('novoton_holidays.check_prices');
    echo '<form method="get" action="' . htmlspecialchars(strtok($dispatch_url, '?')) . '">';
    // Preserve dispatch and security hash from fn_url
    $url_parts = parse_url($dispatch_url);
    if (!empty($url_parts['query'])) {
        parse_str($url_parts['query'], $qs);
        foreach ($qs as $k => $v) {
            echo '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars($v) . '">';
        }
    }
    echo '<input type="hidden" name="run" value="1">';
    echo '<div class="form-row">';
    echo '<div class="form-group"><label>Check-in</label><input type="text" name="check_in" value="' . htmlspecialchars($check_in) . '" placeholder="e.g. ' . htmlspecialchars($default_check_in) . '" style="width:130px"></div>';
    echo '<div class="form-group"><label>Check-out</label><input type="text" name="check_out" value="' . htmlspecialchars($check_out) . '" placeholder="e.g. ' . htmlspecialchars($default_check_out) . '" style="width:130px"></div>';
    echo '<div class="form-group"><label>&nbsp;</label><button type="submit" class="btn-run">Check Prices</button></div>';
    echo '</div>';
    echo '<div class="form-group" style="margin-bottom:15px;"><label>Countries</label>';
    echo '<div class="country-checkboxes">';
    foreach ($available_countries as $c) {
        $checked = in_array($c, $selected_countries) ? ' checked' : '';
        $label = ucwords(strtolower($c));
        echo '<label><input type="checkbox" name="countries[]" value="' . htmlspecialchars($c) . '"' . $checked . '> ' . htmlspecialchars($label) . '</label>';
    }
    echo '</div></div>';
    echo '</form>';

    if (!$run || empty($selected_countries)) {
        echo '<p style="color:#666;">Select countries, set check-in / check-out dates and click <strong>Check Prices</strong> to start.<br>';
        echo 'Uses resort-based bulk queries (1 API call per resort instead of 1 per hotel).</p>';
        echo '<a href="' . fn_url('novoton_holidays.manage') . '" class="btn">&larr; Back</a>';
        echo '</div></body></html>';
        exit;
    }

    echo '<div class="log">';

    $api = new NovotonApi();
    $hotelRepo = Container::getInstance()->hotelRepository();

    $grand_total_hotels = 0;
    $grand_with_prices = 0;
    $grand_no_prices = 0;
    $grand_unknown = 0;
    $grand_resorts = 0;
    $grand_resort_errors = 0;

    // Process each selected country
    foreach ($selected_countries as $country) {
        echo "<div class='country-header'>Country: {$country}</div>\n";
        flush();

        // Step 1: Get resort names from resort_list API (the authoritative source).
        $all_hotels = db_get_hash_array(
            "SELECT hotel_id, hotel_name, city, product_id, has_prices FROM ?:novoton_hotels WHERE country = ?s ORDER BY hotel_name",
            'hotel_id',
            $country
        );
        $total_hotels = count($all_hotels);
        $grand_total_hotels += $total_hotels;

        $resorts = [];
        $resort_list_response = $api->getResortList($country);
        if ($resort_list_response) {
            foreach ($resort_list_response->xpath('//Resort') as $r) {
                $name = trim((string)$r);
                if (!empty($name)) {
                    $resorts[] = $name;
                }
            }
        }

        if (empty($resorts)) {
            echo "<span class='error'>resort_list API returned no resorts for {$country}. Skipping.</span><br>\n";
            continue;
        }

        sort($resorts);
        $total_resorts = count($resorts);
        $grand_resorts += $total_resorts;

        echo "Resorts: {$total_resorts} | Hotels in DB: {$total_hotels}<br>";
        echo "Check-in: {$check_in} | Check-out: {$check_out}<br>\n";
        flush();

        try {
            $hotels_with_prices = [];
            $resort_errors = 0;

            // Step 2: Query each resort in bulk
            foreach ($resorts as $resort_idx => $resort_name) {
                $resort_num = $resort_idx + 1;
                echo "<div class='resort-header'>[{$resort_num}/{$total_resorts}] {$resort_name}</div>\n";
                flush();

                try {
                    $raw_response = $api->getRoomPriceByResortRaw([
                        'resort'    => $resort_name,
                        'check_in'  => $check_in,
                        'check_out' => $check_out,
                        'adults'    => 2,
                    ]);

                    if (empty($raw_response)) {
                        echo "<span class='skip'>  Empty response</span><br>\n";
                        continue;
                    }

                    // Step 3: Regex to extract all <IdHotel> values from raw XML
                    $matches = [];
                    preg_match_all('/<IdHotel>\s*(\d+)\s*<\/IdHotel>/i', $raw_response, $matches);

                    if (!empty($matches[1])) {
                        $resort_hotel_ids = array_unique($matches[1]);
                        $count = count($resort_hotel_ids);

                        foreach ($resort_hotel_ids as $hid) {
                            $hotels_with_prices[$hid] = $resort_name;
                        }

                        $response_kb = round(strlen($raw_response) / 1024, 1);
                        echo "<span class='success'>  {$count} hotels with prices ({$response_kb} KB)</span><br>\n";
                    } else {
                        echo "<span class='skip'>  No hotels with prices</span><br>\n";
                    }

                } catch (Exception $e) {
                    echo "<span class='error'>  Error: " . htmlspecialchars($e->getMessage()) . "</span><br>\n";
                    $resort_errors++;
                }

                flush();
            }

            $grand_resort_errors += $resort_errors;

            // Step 4: Update database - mark hotels with/without prices
            $with_prices_count = 0;
            $no_prices = 0;
            $now = date('Y-m-d H:i:s');

            echo "<br><div class='resort-header'>Updating database...</div>\n";
            flush();

            foreach ($all_hotels as $hotel_id => $hotel) {
                $has = isset($hotels_with_prices[$hotel_id]);

                $hotelRepo->update((string) $hotel_id, [
                    'has_prices' => $has ? 'Y' : 'N',
                    'last_price_check' => $now
                ]);

                if ($has) {
                    $with_prices_count++;
                } else {
                    $no_prices++;
                }
            }

            $grand_with_prices += $with_prices_count;
            $grand_no_prices += $no_prices;

            // Step 5: Report hotels found by API but NOT in our database
            $unknown_hotels = array_diff_key($hotels_with_prices, $all_hotels);
            $grand_unknown += count($unknown_hotels);

            echo "<br><strong>{$country} summary:</strong> ";
            echo "Resorts: {$total_resorts}" . ($resort_errors > 0 ? " ({$resort_errors} errors)" : '') . " | ";
            echo "DB: {$total_hotels} | ";
            echo "With prices: <span class='success'><strong>{$with_prices_count}</strong></span> | ";
            echo "No prices: {$no_prices}<br>";

            if (!empty($unknown_hotels)) {
                echo "<span class='error'><strong>" . count($unknown_hotels) . " hotels with prices NOT in database:</strong></span><br>";
                foreach ($unknown_hotels as $hid => $resort_name) {
                    echo "<span class='error'>  Hotel ID {$hid} (resort: {$resort_name}) - not synced</span><br>\n";
                }
            }

            echo "<br>\n";

        } catch (Exception $e) {
            echo "<span class='error'>Error: " . htmlspecialchars($e->getMessage()) . "</span><br>";
        }
    }

    // Grand total summary across all countries
    if (count($selected_countries) > 1) {
        echo "<div class='country-header' style='background:#28a745;'>Grand Total (" . count($selected_countries) . " countries)</div>\n";
        echo "Resorts queried: {$grand_resorts}" . ($grand_resort_errors > 0 ? " ({$grand_resort_errors} errors)" : '') . "<br>";
        echo "Hotels in DB: {$grand_total_hotels}<br>";
        echo "With prices: <span class='success'><strong>{$grand_with_prices}</strong></span><br>";
        echo "No prices: {$grand_no_prices}<br>";
        if ($grand_unknown > 0) {
            echo "Unknown hotels (not in DB): <span class='error'><strong>{$grand_unknown}</strong></span><br>";
        }
    }

    echo '</div><a href="' . fn_url('novoton_holidays.manage') . '" class="btn">&larr; Back</a>';
    echo '</div></body></html>';
    exit;
}

/**
 * Mode: check_prices_hotel
 * Check which hotels have active prices using hotel-based queries.
 *
 * Unlike check_prices (resort-based), this queries each hotel individually
 * by hotel_id. This ensures we don't miss hotels due to:
 * - Missing or empty city names in database
 * - City name mismatches between database and API
 * - Hotels assigned to unknown resorts
 *
 * Slower (N API calls instead of M calls where M << N), but more accurate.
 * Use this to compare with resort-based results and identify discrepancies.
 */
if ($mode == 'check_prices_hotel') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    header('Content-Type: text/html; charset=utf-8');

    // Default dates: July 7 - July 14, use next year if current date is Nov-Dec
    $month_now = (int) date('n');
    $default_year = ($month_now >= 11) ? (int) date('Y') + 1 : (int) date('Y');
    $default_check_in = $default_year . '-07-07';
    $default_check_out = $default_year . '-07-14';

    $country = strtoupper($_REQUEST['country'] ?? 'BULGARIA');
    $check_in = $_REQUEST['check_in'] ?? $default_check_in;
    $check_out = $_REQUEST['check_out'] ?? $default_check_out;
    $limit = (int)($_REQUEST['limit'] ?? 0); // 0 = all hotels
    $run = isset($_REQUEST['run']);

    echo '<!DOCTYPE html><html><head><title>Checking Hotel Prices (Per-Hotel)</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
        h1 { color: #003580; }
        .log { background: #f8f9fa; padding: 15px; border-radius: 4px; font-family: monospace; font-size: 11px; max-height: 600px; overflow-y: auto; }
        .success { color: green; }
        .error { color: red; }
        .skip { color: #999; }
        .btn { display: inline-block; padding: 10px 20px; background: #003580; color: white; text-decoration: none; border-radius: 4px; margin-top: 20px; margin-right: 10px; }
        .btn-run { background: #28a745; font-size: 14px; border: none; cursor: pointer; color: white; padding: 10px 25px; border-radius: 4px; }
        .progress { margin: 10px 0; padding: 10px; background: #e3f2fd; border-radius: 4px; }
        .form-row { display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 20px; padding: 15px; background: #f0f0f0; border-radius: 6px; }
        .form-group { display: flex; flex-direction: column; gap: 4px; }
        .form-group label { font-size: 12px; font-weight: bold; color: #333; }
        .form-group input, .form-group select { padding: 6px 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; }
        .info-box { background: #fff3cd; border: 1px solid #ffc107; padding: 12px; border-radius: 6px; margin-bottom: 15px; }
    </style></head><body><div class="container"><h1>Checking Hotel Prices (Per-Hotel)</h1>';

    echo '<div class="info-box">';
    echo '<strong>Hotel-based query:</strong> Queries each hotel by hotel_id individually.<br>';
    echo 'Use this to compare with <a href="' . fn_url('novoton_holidays.check_prices') . '">resort-based check</a> and find discrepancies.<br>';
    echo 'Slower but more accurate - catches hotels with missing/mismatched city names.';
    echo '</div>';

    // Date / settings form
    $dispatch_url = fn_url('novoton_holidays.check_prices_hotel');
    echo '<form method="get" action="' . htmlspecialchars(strtok($dispatch_url, '?')) . '">';
    $url_parts = parse_url($dispatch_url);
    if (!empty($url_parts['query'])) {
        parse_str($url_parts['query'], $qs);
        foreach ($qs as $k => $v) {
            echo '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars($v) . '">';
        }
    }
    echo '<input type="hidden" name="run" value="1">';
    echo '<div class="form-row">';
    echo '<div class="form-group"><label>Check-in</label><input type="text" name="check_in" value="' . htmlspecialchars($check_in) . '" style="width:130px"></div>';
    echo '<div class="form-group"><label>Check-out</label><input type="text" name="check_out" value="' . htmlspecialchars($check_out) . '" style="width:130px"></div>';
    echo '<div class="form-group"><label>Country</label><input type="text" name="country" value="' . htmlspecialchars($country) . '" style="width:120px"></div>';
    echo '<div class="form-group"><label>Limit (0=all)</label><input type="number" name="limit" value="' . htmlspecialchars($limit) . '" style="width:80px"></div>';
    echo '<div class="form-group"><label>&nbsp;</label><button type="submit" class="btn-run">Check Prices</button></div>';
    echo '</div></form>';

    if (!$run) {
        echo '<p style="color:#666;">Set check-in / check-out dates and click <strong>Check Prices</strong> to start.<br>';
        echo 'This method queries each hotel individually by hotel_id (slower but complete).</p>';
        echo '<a href="' . fn_url('novoton_holidays.manage') . '" class="btn">&larr; Back</a>';
        echo '<a href="' . fn_url('novoton_holidays.check_prices') . '" class="btn">Resort-based Check</a>';
        echo '</div></body></html>';
        exit;
    }

    echo '<div class="log">';

    // Get all hotels for this country
    $limit_sql = $limit > 0 ? "LIMIT " . (int)($limit) : "";
    $all_hotels = db_get_array(
        "SELECT hotel_id, hotel_name, city, product_id FROM ?:novoton_hotels WHERE country = ?s ORDER BY hotel_name {$limit_sql}",
        $country
    );

    $total_hotels = count($all_hotels);
    $hotels_with_no_city = 0;
    foreach ($all_hotels as $h) {
        if (empty($h['city'])) {
            $hotels_with_no_city++;
        }
    }

    echo "Country: {$country} | Total Hotels: {$total_hotels}<br>";
    echo "Hotels with empty city: <strong>{$hotels_with_no_city}</strong> (these would be missed by resort-based check)<br>";
    echo "Check-in: {$check_in} | Check-out: {$check_out}<br>";
    echo "Method: per-hotel query using hotel_id<br><br>\n";
    flush();

    try {
        $api = new NovotonApi();
        $hotelRepo = Container::getInstance()->hotelRepository();

        $with_prices = 0;
        $no_prices = 0;
        $errors = 0;
        $now = date('Y-m-d H:i:s');
        $start_time = microtime(true);

        foreach ($all_hotels as $idx => $hotel) {
            $hotel_num = $idx + 1;
            $hotel_id = $hotel['hotel_id'];
            $hotel_name = $hotel['hotel_name'];
            $city = $hotel['city'] ?: '<no city>';

            // Progress indicator every 50 hotels
            if ($hotel_num % 50 == 0 || $hotel_num == 1) {
                $elapsed = round(microtime(true) - $start_time, 1);
                echo "<div class='progress'>[{$hotel_num}/{$total_hotels}] Elapsed: {$elapsed}s | With prices: {$with_prices} | No prices: {$no_prices}</div>\n";
                flush();
            }

            try {
                // Use nocache to get fresh results
                $result = $api->getRoomPrice([
                    'hotel_id'  => $hotel_id,
                    'check_in'  => $check_in,
                    'check_out' => $check_out,
                    'adults'    => 2,
                    'nocache'   => true,
                ]);

                // Check if result has any prices
                $has_prices = false;
                $min_price = 0;
                if ($result instanceof \SimpleXMLElement) {
                    $prices = $result->xpath('//Price');
                    $has_prices = !empty($prices) && count($prices) > 0;
                    if ($has_prices) {
                        foreach ($prices as $p) {
                            $pval = (float)((string)$p);
                            if ($pval > 0 && ($min_price == 0 || $pval < $min_price)) {
                                $min_price = $pval;
                            }
                        }
                    }
                }

                // Update database
                $hotelRepo->update((string) $hotel_id, [
                    'has_prices' => $has_prices ? 'Y' : 'N',
                    'last_price_check' => $now
                ]);

                if ($has_prices) {
                    $with_prices++;
                    $price_display = $min_price > 0 ? ' | <strong>' . number_format($min_price, 2) . ' EUR</strong>' : '';
                    echo "<span class='success'>✓ [{$hotel_num}] {$hotel_name} ({$city}){$price_display}</span><br>\n";
                } else {
                    $no_prices++;
                    // Only show first 20 without prices to avoid clutter
                    if ($no_prices <= 20) {
                        echo "<span class='skip'>○ [{$hotel_num}] {$hotel_name} ({$city}) - no prices</span><br>\n";
                    } elseif ($no_prices == 21) {
                        echo "<span class='skip'>... (more hotels without prices)</span><br>\n";
                    }
                }

            } catch (Exception $e) {
                $errors++;
                echo "<span class='error'>✗ [{$hotel_num}] {$hotel_name}: " . htmlspecialchars($e->getMessage()) . "</span><br>\n";
            }

            // Flush periodically
            if ($hotel_num % 10 == 0) {
                flush();
            }
        }

        $elapsed = round(microtime(true) - $start_time, 1);

        echo "<br><strong>Summary:</strong><br>";
        echo "Total hotels checked: {$total_hotels}<br>";
        echo "Hotels with empty city: {$hotels_with_no_city}<br>";
        echo "With prices: <span class='success'><strong>{$with_prices}</strong></span><br>";
        echo "No prices: {$no_prices}<br>";
        echo "Errors: {$errors}<br>";
        echo "Time: {$elapsed}s<br>";
        echo "<br><strong>Compare this with resort-based check to see discrepancies.</strong><br>";

    } catch (Exception $e) {
        echo "<span class='error'>Error: " . htmlspecialchars($e->getMessage()) . "</span><br>";
    }

    echo '</div>';
    echo '<a href="' . fn_url('novoton_holidays.manage') . '" class="btn">&larr; Back</a>';
    echo '<a href="' . fn_url('novoton_holidays.check_prices') . '" class="btn">Resort-based Check</a>';
    echo '</div></body></html>';
    exit;
}

/**
 * Mode: room_price
 * Check room prices for specific hotel
 */
if ($mode == 'room_price') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }
    
    $hotel_id = $_REQUEST['hotel_id'] ?? '';
    $check_in = $_REQUEST['check_in'] ?? date('Y-m-d', strtotime('+30 days'));
    $check_out = $_REQUEST['check_out'] ?? date('Y-m-d', strtotime('+37 days'));
    
    Tygh::$app['view']->assign('hotel_id', $hotel_id);
    Tygh::$app['view']->assign('check_in', $check_in);
    Tygh::$app['view']->assign('check_out', $check_out);
    
    if (!empty($hotel_id) && !empty($_REQUEST['check'])) {
        try {
            $api = new NovotonApi();
            
            $params = [
                'hotel_id' => $hotel_id,
                'check_in' => $check_in,
                'check_out' => $check_out,
                'adults' => (int)($_REQUEST['adults'] ?? 2),
                'children' => (int)($_REQUEST['children'] ?? 0)
            ];
            
            $result = $api->getRoomPrice($params);
            
            Tygh::$app['view']->assign('result', $result);
            Tygh::$app['view']->assign('last_request', $api->getLastRequestFormatted());
            Tygh::$app['view']->assign('last_response', $api->getLastResponse());
            
        } catch (Exception $e) {
            Tygh::$app['view']->assign('error', $e->getMessage());
        }
    }
}

/**
 * Mode: download_active_prices_csv
 * Download CSV with hotels that have active prices
 */
if ($mode == 'download_active_prices_csv') {
    if (!fn_check_permissions('manage_catalog', 'view', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }
    
    $country = strtoupper($_REQUEST['country'] ?? 'BULGARIA');
    
    $hotels = db_get_array(
        "SELECT hotel_id, hotel_name, city, hotel_type, has_prices, product_id, last_price_check
         FROM ?:novoton_hotels
         WHERE country = ?s AND has_prices = 'Y'
         ORDER BY city, hotel_name",
        $country
    );
    
    $csv = "Hotel ID;Hotel Name;City;Hotel Type;Product ID;Last Check\n";

    foreach ($hotels as $hotel) {
        $csv .= implode(';', [
            $hotel['hotel_id'],
            '"' . str_replace('"', '""', $hotel['hotel_name']) . '"',
            $hotel['city'],
            $hotel['hotel_type'],
            $hotel['product_id'] ?: '',
            $hotel['last_price_check'] ?: ''
        ]) . "\n";
    }
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="novoton_active_prices_' . strtolower($country) . '_' . date('Y-m-d') . '.csv"');
    echo $csv;
    exit;
}

/**
 * Mode: cron_offers_update
 * Cron job to update offers/prices
 */
if ($mode == 'cron_offers_update') {
    // Verify access key
    $access_key = $_REQUEST['access_key'] ?? '';
    $expected_key = ConfigProvider::getCronAccessKey();
    
    if (empty($expected_key) || !hash_equals($expected_key, $access_key)) {
        header('HTTP/1.1 403 Forbidden');
        echo 'Invalid access key';
        exit;
    }
    
    header('Content-Type: text/plain; charset=utf-8');
    
    echo "=== NOVOTON OFFERS UPDATE ===\n";
    echo "Started: " . date('Y-m-d H:i:s') . "\n\n";
    
    try {
        $api = new NovotonApi();
        $hotelRepo = Container::getInstance()->hotelRepository();
        $syncLogRepo = Container::getInstance()->syncLogRepository();
        
        $updated = 0;
        $errors = 0;
        $start_time = time();
        
        // Get hotels that need price update
        $hotels = db_get_array(
            "SELECT hotel_id, hotel_name, product_id 
             FROM ?:novoton_hotels 
             WHERE has_prices = 'Y'
               AND (last_price_check IS NULL OR last_price_check < DATE_SUB(NOW(), INTERVAL 24 HOUR))
             ORDER BY CASE WHEN last_price_check IS NULL THEN 0 ELSE 1 END, last_price_check ASC
             LIMIT 100"
        );
        
        echo "Processing " . count($hotels) . " hotels\n\n";
        
        foreach ($hotels as $hotel) {
            try {
                if ($hotel['product_id'] > 0) {
                    $result = fn_novoton_holidays_update_product_prices($hotel['product_id']);
                    if ($result === true) {
                        $updated++;
                        echo "✓ {$hotel['hotel_name']}\n";
                    } else {
                        echo "○ {$hotel['hotel_name']} (no data)\n";
                    }
                } else {
                    // Just update the price check timestamp
                    $hotelRepo->update((string) $hotel['hotel_id'], ['last_price_check' => date('Y-m-d H:i:s')]);
                }
            } catch (Exception $e) {
                $errors++;
                echo "✗ {$hotel['hotel_name']}: " . $e->getMessage() . "\n";
            }
        }
        
        $duration = time() - $start_time;
        
        // Log sync
        $syncLogRepo->logSync('offers_update', count($hotels), $updated, $errors, $duration);
        
        echo "\n=== SUMMARY ===\n";
        echo "Updated: {$updated}\n";
        echo "Errors: {$errors}\n";
        echo "Duration: {$duration}s\n";
        echo "Completed: " . date('Y-m-d H:i:s') . "\n";
        
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
    
    exit;
}

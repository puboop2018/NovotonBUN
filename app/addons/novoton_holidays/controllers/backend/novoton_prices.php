<?php
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
use Tygh\Addons\NovotonHolidays\NovotonApi;
use Tygh\Addons\NovotonHolidays\Repository\HotelRepository;
use Tygh\Addons\NovotonHolidays\Repository\SyncLogRepository;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

// Load API class
$src_dir = Registry::get('config.dir.addons') . 'novoton_holidays/src/';
if (!class_exists('Tygh\Addons\NovotonHolidays\NovotonApi') && file_exists($src_dir . 'NovotonApi.php')) {
    require_once($src_dir . 'NovotonApi.php');
}

// Load Repository classes
$repo_dir = Registry::get('config.dir.addons') . 'novoton_holidays/Repository/';
if (!class_exists('Tygh\Addons\NovotonHolidays\Repository\HotelRepository') && file_exists($repo_dir . 'HotelRepository.php')) {
    require_once($repo_dir . 'HotelRepository.php');
}
if (!class_exists('Tygh\Addons\NovotonHolidays\Repository\SyncLogRepository') && file_exists($repo_dir . 'SyncLogRepository.php')) {
    require_once($repo_dir . 'SyncLogRepository.php');
}

/**
 * Mode: update_prices
 * Update product prices from Novoton API
 */
if ($mode == 'update_prices') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }
    
    $product_id = intval($_REQUEST['product_id'] ?? 0);
    
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
    
    $limit = intval($_REQUEST['limit'] ?? 50);
    
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

    $country = strtoupper($_REQUEST['country'] ?? 'BULGARIA');
    $check_in = $_REQUEST['check_in'] ?? $default_check_in;
    $check_out = $_REQUEST['check_out'] ?? $default_check_out;
    $run = isset($_REQUEST['run']);

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
        .btn { display: inline-block; padding: 10px 20px; background: #003580; color: white; text-decoration: none; border-radius: 4px; margin-top: 20px; }
        .btn-run { background: #28a745; font-size: 14px; border: none; cursor: pointer; color: white; padding: 10px 25px; border-radius: 4px; }
        .progress { margin: 10px 0; padding: 10px; background: #e3f2fd; border-radius: 4px; }
        .form-row { display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 20px; padding: 15px; background: #f0f0f0; border-radius: 6px; }
        .form-group { display: flex; flex-direction: column; gap: 4px; }
        .form-group label { font-size: 12px; font-weight: bold; color: #333; }
        .form-group input, .form-group select { padding: 6px 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; }
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
    echo '<div class="form-group"><label>Country</label><input type="text" name="country" value="' . htmlspecialchars($country) . '" style="width:120px"></div>';
    echo '<div class="form-group"><label>&nbsp;</label><button type="submit" class="btn-run">Check Prices</button></div>';
    echo '</div></form>';

    if (!$run) {
        echo '<p style="color:#666;">Set check-in / check-out dates and click <strong>Check Prices</strong> to start.<br>';
        echo 'Uses resort-based bulk queries (1 API call per resort instead of 1 per hotel).</p>';
        echo '<a href="' . fn_url('novoton_holidays.manage') . '" class="btn">&larr; Back</a>';
        echo '</div></body></html>';
        exit;
    }

    echo '<div class="log">';

    // Step 1: Get authoritative resort names directly from resort_list API,
    // merged with DB hotel cities as fallback
    $all_hotels = db_get_hash_array(
        "SELECT hotel_id, hotel_name, city, product_id, has_prices FROM ?:novoton_hotels WHERE country = ?s ORDER BY hotel_name",
        'hotel_id',
        $country
    );
    $total_hotels = count($all_hotels);

    $api = new NovotonApi();
    $hotelRepo = new HotelRepository();

    // Primary source: call resort_list API directly (~2KB, instant)
    // Returns the exact resort names that room_price API accepts
    $api_resorts = [];
    try {
        $resort_list_response = $api->getResortList($country);
        if ($resort_list_response) {
            foreach ($resort_list_response->xpath('//Resort') as $r) {
                $name = trim((string)$r);
                if (!empty($name)) {
                    $api_resorts[] = $name;
                }
            }
        }
    } catch (Exception $e) {
        // Fall through to DB fallback
    }

    // Fallback: DB hotel cities (if API call fails)
    $db_cities = db_get_fields(
        "SELECT DISTINCT city FROM ?:novoton_hotels WHERE country = ?s AND city != '' AND city IS NOT NULL ORDER BY city",
        $country
    );

    if (!empty($api_resorts)) {
        // Use API resorts as primary, merge DB cities that aren't duplicates.
        // Skip DB cities that normalize to the same name as an API resort
        // (e.g., "ST.CONSTANTINE & ELENA" vs "ST. CONSTANTINE AND ELENA")
        $normalized_api = [];
        foreach ($api_resorts as $ar) {
            $normalized_api[fn_novoton_normalize_resort_name($ar)] = true;
        }
        $extra_cities = [];
        $skipped_cities = [];
        foreach ($db_cities as $dc) {
            if (!in_array($dc, $api_resorts)) {
                $norm = fn_novoton_normalize_resort_name($dc);
                if (isset($normalized_api[$norm])) {
                    $skipped_cities[] = $dc;
                } else {
                    $extra_cities[] = $dc;
                }
            }
        }
        $resorts = array_values(array_unique(array_merge($api_resorts, $extra_cities)));
        $source = 'resort_list API + DB cities (' . count($api_resorts) . ' + ' . count($extra_cities) . ')';
        if (!empty($skipped_cities)) {
            $source .= ' — skipped ' . count($skipped_cities) . ' duplicate DB cities';
        }
    } else {
        $resorts = $db_cities;
        $source = 'DB cities only (resort_list API unavailable)';
    }
    sort($resorts);
    $total_resorts = count($resorts);

    echo "Country: {$country} | Resorts: {$total_resorts} | Hotels in DB: {$total_hotels}<br>";
    echo "Resort source: {$source}<br>";
    echo "Check-in: {$check_in} | Check-out: {$check_out}<br>";
    echo "Method: resort-based bulk query with regex &lt;IdHotel&gt; extraction<br><br>\n";
    flush();

    try {
        // $api and $hotelRepo already instantiated above (step 1)

        // Set of all hotel IDs that have prices (found via resort queries)
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
                // This is much faster than parsing potentially 800KB+ of XML
                $matches = [];
                preg_match_all('/<IdHotel>\s*(\d+)\s*<\/IdHotel>/i', $raw_response, $matches);

                if (!empty($matches[1])) {
                    // Unique hotel IDs found in this resort response
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

        // Step 4: Targeted fallback for hotels not found in any resort query.
        // Only re-check hotels that PREVIOUSLY had prices (has_prices = 'Y').
        // These are the likely city/resort mismatches — avoids checking all ~692
        // no-price hotels when only ~12 are actual mismatches.
        $not_found_in_resorts = array_diff_key($all_hotels, $hotels_with_prices);
        $fallback_candidates = array_filter($not_found_in_resorts, function($h) {
            return ($h['has_prices'] ?? '') === 'Y';
        });
        $fallback_found = 0;
        $fallback_errors = 0;
        $fallback_checked = count($fallback_candidates);

        if (!empty($fallback_candidates)) {
            echo "<br><div class='resort-header'>Fallback: checking {$fallback_checked} hotels that previously had prices but weren't in any resort response...</div>\n";
            flush();

            // Build normalized API resort lookup for fuzzy matching
            $normalized_api_resorts = [];
            foreach ($api_resorts as $ar) {
                $normalized_api_resorts[fn_novoton_normalize_resort_name($ar)] = $ar;
            }

            foreach ($fallback_candidates as $hotel_id => $hotel) {
                try {
                    $result = $api->getRoomPrice([
                        'hotel_id'  => $hotel_id,
                        'check_in'  => $check_in,
                        'check_out' => $check_out,
                        'adults'    => 2,
                        'nocache'   => true,
                    ]);

                    $has_prices = false;
                    if ($result instanceof \SimpleXMLElement) {
                        $prices = $result->xpath('//Price');
                        $has_prices = !empty($prices) && count($prices) > 0;
                    }

                    if ($has_prices) {
                        // Try to extract the actual resort name from the API response
                        // so self-healing (Step 5) can correct the city
                        $resort_for_healing = $hotel['city'] ?: '<no city>';
                        $raw_response = $api->getLastResponse();
                        if (!empty($raw_response) && preg_match('/<Resort>\s*([^<]+?)\s*<\/Resort>/i', $raw_response, $resort_match)) {
                            $extracted = trim($resort_match[1]);
                            if (!empty($extracted)) {
                                $resort_for_healing = $extracted;
                            }
                        }

                        // If API response didn't include Resort, try fuzzy matching
                        // the hotel's DB city against known API resort names
                        if ($resort_for_healing === ($hotel['city'] ?: '<no city>') && !empty($hotel['city'])) {
                            $normalized_city = fn_novoton_normalize_resort_name($hotel['city']);
                            if (isset($normalized_api_resorts[$normalized_city])) {
                                $resort_for_healing = $normalized_api_resorts[$normalized_city];
                            }
                        }

                        $hotels_with_prices[$hotel_id] = $resort_for_healing;
                        $fallback_found++;
                        $mismatch_detail = '';
                        if ($resort_for_healing !== ($hotel['city'] ?: '<no city>')) {
                            $mismatch_detail = " → API resort: " . htmlspecialchars($resort_for_healing);
                        }
                        echo "<span class='success'>  ✓ {$hotel['hotel_name']} (city: " . htmlspecialchars($hotel['city'] ?: '<empty>') . "{$mismatch_detail}) — city/resort mismatch</span><br>\n";
                    } else {
                        echo "<span class='skip'>  ○ {$hotel['hotel_name']} — no longer has prices</span><br>\n";
                    }
                } catch (Exception $e) {
                    $fallback_errors++;
                }

                if (($fallback_found + $fallback_errors) % 10 == 0) {
                    flush();
                }
            }

            echo "<br><span class='success'>  Fallback: {$fallback_found}/{$fallback_checked} still have prices</span><br>\n";
        }

        // Step 5: Self-heal city/resort mismatches.
        // $hotels_with_prices maps hotel_id => resort_name (the resort query that found it).
        // If a hotel's DB city doesn't match, update it so future runs find it directly.
        $city_corrections = 0;
        foreach ($hotels_with_prices as $hid => $found_in_resort) {
            if (isset($all_hotels[$hid]) && $found_in_resort !== '<no city>') {
                $db_city = $all_hotels[$hid]['city'] ?? '';
                if ($db_city !== $found_in_resort) {
                    $hotelRepo->update($hid, ['city' => $found_in_resort]);
                    $city_corrections++;
                    echo "<span class='warning'>  ⟳ {$all_hotels[$hid]['hotel_name']}: city corrected \"{$db_city}\" → \"{$found_in_resort}\"</span><br>\n";
                }
            }
        }
        if ($city_corrections > 0) {
            echo "<br><span class='warning'>  Auto-corrected {$city_corrections} city/resort mismatches (will be found by resort scan next run)</span><br>\n";
        }

        // Step 6: Update database - mark hotels with/without prices
        $with_prices_count = 0;
        $no_prices = 0;
        $now = date('Y-m-d H:i:s');

        echo "<br><div class='resort-header'>Updating database...</div>\n";
        flush();

        foreach ($all_hotels as $hotel_id => $hotel) {
            $has = isset($hotels_with_prices[$hotel_id]);

            $hotelRepo->update($hotel_id, [
                'has_prices' => $has ? 'Y' : 'N',
                'last_price_check' => $now
            ]);

            if ($has) {
                $with_prices_count++;
            } else {
                $no_prices++;
            }
        }

        // Step 7: Report hotels found by API but NOT in our database
        $unknown_hotels = array_diff_key($hotels_with_prices, $all_hotels);

        echo "<br><strong>Summary:</strong><br>";
        echo "Resorts queried: {$total_resorts}" . ($resort_errors > 0 ? " ({$resort_errors} errors)" : '') . "<br>";
        echo "Hotels in DB: {$total_hotels}<br>";
        echo "With prices (resort scan): <span class='success'>" . ($with_prices_count - $fallback_found) . "</span><br>";
        if ($fallback_found > 0) {
            echo "With prices (fallback): <span class='success'>+{$fallback_found}</span> (city/resort mismatch)<br>";
        }
        echo "With prices (total): <span class='success'><strong>{$with_prices_count}</strong></span><br>";
        echo "No prices: {$no_prices}<br>";
        if ($city_corrections > 0) {
            echo "City/resort corrections: <span class='warning'>{$city_corrections}</span> (self-healed for next run)<br>";
        }

        if (!empty($unknown_hotels)) {
            echo "<br><span class='error'><strong>" . count($unknown_hotels) . " hotels with prices NOT in database:</strong></span><br>";
            foreach ($unknown_hotels as $hid => $resort_name) {
                echo "<span class='error'>  Hotel ID {$hid} (resort: {$resort_name}) - not synced</span><br>\n";
            }
            echo "<br><span style='color:#666;'>These hotels have prices in the API but are missing from your hotel list. Run Hotel Sync to add them.</span><br>";
        }

    } catch (Exception $e) {
        echo "<span class='error'>Error: " . htmlspecialchars($e->getMessage()) . "</span><br>";
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
    $limit = intval($_REQUEST['limit'] ?? 0); // 0 = all hotels
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
    $limit_sql = $limit > 0 ? "LIMIT " . intval($limit) : "";
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
        $hotelRepo = new HotelRepository();

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
                            $pval = floatval((string)$p);
                            if ($pval > 0 && ($min_price == 0 || $pval < $min_price)) {
                                $min_price = $pval;
                            }
                        }
                    }
                }

                // Update database
                $hotelRepo->update($hotel_id, [
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
                'adults' => intval($_REQUEST['adults'] ?? 2),
                'children' => intval($_REQUEST['children'] ?? 0)
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
    $addon_settings = Registry::get('addons.novoton_holidays') ?? [];
    $expected_key = $addon_settings['cron_access_key'] ?? '';
    
    if (empty($expected_key) || $access_key !== $expected_key) {
        header('HTTP/1.1 403 Forbidden');
        echo 'Invalid access key';
        exit;
    }
    
    header('Content-Type: text/plain; charset=utf-8');
    
    echo "=== NOVOTON OFFERS UPDATE ===\n";
    echo "Started: " . date('Y-m-d H:i:s') . "\n\n";
    
    try {
        $api = new NovotonApi();
        $hotelRepo = new HotelRepository();
        $syncLogRepo = new SyncLogRepository();
        
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
                    $hotelRepo->update($hotel['hotel_id'], ['last_price_check' => date('Y-m-d H:i:s')]);
                }
            } catch (Exception $e) {
                $errors++;
                echo "✗ {$hotel['hotel_name']}: " . $e->getMessage() . "\n";
            }
        }
        
        $duration = time() - $start_time;
        
        // Log sync
        $syncLogRepo->logSync('offers_update', count($hotels), 0, $updated, $errors, $duration);
        
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

<?php
/**
 * Novoton Holidays - Prices Controller
 * 
 * Price synchronization and management.
 * Split from novoton_holidays.php for maintainability.
 * 
 * Modes:
 * - update_prices: Update product prices from API
 * - check_prices: Check which hotels have active prices
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
 * Check which hotels have active prices
 */
if ($mode == 'check_prices') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    header('Content-Type: text/html; charset=utf-8');

    echo '<!DOCTYPE html><html><head><title>Checking Hotel Prices</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
        h1 { color: #003580; }
        .log { background: #f8f9fa; padding: 15px; border-radius: 4px; font-family: monospace; font-size: 11px; max-height: 600px; overflow-y: auto; }
        .success { color: green; }
        .error { color: red; }
        .skip { color: #999; }
        .btn { display: inline-block; padding: 10px 20px; background: #003580; color: white; text-decoration: none; border-radius: 4px; margin-top: 20px; }
        .progress { margin: 10px 0; padding: 10px; background: #e3f2fd; border-radius: 4px; }
        .info { margin-bottom: 15px; padding: 10px; background: #f0f0f0; border-radius: 4px; font-size: 12px; }
    </style></head><body><div class="container"><h1>Checking Hotel Prices</h1>';

    $country = strtoupper($_REQUEST['country'] ?? 'BULGARIA');
    $limit = intval($_REQUEST['limit'] ?? 500);
    $check_in = $_REQUEST['check_in'] ?? date('Y-m-d', strtotime('+7 days'));
    $check_out = $_REQUEST['check_out'] ?? date('Y-m-d', strtotime('+14 days'));

    echo '<div class="info">';
    echo "<strong>Country:</strong> {$country} | ";
    echo "<strong>Check-in:</strong> {$check_in} | ";
    echo "<strong>Check-out:</strong> {$check_out} | ";
    echo "<strong>Limit:</strong> {$limit}<br>";
    echo "<em>Customize: ?country=BULGARIA&amp;check_in=YYYY-MM-DD&amp;check_out=YYYY-MM-DD&amp;limit=500</em>";
    echo '</div><div class="log">';

    // Get ALL hotels for the country (no recency filter - always recheck)
    $hotels = db_get_array(
        "SELECT hotel_id, hotel_name, product_id
         FROM ?:novoton_hotels
         WHERE country = ?s
         ORDER BY hotel_name ASC
         LIMIT ?i",
        $country, $limit
    );

    echo "Checking prices for " . count($hotels) . " hotels in {$country}...<br><br>\n";
    flush();

    try {
        $api = new NovotonApi();
        $hotelRepo = new HotelRepository();

        $with_prices = 0;
        $no_prices = 0;

        foreach ($hotels as $hotel) {
            $hotel_id = $hotel['hotel_id'];
            $product_code = 'NVT-' . $hotel_id;

            try {
                $params = [
                    'hotel_id' => $hotel_id,
                    'check_in' => $check_in,
                    'check_out' => $check_out,
                    'adults' => 2,
                    'children' => 0
                ];

                $result = $api->getRoomPrice($params);

                // Find any Price element in the response (can be nested in hotel/room/board)
                $best_price = 0;
                if ($result instanceof \SimpleXMLElement) {
                    $prices = $result->xpath('//Price');
                    if (!empty($prices)) {
                        foreach ($prices as $p) {
                            $pv = floatval((string)$p);
                            if ($pv > 0 && ($best_price == 0 || $pv < $best_price)) {
                                $best_price = $pv;
                            }
                        }
                    }
                }

                $has_prices = ($best_price > 0);

                $hotelRepo->update($hotel_id, [
                    'has_prices' => $has_prices ? 'Y' : 'N',
                    'last_price_check' => date('Y-m-d H:i:s')
                ]);

                if ($has_prices) {
                    echo "<span class='success'>✓ {$product_code} | {$hotel['hotel_name']} - €" . number_format($best_price, 2) . "</span><br>\n";
                    $with_prices++;
                } else {
                    echo "<span class='skip'>○ {$product_code} | {$hotel['hotel_name']} - No prices</span><br>\n";
                    $no_prices++;
                }

            } catch (Exception $e) {
                echo "<span class='error'>✗ {$product_code} | {$hotel['hotel_name']} - Error</span><br>\n";
                $no_prices++;
            }

            if (($with_prices + $no_prices) % 10 == 0) {
                echo "<div class='progress'>Progress: " . ($with_prices + $no_prices) . "/" . count($hotels) . "</div>";
                flush();
            }
        }

        echo "<br><strong>Summary:</strong><br>";
        echo "With prices: {$with_prices}<br>";
        echo "No prices: {$no_prices}<br>";
        echo "Total checked: " . ($with_prices + $no_prices) . "<br>";

    } catch (Exception $e) {
        echo "<span class='error'>Error: " . htmlspecialchars($e->getMessage()) . "</span><br>";
    }

    echo '</div><a href="' . fn_url('novoton_holidays.manage') . '" class="btn">← Back</a>';
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
        "SELECT hotel_id, hotel_name, city, stars, has_prices, product_id, last_price_check
         FROM ?:novoton_hotels 
         WHERE country = ?s AND has_prices = 'Y'
         ORDER BY city, hotel_name",
        $country
    );
    
    $csv = "Hotel ID;Hotel Name;City;Stars;Product ID;Last Check\n";
    
    foreach ($hotels as $hotel) {
        $csv .= implode(';', [
            $hotel['hotel_id'],
            '"' . str_replace('"', '""', $hotel['hotel_name']) . '"',
            $hotel['city'],
            $hotel['stars'],
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

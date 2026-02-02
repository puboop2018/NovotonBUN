<?php
/**
 * Novoton Holidays - Hotels Controller
 * 
 * Hotel synchronization, adding hotels as products, managing hotel data.
 * Split from novoton_holidays.php for maintainability.
 * 
 * Modes:
 * - add_hotels_as_products: Import hotels to CS-Cart
 * - view_hotels_to_add: Preview hotels for import
 * - list_facilities: View facilities list
 * - sync_facilities: Sync facilities from API
 * - check_packages: Check hotel packages from API
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
 * Mode: sync (removed)
 * Hotel sync is now handled exclusively by the cron Hotel List Sync:
 *   dispatch=novoton_cron.run&access_key=KEY&mode=hotel_list
 * The cron version saves more complete data (region, lat/lng, proper timestamps).
 */

/**
 * Mode: view_hotels_to_add
 * Preview hotels that can be added as products
 */
if ($mode == 'view_hotels_to_add') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }
    
    $country = strtoupper($_REQUEST['country'] ?? 'BULGARIA');
    $filter = $_REQUEST['filter'] ?? 'prices'; // prices or packages
    
    $hotelRepo = new HotelRepository();
    
    if ($filter == 'packages') {
        $hotels = db_get_array(
            "SELECT h.*, p.product_id as existing_product 
             FROM ?:novoton_hotels h
             LEFT JOIN ?:products p ON h.product_id = p.product_id
             WHERE h.country = ?s 
               AND h.packages_data IS NOT NULL 
               AND h.packages_data != '' 
               AND h.packages_data != '[]'
               AND (h.product_id IS NULL OR h.product_id = 0)
             ORDER BY h.hotel_name
             LIMIT 500",
            $country
        );
    } else {
        $hotels = db_get_array(
            "SELECT h.*, p.product_id as existing_product 
             FROM ?:novoton_hotels h
             LEFT JOIN ?:products p ON h.product_id = p.product_id
             WHERE h.country = ?s 
               AND h.has_prices = 'Y'
               AND (h.product_id IS NULL OR h.product_id = 0)
             ORDER BY h.hotel_name
             LIMIT 500",
            $country
        );
    }
    
    // Get statistics
    $stats = [
        'total' => $hotelRepo->count(['country' => $country]),
        'with_prices' => $hotelRepo->count(['country' => $country, 'has_prices' => 'Y']),
        'with_product' => $hotelRepo->count(['country' => $country, 'has_product' => true]),
        'ready_to_add' => count($hotels)
    ];
    
    Tygh::$app['view']->assign('hotels', $hotels);
    Tygh::$app['view']->assign('country', $country);
    Tygh::$app['view']->assign('filter', $filter);
    Tygh::$app['view']->assign('stats', $stats);
}

/**
 * Mode: add_hotels_as_products
 * Import hotels as CS-Cart products
 */
if ($mode == 'add_hotels_as_products') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }
    
    $run_process = isset($_REQUEST['run']);
    
    if (!$run_process) {
        // Show configuration form
        $country = strtoupper($_REQUEST['country'] ?? 'BULGARIA');
        
        $hotelRepo = new HotelRepository();
        
        $stats = [
            'total' => $hotelRepo->count(['country' => $country]),
            'with_prices' => $hotelRepo->count(['country' => $country, 'has_prices' => 'Y']),
            'with_packages' => db_get_field(
                "SELECT COUNT(*) FROM ?:novoton_hotels 
                 WHERE country = ?s AND packages_data IS NOT NULL AND packages_data != '' AND packages_data != '[]'",
                $country
            ),
            'already_products' => $hotelRepo->count(['country' => $country, 'has_product' => true]),
        ];
        $stats['to_add'] = $stats['with_prices'] - $stats['already_products'];
        
        // Get resorts
        $resorts = db_get_array(
            "SELECT city, COUNT(*) as hotel_count, 
                    SUM(CASE WHEN has_prices = 'Y' THEN 1 ELSE 0 END) as with_prices
             FROM ?:novoton_hotels 
             WHERE country = ?s AND city IS NOT NULL AND city != ''
             GROUP BY city ORDER BY hotel_count DESC",
            $country
        );
        
        // Get categories
        $categories = db_get_array(
            "SELECT c.category_id, cd.category, c.parent_id
             FROM ?:categories c
             LEFT JOIN ?:category_descriptions cd ON c.category_id = cd.category_id AND cd.lang_code = ?s
             WHERE c.status = 'A'
             ORDER BY cd.category",
            CART_LANGUAGE
        );
        
        // Get languages
        $languages = db_get_array("SELECT lang_code, name FROM ?:languages WHERE status = 'A' ORDER BY name");
        
        Tygh::$app['view']->assign('country', $country);
        Tygh::$app['view']->assign('stats', $stats);
        Tygh::$app['view']->assign('resorts', $resorts);
        Tygh::$app['view']->assign('categories', $categories);
        Tygh::$app['view']->assign('languages', $languages);
        
    } else {
        // Process import
        header('Content-Type: text/html; charset=utf-8');
        
        echo '<!DOCTYPE html><html><head><title>Adding Hotels as Products</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
            .container { max-width: 1000px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
            h1 { color: #003580; }
            .log { background: #f8f9fa; padding: 15px; border-radius: 4px; font-family: monospace; font-size: 11px; max-height: 600px; overflow-y: auto; }
            .success { color: green; }
            .error { color: red; }
            .skip { color: #999; }
            .btn { display: inline-block; padding: 10px 20px; background: #003580; color: white; text-decoration: none; border-radius: 4px; margin-top: 20px; }
        </style></head><body><div class="container"><h1>🏨 Adding Hotels as Products</h1><div class="log">';
        
        $country = strtoupper($_REQUEST['country'] ?? 'BULGARIA');
        $category_id = intval($_REQUEST['category_id'] ?? 0);
        $import_mode = $_REQUEST['import_mode'] ?? 'new_only';
        $limit = intval($_REQUEST['limit'] ?? 0);
        $selected_resorts = $_REQUEST['resorts'] ?? [];
        $selected_languages = $_REQUEST['languages'] ?? ['en', 'ro'];
        
        // Build query based on import mode
        $condition = "country = ?s AND has_prices = 'Y'";
        $params = [$country];
        
        if ($import_mode == 'new_only') {
            $condition .= " AND (product_id IS NULL OR product_id = 0)";
        }
        
        if (!empty($selected_resorts)) {
            $condition .= " AND city IN (?a)";
            $params[] = $selected_resorts;
        }
        
        $limit_sql = $limit > 0 ? " LIMIT " . intval($limit) : "";
        
        $hotels = db_get_array(
            "SELECT * FROM ?:novoton_hotels WHERE {$condition} ORDER BY hotel_name {$limit_sql}",
            ...$params
        );
        
        echo "Found " . count($hotels) . " hotels to process<br><br>\n";
        flush();
        
        $added = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;
        
        $hotelRepo = new HotelRepository();
        
        foreach ($hotels as $hotel) {
            $hotel_id = $hotel['hotel_id'];
            $hotel_name = $hotel['hotel_name'];
            
            // Skip if already has product and mode is new_only
            if ($import_mode == 'new_only' && !empty($hotel['product_id'])) {
                echo "<span class='skip'>↷ Skipped (has product): {$hotel_name}</span><br>\n";
                $skipped++;
                continue;
            }
            
            try {
                // Build product title
                $title = fn_novoton_build_hotel_title($hotel_name, $hotel['city'], $country, date('Y'));
                
                // Create product
                $product_data = [
                    'product' => $title,
                    'product_code' => 'NVT-' . $hotel_id,
                    'price' => 0,
                    'status' => 'A',
                    'company_id' => 1,
                    'main_category' => $category_id > 0 ? $category_id : fn_get_default_category_id(),
                ];
                
                // Add descriptions for selected languages
                foreach ($selected_languages as $lang_code) {
                    $desc_field = 'description_' . strtolower($lang_code);
                    $desc = !empty($hotel[$desc_field]) ? $hotel[$desc_field] : ($hotel['description_en'] ?? '');
                    
                    $product_data['description'][$lang_code] = $desc;
                    $product_data['product'][$lang_code] = $title;
                }
                
                if (!empty($hotel['product_id']) && $import_mode == 'update') {
                    // Update existing
                    fn_update_product($product_data, $hotel['product_id'], $selected_languages[0] ?? 'en');
                    $updated++;
                    echo "<span class='success'>✓ Updated: {$hotel_name}</span><br>\n";
                } else {
                    // Create new
                    $product_id = fn_update_product($product_data, 0, $selected_languages[0] ?? 'en');
                    
                    if ($product_id) {
                        // Link hotel to product
                        $hotelRepo->update($hotel_id, ['product_id' => $product_id]);
                        
                        // Set star rating feature
                        $star_rating = intval($hotel['hotel_type'] ?? '');
                        if ($star_rating > 0) {
                            fn_novoton_assign_star_rating_feature($product_id, $star_rating);
                        }
                        
                        $added++;
                        echo "<span class='success'>✓ Added: {$hotel_name} (#{$product_id})</span><br>\n";
                    } else {
                        $errors++;
                        echo "<span class='error'>✗ Failed: {$hotel_name}</span><br>\n";
                    }
                }
                
            } catch (Exception $e) {
                $errors++;
                echo "<span class='error'>✗ Error: {$hotel_name} - " . htmlspecialchars($e->getMessage()) . "</span><br>\n";
            }
            
            if (($added + $updated) % 10 == 0) {
                flush();
            }
        }
        
        echo "<br><strong>Summary:</strong><br>";
        echo "Added: {$added}<br>";
        echo "Updated: {$updated}<br>";
        echo "Skipped: {$skipped}<br>";
        echo "Errors: {$errors}<br>";
        
        echo '</div><a href="' . fn_url('novoton_holidays.manage') . '" class="btn">← Back to Dashboard</a>';
        echo '</div></body></html>';
        exit;
    }
}

/**
 * Mode: list_facilities
 * View facilities list
 */
if ($mode == 'list_facilities') {
    $facilities = db_get_array("SELECT * FROM ?:novoton_facilities ORDER BY facility_name_en");
    $count = count($facilities);
    
    Tygh::$app['view']->assign('facilities', $facilities);
    Tygh::$app['view']->assign('facilities_count', $count);
}

/**
 * Mode: sync_facilities
 * Sync facilities from API
 */
if ($mode == 'sync_facilities') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }
    
    $result = fn_novoton_sync_facilities_list();
    
    if ($result['success']) {
        fn_set_notification('N', __('notice'), "Facilities synced! Added: {$result['added']}, Updated: {$result['updated']}");
    } else {
        fn_set_notification('E', __('error'), $result['error'] ?? 'Sync failed');
    }
    
    return [CONTROLLER_STATUS_REDIRECT, 'novoton_hotels.list_facilities'];
}

/**
 * Mode: check_packages
 * Check hotel packages from API (hotelinfo) for all countries
 */
if ($mode == 'check_packages') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    header('Content-Type: text/html; charset=utf-8');

    $limit = intval($_REQUEST['limit'] ?? 500);
    $run = isset($_REQUEST['run']);

    echo '<!DOCTYPE html><html><head><title>Check Hotel Packages</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1100px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { color: #003580; }
        .log { background: #f8f9fa; padding: 15px; border-radius: 4px; font-family: monospace; font-size: 11px; max-height: 700px; overflow-y: auto; }
        .success { color: green; }
        .error { color: red; }
        .skip { color: #999; }
        .country-header { color: #003580; font-weight: bold; margin-top: 10px; padding: 5px 0; border-bottom: 1px solid #dee2e6; }
        .btn { display: inline-block; padding: 10px 20px; background: #003580; color: white; text-decoration: none; border-radius: 4px; margin-top: 20px; }
        .btn-run { background: #28a745; font-size: 14px; border: none; cursor: pointer; color: white; padding: 10px 25px; border-radius: 4px; }
        .progress { margin: 10px 0; padding: 8px; background: #e3f2fd; border-radius: 4px; font-weight: bold; }
        .form-row { display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 20px; padding: 15px; background: #f0f0f0; border-radius: 6px; }
        .form-group { display: flex; flex-direction: column; gap: 4px; }
        .form-group label { font-size: 12px; font-weight: bold; color: #333; }
        .form-group input { padding: 6px 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; }
        .summary-table { border-collapse: collapse; width: 100%; margin-top: 15px; }
        .summary-table th, .summary-table td { border: 1px solid #dee2e6; padding: 6px 10px; text-align: left; font-size: 12px; }
        .summary-table th { background: #f8f9fa; color: #003580; }
    </style></head><body><div class="container"><h1>Check Hotel Packages</h1>
    <p style="color:#666;">Retrieves <code>&lt;PackageName&gt;</code> from <code>hotelinfo</code> API for all hotels across all countries.</p>';

    // Form
    $dispatch_url = fn_url('novoton_holidays.check_packages');
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
    echo '<div class="form-group"><label>Limit per country</label><input type="number" name="limit" value="' . $limit . '" min="1" max="2000" style="width:80px"></div>';
    echo '<div class="form-group"><label>&nbsp;</label><button type="submit" class="btn-run">Check Packages</button></div>';
    echo '</div></form>';

    if (!$run) {
        echo '<p style="color:#666;">Click <strong>Check Packages</strong> to retrieve package names from the API for all countries.</p>';
        echo '<a href="' . fn_url('novoton_holidays.manage') . '" class="btn">&larr; Back</a>';
        echo '</div></body></html>';
        exit;
    }

    echo '<div class="log">';

    // Get all countries from settings
    $addon_settings = Registry::get('addons.novoton_holidays') ?? [];
    $countries = fn_novoton_parse_countries($addon_settings['selected_countries'] ?? '');

    echo "Countries: " . implode(', ', $countries) . "<br>";
    echo "Limit per country: {$limit}<br>";
    echo "Started: " . date('Y-m-d H:i:s') . "<br><br>\n";
    flush();

    try {
        $api = new NovotonApi();

        $grand_total = 0;
        $with_packages = 0;
        $without_packages = 0;
        $errors = 0;
        $results_table = [];

        foreach ($countries as $country) {
            $hotels = db_get_array(
                "SELECT hotel_id, hotel_name, package_name FROM ?:novoton_hotels WHERE country = ?s ORDER BY hotel_name LIMIT ?i",
                $country, $limit
            );

            if (empty($hotels)) {
                echo "<div class='country-header'>{$country}: 0 hotels in DB</div>\n";
                continue;
            }

            echo "<div class='country-header'>{$country}: " . count($hotels) . " hotels</div>\n";
            flush();

            foreach ($hotels as $hotel) {
                $grand_total++;

                try {
                    $hotel_info = $api->getHotelInfo($hotel['hotel_id']);

                    $package_name = '';
                    if ($hotel_info) {
                        // Response root is <hotel>, packages at <packages><PackageName>
                        if (isset($hotel_info->packages->PackageName)) {
                            $package_name = (string)$hotel_info->packages->PackageName;
                        } elseif (isset($hotel_info->packages->Package)) {
                            $package_name = (string)$hotel_info->packages->Package;
                        }
                        // Fallback: try xpath for any PackageName in the response
                        if (empty($package_name)) {
                            $pn = $hotel_info->xpath('//PackageName');
                            if (!empty($pn)) {
                                $package_name = (string)$pn[0];
                            }
                        }
                    }

                    if (!empty($package_name)) {
                        $with_packages++;
                        // Update package_name in DB
                        db_query("UPDATE ?:novoton_hotels SET package_name = ?s WHERE hotel_id = ?s", $package_name, $hotel['hotel_id']);
                        echo "<span class='success'>&check; NVT-{$hotel['hotel_id']} | {$hotel['hotel_name']} &rarr; " . htmlspecialchars($package_name) . "</span><br>\n";

                        $results_table[] = [
                            'hotel_id' => $hotel['hotel_id'],
                            'hotel_name' => $hotel['hotel_name'],
                            'package_name' => $package_name,
                            'country' => $country
                        ];
                    } else {
                        $without_packages++;
                        echo "<span class='skip'>&cir; NVT-{$hotel['hotel_id']} | {$hotel['hotel_name']} - No package</span><br>\n";
                    }
                } catch (Exception $e) {
                    $errors++;
                    echo "<span class='error'>&cross; NVT-{$hotel['hotel_id']} | {$hotel['hotel_name']} - Error: " . htmlspecialchars($e->getMessage()) . "</span><br>\n";
                }

                if ($grand_total % 25 == 0) {
                    echo "<div class='progress'>Progress: {$grand_total} hotels checked ({$with_packages} with packages)...</div>\n";
                    flush();
                }

                usleep(100000); // 100ms delay between API calls
            }
        }

        echo "<br><strong>Summary:</strong><br>";
        echo "With packages: {$with_packages}<br>";
        echo "Without packages: {$without_packages}<br>";
        echo "Errors: {$errors}<br>";
        echo "Total checked: {$grand_total}<br>";
        echo "Completed: " . date('Y-m-d H:i:s') . "<br>";

        // Show results table if any
        if (!empty($results_table)) {
            echo '</div>';
            echo '<h3 style="margin-top:20px; color:#003580;">Hotels with Packages (' . count($results_table) . ')</h3>';
            echo '<table class="summary-table">';
            echo '<tr><th>#</th><th>Hotel ID</th><th>Hotel Name</th><th>Package Name</th><th>Country</th></tr>';
            $idx = 0;
            foreach ($results_table as $row) {
                $idx++;
                echo '<tr>';
                echo '<td>' . $idx . '</td>';
                echo '<td>NVT-' . htmlspecialchars($row['hotel_id']) . '</td>';
                echo '<td>' . htmlspecialchars($row['hotel_name']) . '</td>';
                echo '<td><strong>' . htmlspecialchars($row['package_name']) . '</strong></td>';
                echo '<td>' . htmlspecialchars($row['country']) . '</td>';
                echo '</tr>';
            }
            echo '</table>';
        } else {
            echo '</div>';
        }

    } catch (Exception $e) {
        echo "<span class='error'>Error: " . htmlspecialchars($e->getMessage()) . "</span><br>";
        echo '</div>';
    }

    echo '<a href="' . fn_url('novoton_holidays.manage') . '" class="btn">&larr; Back</a>';
    echo '</div></body></html>';
    exit;
}

/**
 * Helper: Parse countries from settings
 * Note: This is also defined in helpers.php - this is a fallback if helpers.php not loaded
 */
if (!function_exists('fn_novoton_parse_countries')) {
function fn_novoton_parse_countries($selected_countries) {
    $countries = [];
    
    // Parse the selected countries from settings
    if (is_array($selected_countries)) {
        foreach ($selected_countries as $key => $value) {
            if ($value === 'Y' || $value === '1' || $value === 1) {
                $countries[] = strtoupper(trim($key));
            } elseif (is_string($value) && strlen($value) > 2) {
                $countries[] = strtoupper(trim($value));
            }
        }
    } elseif (!empty($selected_countries) && is_string($selected_countries)) {
        $countries = array_map(function($c) {
            return strtoupper(trim($c));
        }, explode(',', $selected_countries));
    }
    
    $countries = array_filter($countries);
    
    // If no countries selected, return ALL available countries
    if (empty($countries)) {
        // Use Constants if available, otherwise use hardcoded list
        if (class_exists('\\Tygh\\Addons\\NovotonHolidays\\Constants')) {
            return \Tygh\Addons\NovotonHolidays\Constants::COUNTRIES;
        }
        // Fallback list of all Novoton-supported countries
        return [
            'ALBANIA',
            'BULGARIA', 
            'CYPRUS',
            'EGYPT',
            'FRANCE',
            'GREECE',
            'ITALY',
            'MALDIVES',
            'SPAIN',
            'TURKEY',
            'UNITED ARAB EMIRATES',
            'UNITED KINGDOM',
        ];
    }
    
    return $countries;
}
} // end function_exists

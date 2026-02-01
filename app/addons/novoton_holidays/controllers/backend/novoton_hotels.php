<?php
/**
 * Novoton Holidays - Hotels Controller
 * 
 * Hotel synchronization, adding hotels as products, managing hotel data.
 * Split from novoton_holidays.php for maintainability.
 * 
 * Modes:
 * - sync: Sync hotels from API
 * - sync_resorts: Sync resort data
 * - add_hotels_as_products: Import hotels to CS-Cart
 * - view_hotels_to_add: Preview hotels for import
 * - list_facilities: View facilities list
 * - sync_facilities: Sync facilities from API
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
 * Mode: sync
 * Sync hotels from Novoton API
 */
if ($mode == 'sync') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }
    
    header('Content-Type: text/html; charset=utf-8');
    
    echo '<!DOCTYPE html>
<html><head><title>Novoton Hotels Sync</title>
<style>
    body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
    .container { max-width: 1000px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    h1 { color: #003580; }
    .log { background: #f8f9fa; padding: 15px; border-radius: 4px; font-family: monospace; font-size: 12px; max-height: 500px; overflow-y: auto; }
    .success { color: green; }
    .error { color: red; }
    .btn { display: inline-block; padding: 10px 20px; background: #003580; color: white; text-decoration: none; border-radius: 4px; margin-top: 20px; }
</style>
</head><body><div class="container"><h1>Novoton Hotels Sync</h1><div class="log">';
    
    $addon_settings = Registry::get('addons.novoton_holidays') ?? [];
    $countries = fn_novoton_parse_countries($addon_settings['selected_countries'] ?? '');
    
    echo "Starting sync for: " . implode(', ', $countries) . "<br>\n";
    if (count($countries) > 3) {
        echo "<em>(All available countries - none specifically selected in settings)</em><br>\n";
    }
    echo "Time: " . date('Y-m-d H:i:s') . "<br><br>\n";
    flush();
    
    try {
        $api = new NovotonApi();
        $hotelRepo = new HotelRepository();
        $syncLogRepo = new SyncLogRepository();
        
        $total_synced = 0;
        $total_added = 0;
        $total_updated = 0;
        $errors = 0;
        $start_time = time();
        
        foreach ($countries as $country) {
            echo "<strong>Fetching hotels from {$country}...</strong><br>\n";
            flush();
            
            $hotels = $api->getHotelList($country);
            
            // Iterate directly over result (SimpleXMLElement), like cron does
            if (!empty($hotels)) {
                $count = 0;
                foreach ($hotels as $hotel) {
                    // Use ->Property syntax for SimpleXMLElement (not ['Property'])
                    $hotel_id = (string)($hotel->IdHotel ?? '');
                    if (empty($hotel_id)) continue;
                    
                    $hotel_data = [
                        'hotel_name' => (string)($hotel->Hotel ?? ''),
                        'country' => $country,
                        'city' => (string)($hotel->City ?? ''),
                        'resort' => (string)($hotel->Resort ?? $hotel->City ?? ''),
                        'stars' => intval($hotel->Stars ?? 0),
                        'hotel_type' => (string)($hotel->HotelType ?? ''),
                        'synced_at' => date('Y-m-d H:i:s')
                    ];
                    
                    if ($hotelRepo->exists($hotel_id)) {
                        $hotelRepo->update($hotel_id, $hotel_data);
                        $total_updated++;
                    } else {
                        $hotel_data['hotel_id'] = $hotel_id;
                        $hotelRepo->insert($hotel_data);
                        $total_added++;
                    }
                    
                    $total_synced++;
                    $count++;
                }
                
                echo "<span class='success'>✓ {$country}: {$count} hotels synced</span><br>\n";
            } else {
                echo "<span class='error'>✗ {$country}: No hotels or error</span><br>\n";
                $errors++;
            }
            flush();
        }
        
        $duration = time() - $start_time;
        
        // Log sync
        $syncLogRepo->logSync('resinfo', $total_synced, $total_added, $total_updated, $errors, $duration, [
            'countries' => $countries
        ]);
        
        echo "<br><strong>Summary:</strong><br>";
        echo "Total synced: {$total_synced}<br>";
        echo "Added: {$total_added}<br>";
        echo "Updated: {$total_updated}<br>";
        echo "Duration: {$duration}s<br>";
        
    } catch (Exception $e) {
        echo "<span class='error'>Error: " . htmlspecialchars($e->getMessage()) . "</span><br>\n";
    }
    
    echo '</div><a href="' . fn_url('novoton_holidays.manage') . '" class="btn">← Back to Dashboard</a>';
    echo '</div></body></html>';
    exit;
}

/**
 * Mode: sync_resorts
 * Sync resort data for hotels
 */
if ($mode == 'sync_resorts') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }
    
    $result = fn_novoton_sync_resorts_from_api();
    
    if ($result['success']) {
        fn_set_notification('N', __('notice'), "Resorts synced: {$result['synced']}");
    } else {
        fn_set_notification('E', __('error'), $result['error'] ?? 'Sync failed');
    }
    
    return [CONTROLLER_STATUS_REDIRECT, 'novoton_holidays.manage'];
}

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
                        if (!empty($hotel['stars']) && $hotel['stars'] > 0) {
                            fn_novoton_assign_star_rating_feature($product_id, $hotel['stars']);
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

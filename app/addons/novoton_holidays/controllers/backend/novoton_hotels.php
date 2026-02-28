<?php
declare(strict_types=1);
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
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\Container;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

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
    
    $country = preg_replace('/[^A-Z\s]/', '', strtoupper($_REQUEST['country'] ?? 'BULGARIA'));
    $filter = in_array($_REQUEST['filter'] ?? '', ['prices', 'packages']) ? $_REQUEST['filter'] : 'prices';
    
    $hotelRepo = Container::getInstance()->hotelRepository();
    
    if ($filter == 'packages') {
        // V3: Check for packages in novoton_hotel_packages table
        $hotels = db_get_array(
            "SELECT h.*, p.product_id as existing_product
             FROM ?:novoton_hotels h
             INNER JOIN ?:novoton_hotel_packages pkg ON h.hotel_id = pkg.hotel_id
             LEFT JOIN ?:products p ON h.product_id = p.product_id
             WHERE h.country = ?s
               AND (h.product_id IS NULL OR h.product_id = 0)
             GROUP BY h.hotel_id
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
    
    // Country list for the filter dropdown
    $countries = db_get_array(
        "SELECT country, COUNT(*) as cnt FROM ?:novoton_hotels WHERE has_prices = 'Y' GROUP BY country ORDER BY country"
    );

    Tygh::$app['view']->assign('hotels', $hotels);
    Tygh::$app['view']->assign('country', $country);
    Tygh::$app['view']->assign('filter', $filter);
    Tygh::$app['view']->assign('stats', $stats);
    Tygh::$app['view']->assign('countries', $countries);
    Tygh::$app['view']->assign('in_cart_count', $stats['with_product']);
    Tygh::$app['view']->assign('current_year', date('Y'));
}

/**
 * Mode: add_hotels_as_products
 * Import hotels as CS-Cart products
 */
if ($mode == 'add_hotels_as_products') {
    // Permission check handled by schema in admin.post.php

    $run_process = isset($_REQUEST['run']);

    if (!$run_process) {
        // Show configuration form
        try {
            $country = preg_replace('/[^A-Z\s]/', '', strtoupper($_REQUEST['country'] ?? 'BULGARIA'));

            $hotelRepo = Container::getInstance()->hotelRepository();

            $stats = [
                'total' => $hotelRepo->count(['country' => $country]),
                'with_prices' => $hotelRepo->count(['country' => $country, 'has_prices' => 'Y']),
                'with_packages' => (int) db_get_field(
                    "SELECT COUNT(DISTINCT h.hotel_id) FROM ?:novoton_hotels h
                     INNER JOIN ?:novoton_hotel_packages p ON h.hotel_id = p.hotel_id
                     WHERE h.country = ?s",
                    $country
                ),
                'already_products' => $hotelRepo->count(['country' => $country, 'has_product' => true]),
            ];
            $stats['to_add'] = max(0, $stats['with_prices'] - $stats['already_products']);

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

            // Get available countries for country-selector tabs
            $available_countries = ConfigProvider::getSelectedCountries();

            Tygh::$app['view']->assign('country', $country);
            Tygh::$app['view']->assign('stats', $stats);
            Tygh::$app['view']->assign('resorts', $resorts);
            Tygh::$app['view']->assign('categories', $categories);
            Tygh::$app['view']->assign('languages', $languages);
            Tygh::$app['view']->assign('available_countries', $available_countries);
        } catch (\Throwable $e) {
            fn_set_notification('E', __('error'), 'Add Hotels as Products error: ' . $e->getMessage());
            return [CONTROLLER_STATUS_REDIRECT, 'novoton_holidays.manage'];
        }

    } else {
        // Process import
        fn_novoton_holidays_stream_page_open('Adding Hotels as Products');
        echo '<div class="log">';
        
        $country = preg_replace('/[^A-Z\s]/', '', strtoupper($_REQUEST['country'] ?? 'BULGARIA'));
        $category_id = (int)($_REQUEST['category_id'] ?? 0);
        $import_mode = in_array($_REQUEST['import_mode'] ?? '', ['new_only', 'update']) ? $_REQUEST['import_mode'] : 'new_only';
        $limit = max(0, min(5000, (int)($_REQUEST['limit'] ?? 0)));
        $selected_resorts = is_array($_REQUEST['resorts'] ?? null) ? array_map(function($r) {
            return preg_replace('/[^\p{L}\s\-\.]/u', '', mb_substr($r, 0, 100));
        }, $_REQUEST['resorts']) : [];
        // Whitelist language codes to 2-3 char lowercase alpha codes
        $selected_languages = is_array($_REQUEST['languages'] ?? null) ? array_filter(array_map(function($l) {
            $l = strtolower(trim($l));
            return preg_match('/^[a-z]{2,3}$/', $l) ? $l : null;
        }, $_REQUEST['languages'])) : ['en', 'ro'];
        
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
        
        $limit_sql = $limit > 0 ? " LIMIT " . (int)($limit) : "";
        
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
        
        $hotelRepo = Container::getInstance()->hotelRepository();
        
        foreach ($hotels as $hotel) {
            $hotel_id = $hotel['hotel_id'];
            $hotel_name = htmlspecialchars($hotel['hotel_name']);

            // Skip if already has product and mode is new_only
            if ($import_mode == 'new_only' && !empty($hotel['product_id'])) {
                echo "<span class='skip'>↷ Skipped (has product): {$hotel_name}</span><br>\n";
                $skipped++;
                continue;
            }
            
            try {
                // Build product title
                $title = fn_novoton_holidays_build_hotel_title($hotel_name, $hotel['city'], $country, date('Y'));
                
                // Create product
                $product_data = [
                    'product' => $title,
                    'product_code' => ConfigProvider::PRODUCT_CODE_PREFIX . $hotel_id,
                    'price' => 0,
                    'status' => 'A',
                    'company_id' => Registry::get('runtime.company_id') ?: 1,
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
                        $star_rating = (int)($hotel['hotel_type'] ?? '');
                        if ($star_rating > 0) {
                            fn_novoton_holidays_assign_star_rating_feature($product_id, $star_rating);
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
            
            if (($added + $updated) > 0 && ($added + $updated) % 10 == 0) {
                flush();
            }
        }
        
        echo "<br><strong>Summary:</strong><br>";
        echo "Added: {$added}<br>";
        echo "Updated: {$updated}<br>";
        echo "Skipped: {$skipped}<br>";
        echo "Errors: {$errors}<br>";
        
        echo '</div>';
        fn_novoton_holidays_stream_page_close();
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
    $last_sync = db_get_field("SELECT MAX(synced_at) FROM ?:novoton_facilities");

    Tygh::$app['view']->assign('facilities', $facilities);
    Tygh::$app['view']->assign('facilities_count', $count);
    Tygh::$app['view']->assign('last_sync', $last_sync);
}

/**
 * Mode: sync_facilities
 * Sync facilities from API
 */
if ($mode == 'sync_facilities') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }
    
    $result = fn_novoton_holidays_sync_facilities_list();
    
    if ($result['success']) {
        fn_set_notification('N', __('notice'), "Facilities synced! Added: {$result['added']}, Updated: {$result['updated']}");
    } else {
        fn_set_notification('E', __('error'), $result['error'] ?? 'Sync failed');
    }
    
    return [CONTROLLER_STATUS_REDIRECT, 'novoton_holidays.list_facilities'];
}

/**
 * Mode: sync_hotel_facilities
 * Populate novoton_hotel_facilities junction table by calling the API for each hotel
 */
if ($mode == 'sync_hotel_facilities') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    $hotel_ids = db_get_fields("SELECT hotel_id FROM ?:novoton_hotels");
    $synced = 0;
    $failed = 0;

    foreach ($hotel_ids as $hid) {
        $ok = fn_novoton_holidays_sync_hotel_facilities($hid);
        if ($ok) {
            $synced++;
        } else {
            $failed++;
        }
    }

    fn_set_notification('N', __('notice'), "Hotel facilities synced for {$synced} hotels. Failed: {$failed}.");

    return [CONTROLLER_STATUS_REDIRECT, 'novoton_holidays.list_facilities'];
}

/**
 * Mode: save_facility_types
 * Save facility type classifications (hotel/room) from admin form
 */
if ($mode == 'save_facility_types') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    $facility_types = $_REQUEST['facility_types'] ?? [];
    $allowed = ['hotel', 'room'];
    $updated = 0;

    foreach ($facility_types as $facility_id => $type) {
        $facility_id = (int) $facility_id;
        if ($facility_id <= 0 || !in_array($type, $allowed, true)) {
            continue;
        }
        db_query(
            "UPDATE ?:novoton_facilities SET facility_type = ?s WHERE facility_id = ?i",
            $type, $facility_id
        );
        $updated++;
    }

    fn_set_notification('N', __('notice'), "Facility types saved ({$updated} updated).");

    return [CONTROLLER_STATUS_REDIRECT, 'novoton_holidays.list_facilities'];
}

/**
 * Mode: check_packages
 * Check hotel packages from API (hotelinfo) for all countries
 */
if ($mode == 'check_packages') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    $limit = max(1, min(2000, (int)($_REQUEST['limit'] ?? 500)));
    $run = isset($_REQUEST['run']);

    fn_novoton_holidays_stream_page_open('Check Hotel Packages');
    echo '<p class="hint">Retrieves <code>&lt;PackageName&gt;</code> from <code>hotelinfo</code> API for all hotels across all countries.</p>';

    // Form
    $form = fn_novoton_holidays_stream_form_fields(fn_url('novoton_holidays.check_packages'));
    echo '<form method="get" action="' . $form['action'] . '">';
    echo $form['hidden_fields'];
    echo '<input type="hidden" name="run" value="1">';
    echo '<div class="form-row">';
    echo '<div class="form-group"><label>Limit per country</label><input type="number" name="limit" value="' . $limit . '" min="1" max="2000" style="width:80px"></div>';
    echo '<div class="form-group"><label>&nbsp;</label><button type="submit" class="btn-run">Check Packages</button></div>';
    echo '</div></form>';

    if (!$run) {
        echo '<p class="hint">Click <strong>Check Packages</strong> to retrieve package names from the API for all countries.</p>';
        fn_novoton_holidays_stream_page_close();
        exit;
    }

    echo '<div class="log">';

    // Get all countries from settings
    $countries = fn_novoton_holidays_parse_countries(ConfigProvider::get('selected_countries', ''));

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
            // V3: Select hotels without package_name column (it's now in novoton_hotel_packages)
            $hotels = db_get_array(
                "SELECT hotel_id, hotel_name FROM ?:novoton_hotels WHERE country = ?s ORDER BY hotel_name LIMIT ?i",
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

                    // V3: Extract all packages from hotelinfo
                    $packages = [];
                    if ($hotel_info && isset($hotel_info->packages)) {
                        // Single package format
                        if (isset($hotel_info->packages->IdCont)) {
                            $packages[] = [
                                'id' => (string)$hotel_info->packages->IdCont,
                                'name' => (string)($hotel_info->packages->PackageName ?? '')
                            ];
                        }
                        // Multiple packages format
                        if (isset($hotel_info->packages->package)) {
                            foreach ($hotel_info->packages->package as $pkg) {
                                $packages[] = [
                                    'id' => (string)($pkg->IdCont ?? ''),
                                    'name' => (string)($pkg->PackageName ?? '')
                                ];
                            }
                        }
                    }

                    if (!empty($packages)) {
                        $with_packages++;
                        $pkg_names = [];

                        // V3: Store packages in novoton_hotel_packages table
                        foreach ($packages as $pkg) {
                            if (!empty($pkg['id'])) {
                                db_query(
                                    "INSERT INTO ?:novoton_hotel_packages (hotel_id, package_id, package_name, created_at)
                                     VALUES (?s, ?s, ?s, NOW())
                                     ON DUPLICATE KEY UPDATE package_name = ?s",
                                    $hotel['hotel_id'], $pkg['id'], $pkg['name'], $pkg['name']
                                );
                                $pkg_names[] = $pkg['name'];
                            }
                        }

                        // Update hotel packages_count
                        db_query(
                            "UPDATE ?:novoton_hotels SET packages_count = ?i, has_prices = 'Y' WHERE hotel_id = ?s",
                            count($packages), $hotel['hotel_id']
                        );

                        $display_pkgs = implode(', ', array_slice($pkg_names, 0, 2)) . (count($pkg_names) > 2 ? '...' : '');
                        echo "<span class='success'>&check; NVT-{$hotel['hotel_id']} | {$hotel['hotel_name']} &rarr; " . count($packages) . " pkg: " . htmlspecialchars($display_pkgs) . "</span><br>\n";

                        $results_table[] = [
                            'hotel_id' => $hotel['hotel_id'],
                            'hotel_name' => $hotel['hotel_name'],
                            'package_name' => implode(', ', $pkg_names),
                            'country' => $country
                        ];
                    } else {
                        $without_packages++;
                        echo "<span class='skip'>&cir; NVT-{$hotel['hotel_id']} | {$hotel['hotel_name']} - No packages</span><br>\n";
                    }
                } catch (Exception $e) {
                    $errors++;
                    echo "<span class='error'>&cross; NVT-{$hotel['hotel_id']} | {$hotel['hotel_name']} - Error: " . htmlspecialchars($e->getMessage()) . "</span><br>\n";
                }

                if ($grand_total % 25 == 0) {
                    echo "<div class='progress'>Progress: {$grand_total} hotels checked ({$with_packages} with packages)...</div>\n";
                    flush();
                }

                usleep(\Tygh\Addons\NovotonHolidays\Constants::API_DELAY_NORMAL);
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

    fn_novoton_holidays_stream_page_close();
    exit;
}


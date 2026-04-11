<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Hotels Sub-Controller
 *
 * Delegated from novoton_holidays.php for modes that stream output or redirect.
 * Template-rendering modes (view_hotels_to_add, list_facilities, add_hotels_as_products
 * form display) live in the main controller for proper CS-Cart template resolution.
 *
 * Modes handled here (all exit() or return redirect):
 * - add_hotels_as_products (with &run): Streaming import process
 * - sync_facilities: Sync facilities from API (redirect)
 * - sync_hotel_facilities: Sync hotel facilities (redirect)
 * - save_facilities: Save facility classifications (redirect)
 * - check_packages: Check hotel packages from API (streaming + exit)
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

// NOTE: view_hotels_to_add template mode is handled by novoton_holidays.php (main controller).
// This sub-controller is only included for the add_hotels_as_products 'run' branch
// (streaming import) and delegate modes (sync_facilities, etc.).

/**
 * Mode: add_hotels_as_products
 * Import hotels as CS-Cart products (run branch only — form display is in main controller)
 */
if ($mode === 'add_hotels_as_products') {
    // Permission check handled by schema in admin.post.php
    // NOTE: Form display (no &run) is handled by novoton_holidays.php main controller.
    // This sub-controller is only included when &run is set (import execution).

    if (isset($_REQUEST['run'])) {
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
        
        $hotelRepo = Container::getInstance()->hotelRepository();
        $hotels = $hotelRepo->findForImport($country, $import_mode, $selected_resorts, $limit);
        
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
            if ($import_mode === 'new_only' && !empty($hotel['product_id'])) {
                echo "<span class='skip'>↷ Skipped (has product): {$hotel_name}</span><br>\n";
                $skipped++;
                continue;
            }
            
            try {
                // Detect property type and format display name
                $propertyDetector = _nvt_property_type_detector();
                $hotelData = fn_novoton_holidays_get_hotel_data($hotel_id);
                $packageNames = [];
                $roomNames = [];
                if (!empty($hotelData['packages'])) {
                    foreach ($hotelData['packages'] as $pkg) {
                        $packageNames[] = is_array($pkg) ? ($pkg['PackageName'] ?? '') : (string) $pkg;
                    }
                }
                if (!empty($hotelData['rooms'])) {
                    foreach ($hotelData['rooms'] as $rm) {
                        $roomNames[] = is_array($rm) ? ($rm['Type'] ?? $rm['IdRoom'] ?? '') : (string) $rm;
                    }
                }
                $detectedType = $propertyDetector->detect($hotel['hotel_name'], $packageNames, $roomNames);
                $display_name = fn_novoton_holidays_format_hotel_display_name($hotel['hotel_name'], $detectedType);

                // Build product title
                $title = fn_novoton_holidays_build_hotel_title($display_name, $hotel['city'], $country, date('Y'));

                // Create product
                $product_data = [
                    'product' => $display_name,
                    'product_code' => \Tygh\Addons\NovotonHolidays\Constants::PRODUCT_CODE_PREFIX . $hotel_id,
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
                
                if (!empty($hotel['product_id']) && $import_mode === 'update') {
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
                            fn_novoton_holidays_assign_property_rating_feature($product_id, $star_rating);
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
            
            if (($added + $updated) > 0 && ($added + $updated) % 10 === 0) {
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

// NOTE: list_facilities template mode is handled by novoton_holidays.php (main controller).

/**
 * Mode: sync_facilities
 * Sync facilities from API
 */
if ($mode === 'sync_facilities') {
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
if ($mode === 'sync_hotel_facilities') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    $hotelRepo = Container::getInstance()->hotelRepository();
    $hotel_ids = $hotelRepo->getAllIds();
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
 * Mode: save_facilities
 * Save facility feature type mappings and Romanian translations from admin form
 */
if ($mode === 'save_facilities') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    $facility_types = $_REQUEST['facility_types'] ?? [];
    $facility_translations = $_REQUEST['facility_translations'] ?? [];
    $allowed = [
        \Tygh\Addons\NovotonHolidays\Constants::FEATURE_TYPE_HOTEL_FACILITY,
        \Tygh\Addons\NovotonHolidays\Constants::FEATURE_TYPE_ROOM_FACILITY,
        \Tygh\Addons\NovotonHolidays\Constants::FEATURE_TYPE_TRAVEL_GROUP,
        \Tygh\Addons\NovotonHolidays\Constants::FEATURE_TYPE_BEACH_ACCESS,
    ];
    $updated = 0;
    $facilityRepo = Container::getInstance()->facilityRepository();

    foreach ($facility_types as $facility_id => $type) {
        $facility_id = (int) $facility_id;
        if ($facility_id <= 0 || !in_array($type, $allowed, true)) {
            continue;
        }

        $name_ro = isset($facility_translations[$facility_id])
            ? trim((string) $facility_translations[$facility_id])
            : null;

        $facilityRepo->updateTypeAndTranslation($facility_id, $type, $name_ro);
        $updated++;
    }

    fn_set_notification('N', __('notice'), "Facilities saved ({$updated} updated).");

    return [CONTROLLER_STATUS_REDIRECT, 'novoton_holidays.list_facilities'];
}

/**
 * Mode: check_packages
 * Check hotel packages from API (hotelinfo) for all countries
 */
if ($mode === 'check_packages') {
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

        $hotelRepo = Container::getInstance()->hotelRepository();
        $packageRepo = Container::getInstance()->hotelPackageRepository();

        foreach ($countries as $country) {
            // V3: Select hotels without package_name column (it's now in novoton_hotel_packages)
            $hotels = $hotelRepo->findByCountryWithLimit($country, $limit);

            if (empty($hotels)) {
                echo "<div class='country-header'>{$country}: 0 hotels in DB</div>\n";
                continue;
            }

            echo "<div class='country-header'>{$country}: " . count($hotels) . " hotels</div>\n";
            flush();

            foreach ($hotels as $hotel) {
                $grand_total++;

                try {
                    $hotel_info = $api->hotels()->getHotelInfo($hotel['hotel_id']);

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
                                $packageRepo->upsertByHotelAndPackage($hotel['hotel_id'], $pkg['id'], $pkg['name']);
                                $pkg_names[] = $pkg['name'];
                            }
                        }

                        // Update hotel packages_count (has_room_price is set exclusively by room_price check)
                        $hotelRepo->updatePackagesCount($hotel['hotel_id'], count($packages));

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

                if ($grand_total % 25 === 0) {
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


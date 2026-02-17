<?php
/**
 * Novoton Holidays - Hotel Functions
 * 
 * Functions for hotel data, sync, and facilities.
 * 
 * @package NovotonHolidays
 * @since 2.8.0
 */

use Tygh\Registry;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

/**
 * Transform database package record to normalized format
 * Shared helper to avoid code duplication
 *
 * @param array $pkg Package record from novoton_hotel_packages table
 * @param bool $include_priceinfo_details Whether to extract detailed priceinfo (seasons, prices)
 * @return array Normalized package data
 */
function fn_novoton_normalize_package($pkg, $include_priceinfo_details = false)
{
    $packageData = [
        'IdCont' => $pkg['package_id'],
        'PackageName' => $pkg['package_name'],
        'min_price' => $pkg['min_price'],
        'has_early_booking' => $pkg['has_early_booking'],
        'seasons_count' => $pkg['seasons_count'],
        'synced_at' => $pkg['synced_at']
    ];

    // Decode priceinfo if available
    if (!empty($pkg['priceinfo_data'])) {
        $priceinfo = json_decode($pkg['priceinfo_data'], true);
        if ($priceinfo) {
            $packageData['priceinfo'] = $priceinfo;

            // Extract detailed priceinfo components if requested
            if ($include_priceinfo_details) {
                // Extract seasons for display
                if (isset($priceinfo['seasons']['season'])) {
                    $packageData['seasons'] = $priceinfo['seasons']['season'];
                    // Normalize single season to array
                    if (isset($packageData['seasons']['IdSeason'])) {
                        $packageData['seasons'] = [$packageData['seasons']];
                    }
                }

                // Extract early booking for display
                if (isset($priceinfo['early_booking'])) {
                    $packageData['early_booking'] = $priceinfo['early_booking'];
                }

                // Extract season prices for display
                if (isset($priceinfo['season_price'])) {
                    $packageData['season_price'] = $priceinfo['season_price'];
                    // Normalize single entry to array
                    if (isset($packageData['season_price']['IdRoom'])) {
                        $packageData['season_price'] = [$packageData['season_price']];
                    }
                }
            }
        }
    }

    return $packageData;
}

/**
 * Get hotel data by hotel_id
 * V3 Architecture: Reads from hotelinfo_data JSON + packages from novoton_hotel_packages
 *
 * NOTE: This is the ONLY function that should read novoton_hotels.hotel_data.
 * That column is an audit/cache of the raw API response. All other code should
 * use parsed columns (hotel_name, city, region, etc.) or this function's
 * extracted arrays (rooms, boards, ages). Never query hotel_data directly for display.
 *
 * @param string $hotel_id Novoton hotel ID
 * @param bool $force Force refresh from database
 * @return array|null Hotel data with extracted rooms/boards/ages, or null
 */
function fn_novoton_get_hotel_data($hotel_id, $force = false)
{
    static $cache = [];

    if (!$force && isset($cache[$hotel_id])) {
        return $cache[$hotel_id];
    }

    $hotel = db_get_row(
        "SELECT * FROM ?:novoton_hotels WHERE hotel_id = ?s",
        $hotel_id
    );

    if ($hotel) {
        // V3: Decode hotel_data JSON (stores hotelinfo API response)
        $hotelInfoJson = $hotel['hotel_data'] ?? '';
        if (!empty($hotelInfoJson)) {
            $hotelInfo = json_decode($hotelInfoJson, true);
            if ($hotelInfo) {
                // Extract rooms from hotelinfo
                if (isset($hotelInfo['rooms'])) {
                    $hotel['rooms'] = $hotelInfo['rooms'];
                    // Normalize single room to array
                    if (isset($hotel['rooms']['IdRoom'])) {
                        $hotel['rooms'] = [$hotel['rooms']];
                    }
                }

                // Extract boards from hotelinfo
                if (isset($hotelInfo['boards'])) {
                    $hotel['boards'] = $hotelInfo['boards'];
                    if (isset($hotel['boards']['IdBoard'])) {
                        $hotel['boards'] = [$hotel['boards']];
                    }
                }

                // Extract ages from hotelinfo
                if (isset($hotelInfo['ages'])) {
                    $hotel['ages'] = $hotelInfo['ages'];
                }

                // Store full hotelinfo for access
                $hotel['full_data'] = $hotelInfo;
            }
        }

        // V3: Get packages from novoton_hotel_packages table
        $packages = db_get_array(
            "SELECT * FROM ?:novoton_hotel_packages WHERE hotel_id = ?s ORDER BY package_name",
            $hotel_id
        );

        if (!empty($packages)) {
            $hotel['packages'] = [];
            foreach ($packages as $pkg) {
                $hotel['packages'][] = fn_novoton_normalize_package($pkg, false);
            }
        }

        $cache[$hotel_id] = $hotel;
    }

    return $hotel;
}

/**
 * Get hotel prices for a product
 * V3 Architecture: Returns packages with priceinfo from novoton_hotel_packages
 *
 * @param int $product_id Product ID
 * @param bool $force Force refresh
 * @return array Packages with prices data
 */
function fn_novoton_get_hotel_prices($product_id, $force = false, $hotel_id = null)
{
    static $cache = [];

    if (!$force && isset($cache[$product_id])) {
        return $cache[$product_id];
    }

    // Try product_id lookup first
    if (empty($hotel_id)) {
        $hotel_id = fn_novoton_get_hotel_id_by_product($product_id);
    }

    // Fallback: extract hotel_id from product_code
    if (empty($hotel_id)) {
        $product_code = db_get_field("SELECT product_code FROM ?:products WHERE product_id = ?i", $product_id);
        if (!empty($product_code) && preg_match('/\d+/', $product_code, $m)) {
            $hotel_id = $m[0];
        }
    }

    if (empty($hotel_id)) {
        return [];
    }

    // V3: Get first package with priceinfo_data (active package)
    $package = db_get_row(
        "SELECT * FROM ?:novoton_hotel_packages
         WHERE hotel_id = ?s AND priceinfo_data IS NOT NULL
         ORDER BY synced_at DESC LIMIT 1",
        $hotel_id
    );

    if (empty($package) || empty($package['priceinfo_data'])) {
        return [];
    }

    $priceinfo = json_decode($package['priceinfo_data'], true);
    if (empty($priceinfo) || empty($priceinfo['season_price'])) {
        return [];
    }

    // Transform season_price data into flat format for template
    $result = [];
    $season_prices = $priceinfo['season_price'];

    // Normalize single entry to array
    if (isset($season_prices['IdRoom'])) {
        $season_prices = [$season_prices];
    }

    // Map IdAge to age_type label
    $age_type_map = [
        '1' => 'ADULT',
        '2' => 'CHD 0-1.99',
        '3' => 'CHD 2-11.99',
        '4' => 'CHD 12-17.99',
        'ADULT' => 'ADULT',
        'ADULT ' => 'ADULT',
    ];

    foreach ($season_prices as $sp) {
        $room_id = $sp['IdRoom'] ?? '';
        $board_id = $sp['IdBoard'] ?? '';
        $id_age = $sp['IdAge'] ?? '1';
        $id_acc = $sp['IdAcc'] ?? 'REGULAR';

        if (empty($room_id) || empty($board_id)) {
            continue;
        }

        // Determine age_type from IdAge or fAge field
        $age_type = $age_type_map[$id_age] ?? $id_age;
        if (isset($sp['fAge']) && !empty($sp['fAge'])) {
            $age_type = $sp['fAge'];
        }

        // Build flat price entry
        $entry = [
            'room_id' => $room_id,
            'room_type' => $room_id,  // Can be enhanced with room name lookup
            'board_id' => $board_id,
            'age_type' => $age_type,
            'acc_type' => $id_acc,
            'star_rating' => $sp['IdStar'] ?? '',
            'code' => $sp['Code'] ?? '',
            'base' => $sp['Base'] ?? '',
            'room_price' => $sp['RoomPrice'] ?? 'No',
        ];

        // Add all Price columns (Price1 through Price20)
        for ($i = 1; $i <= 20; $i++) {
            $key = 'Price' . $i;
            $target_key = 'price_' . $i;
            if (isset($sp[$key])) {
                $val = $sp[$key];
                // Handle percentage values like "80%"
                if (is_string($val) && strpos($val, '%') !== false) {
                    $entry[$target_key] = $val; // Keep as string for template to handle
                } else {
                    $entry[$target_key] = floatval($val);
                }
            }
        }

        $result[] = $entry;
    }

    $cache[$product_id] = $result;
    return $result;
}

/**
 * Get priceinfo data for a specific package
 * V3 Architecture: Returns decoded priceinfo JSON
 *
 * @param string $hotel_id Hotel ID
 * @param string $package_id Package ID (IdCont)
 * @return array|null Priceinfo data or null
 */
function fn_novoton_get_package_priceinfo($hotel_id, $package_id)
{
    $pkg = db_get_row(
        "SELECT priceinfo_data FROM ?:novoton_hotel_packages
         WHERE hotel_id = ?s AND package_id = ?s",
        $hotel_id,
        $package_id
    );

    if (empty($pkg) || empty($pkg['priceinfo_data'])) {
        return null;
    }

    return json_decode($pkg['priceinfo_data'], true);
}

/**
 * Get priceinfo data by package name
 * V3 Architecture: Returns decoded priceinfo JSON
 *
 * @param string $hotel_id Hotel ID
 * @param string $package_name Package name
 * @return array|null Priceinfo data or null
 */
function fn_novoton_get_package_priceinfo_by_name($hotel_id, $package_name)
{
    $pkg = db_get_row(
        "SELECT priceinfo_data FROM ?:novoton_hotel_packages
         WHERE hotel_id = ?s AND package_name = ?s",
        $hotel_id,
        $package_name
    );

    if (empty($pkg) || empty($pkg['priceinfo_data'])) {
        return null;
    }

    return json_decode($pkg['priceinfo_data'], true);
}

/**
 * Get total hotels count
 * 
 * @return int Count
 */
function fn_novoton_get_hotels_count()
{
    return db_get_field("SELECT COUNT(*) FROM ?:novoton_hotels");
}

/**
 * Get count of hotels without packages data
 * V3: Checks novoton_hotel_packages table instead of packages_data column
 *
 * @return int Count
 */
function fn_novoton_get_hotels_no_packages_count()
{
    return db_get_field(
        "SELECT COUNT(*) FROM ?:novoton_hotels h
         WHERE NOT EXISTS (
             SELECT 1 FROM ?:novoton_hotel_packages p WHERE p.hotel_id = h.hotel_id
         )"
    );
}

/**
 * Get hotels without packages data grouped by country
 * V3: Checks novoton_hotel_packages table instead of packages_data column
 *
 * @return array Array with country => count
 */
function fn_novoton_get_hotels_no_packages_by_country()
{
    return db_get_hash_single_array(
        "SELECT h.country, COUNT(*) as cnt FROM ?:novoton_hotels h
         WHERE NOT EXISTS (
             SELECT 1 FROM ?:novoton_hotel_packages p WHERE p.hotel_id = h.hotel_id
         )
         GROUP BY h.country
         ORDER BY cnt DESC",
        ['country', 'cnt']
    );
}

/**
 * Get hotel_id by product_id
 * 
 * @param int $product_id Product ID
 * @return string|null Hotel ID or null
 */
function fn_novoton_get_hotel_id_by_product($product_id)
{
    return db_get_field(
        "SELECT hotel_id FROM ?:novoton_hotels WHERE product_id = ?i",
        $product_id
    );
}

/**
 * Get or create category by path
 * 
 * @param string $path Category path (e.g., "Bulgaria/Golden Sands")
 * @return int Category ID
 */
function fn_novoton_get_or_create_category($path)
{
    $parts = explode('/', $path);
    $parent_id = 0;
    
    foreach ($parts as $part) {
        $part = trim($part);
        if (empty($part)) continue;
        
        // Check if category exists
        $category_id = db_get_field(
            "SELECT c.category_id FROM ?:categories c
             LEFT JOIN ?:category_descriptions cd ON c.category_id = cd.category_id AND cd.lang_code = ?s
             WHERE c.parent_id = ?i AND cd.category = ?s",
            CART_LANGUAGE, $parent_id, $part
        );
        
        if ($category_id) {
            $parent_id = $category_id;
        } else {
            // Create category
            $category_data = [
                'parent_id' => $parent_id,
                'status' => 'A'
            ];
            
            $category_id = fn_update_category($category_data, 0);
            
            if ($category_id) {
                // Add descriptions for all languages
                $languages = db_get_fields("SELECT lang_code FROM ?:languages WHERE status = 'A'");
                foreach ($languages as $lang_code) {
                    db_query(
                        "INSERT INTO ?:category_descriptions (category_id, lang_code, category)
                         VALUES (?i, ?s, ?s)
                         ON DUPLICATE KEY UPDATE category = ?s",
                        $category_id, $lang_code, $part, $part
                    );
                }
                
                $parent_id = $category_id;
            }
        }
    }
    
    return $parent_id;
}

/**
 * Sync resort list from API (resort_list endpoint)
 * Stores the authoritative resort names that room_price API accepts.
 *
 * @param string $country Country name (default: BULGARIA)
 * @return array Result with counts
 */
function fn_novoton_sync_resorts_list($country = 'BULGARIA')
{
    $api = fn_novoton_get_api();
    if (!$api) {
        return ['success' => false, 'error' => 'API not available'];
    }

    $result = [
        'success' => true,
        'added' => 0,
        'updated' => 0,
        'removed' => 0,
        'total' => 0
    ];

    try {
        $response = $api->getResortList($country);

        if (empty($response)) {
            return ['success' => false, 'error' => 'Empty API response'];
        }

        $resorts = $response->xpath('//Resort') ?: [];
        $now = date('Y-m-d H:i:s');
        $api_resort_names = [];

        foreach ($resorts as $r) {
            $name = trim((string)$r);
            if (empty($name)) continue;

            $result['total']++;
            $api_resort_names[] = $name;

            $exists = db_get_field(
                "SELECT resort_name FROM ?:novoton_resorts WHERE resort_name = ?s AND country = ?s",
                $name, $country
            );

            if ($exists) {
                db_query(
                    "UPDATE ?:novoton_resorts SET synced_at = ?s WHERE resort_name = ?s AND country = ?s",
                    $now, $name, $country
                );
                $result['updated']++;
            } else {
                db_query(
                    "INSERT INTO ?:novoton_resorts (resort_name, country, synced_at) VALUES (?s, ?s, ?s)",
                    $name, $country, $now
                );
                $result['added']++;
            }
        }

        // Remove resorts no longer in API response
        if (!empty($api_resort_names)) {
            $removed = db_query(
                "DELETE FROM ?:novoton_resorts WHERE country = ?s AND resort_name NOT IN (?a)",
                $country, $api_resort_names
            );
            $result['removed'] = db_affected_rows();
        }

    } catch (\Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }

    return $result;
}

/**
 * Sync facilities list from API
 *
 * @return array Result with counts
 */
function fn_novoton_sync_facilities_list()
{
    $api = fn_novoton_get_api();
    if (!$api) {
        return ['success' => false, 'error' => 'API not available'];
    }
    
    $result = [
        'success' => true,
        'added' => 0,
        'updated' => 0,
        'total' => 0
    ];
    
    try {
        $response = $api->listFacilities();
        
        if (empty($response)) {
            return ['success' => false, 'error' => 'Empty API response'];
        }
        
        $facilities = $response->xpath('//facility') ?: $response->xpath('//Facility') ?: [];

        foreach ($facilities as $facility) {
            $facility_id = (int)($facility->IdFacility ?? $facility->Id ?? $facility['Id'] ?? 0);
            $name_en = (string)($facility->FacilityName ?? $facility->Name ?? $facility['Name'] ?? $facility);
            $name_ro = $name_en;
            
            if ($facility_id <= 0) continue;
            
            $result['total']++;
            
            $exists = db_get_field("SELECT facility_id FROM ?:novoton_facilities WHERE facility_id = ?i", $facility_id);
            
            if ($exists) {
                db_query(
                    "UPDATE ?:novoton_facilities SET facility_name_en = ?s WHERE facility_id = ?i",
                    $name_en, $facility_id
                );
                $result['updated']++;
            } else {
                db_query(
                    "INSERT INTO ?:novoton_facilities (facility_id, facility_name_en, facility_name_ro)
                     VALUES (?i, ?s, ?s)",
                    $facility_id, $name_en, $name_ro
                );
                $result['added']++;
            }
        }
        
    } catch (\Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
    
    return $result;
}

/**
 * Sync facilities for a specific hotel
 * 
 * @param string $hotel_id Hotel ID
 * @return bool Success
 */
function fn_novoton_sync_hotel_facilities($hotel_id)
{
    $api = fn_novoton_get_api();
    if (!$api) {
        return false;
    }
    
    try {
        $hotel_info = $api->getHotelInfo($hotel_id);
        
        if (empty($hotel_info)) {
            return false;
        }
        
        // Clear existing facilities for this hotel
        db_query("DELETE FROM ?:novoton_hotel_facilities WHERE hotel_id = ?s", $hotel_id);
        
        // Parse facilities from hotel info
        $facilities = $hotel_info->xpath('//Facility') ?: $hotel_info->xpath('//facility') ?: [];
        
        foreach ($facilities as $facility) {
            $facility_id = (int)($facility['Id'] ?? $facility['id'] ?? 0);
            
            if ($facility_id > 0) {
                db_query(
                    "INSERT IGNORE INTO ?:novoton_hotel_facilities (hotel_id, facility_id) VALUES (?s, ?i)",
                    $hotel_id, $facility_id
                );
            }
        }
        
        return true;
        
    } catch (\Exception $e) {
        fn_log_event('general', 'runtime', [
            'message' => 'Novoton: Failed to sync hotel facilities',
            'hotel_id' => $hotel_id,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

/**
 * Get facilities for a hotel
 * 
 * @param string $hotel_id Hotel ID
 * @param string $lang Language code (en/ro)
 * @return array Facilities list
 */
function fn_novoton_get_hotel_facilities($hotel_id, $lang = 'en')
{
    $name_field = ($lang == 'ro') ? 'facility_name_ro' : 'facility_name_en';
    
    return db_get_array(
        "SELECT f.facility_id, f.{$name_field} as facility_name
         FROM ?:novoton_hotel_facilities hf
         LEFT JOIN ?:novoton_facilities f ON hf.facility_id = f.facility_id
         WHERE hf.hotel_id = ?s
         ORDER BY f.{$name_field}",
        $hotel_id
    );
}

/**
 * Get resorts list for settings dropdown (resort = city in the API)
 *
 * @return array Resorts grouped by country
 */
function fn_novoton_get_resorts_for_settings()
{
    $selected_countries = fn_novoton_parse_countries();

    $resorts = [];

    // Get from database — city = resort in the Novoton API
    $query = "SELECT DISTINCT city, country FROM ?:novoton_hotels WHERE city != '' AND city IS NOT NULL";

    if (!empty($selected_countries)) {
        $query .= db_quote(" AND country IN (?a)", $selected_countries);
    }

    $query .= " ORDER BY country, city";

    $db_resorts = db_get_array($query);

    foreach ($db_resorts as $row) {
        $country = $row['country'];
        $resort = $row['city'];

        if (!isset($resorts[$country])) {
            $resorts[$country] = [];
        }

        $resorts[$country][] = $resort;
    }

    return $resorts;
}

/**
 * Assign star rating feature to product
 * 
 * @param int $product_id Product ID
 * @param int $star_rating Star rating (1-5)
 * @param int $feature_id Feature ID for stars
 * @return bool Success
 */
function fn_novoton_assign_star_rating_feature($product_id, $star_rating, $feature_id = 4)
{
    if ($star_rating < 1 || $star_rating > 5) {
        return false;
    }
    
    $variant_names = [
        1 => ['ro' => '1 stea', 'en' => '1 star'],
        2 => ['ro' => '2 stele', 'en' => '2 stars'],
        3 => ['ro' => '3 stele', 'en' => '3 stars'],
        4 => ['ro' => '4 stele', 'en' => '4 stars'],
        5 => ['ro' => '5 stele', 'en' => '5 stars'],
    ];
    
    $ro_name = $variant_names[$star_rating]['ro'];
    $en_name = $variant_names[$star_rating]['en'];
    
    // Check if feature exists
    $feature_exists = db_get_field("SELECT feature_id FROM ?:product_features WHERE feature_id = ?i", $feature_id);
    if (!$feature_exists) {
        fn_log_event('novoton', 'warning', "Star rating feature (ID: {$feature_id}) not found");
        return false;
    }
    
    // Find variant by name
    $variant_id = db_get_field(
        "SELECT variant_id FROM ?:product_feature_variant_descriptions 
         WHERE (variant = ?s OR variant = ?s) 
         AND variant_id IN (SELECT variant_id FROM ?:product_feature_variants WHERE feature_id = ?i)
         LIMIT 1",
        $ro_name, $en_name, $feature_id
    );
    
    // Create variant if not found
    if (empty($variant_id)) {
        $variant_data = [
            'feature_id' => $feature_id,
            'position' => $star_rating * 10,
        ];
        $variant_id = db_query("INSERT INTO ?:product_feature_variants ?e", $variant_data);
        
        if ($variant_id) {
            $languages = db_get_fields("SELECT lang_code FROM ?:languages WHERE status = 'A'");
            foreach ($languages as $lang_code) {
                $variant_name = ($lang_code == 'ro') ? $ro_name : $en_name;
                db_query(
                    "INSERT INTO ?:product_feature_variant_descriptions (variant_id, lang_code, variant) 
                     VALUES (?i, ?s, ?s) 
                     ON DUPLICATE KEY UPDATE variant = ?s",
                    $variant_id, $lang_code, $variant_name, $variant_name
                );
            }
        }
    }
    
    if (empty($variant_id)) {
        return false;
    }
    
    // Delete existing and insert new
    db_query("DELETE FROM ?:product_features_values WHERE feature_id = ?i AND product_id = ?i", $feature_id, $product_id);
    
    $value_data = [
        'feature_id' => $feature_id,
        'product_id' => $product_id,
        'variant_id' => $variant_id,
        'value' => '',
        'value_int' => $variant_id,
        'lang_code' => 'en',
    ];
    
    db_query("INSERT INTO ?:product_features_values ?e", $value_data);
    
    // Add for other languages
    $languages = db_get_fields("SELECT lang_code FROM ?:languages WHERE status = 'A' AND lang_code != 'en'");
    foreach ($languages as $lang_code) {
        $value_data['lang_code'] = $lang_code;
        db_query(
            "INSERT INTO ?:product_features_values ?e ON DUPLICATE KEY UPDATE variant_id = ?i, value_int = ?i",
            $value_data, $variant_id, $variant_id
        );
    }
    
    return true;
}

/**
 * Add image to product from URL
 * 
 * Downloads image from URL and attaches it to a CS-Cart product.
 * 
 * @param int $product_id Product ID
 * @param string $image_url URL of the image
 * @param bool $is_main Whether this is the main product image
 * @return bool Success status
 */
function fn_novoton_add_product_image($product_id, $image_url, $is_main = false)
{
    if (empty($product_id) || empty($image_url)) {
        return false;
    }
    
    // Download image to temp file
    $temp_file = fn_create_temp_file();
    if (!$temp_file) {
        return false;
    }
    
    // Use CS-Cart's HTTP class for download
    $result = \Tygh\Http::get($image_url, [], [
        'write_to_file' => $temp_file
    ]);
    
    if (empty($result) || !file_exists($temp_file) || filesize($temp_file) < 1000) {
        @unlink($temp_file);
        return false;
    }
    
    // Detect image type
    $image_info = @getimagesize($temp_file);
    if (!$image_info) {
        @unlink($temp_file);
        return false;
    }
    
    // Get extension from mime type
    $mime_to_ext = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp'
    ];
    
    $ext = $mime_to_ext[$image_info['mime']] ?? 'jpg';
    $filename = "novoton_hotel_{$product_id}_" . time() . ".{$ext}";
    
    // Prepare image data for CS-Cart
    $image_data = [
        'detailed' => [
            'name' => $filename,
            'path' => $temp_file,
            'size' => filesize($temp_file)
        ]
    ];
    
    // Get current image count for pair_id
    $existing_pairs = db_get_field(
        "SELECT COUNT(*) FROM ?:images_links WHERE object_id = ?i AND object_type = 'product'",
        $product_id
    );
    
    $pair_data = [
        'type' => $is_main ? 'M' : 'A',  // M = Main, A = Additional
        'object_id' => $product_id,
        'object_type' => 'product',
        'position' => $existing_pairs
    ];
    
    // Use CS-Cart's function to add image
    if (function_exists('fn_update_image_pairs')) {
        $icons = [];
        $detailed = [
            0 => [
                'name' => $filename,
                'path' => $temp_file,
                'size' => filesize($temp_file)
            ]
        ];
        
        $pair_ids = fn_update_image_pairs($icons, $detailed, $pair_data, 'product', $product_id);
        
        @unlink($temp_file);
        return !empty($pair_ids);
    }
    
    @unlink($temp_file);
    return false;
}

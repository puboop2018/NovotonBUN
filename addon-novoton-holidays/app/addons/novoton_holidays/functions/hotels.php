<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Hotel Functions
 * 
 * Functions for hotel data, sync, and facilities.
 * 
 * @package NovotonHolidays
 * @since 2.8.0
 */

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

/**
 * Transform database package record to normalized format
 * Shared helper to avoid code duplication
 *
 * @param array<string, mixed> $pkg Package record from novoton_hotel_packages table
 * @param bool $include_priceinfo_details Whether to extract detailed priceinfo (seasons, prices)
 * @return array<string, mixed> Normalized package data
 */
function fn_novoton_holidays_normalize_package(array $pkg, bool $include_priceinfo_details = false): array
{
    $packageData = [
        'IdCont' => $pkg['package_id'],
        'PackageName' => $pkg['package_name'],
        'min_price' => $pkg['min_price'],
        'has_early_booking' => $pkg['has_early_booking'],
        'seasons_count' => $pkg['seasons_count'],
        'synced_at' => $pkg['synced_at']
    ];

    // Decode priceinfo only when detailed extraction is requested.
    // When called from prefetch (false), priceinfo_data is excluded from
    // the SELECT to avoid transferring 50-200KB of JSON per package row.
    if ($include_priceinfo_details && !empty($pkg['priceinfo_data'])) {
        $priceinfo = json_decode($pkg['priceinfo_data'], true);
        if ($priceinfo === null) return $packageData;
        if ($priceinfo) {
            $packageData['priceinfo'] = $priceinfo;

            // Extract detailed priceinfo components if requested
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

    return $packageData;
}

/**
 * Shared cache accessor for hotel data.
 *
 * All functions that read/write the hotel data cache MUST use this
 * to guarantee a single shared store within each PHP request.
 *
 * Returns by reference so callers can mutate the cache in-place.
 *
 * @return array<string, mixed> The shared cache array, returned by reference
 */
function &_novoton_hotel_data_cache(): array
{
    static $cache = [];
    return $cache;
}

/**
 * Enrich a raw novoton_hotels DB row: decode hotel_data JSON, extract
 * rooms/boards/ages, attach packages.
 *
 * @param array<string, mixed>      $hotel    Raw row from novoton_hotels
 * @param list<array<string, mixed>>|null $packages Pre-fetched package rows (already normalized), or null to query
 * @return array<string, mixed> Enriched hotel array
 */
function _novoton_enrich_hotel_row(array $hotel, ?array $packages = null): array
{
    // Decode hotel_data JSON (stores hotelinfo API response)
    $hotelInfoJson = $hotel['hotel_data'] ?? '';
    if (!empty($hotelInfoJson)) {
        $hotelInfo = json_decode($hotelInfoJson, true);
        if ($hotelInfo === null) return $hotel;
        if ($hotelInfo) {
            if (isset($hotelInfo['rooms'])) {
                $hotel['rooms'] = $hotelInfo['rooms'];
                if (isset($hotel['rooms']['IdRoom'])) {
                    $hotel['rooms'] = [$hotel['rooms']];
                }
            }
            if (isset($hotelInfo['boards'])) {
                $hotel['boards'] = $hotelInfo['boards'];
                if (isset($hotel['boards']['IdBoard'])) {
                    $hotel['boards'] = [$hotel['boards']];
                }
            }
            if (isset($hotelInfo['ages'])) {
                $hotel['ages'] = $hotelInfo['ages'];
            }
            $hotel['full_data'] = $hotelInfo;
        }
    }

    // Attach packages — use pre-fetched if provided, otherwise query
    if ($packages !== null) {
        $hotel['packages'] = $packages;
    } else {
        $rows = db_get_array(
            "SELECT id, hotel_id, package_id, package_name, min_price, has_early_booking,
                    seasons_count, currency, synced_at
             FROM ?:novoton_hotel_packages WHERE hotel_id = ?s ORDER BY package_name",
            $hotel['hotel_id']
        );
        if (!empty($rows)) {
            $hotel['packages'] = [];
            foreach ($rows as $pkg) {
                $hotel['packages'][] = fn_novoton_holidays_normalize_package($pkg, false);
            }
        }
    }

    return $hotel;
}

/**
 * Batch pre-fetch hotel data for multiple hotel IDs into the shared cache.
 *
 * Reduces N×2 queries (1 hotel + 1 packages per hotel) to exactly 2 queries
 * for any number of hotels. Subsequent calls to fn_novoton_holidays_get_hotel_data()
 * for these IDs will be O(1) cache hits.
 *
 * @param list<string> $hotel_ids List of Novoton hotel IDs to prefetch
 */
function fn_novoton_holidays_prefetch_hotel_data(array $hotel_ids): void
{
    $cache = &_novoton_hotel_data_cache();

    // Filter to IDs not already cached
    $missing = [];
    foreach ($hotel_ids as $id) {
        $id = (string) $id;
        if ($id !== '' && !isset($cache[$id])) {
            $missing[] = $id;
        }
    }
    if (empty($missing)) {
        return;
    }

    // Batch query 1: all hotel rows
    $hotels = db_get_hash_array(
        "SELECT * FROM ?:novoton_hotels WHERE hotel_id IN (?a)",
        'hotel_id',
        $missing
    );

    // Batch query 2: package metadata (exclude priceinfo_data JSON — not needed for listing)
    $all_packages = db_get_array(
        "SELECT id, hotel_id, package_id, package_name, min_price, has_early_booking,
                seasons_count, currency, synced_at
         FROM ?:novoton_hotel_packages WHERE hotel_id IN (?a) ORDER BY hotel_id, package_name",
        $missing
    );

    // Group & normalize packages by hotel_id
    $pkgs_by_hotel = [];
    foreach ($all_packages as $pkg) {
        $pkgs_by_hotel[$pkg['hotel_id']][] = fn_novoton_holidays_normalize_package($pkg, false);
    }

    // Enrich and cache each hotel
    foreach ($missing as $hotel_id) {
        if (!isset($hotels[$hotel_id])) {
            continue;
        }
        $cache[$hotel_id] = _novoton_enrich_hotel_row(
            $hotels[$hotel_id],
            $pkgs_by_hotel[$hotel_id] ?? []
        );
    }
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
 * @return array<string, mixed>|null Hotel data with extracted rooms/boards/ages, or null
 */
function fn_novoton_holidays_get_hotel_data(string|int|null $hotel_id, bool $force = false): ?array
{
    $cache = &_novoton_hotel_data_cache();

    // PHP 8.1+: prevent null from reaching real_escape_string via db_quote ?s
    if ($hotel_id === null || $hotel_id === '') {
        return null;
    }
    $hotel_id = (string) $hotel_id;

    if (!$force && isset($cache[$hotel_id])) {
        return $cache[$hotel_id];
    }

    $hotel = db_get_row(
        "SELECT * FROM ?:novoton_hotels WHERE hotel_id = ?s",
        $hotel_id
    );

    if ($hotel) {
        $cache[$hotel_id] = _novoton_enrich_hotel_row($hotel);
    }

    return $cache[$hotel_id] ?? null;
}

/**
 * Get hotel prices for a product
 * V3 Architecture: Returns packages with priceinfo from novoton_hotel_packages
 *
 * @param int $product_id Product ID
 * @param bool $force Force refresh
 * @param string|int|null $hotel_id Hotel ID (string from API, int from DB)
 * @return list<array<string, mixed>> Packages with prices data
 */
function fn_novoton_holidays_get_hotel_prices(int $product_id, bool $force = false, string|int|null $hotel_id = null): array
{
    static $cache = [];

    $cache_key = $product_id . '_' . ($hotel_id ?? '');
    if (!$force && isset($cache[$cache_key])) {
        return $cache[$cache_key];
    }

    // Try product_id lookup first
    if (empty($hotel_id)) {
        $hotel_id = fn_novoton_holidays_get_hotel_id_by_product($product_id);
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
         WHERE hotel_id = ?s AND priceinfo_data IS NOT NULL AND priceinfo_data != ''
         ORDER BY synced_at DESC LIMIT 1",
        $hotel_id
    );

    if (empty($package) || empty($package['priceinfo_data'])) {
        return [];
    }

    $priceinfo = json_decode($package['priceinfo_data'], true);
    if ($priceinfo === null) return [];
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
    $age_type_map = \Tygh\Addons\NovotonHolidays\Constants::AGE_TYPE_MAP;

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
                if (is_string($val) && str_contains($val, '%')) {
                    $entry[$target_key] = $val; // Keep as string for template to handle
                } else {
                    $entry[$target_key] = (float)($val);
                }
            }
        }

        $result[] = $entry;
    }

    $cache[$cache_key] = $result;
    return $result;
}

/**
 * Get priceinfo data for a specific package
 * V3 Architecture: Returns decoded priceinfo JSON
 *
 * @param string $hotel_id Hotel ID
 * @param string $package_id Package ID (IdCont)
 * @return array<string, mixed>|null Priceinfo data or null
 */
function fn_novoton_holidays_get_package_priceinfo(string $hotel_id, string $package_id): ?array
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

    $data = json_decode($pkg['priceinfo_data'], true);
    if ($data === null) return null;
    return $data;
}

/**
 * Get priceinfo data by package name
 * V3 Architecture: Returns decoded priceinfo JSON
 *
 * @param string $hotel_id Hotel ID
 * @param string $package_name Package name
 * @return array<string, mixed>|null Priceinfo data or null
 */
function fn_novoton_holidays_get_package_priceinfo_by_name(string $hotel_id, string $package_name): ?array
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

    $data = json_decode($pkg['priceinfo_data'], true);
    if ($data === null) return null;
    return $data;
}

/**
 * Get total hotels count
 * 
 * @return int Count
 */
function fn_novoton_holidays_get_hotels_count(): int
{
    return (int)db_get_field("SELECT COUNT(*) FROM ?:novoton_hotels");
}

/**
 * Get count of hotels without packages data
 * V3: Checks novoton_hotel_packages table instead of packages_data column
 *
 * @return int Count
 */
function fn_novoton_holidays_get_hotels_no_packages_count(): int
{
    return (int)db_get_field(
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
 * @return array<string, mixed> Array with country => count
 */
function fn_novoton_holidays_get_hotels_no_packages_by_country(): array
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
function fn_novoton_holidays_get_hotel_id_by_product(int $product_id): ?string
{
    $result = db_get_field(
        "SELECT hotel_id FROM ?:novoton_hotels WHERE product_id = ?i",
        $product_id
    );
    return ($result !== false && $result !== '') ? (string)$result : null;
}

/**
 * Get or create category by path
 * 
 * @param string $path Category path (e.g., "Bulgaria/Golden Sands")
 * @return int Category ID
 */
function fn_novoton_holidays_get_or_create_category(string $path): int
{
    return fn_travel_core_get_or_create_category($path);
}

/**
 * Sync resort list from API (resort_list endpoint)
 * Stores the authoritative resort names that room_price API accepts.
 *
 * @param string $country Country name (default: BULGARIA)
 * @return array<string, mixed> Result with counts
 */
function fn_novoton_holidays_sync_resorts_list(string $country = \Tygh\Addons\NovotonHolidays\Constants::DEFAULT_COUNTRY): array
{
    $country = (string) $country;

    $api = fn_novoton_holidays_get_api();
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
        $response = $api->destinations()->getResortList($country);

        if (empty($response)) {
            return ['success' => false, 'error' => 'Empty API response'];
        }

        $resorts = $response->xpath('//Resort') ?: [];
        $now = date('Y-m-d H:i:s');
        $api_resort_names = [];

        foreach ($resorts as $r) {
            $name = mb_convert_case(trim((string)$r), MB_CASE_TITLE, 'UTF-8');
            if (empty($name)) continue;

            $result['total']++;
            $api_resort_names[] = $name;

            // Atomic upsert — avoids race condition between SELECT and INSERT/UPDATE
            $affected = db_query(
                "INSERT INTO ?:novoton_resorts (resort_name, country, synced_at)
                 VALUES (?s, ?s, ?s) AS new_row
                 ON DUPLICATE KEY UPDATE synced_at = new_row.synced_at",
                $name, $country, $now
            );
            // affected_rows = 1 for INSERT, 2 for UPDATE (MySQL convention)
            if ($affected == 1) {
                $result['added']++;
            } else {
                $result['updated']++;
            }
        }

        // Remove resorts no longer in API response
        if (!empty($api_resort_names)) {
            $affected = db_query(
                "DELETE FROM ?:novoton_resorts WHERE country = ?s AND resort_name NOT IN (?a)",
                $country, $api_resort_names
            );
            $result['removed'] = (int) $affected;
        }

    } catch (\Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }

    return $result;
}

/**
 * Sync facilities list from API
 *
 * @return array<string, mixed> Result with counts
 */
function fn_novoton_holidays_sync_facilities_list(): array
{
    $api = fn_novoton_holidays_get_api();
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
        $response = $api->hotels()->listFacilities();
        
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
            
            // Atomic upsert — avoids race condition between SELECT and INSERT/UPDATE
            $affected = db_query(
                "INSERT INTO ?:novoton_facilities (facility_id, facility_name_en, facility_name_ro)
                 VALUES (?i, ?s, ?s) AS new_row
                 ON DUPLICATE KEY UPDATE facility_name_en = new_row.facility_name_en",
                $facility_id, $name_en, $name_ro
            );
            if ($affected == 1) {
                $result['added']++;
            } else {
                $result['updated']++;
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
function fn_novoton_holidays_sync_hotel_facilities(string $hotel_id): bool
{
    if ($hotel_id === '') {
        return false;
    }

    $api = fn_novoton_holidays_get_api();
    if (!$api) {
        return false;
    }
    
    try {
        // Use dedicated hotel_facilities API (function 27) — returns <IdFacility> elements
        $response = $api->hotels()->getHotelFacilities($hotel_id);

        if (empty($response)) {
            return false;
        }

        // Clear existing facilities for this hotel
        db_query("DELETE FROM ?:novoton_hotel_facilities WHERE hotel_id = ?s", $hotel_id);

        // Parse <IdFacility> elements from hotel_facilities API response
        $facility_nodes = $response->xpath('//IdFacility') ?: [];

        // Collect valid IDs and batch INSERT (single query instead of N round trips)
        $facility_ids = [];
        foreach ($facility_nodes as $node) {
            $fid = (int) $node;
            if ($fid > 0) {
                $facility_ids[] = $fid;
            }
        }
        if (!empty($facility_ids)) {
            $values = [];
            foreach ($facility_ids as $fid) {
                $values[] = db_quote("(?s, ?i)", $hotel_id, $fid);
            }
            db_query(
                "INSERT IGNORE INTO ?:novoton_hotel_facilities (hotel_id, facility_id) VALUES " . implode(', ', $values)
            );
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
 * @return array<string, mixed> Facilities list
 */
function fn_novoton_holidays_get_hotel_facilities(string $hotel_id, string $lang = 'en'): array
{
    if ($hotel_id === '') {
        return [];
    }

    $allowed = ['ro' => 'facility_name_ro', 'en' => 'facility_name_en'];
    $col = $allowed[$lang] ?? $allowed['en'];

    return db_get_array(
        "SELECT f.facility_id, f.{$col} as facility_name
         FROM ?:novoton_hotel_facilities hf
         LEFT JOIN ?:novoton_facilities f ON hf.facility_id = f.facility_id
         WHERE hf.hotel_id = ?s
         ORDER BY f.{$col}",
        $hotel_id
    );
}

/**
 * Get facilities for a hotel filtered by type
 *
 * @param string $hotel_id Hotel ID
 * @param string $facility_type Feature type constant: hotel_facility, room_facility, travel_group, beach_access
 * @param string $lang Language code (en/ro)
 * @return array<string, mixed> Facilities list
 */
function fn_novoton_holidays_get_hotel_facilities_by_type(string $hotel_id, string $facility_type, string $lang = 'en'): array
{
    if ($hotel_id === '') {
        return [];
    }

    $allowed = ['ro' => 'facility_name_ro', 'en' => 'facility_name_en'];
    $col = $allowed[$lang] ?? $allowed['en'];

    return db_get_array(
        "SELECT f.facility_id, f.{$col} as facility_name
         FROM ?:novoton_hotel_facilities hf
         JOIN ?:novoton_facilities f ON hf.facility_id = f.facility_id
         WHERE hf.hotel_id = ?s AND f.facility_type = ?s
         ORDER BY f.{$col}",
        $hotel_id, $facility_type
    );
}

/**
 * Get resorts list for settings dropdown (resort = city in the API)
 *
 * @return array<string, mixed> Resorts grouped by country
 */
function fn_novoton_holidays_get_resorts_for_settings(): array
{
    $selected_countries = fn_novoton_holidays_parse_countries();

    $resorts = [];

    // Get from database — city = resort in the Novoton API
    $query = "SELECT DISTINCT city, country FROM ?:novoton_hotels WHERE city != '' AND city IS NOT NULL";

    if (!empty($selected_countries)) {
        $query .= db_quote(" AND country IN (?a)", $selected_countries);
    }

    $query .= " ORDER BY country, city";

    $db_resorts = db_get_array($query);
    $hidden_resorts = array_map('strtoupper', \Tygh\Addons\NovotonHolidays\Constants::HIDDEN_RESORTS);

    foreach ($db_resorts as $row) {
        $country = $row['country'];
        $resort = $row['city'];

        if (in_array(strtoupper($resort), $hidden_resorts, true)) {
            continue;
        }

        if (!isset($resorts[$country])) {
            $resorts[$country] = [];
        }

        $resorts[$country][] = $resort;
    }

    return $resorts;
}

/**
 * Assign star rating feature to product via FeatureMapper.
 *
 * @param int $product_id Product ID
 * @param int $star_rating Star rating (1-5)
 * @return bool Success
 */
function fn_novoton_holidays_assign_property_rating_feature(int $product_id, string|int $star_rating): bool
{
    if ($star_rating < 1 || $star_rating > 5) {
        return false;
    }

    $container = \Tygh\Addons\NovotonHolidays\Services\Container::getInstance();
    $featureMapper = $container->featureMapper();
    $normalizer = $container->novotonNormalizer();

    $code = $normalizer->normalizeStarRating((string) $star_rating);
    if ($code === null) {
        return false;
    }

    // Uses shared travel_core mapping (travel_feature_map + travel_api_alias)
    return $featureMapper->assignFeatureViaCore((int) $product_id, 'stars', $code);
}

/**
 * Add image to product from URL.
 *
 * Downloads via CS-Cart's Http::get(), then delegates validation and
 * attachment to the shared fn_travel_core_attach_product_image().
 *
 * @param int    $product_id Product ID
 * @param string $image_url  URL of the image
 * @param bool   $is_main    Whether this is the main product image
 * @return bool Success status
 */
function fn_novoton_holidays_add_product_image(int $product_id, string $image_url, bool $is_main = false): bool
{
    if (empty($product_id) || empty($image_url)) {
        return false;
    }

    $temp_file = fn_create_temp_file();
    if (!$temp_file) {
        return false;
    }

    \Tygh\Http::get($image_url, [], ['write_to_file' => $temp_file]);

    return fn_travel_core_attach_product_image($product_id, $temp_file, 'novoton', $is_main);
}
<?php
declare(strict_types=1);
/***************************************************************************
 *                                                                          *
 *   (c) 2024-2026 VacanteLitoral.ro                                       *
 *                                                                          *
 *   Location: app/addons/sphinx_holidays/func.php                         *
 *                                                                          *
 ***************************************************************************/

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Registry;

/**
 * Variants function for the default_currency addon setting.
 * Pulls currencies from CS-Cart's configured currencies.
 */
function fn_settings_variants_addons_sphinx_holidays_default_currency(): array
{
    $currencies = Registry::get('currencies');
    $result = [];

    if (empty($currencies) || !is_array($currencies)) {
        return $result;
    }

    foreach ($currencies as $code => $currency) {
        $result[$code] = $code . (!empty($currency['symbol']) ? ' (' . $currency['symbol'] . ')' : '');
    }

    return $result;
}

/**
 * Dynamic variants for the "Product languages" multiple checkboxes setting.
 * Lists all active CS-Cart languages.
 */
function fn_settings_variants_addons_sphinx_holidays_product_languages(): array
{
    $languages = db_get_array("SELECT lang_code, name FROM ?:languages WHERE status = 'A' ORDER BY name");
    $result = [];
    foreach ($languages as $lang) {
        $result[$lang['lang_code']] = $lang['name'] . ' (' . strtoupper($lang['lang_code']) . ')';
    }
    return $result;
}

/**
 * Addon uninstall function.
 * Drops Sphinx-specific tables and cleans up.
 */
function fn_sphinx_holidays_uninstall(): bool
{
    // Remove Sphinx aliases from shared feature mapping (table may not exist if travel_core already uninstalled)
    $tablePrefix = \Tygh\Registry::get('config.table_prefix');
    $aliasTableExists = db_get_field(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?s",
        $tablePrefix . 'travel_api_alias'
    );
    if ($aliasTableExists) {
        db_query("DELETE FROM ?:travel_api_alias WHERE api_source = 'sphinx'");
    }

    // Remove language variables
    db_query("DELETE FROM ?:language_values WHERE name LIKE 'sphinx_holidays.%'");

    // Drop Sphinx-specific tables (order matters for FK constraints)
    db_query("DROP TABLE IF EXISTS ?:sphinx_destination_whitelist");
    db_query("DROP TABLE IF EXISTS ?:sphinx_cache");
    db_query("DROP TABLE IF EXISTS ?:sphinx_sync_log");
    db_query("DROP TABLE IF EXISTS ?:sphinx_bookings");
    db_query("DROP TABLE IF EXISTS ?:sphinx_package_routes");
    db_query("DROP TABLE IF EXISTS ?:sphinx_experiences");
    db_query("DROP TABLE IF EXISTS ?:sphinx_circuits");
    db_query("DROP TABLE IF EXISTS ?:sphinx_destinations");
    db_query("DROP TABLE IF EXISTS ?:sphinx_hotels");

    return true;
}

/**
 * Post-install function.
 * Seeds Sphinx-specific aliases into the shared feature mapping.
 */
function fn_sphinx_holidays_post_install(): bool
{
    fn_sphinx_holidays_seed_aliases();
    fn_sphinx_holidays_seed_region_mappings();
    fn_sphinx_holidays_seed_language_keys();
    return true;
}

/**
 * Ensure language keys added after initial install are present in the database.
 * Idempotent — skips keys that already exist.
 */
function fn_sphinx_holidays_seed_language_keys(): void
{
    $keys = require __DIR__ . '/lang_keys.php';

    foreach ($keys as $name => $translations) {
        foreach ($translations as $lang_code => $value) {
            $exists = db_get_field(
                "SELECT COUNT(*) FROM ?:language_values WHERE name = ?s AND lang_code = ?s",
                $name, $lang_code
            );
            if (!$exists) {
                db_query(
                    "INSERT INTO ?:language_values (name, lang_code, value) VALUES (?s, ?s, ?s)",
                    $name, $lang_code, $value
                );
            }
        }
    }
}

/**
 * Seed Sphinx API aliases into the shared travel_api_alias table.
 * Maps Sphinx free-text values to canonical feature codes.
 *
 * Idempotent — uses FeatureMapper::addAlias() which does INSERT ON DUPLICATE KEY UPDATE.
 */
function fn_sphinx_holidays_seed_aliases(): void
{
    if (!class_exists(\Tygh\Addons\TravelCore\Services\FeatureMapper::class)) {
        return;
    }

    // Board/Meal aliases
    $boardAliases = [
        // Romanian free-text
        'All Inclusive'         => 'AI',
        'All Inclusive Light'   => 'AIL',
        'All Inclusive Soft'    => 'AIL',
        'ALL INCLUSIVE PLUS'    => 'AI',
        'Ultra All Inclusive'   => 'UAI',
        'Pensiune completa'    => 'FB',
        'Pensiune completă'    => 'FB',
        'Full Board'           => 'FB',
        'Demipensiune'         => 'HB',
        'Half Board'           => 'HB',
        'Mic dejun'            => 'BB',
        'Bed & Breakfast'      => 'BB',
        'Bed and Breakfast'    => 'BB',
        'Fara masa'            => 'RO',
        'Fără masă'            => 'RO',
        'Room Only'            => 'RO',
        'ROOM ONLY'            => 'RO',
        'RO'                   => 'RO',
        'Self Catering'        => 'SC',
        'SELF CATERING'        => 'SC',
        'FULL BOARD'           => 'FB',
        'HALF BOARD'           => 'HB',
        'BED AND BREAKFAST'    => 'BB',
        'BUFFET BREAKFAST'     => 'BB',
        'ULTRA ALL INCLUSIVE'  => 'UAI',
        'PLATINUM ALL INCLUSIVE' => 'AI',
        'ALL INCLUSIVE PLUS'   => 'AI',
    ];

    // Room type aliases (prefix match)
    $roomAliases = [
        'Single Room'  => 'SGL',
        'Double Room'  => 'DBL',
        'Twin Room'    => 'TWIN',
        'Triple Room'  => 'TRP',
        'Quadruple'    => 'QUAD',
        'Suite'        => 'SUITE',
        'Apartment'    => 'APT',
        'Studio'       => 'STUDIO',
        'Family Room'  => 'DBL',
    ];

    // Guard: travel_feature_map table may not exist if travel_core isn't installed yet
    $tablePrefix = \Tygh\Registry::get('config.table_prefix');
    $tableExists = db_get_field(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?s",
        $tablePrefix . 'travel_feature_map'
    );
    if (!$tableExists) {
        fn_log_event('general', 'runtime', [
            'message' => 'Sphinx: Skipping alias seeding — travel_feature_map table not found (travel_core not installed?)',
        ]);
        return;
    }

    // Helper: resolve canonical code to map_id and register alias.
    // Eliminates 4 identical foreach+query blocks.
    $seedAliasGroup = static function (string $featureType, array $aliases, string $matchType = 'exact'): void {
        // Batch-load all map_ids for this feature type to avoid N+1 queries
        $allMaps = db_get_hash_single_array(
            "SELECT canonical_code, map_id FROM ?:travel_feature_map WHERE feature_type = ?s",
            ['canonical_code', 'map_id'],
            $featureType
        );

        foreach ($aliases as $apiValue => $canonicalCode) {
            $mapId = (int) ($allMaps[$canonicalCode] ?? 0);
            if ($mapId > 0) {
                \Tygh\Addons\TravelCore\Services\FeatureMapper::addAlias('sphinx', (string) $apiValue, $mapId, $matchType);
            }
        }
    };

    $seedAliasGroup('board', $boardAliases, 'exact');
    $seedAliasGroup('room_type', $roomAliases, 'prefix');

    // Star rating aliases (feature_type='stars'): canonical codes '1' through '5'
    $starAliases = [
        '1' => '1',
        '2' => '2',
        '3' => '3',
        '4' => '4',
        '5' => '5',
        '1 star'  => '1',
        '2 star'  => '2',
        '2 stars' => '2',
        '3 star'  => '3',
        '3 stars' => '3',
        '4 star'  => '4',
        '4 stars' => '4',
        '5 star'  => '5',
        '5 stars' => '5',
    ];

    $seedAliasGroup('stars', $starAliases, 'exact');

    // Property type aliases (feature_type='property_type')
    $propertyTypeAliases = [
        'hotel'       => 'hotel',
        'villa'       => 'villa',
        'apartment'   => 'apartment',
        'resort'      => 'resort',
        'hostel'      => 'hostel',
        'guest_house' => 'guest_house',
        'guesthouse'  => 'guest_house',
        'pension'     => 'guest_house',
        'pensiune'    => 'guest_house',
        'chalet'      => 'chalet',
        'cabana'      => 'chalet',
        'motel'       => 'motel',
    ];

    $seedAliasGroup('property_type', $propertyTypeAliases, 'exact');

    // Hotel facility aliases — property-level amenities
    $hotelFacilityAliases = [
        // Food & Drink
        '1'   => 'kids_menu',
        '4'   => 'water_bottle',
        '5'   => 'fruits',
        '8'   => 'restaurant',
        '9'   => 'restaurant_alacarte',
        '11'  => 'bar',
        '14'  => 'packed_lunch',
        '17'  => 'special_diet',
        '18'  => 'room_service',
        '19'  => 'breakfast_in_room',
        // Wellness & Recreation
        '25'  => 'spa',
        '31'  => 'fitness',
        '37'  => 'pool',
        '44'  => 'massage',
        '86'  => 'casino',
        '163' => 'full_body_massage',
        '178' => 'ski',
        '179' => 'hiking',
        '180' => 'squash',
        '181' => 'cycling',
        '182' => 'bowling',
        '183' => 'game_room',
        '184' => 'aqua_park',
        '186' => 'tennis',
        '187' => 'horse_riding',
        '188' => 'ski_school',
        '189' => 'bike_rental',
        '192' => 'relaxation_area',
        // Parking & Transport
        '47'  => 'free_parking',
        '48'  => 'secured_parking',
        '54'  => 'transfer_service',
        '57'  => 'airport_transfer',
        '142' => 'car_rental',
        '143' => 'bike_tours',
        '148' => 'walking_tours',
        '199' => 'parking',
        // Front Desk & Services
        '59'  => 'front_desk_24h',
        '64'  => 'tour_desk',
        '65'  => 'currency_exchange',
        '68'  => 'luggage_storage',
        '70'  => 'safety_deposit_box',
        '94'  => 'strollers',
        '98'  => 'dry_cleaning',
        '99'  => 'ironing_service',
        '100' => 'laundry',
        '101' => 'daily_housekeeping',
        '104' => 'meeting_facilities',
        '105' => 'business_centre',
        '106' => 'fax',
        '128' => 'conference_rooms',
        '155' => 'front_desk_24h',     // "Receptie nonstop" = same as 24h front desk
        '156' => 'wake_up_service',
        '170' => 'wake_up_service',     // "Serviciu de trezire/ceas desteptator" = same
        '172' => 'conference_rooms',    // "Sali de conferinte si petreceri" = same
        '195' => 'business_centre',     // Duplicate business centre entry
        '196' => 'cafe',
        '198' => 'invoice_available',
        '201' => 'express_checkin',
        '202' => 'babysitting',
        // Outdoor
        '72'  => 'outdoor_furniture',
        '75'  => 'garden',
        '76'  => 'terrace',
        '77'  => 'sun_terrace',
        // Security
        '153' => 'security_24h',
        '154' => 'soundproof_rooms',
        '159' => 'security_alarm',
        '166' => 'fire_extinguishers',
        '171' => 'co_detector',
        '173' => 'card_access',
        '174' => 'cctv_common',
        '175' => 'cctv_outside',
        '176' => 'smoke_alarm',
        '197' => 'key_access',
        // Policies & Groups
        '111' => 'pets_allowed',
        '115' => 'non_smoking',
        '116' => 'smoking_area',
        '117' => 'non_smoking_rooms',
        '120' => 'family_rooms',
        '118' => 'disabled_access',
        '203' => 'stairs_only',
        '204' => 'no_smoking_all',
    ];

    // Room facility aliases — in-room amenities
    $roomFacilityAliases = [
        '124' => 'air_conditioning',
        '125' => 'heating',
        '126' => 'free_wifi',
        '127' => 'washer',
        '129' => 'ski_storage',
        '130' => 'tv',
        '131' => 'fan',
        '132' => 'desk',
        '133' => 'shower',
        '134' => 'view',
        '135' => 'minibar',
        '136' => 'toilet',
        '137' => 'towels',
        '138' => 'bed_linen',
        '139' => 'slippers',
        '140' => 'telephone',
        '141' => 'hair_dryer',
        '144' => 'alarm_clock',
        '145' => 'toilet_paper',
        '146' => 'flat_screen_tv',
        '147' => 'soundproofing',
        '149' => 'dressing_room',
        '150' => 'cable_channels',
        '151' => 'carpet',
        '152' => 'free_toiletries',
        '157' => 'air_conditioning',    // "Aer conditionat" = same as 124
        '158' => 'private_bathroom',
        '160' => 'private_entrance',
        '161' => 'safe',
        '162' => 'internet',
        '164' => 'games_puzzles',
        '165' => 'bedside_socket',
        '190' => 'mosquito_net',
        '191' => 'fridge',
        '193' => 'wine_champagne',
        '194' => 'wardrobe',
        '200' => 'shared_lounge',
    ];

    // Beach access aliases — beach & location amenities
    $beachAccessAliases = [
        // No Sphinx-specific beach IDs mapped yet; add here as they appear.
    ];

    $seedAliasGroup('hotel_facility', $hotelFacilityAliases, 'exact');
    $seedAliasGroup('room_facility', $roomFacilityAliases, 'exact');
    $seedAliasGroup('beach_access', $beachAccessAliases, 'exact');

    // Travel groups are NOT seeded as aliases — they're derived from facilities
    // at runtime via TravelGroupResolver::derive(). No API value mapping needed.

    // Clear resolve cache after batch alias inserts
    \Tygh\Addons\TravelCore\Services\FeatureMapper::clearCache();
}

/**
 * Seed whitelisted regions into travel_feature_map.
 *
 * For each region in the Destination Whitelist, creates a travel_feature_map row
 * (feature_type='region') and a travel_api_alias linking the Sphinx destination_id.
 * This allows SphinxFeatureAssigner::assignRegion() to resolve regions through
 * the standard FeatureMapper pipeline instead of creating variants ad-hoc.
 *
 * Idempotent — uses INSERT IGNORE for map rows and addAlias() for aliases.
 */
function fn_sphinx_holidays_seed_region_mappings(): void
{
    if (!class_exists(\Tygh\Addons\TravelCore\Services\FeatureMapper::class)) {
        return;
    }

    $tablePrefix = \Tygh\Registry::get('config.table_prefix');
    $mapTableExists = db_get_field(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?s",
        $tablePrefix . 'travel_feature_map'
    );
    $whitelistTableExists = db_get_field(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?s",
        $tablePrefix . 'sphinx_destination_whitelist'
    );
    if (!$mapTableExists || !$whitelistTableExists) {
        return;
    }

    // Get all whitelisted regions:
    // 1) Regions under countries with selection_type='all' (all children included)
    // 2) Regions explicitly whitelisted with selection_type='specific'
    $regions = db_get_array(
        "SELECT d.destination_id, d.name, d.country_code
         FROM ?:sphinx_destinations d
         JOIN ?:sphinx_destination_whitelist w ON w.destination_id = d.parent_id AND w.selection_type = 'all'
         WHERE d.type = 'region'
         UNION
         SELECT d.destination_id, d.name, d.country_code
         FROM ?:sphinx_destinations d
         JOIN ?:sphinx_destination_whitelist w ON w.destination_id = d.destination_id AND w.selection_type = 'specific'
         WHERE d.type = 'region'"
    );

    if (empty($regions)) {
        return;
    }

    foreach ($regions as $region) {
        $destId = (int) $region['destination_id'];
        $canonicalCode = 'region_' . $destId;
        $displayName = (string) $region['name'];

        db_query(
            "INSERT IGNORE INTO ?:travel_feature_map (feature_type, canonical_code, display_name_en, mapping_source, status)
             VALUES ('region', ?s, ?s, 'auto', 'A')",
            $canonicalCode, $displayName
        );

        $mapId = (int) db_get_field(
            "SELECT map_id FROM ?:travel_feature_map WHERE feature_type = 'region' AND canonical_code = ?s",
            $canonicalCode
        );

        if ($mapId > 0) {
            \Tygh\Addons\TravelCore\Services\FeatureMapper::addAlias('sphinx', (string) $destId, $mapId, 'exact');
        }
    }

    \Tygh\Addons\TravelCore\Services\FeatureMapper::clearCache();
}

// =========================================================================
// HOOK FUNCTIONS
// =========================================================================

/**
 * Hook: pre_place_order
 * Re-verify Sphinx offer prices before order is placed.
 *
 * If a Sphinx offer is no longer available, the item is removed from the cart
 * instead of blocking the entire order. This allows mixed-provider orders
 * (e.g. Novoton + Sphinx) to proceed with the available items.
 *
 * The order is only blocked if ALL remaining cart items become unavailable
 * (i.e. the cart would be empty after removals).
 */
function fn_sphinx_holidays_pre_place_order(&$cart, &$allow, &$product_groups): void
{
    $verifier = \Tygh\Addons\SphinxHolidays\Services\Container::getPreOrderPriceVerifier();
    $result = $verifier->verify($cart);

    // Remove unavailable Sphinx offers from cart instead of blocking the entire order
    if (!empty($result['unavailable'])) {
        foreach ($result['unavailable'] as $cartId => $info) {
            $hotelName = $info['hotel_name'] ?: $info['offer_id'];

            fn_set_notification('W', __('warning'),
                __('sphinx_holidays.offer_removed_from_order', [
                    '[hotel]' => $hotelName,
                    '[default]' => 'The Sphinx offer for "' . $hotelName . '" is no longer available and has been removed from your order.',
                ])
            );

            fn_log_event('general', 'runtime', [
                'message' => 'Sphinx pre_place_order: removed unavailable item from cart',
                'cart_id' => $cartId,
                'hotel_name' => $hotelName,
            ]);

            unset($cart['products'][$cartId]);
        }

        // If the cart is now empty (all items were Sphinx and all unavailable), block the order
        if (empty($cart['products'])) {
            fn_set_notification('E', __('error'),
                __('sphinx_holidays.all_offers_unavailable', [
                    '[default]' => 'All hotel offers in your cart are no longer available. Please search again.',
                ])
            );
            $allow = false;
            return;
        }
    }

    if (!empty($result['corrections'])) {
        foreach ($result['corrections'] as $cartId => $correction) {
            if (!isset($cart['products'][$cartId])) {
                continue;
            }
            $newPrice = (float)$correction['api_price'];
            $cart['products'][$cartId]['price'] = $newPrice;
            $cart['products'][$cartId]['base_price'] = $newPrice;
            $cart['products'][$cartId]['original_price'] = $newPrice;
            $cart['products'][$cartId]['extra']['total_price'] = $newPrice;
        }
    }
}

/**
 * Hook: place_order_post
 * After an order is placed, submit the booking to the Sphinx API.
 *
 * Status flow: pending → (API call) → confirmed on success, failed on error.
 * On API failure: marks booking as STATUS_FAILED and logs the error.
 */
function fn_sphinx_holidays_place_order_post(&$order_id, &$action, &$order_status, &$cart, &$auth): void
{
    if (empty($order_id) || empty($cart['products'])) {
        return;
    }

    $repo = \Tygh\Addons\SphinxHolidays\Services\Container::getBookingRepository();

    foreach ($cart['products'] as $cart_id => $product) {
        if (empty($product['extra']['sphinx_booking']) || empty($product['extra']['travel_booking_id'])) {
            continue;
        }

        $booking_id = (int)$product['extra']['travel_booking_id'];
        $offer_id = $product['extra']['offer_id'] ?? '';

        // Link booking to order with PENDING status (not confirmed yet — API call hasn't happened)
        $repo->linkToOrder($booking_id, $order_id, \Tygh\Addons\TravelCore\TravelConstants::STATUS_PENDING);

        // Submit booking to Sphinx API
        if (!empty($offer_id)) {
            try {
                $api = \Tygh\Addons\SphinxHolidays\Services\Container::getApi();
                $guests_data = [];
                if (!empty($product['extra']['guests_data'])) {
                    $guests_data = is_string($product['extra']['guests_data'])
                        ? json_decode($product['extra']['guests_data'], true)
                        : $product['extra']['guests_data'];
                }

                $bookResult = $api->bookHotel([
                    'offer_id' => $offer_id,
                    'guests' => $guests_data ?: [],
                    'contact' => [
                        'email' => $product['extra']['contact_email'] ?? '',
                        'phone' => $product['extra']['contact_phone'] ?? '',
                    ],
                ]);

                if (!empty($bookResult['booking_reference'])) {
                    $repo->updateApiResponse(
                        $booking_id,
                        $bookResult['booking_reference'],
                        json_encode($bookResult)
                    );
                }

                // API call succeeded — now set confirmed status
                $repo->update($booking_id, [
                    'status' => \Tygh\Addons\TravelCore\TravelConstants::STATUS_CONFIRMED,
                ]);

            } catch (\Throwable $e) {
                // Mark booking as failed in both sphinx_bookings and travel_bookings
                $repo->update($booking_id, [
                    'status' => \Tygh\Addons\TravelCore\TravelConstants::STATUS_FAILED,
                ]);

                fn_log_event('general', 'runtime', [
                    'message' => 'Sphinx bookHotel API call failed: ' . $e->getMessage(),
                    'booking_id' => $booking_id,
                    'order_id' => $order_id,
                ]);
            }
        }
    }
}

/**
 * Hook: calculate_cart_items
 * Preserve stored price for Sphinx bookings.
 */
function fn_sphinx_holidays_calculate_cart_items(&$cart, &$cart_products, &$auth): void
{
    if (empty($cart['products'])) {
        return;
    }

    foreach ($cart['products'] as $cart_id => &$product) {
        if (!empty($product['extra']['sphinx_booking']) && !empty($product['stored_price'])) {
            $product['price'] = $product['base_price'] ?? $product['price'];
        }
    }
    unset($product);
}

/**
 * Hook: get_product_data_post
 * Attach booking engine config to Sphinx hotel products.
 */
function fn_sphinx_holidays_get_product_data_post(&$product_data, &$auth, $preview, $lang_code): void
{
    $hotel_id = _sphinx_extract_hotel_id($product_data['product_code'] ?? '');
    if ($hotel_id === '') {
        return;
    }

    $hotel = db_get_row(
        "SELECT hotel_id, classification, property_type,
                destination_id, destination_name, region_id, region_name,
                country_code, country_name, latitude, longitude
         FROM ?:sphinx_hotels WHERE hotel_id = ?s",
        $hotel_id
    );

    if (!empty($hotel)) {
        // Assign hotel data to Smarty view only — NOT to $product_data.
        // Any keys added to $product_data pollute Smarty's $product scope chain,
        // causing Data::getVariable() stack overflow on product detail pages.
        \Tygh\Tygh::$app['view']->assign('sphinx_hotel_data', $hotel);
    }
}

/**
 * Hook: gather_additional_product_data_post
 * Assign Smarty variables for Sphinx hotel product pages so the booking
 * engine form can render on the product detail page.
 */
function fn_sphinx_holidays_gather_additional_product_data_post(&$product, $auth, $params): void
{
    $hotel_id = _sphinx_extract_hotel_id($product['product_code'] ?? '');
    if ($hotel_id === '') {
        \Tygh\Tygh::$app['view']->assign('is_sphinx_hotel', false);
        return;
    }

    // Check if hotel exists in sphinx_hotels (any sync_status — the booking form
    // should render even for hotels not yet synced, as the search API is always available)
    $hotelStatus = db_get_field(
        "SELECT sync_status FROM ?:sphinx_hotels WHERE hotel_id = ?s",
        $hotel_id
    );

    // Show booking form for any SPX-prefixed product, even if hotel isn't in local DB.
    // The React form will handle API availability at search time.

    // Assign to Smarty view only — NOT to $product.
    // Stuffing values into $product forces Smarty's Data::getVariable() to walk
    // the entire $product scope chain on every $product.* template access, which
    // overflows the call stack on product detail pages (zend.max_allowed_stack_size).
    $view = \Tygh\Tygh::$app['view'];
    $view->assign('is_sphinx_hotel', true);
    $view->assign('sphinx_hotel_id', $hotel_id);
    $view->assign('show_sphinx_booking_form', true);
    $view->assign('sphinx_booking_form_position', 'before_tabs');
    $view->assign('product_id', $product['product_id']);
}

/**
 * Hook: get_product_tabs_post
 * Hide the Novoton "Hotel Prices" tab on Sphinx hotel product pages.
 */
function fn_sphinx_holidays_get_product_tabs_post($product_id, &$tabs): void
{
    $code = (string) db_get_field("SELECT product_code FROM ?:products WHERE product_id = ?i", $product_id);
    if (_sphinx_extract_hotel_id($code) !== '') {
        foreach ($tabs as $key => $tab) {
            if (($tab['addon'] ?? '') === 'novoton_holidays') {
                unset($tabs[$key]);
            }
        }
    }
}

/**
 * Extract hotel ID from a product code using the configured prefix.
 *
 * @return string Hotel ID or empty string if not a Sphinx product
 */
function _sphinx_extract_hotel_id(string $productCode): string
{
    if ($productCode === '') {
        return '';
    }

    $prefix = \Tygh\Addons\SphinxHolidays\Services\ConfigProvider::getProductCodePrefix();
    if ($prefix !== '' && str_starts_with($productCode, $prefix)) {
        return substr($productCode, strlen($prefix));
    }

    return '';
}

/**
 * Hook: user_login_post
 * Link session-based sphinx bookings to the logged-in user.
 */
function fn_sphinx_holidays_user_login_post($user_data, &$auth): void
{
    if (empty($auth['user_id'])) {
        return;
    }

    $session_id = session_id();
    if (empty($session_id)) {
        return;
    }

    $repo = \Tygh\Addons\SphinxHolidays\Services\Container::getBookingRepository();
    $repo->linkToUserBySession((int)$auth['user_id'], $session_id);
}

/**
 * Hook: create_user_post
 * Link session-based sphinx bookings to the newly created user.
 */
function fn_sphinx_holidays_create_user_post($user_id, $user_data, &$auth): void
{
    if (empty($user_id)) {
        return;
    }

    $session_id = session_id();
    if (empty($session_id)) {
        return;
    }

    $repo = \Tygh\Addons\SphinxHolidays\Services\Container::getBookingRepository();
    $repo->linkToUserBySession((int)$user_id, $session_id);
}

/**
 * Hook: get_order_info
 * Admin panel notification for failed Sphinx bookings.
 *
 * When an admin views an order that contains failed Sphinx bookings,
 * shows an orange warning notification via fn_set_notification('W', ...).
 */
function fn_sphinx_holidays_get_order_info(&$order, $additional_data): void
{
    // Only show notification in admin panel
    if (!defined('AREA') || AREA !== 'A' || empty($order['order_id'])) {
        return;
    }

    $repo = \Tygh\Addons\SphinxHolidays\Services\Container::getBookingRepository();
    $bookings = $repo->findByOrderId((int) $order['order_id']);

    foreach ($bookings as $booking) {
        if (($booking['status'] ?? '') === \Tygh\Addons\TravelCore\TravelConstants::STATUS_FAILED) {
            $hotelName = $booking['hotel_name'] ?? '';
            fn_set_notification('W', __('warning'),
                __('sphinx_holidays.booking_api_failed', [
                    '[hotel]' => $hotelName,
                    '[order_id]' => $order['order_id'],
                    '[default]' => 'Sphinx booking failed for hotel "' . $hotelName . '" in order #' . $order['order_id'] . '. Please verify and resubmit.',
                ])
            );
            break; // One notification per order is enough
        }
    }
}

/**
 * Hook: travel_core_exchange_rates_updated
 *
 * Logs the exchange rate update result from travel_core to sphinx_sync_log
 * so the admin panel can display "last updated" timestamps.
 *
 * @param array $result Full result from fn_travel_core_update_exchange_rates()
 */
function fn_sphinx_holidays_travel_core_exchange_rates_updated(array &$result): void
{
    if (empty($result['success'])) {
        return;
    }

    $updates = $result['updates'] ?? [];
    $total = count($updates);
    $synced = count(array_filter($updates, fn($u) => $u['success'] ?? false));

    db_query(
        "INSERT INTO ?:sphinx_sync_log (sync_type, status, items_total, items_synced, items_failed, error_message, started_at, completed_at) VALUES (?s, ?s, ?i, ?i, ?i, ?s, NOW(), NOW())",
        'exchange_rates',
        'completed',
        $total,
        $synced,
        $total - $synced,
        ''
    );
}

/**
 * Download an external image URL and attach it to a CS-Cart product.
 *
 * Uses CS-Cart's fn_update_image_pairs() to properly generate thumbnails
 * and store the image in the standard product gallery.
 *
 * @param int    $product_id CS-Cart product ID
 * @param string $image_url  External image URL to download
 * @param bool   $is_main    True for main product image, false for additional
 * @return bool True on success
 */
function fn_sphinx_holidays_add_product_image(int $product_id, string $image_url, bool $is_main = false): bool
{
    if (empty($product_id) || empty($image_url)) {
        return false;
    }

    $temp_file = fn_create_temp_file();
    if (!$temp_file) {
        error_log("sphinx_holidays: fn_create_temp_file() returned empty for product #{$product_id}");
        return false;
    }

    // Only add auth headers + watermark param for images hosted on the Sphinx API domain.
    // CDN-hosted images (e.g. b-cdn.net) are public and don't need/accept auth.
    $apiHost = parse_url(\Tygh\Addons\SphinxHolidays\Services\ConfigProvider::getApiBaseUrl(), PHP_URL_HOST);
    $imageHost = parse_url($image_url, PHP_URL_HOST);
    $isApiHosted = ($apiHost && $imageHost && str_contains($imageHost, $apiHost));

    $download_url = $isApiHosted
        ? \Tygh\Addons\SphinxHolidays\Api\ImageHelper::withoutWatermark($image_url)
        : $image_url;
    $headers = $isApiHosted
        ? \Tygh\Addons\SphinxHolidays\Api\ImageHelper::getCurlAuthHeaders()
        : [];

    // Use direct cURL — CS-Cart's Http::get ignores custom headers and returns
    // empty string with write_to_file, which caused all downloads to fail.
    $fp = fopen($temp_file, 'wb');
    if (!$fp) {
        error_log("sphinx_holidays: fopen() failed for temp file '{$temp_file}' product #{$product_id}");
        if (file_exists($temp_file)) { unlink($temp_file); }
        return false;
    }

    $ch = curl_init($download_url);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fp,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
    ]);

    curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    if ($httpCode !== 200 || !file_exists($temp_file) || filesize($temp_file) < 1000) {
        error_log("sphinx_holidays: image download failed for product #{$product_id}: HTTP {$httpCode}, size=" . (file_exists($temp_file) ? filesize($temp_file) : 'N/A') . ", url={$download_url}" . ($curlError ? " ({$curlError})" : ''));
        if (file_exists($temp_file)) { unlink($temp_file); }
        return false;
    }

    try {
        $image_info = getimagesize($temp_file);
    } catch (\Throwable $e) {
        $image_info = false;
    }
    if (!$image_info) {
        error_log("sphinx_holidays: getimagesize() failed for product #{$product_id}, file={$temp_file}, size=" . filesize($temp_file));
        if (file_exists($temp_file)) { unlink($temp_file); }
        return false;
    }

    $mime_to_ext = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    $ext = $mime_to_ext[$image_info['mime']] ?? 'jpg';
    $filename = "sphinx_hotel_{$product_id}_" . time() . '_' . mt_rand(100, 999) . ".{$ext}";

    $existing_pairs = (int) db_get_field(
        "SELECT COUNT(*) FROM ?:images_links WHERE object_id = ?i AND object_type = 'product'",
        $product_id
    );

    $pair_data = [
        'type'        => $is_main ? 'M' : 'A',
        'object_id'   => $product_id,
        'object_type' => 'product',
        'position'    => $existing_pairs,
    ];

    if (function_exists('fn_update_image_pairs')) {
        $icons = [];
        $detailed = [
            0 => [
                'name'     => $filename,
                'path'     => $temp_file,
                'tmp_name' => $temp_file,
                'size'     => filesize($temp_file),
                'type'     => $image_info['mime'],
            ],
        ];

        $pair_ids = fn_update_image_pairs($icons, $detailed, $pair_data, $product_id, 'product');

        if (file_exists($temp_file)) { unlink($temp_file); }

        if (empty($pair_ids)) {
            error_log("sphinx_holidays: fn_update_image_pairs() returned empty for product #{$product_id}, file={$filename}, size=" . ($detailed[0]['size'] ?? '?') . ", mime={$image_info['mime']}");
            return false;
        }

        return true;
    }

    error_log("sphinx_holidays: fn_update_image_pairs() function not found");
    if (file_exists($temp_file)) { unlink($temp_file); }
    return false;
}

/**
 * Get hotels with filtering, sorting, and pagination (fn_get_products pattern).
 *
 * @param array $params Search/filter/sort parameters from $_REQUEST
 * @return array{0: array, 1: array} [$hotels, $search_params]
 */
function fn_sphinx_holidays_get_hotels(array $params = []): array
{
    $default_params = [
        'page'           => 1,
        'items_per_page' => (int) \Tygh\Registry::get('settings.Appearance.admin_elements_per_page') ?: 50,
        'sort_by'        => 'name',
        'sort_order'     => 'asc',
        'country_code'   => '',
        'region_id'      => 0,
        'destination_id' => 0,
        'sync_status'    => '',
        'classification' => '',
        'property_type'  => '',
        'link_status'    => '',
        'q'              => '',
    ];

    $params = array_merge($default_params, array_intersect_key($params, $default_params));
    $params['page'] = max(1, (int) $params['page']);
    $params['items_per_page'] = max(1, (int) $params['items_per_page']);
    $params['region_id'] = (int) $params['region_id'];
    $params['destination_id'] = (int) $params['destination_id'];
    $params['q'] = trim((string) $params['q']);

    // Sortings map: allowed sort columns
    $sortings = [
        'hotel_id'       => 'h.hotel_id',
        'name'           => 'h.name',
        'classification' => 'h.classification',
        'country_code'   => 'h.country_code',
        'sync_status'    => 'h.sync_status',
        'last_synced_at' => 'h.last_synced_at',
        'property_type'  => 'h.property_type',
    ];

    $sort_by = isset($sortings[$params['sort_by']]) ? $params['sort_by'] : 'name';
    $sort_order = strtolower($params['sort_order']) === 'desc' ? 'DESC' : 'ASC';
    $sort_column = $sortings[$sort_by];

    $params['sort_by'] = $sort_by;
    $params['sort_order'] = strtolower($sort_order);
    $params['sort_order_toggle'] = ($sort_order === 'ASC') ? 'desc' : 'asc';

    // Build WHERE condition
    $condition = '';

    if ($params['country_code'] !== '') {
        $condition .= db_quote(" AND h.country_code = ?s", $params['country_code']);
    }
    if ($params['region_id'] > 0) {
        $condition .= db_quote(" AND h.region_id = ?i", $params['region_id']);
    }
    if ($params['destination_id'] > 0) {
        $condition .= db_quote(" AND h.destination_id = ?i", $params['destination_id']);
    }
    if ($params['sync_status'] !== '') {
        $condition .= db_quote(" AND h.sync_status = ?s", $params['sync_status']);
    }
    if ($params['classification'] !== '') {
        $classification = (int) $params['classification'];
        if ($classification === 0) {
            $condition .= " AND (h.classification IS NULL OR h.classification = 0)";
        } else {
            $condition .= db_quote(" AND h.classification = ?i", $classification);
        }
    }
    if ($params['property_type'] !== '') {
        $condition .= db_quote(" AND h.property_type = ?s", $params['property_type']);
    }
    if ($params['link_status'] === 'linked') {
        $condition .= " AND h.product_id IS NOT NULL AND h.product_id > 0";
    } elseif ($params['link_status'] === 'orphan') {
        $condition .= " AND (h.product_id IS NULL OR h.product_id = 0)";
    }
    if ($params['q'] !== '') {
        $condition .= db_quote(" AND h.name LIKE ?l", '%' . $params['q'] . '%');
    }

    // Total count
    $params['total_items'] = (int) db_get_field(
        "SELECT COUNT(*) FROM ?:sphinx_hotels h WHERE 1 ?p",
        $condition
    );

    // Pagination
    $offset = ($params['page'] - 1) * $params['items_per_page'];

    // Select listing columns (prefixed with alias)
    $listing_cols = 'h.hotel_id, h.product_id, h.name, h.classification, h.property_type, '
        . 'h.destination_id, h.destination_name, h.region_id, h.region_name, '
        . 'h.country_code, h.country_name, h.latitude, h.longitude, '
        . 'h.image_url, h.is_recommended, h.is_adults_only, h.rating, h.rating_count, '
        . 'h.sync_status, h.last_synced_at, h.created_at, h.updated_at, h.product_skip_reason';

    $hotels = db_get_array(
        "SELECT {$listing_cols} FROM ?:sphinx_hotels h"
        . " WHERE 1 ?p"
        . " ORDER BY {$sort_column} {$sort_order}"
        . " LIMIT ?i, ?i",
        $condition,
        $offset,
        $params['items_per_page']
    );

    return [$hotels, $params];
}

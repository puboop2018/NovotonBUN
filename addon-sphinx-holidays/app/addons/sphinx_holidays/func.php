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
    fn_sphinx_holidays_seed_language_keys();
    return true;
}

/**
 * Ensure language keys added after initial install are present in the database.
 * Idempotent — skips keys that already exist.
 */
function fn_sphinx_holidays_seed_language_keys(): void
{
    $keys = [
        'sphinx_holidays.circuits_category_id' => [
            'en' => 'CS-Cart category ID for circuits',
            'ro' => 'ID categorie CS-Cart pentru circuite',
        ],
        'sphinx_holidays.experiences_category_id' => [
            'en' => 'CS-Cart category ID for experiences',
            'ro' => 'ID categorie CS-Cart pentru experiențe',
        ],
        'sphinx_holidays.product_languages' => [
            'en' => 'Product languages',
            'ro' => 'Limbi produse',
        ],
        'sphinx_holidays.product_languages.tooltip' => [
            'en' => 'Select which CS-Cart languages to create hotel product descriptions for. Hotels will only appear in the storefront for selected languages.',
            'ro' => 'Selectati pentru care limbi CS-Cart sa se creeze descrierile produselor hoteliere. Hotelurile vor aparea in magazin doar pentru limbile selectate.',
        ],
        'sphinx_holidays.addon_settings' => [
            'en' => 'Settings',
            'ro' => 'Setări',
        ],
        'sphinx_holidays.show_whitelisted_only' => [
            'en' => 'Show whitelisted only',
            'ro' => 'Arată doar cele din whitelist',
        ],
        'sphinx_holidays.classification' => [
            'en' => 'Classification',
            'ro' => 'Clasificare',
        ],
        'sphinx_holidays.link_status' => [
            'en' => 'Link Status',
            'ro' => 'Status Legătură',
        ],
        'sphinx_holidays.linked' => [
            'en' => 'Linked',
            'ro' => 'Legat',
        ],
        'sphinx_holidays.orphan' => [
            'en' => 'Orphan',
            'ro' => 'Orfan',
        ],
        'sphinx_holidays.product' => [
            'en' => 'Product',
            'ro' => 'Produs',
        ],
        'sphinx_holidays.unclassified' => [
            'en' => 'Unclassified',
            'ro' => 'Neclasificat',
        ],
        'sphinx_holidays.bulk_activate' => [
            'en' => 'Activate Selected',
            'ro' => 'Activează Selectate',
        ],
        'sphinx_holidays.bulk_deactivate' => [
            'en' => 'Deactivate Selected',
            'ro' => 'Dezactivează Selectate',
        ],
        'sphinx_holidays.bulk_delete' => [
            'en' => 'Delete Selected',
            'ro' => 'Șterge Selectate',
        ],
        'sphinx_holidays.bulk_sync_images' => [
            'en' => 'Sync Images',
            'ro' => 'Sincronizează Imagini',
        ],
        'sphinx_holidays.bulk_delete_confirm' => [
            'en' => 'Are you sure you want to delete the selected hotels?',
            'ro' => 'Sigur doriți să ștergeți hotelurile selectate?',
        ],
        'sphinx_holidays.images_synced' => [
            'en' => 'Images synced',
            'ro' => 'Imagini sincronizate',
        ],
        'sphinx_holidays.hotels_updated' => [
            'en' => 'Hotels updated',
            'ro' => 'Hoteluri actualizate',
        ],
        'sphinx_holidays.hotels_deleted' => [
            'en' => 'Hotels deleted',
            'ro' => 'Hoteluri șterse',
        ],
        'sphinx_holidays.no_hotels_selected' => [
            'en' => 'No hotels selected',
            'ro' => 'Niciun hotel selectat',
        ],
        'sphinx_holidays.all_classifications' => [
            'en' => 'All classifications',
            'ro' => 'Toate clasificările',
        ],
        'sphinx_holidays.all_property_types' => [
            'en' => 'All types',
            'ro' => 'Toate tipurile',
        ],
        'sphinx_holidays.all_link_statuses' => [
            'en' => 'All',
            'ro' => 'Toate',
        ],
        'sphinx_holidays.with_selected' => [
            'en' => 'With selected',
            'ro' => 'Cu cele selectate',
        ],
        'sphinx_holidays.skipped_hotels' => [
            'en' => 'Skipped hotels',
            'ro' => 'Hoteluri omise',
        ],
        'sphinx_holidays.retry_skipped' => [
            'en' => 'Retry skipped hotels',
            'ro' => 'Reîncearcă hotelurile omise',
        ],
        'sphinx_holidays.retry_skipped_confirm' => [
            'en' => 'This will reset all skipped hotels so they can be processed again. Continue?',
            'ro' => 'Aceasta va reseta toate hotelurile omise pentru a fi procesate din nou. Continuați?',
        ],
        'sphinx_holidays.skipped_reset' => [
            'en' => '[count] skipped hotel(s) have been reset and are now eligible for product creation.',
            'ro' => '[count] hotel(uri) omise au fost resetate și sunt acum eligibile pentru crearea de produse.',
        ],
        'sphinx_holidays.no_skipped_hotels' => [
            'en' => 'No skipped hotels found.',
            'ro' => 'Nu s-au găsit hoteluri omise.',
        ],
        // SEO Templates section
        'sphinx_holidays.seo_templates' => [
            'en' => 'SEO Templates',
            'ro' => 'Șabloane SEO',
        ],
        'sphinx_holidays.seo_placeholders_info' => [
            'en' => 'Available placeholders',
            'ro' => 'Placeholder-e disponibile',
        ],
        'sphinx_holidays.seo_product_name' => [
            'en' => 'Product name pattern',
            'ro' => 'Șablon nume produs',
        ],
        'sphinx_holidays.seo_product_name.tooltip' => [
            'en' => 'Template for the product name.',
            'ro' => 'Șablon pentru numele produsului.',
        ],
        'sphinx_holidays.seo_page_title' => [
            'en' => 'Page title pattern',
            'ro' => 'Șablon titlu pagină',
        ],
        'sphinx_holidays.seo_page_title.tooltip' => [
            'en' => 'Template for the HTML page title (SEO).',
            'ro' => 'Șablon pentru titlul paginii HTML (SEO).',
        ],
        'sphinx_holidays.seo_meta_description' => [
            'en' => 'Meta description pattern',
            'ro' => 'Șablon meta descriere',
        ],
        'sphinx_holidays.seo_meta_description.tooltip' => [
            'en' => 'Template for the meta description tag.',
            'ro' => 'Șablon pentru tag-ul meta description.',
        ],
        'sphinx_holidays.seo_meta_keywords' => [
            'en' => 'Meta keywords pattern',
            'ro' => 'Șablon meta cuvinte cheie',
        ],
        'sphinx_holidays.seo_meta_keywords.tooltip' => [
            'en' => 'Template for the meta keywords tag.',
            'ro' => 'Șablon pentru tag-ul meta keywords.',
        ],
        'sphinx_holidays.seo_name_slug' => [
            'en' => 'SEO URL slug pattern',
            'ro' => 'Șablon URL SEO (slug)',
        ],
        'sphinx_holidays.seo_name_slug.tooltip' => [
            'en' => 'Template for the SEO-friendly URL slug. Result is automatically sanitized.',
            'ro' => 'Șablon pentru slug-ul URL SEO. Rezultatul este sanitizat automat.',
        ],
        'sphinx_holidays.seo_full_description' => [
            'en' => 'Full description pattern (optional)',
            'ro' => 'Șablon descriere completă (opțional)',
        ],
        'sphinx_holidays.seo_full_description.tooltip' => [
            'en' => 'Optional template to wrap or replace the API description. Leave empty to use the raw API description as-is.',
            'ro' => 'Șablon opțional pentru a înfășura sau înlocui descrierea API. Lăsați gol pentru a folosi descrierea API în forma originală.',
        ],
    ];

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

    // Facility aliases — map Sphinx facility IDs to canonical codes.
    // Each canonical code row in travel_feature_map carries its own cscart_feature_id,
    // so the admin can assign any facility to any CS-Cart feature via the admin UI.
    $facilityAliases = [
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
        // Room Amenities
        '111' => 'pets_allowed',
        '115' => 'non_smoking',
        '116' => 'smoking_area',
        '117' => 'non_smoking_rooms',
        '120' => 'family_rooms',
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
        // Accessibility
        '118' => 'disabled_access',
        '203' => 'stairs_only',
        // Smoking Policy
        '204' => 'no_smoking_all',
    ];

    $seedAliasGroup('facility', $facilityAliases, 'exact');

    // Travel group aliases — map hotel attributes to canonical codes.
    // 'Y' = is_adults_only flag, 'family' = inferred from family facilities.
    $travelGroupAliases = [
        'Y'      => 'adults_only',
        'family' => 'family_friendly',
    ];
    $seedAliasGroup('travel_group', $travelGroupAliases, 'exact');

    // Clear resolve cache after batch alias inserts
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
    if (empty($product_data['product_code'])) {
        return;
    }

    $code = $product_data['product_code'];
    if (str_starts_with($code, 'SPX')) {
        $hotel_id = substr($code, 3);
    } else {
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
        $product_data['hotel_id'] = $hotel['hotel_id'];
        // Use CS-Cart product name (single source of truth); fall back to hotel_id if product was deleted
        $product_data['hotel_name'] = $product_data['product'] ?? ('Hotel ' . $hotel['hotel_id']);
        $product_data['star_rating'] = $hotel['classification'];
        $product_data['travel_provider'] = 'sphinx';
        // Assign hotel data to Smarty view directly — NOT to $product_data.
        // Stuffing large nested arrays into $product causes Smarty's Data class
        // to overflow PHP's stack limit during variable scope resolution.
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
    if (empty($product['product_code'])) {
        return;
    }

    $code = $product['product_code'];
    if (str_starts_with($code, 'SPX')) {
        $hotel_id = substr($code, 3);
    } elseif (str_starts_with($code, 'SPH_')) {
        $hotel_id = substr($code, 4);
    } else {
        \Tygh\Tygh::$app['view']->assign('is_sphinx_hotel', false);
        return;
    }

    $exists = (int) db_get_field(
        "SELECT COUNT(*) FROM ?:sphinx_hotels WHERE hotel_id = ?s AND sync_status = 'active'",
        $hotel_id
    );

    if (!$exists) {
        \Tygh\Tygh::$app['view']->assign('is_sphinx_hotel', false);
        return;
    }

    $view = \Tygh\Tygh::$app['view'];
    $view->assign('is_sphinx_hotel', true);
    $view->assign('sphinx_hotel_id', $hotel_id);
    $view->assign('show_sphinx_booking_form', true);
    $view->assign('sphinx_booking_form_position', 'before_tabs');
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
        return false;
    }

    // Sphinx API requires auth header for watermark-free images
    $download_url = \Tygh\Addons\SphinxHolidays\Api\ImageHelper::withoutWatermark($image_url);
    $headers = \Tygh\Addons\SphinxHolidays\Api\ImageHelper::getCurlAuthHeaders();

    // Use direct cURL — CS-Cart's Http::get ignores custom headers and returns
    // empty string with write_to_file, which caused all downloads to fail.
    $fp = fopen($temp_file, 'wb');
    if (!$fp) {
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
        if ($httpCode !== 200) {
            error_log("sphinx_holidays: image download failed HTTP {$httpCode} for {$download_url}" . ($curlError ? " ({$curlError})" : ''));
        }
        if (file_exists($temp_file)) { unlink($temp_file); }
        return false;
    }

    try {
        $image_info = getimagesize($temp_file);
    } catch (\Throwable $e) {
        $image_info = false;
    }
    if (!$image_info) {
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
                'name' => $filename,
                'path' => $temp_file,
                'size' => filesize($temp_file),
            ],
        ];

        $pair_ids = fn_update_image_pairs($icons, $detailed, $pair_data, 'product', $product_id);

        if (file_exists($temp_file)) { unlink($temp_file); }
        return !empty($pair_ids);
    }

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
        $escaped = addcslashes($params['q'], '%_\\');
        $condition .= db_quote(" AND h.name LIKE ?l", '%' . $escaped . '%');
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

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
    db_query("DROP TABLE IF EXISTS ?:sphinx_cache");
    db_query("DROP TABLE IF EXISTS ?:sphinx_sync_log");
    db_query("DROP TABLE IF EXISTS ?:sphinx_bookings");
    db_query("DROP TABLE IF EXISTS ?:sphinx_package_routes");
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
    return true;
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
        'Self Catering'        => 'SC',
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

    // Resolve map_ids from canonical codes and insert aliases
    foreach ($boardAliases as $apiValue => $canonicalCode) {
        $mapId = (int) db_get_field(
            "SELECT map_id FROM ?:travel_feature_map WHERE feature_type = 'board' AND canonical_code = ?s",
            $canonicalCode
        );
        if ($mapId > 0) {
            \Tygh\Addons\TravelCore\Services\FeatureMapper::addAlias('sphinx', $apiValue, $mapId, 'exact');
        }
    }

    foreach ($roomAliases as $apiValue => $canonicalCode) {
        $mapId = (int) db_get_field(
            "SELECT map_id FROM ?:travel_feature_map WHERE feature_type = 'room_type' AND canonical_code = ?s",
            $canonicalCode
        );
        if ($mapId > 0) {
            \Tygh\Addons\TravelCore\Services\FeatureMapper::addAlias('sphinx', $apiValue, $mapId, 'prefix');
        }
    }

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

    foreach ($starAliases as $apiValue => $canonicalCode) {
        $mapId = (int) db_get_field(
            "SELECT map_id FROM ?:travel_feature_map WHERE feature_type = 'stars' AND canonical_code = ?s",
            $canonicalCode
        );
        if ($mapId > 0) {
            \Tygh\Addons\TravelCore\Services\FeatureMapper::addAlias('sphinx', (string) $apiValue, $mapId, 'exact');
        }
    }

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

    foreach ($propertyTypeAliases as $apiValue => $canonicalCode) {
        $mapId = (int) db_get_field(
            "SELECT map_id FROM ?:travel_feature_map WHERE feature_type = 'property_type' AND canonical_code = ?s",
            $canonicalCode
        );
        if ($mapId > 0) {
            \Tygh\Addons\TravelCore\Services\FeatureMapper::addAlias('sphinx', $apiValue, $mapId, 'exact');
        }
    }

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

    foreach ($facilityAliases as $sphinxId => $canonicalCode) {
        $mapId = (int) db_get_field(
            "SELECT map_id FROM ?:travel_feature_map WHERE feature_type = 'facility' AND canonical_code = ?s",
            $canonicalCode
        );
        if ($mapId > 0) {
            \Tygh\Addons\TravelCore\Services\FeatureMapper::addAlias('sphinx', $sphinxId, $mapId, 'exact');
        }
    }

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
function fn_sphinx_holidays_place_order_post(&$order_id, &$action, &$order_status, &$cart, &$auth)
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
function fn_sphinx_holidays_calculate_cart_items(&$cart, &$cart_products, &$auth)
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
function fn_sphinx_holidays_get_product_data_post(&$product_data, &$auth, $preview, $lang_code)
{
    if (empty($product_data['product_code'])) {
        return;
    }

    // Support both legacy SPH_ and new SPX product code prefixes
    $code = $product_data['product_code'];
    if (strpos($code, 'SPX') === 0) {
        $hotel_id = substr($code, 3);
    } elseif (strpos($code, 'SPH_') === 0) {
        $hotel_id = substr($code, 4);
    } else {
        return;
    }

    $hotel = db_get_row(
        "SELECT * FROM ?:sphinx_hotels WHERE hotel_id = ?s",
        $hotel_id
    );

    if (!empty($hotel)) {
        $product_data['hotel_id'] = $hotel['hotel_id'];
        $product_data['hotel_name'] = $hotel['name'];
        $product_data['star_rating'] = $hotel['classification'];
        $product_data['travel_provider'] = 'sphinx';
        $product_data['sphinx_hotel'] = $hotel;
    }
}

/**
 * Hook: user_login_post
 * Link session-based sphinx bookings to the logged-in user.
 */
function fn_sphinx_holidays_user_login_post($user_data, &$auth)
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
function fn_sphinx_holidays_create_user_post($user_id, $user_data, &$auth)
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

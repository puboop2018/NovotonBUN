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

    // Clear resolve cache after batch alias inserts
    \Tygh\Addons\TravelCore\Services\FeatureMapper::clearCache();
}

// =========================================================================
// HOOK FUNCTIONS
// =========================================================================

/**
 * Hook: place_order_post
 * After an order is placed, submit the booking to the Sphinx API.
 */
function fn_sphinx_holidays_place_order_post(&$order_id, &$action, &$order_status, &$cart, &$auth)
{
    if (empty($order_id) || empty($cart['products'])) {
        return;
    }

    foreach ($cart['products'] as $cart_id => $product) {
        if (empty($product['extra']['sphinx_booking']) || empty($product['extra']['travel_booking_id'])) {
            continue;
        }

        $booking_id = (int)$product['extra']['travel_booking_id'];
        $offer_id = $product['extra']['offer_id'] ?? '';

        // Update sphinx_bookings with order_id
        $confirmed = \Tygh\Addons\TravelCore\TravelConstants::STATUS_CONFIRMED;
        db_query(
            "UPDATE ?:sphinx_bookings SET order_id = ?i, status = ?s WHERE booking_id = ?i",
            $order_id, $confirmed, $booking_id
        );

        // Update travel_bookings
        db_query(
            "UPDATE ?:travel_bookings SET order_id = ?i, status = ?s WHERE provider = 'sphinx' AND provider_booking_id = ?s",
            $order_id, $confirmed, (string)$booking_id
        );

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
                    db_query(
                        "UPDATE ?:sphinx_bookings SET api_booking_ref = ?s, api_response = ?s WHERE booking_id = ?i",
                        $bookResult['booking_reference'],
                        json_encode($bookResult),
                        $booking_id
                    );
                }
            } catch (\Throwable $e) {
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

    db_query(
        "UPDATE ?:sphinx_bookings SET user_id = ?i WHERE session_id = ?s AND user_id = 0 AND order_id = 0",
        (int)$auth['user_id'], $session_id
    );

    // Update travel_bookings via provider_booking_id link
    $sphinx_booking_ids = db_get_fields(
        "SELECT booking_id FROM ?:sphinx_bookings WHERE session_id = ?s AND user_id = ?i AND order_id = 0",
        $session_id, (int)$auth['user_id']
    );
    if (!empty($sphinx_booking_ids)) {
        foreach ($sphinx_booking_ids as $bid) {
            db_query(
                "UPDATE ?:travel_bookings SET user_id = ?i WHERE provider = 'sphinx' AND provider_booking_id = ?s AND (user_id IS NULL OR user_id = 0)",
                (int)$auth['user_id'], (string)$bid
            );
        }
    }
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

    db_query(
        "UPDATE ?:sphinx_bookings SET user_id = ?i WHERE session_id = ?s AND user_id = 0 AND order_id = 0",
        (int)$user_id, $session_id
    );

    // Update travel_bookings via provider_booking_id link
    $sphinx_booking_ids = db_get_fields(
        "SELECT booking_id FROM ?:sphinx_bookings WHERE session_id = ?s AND user_id = ?i AND order_id = 0",
        $session_id, (int)$user_id
    );
    if (!empty($sphinx_booking_ids)) {
        foreach ($sphinx_booking_ids as $bid) {
            db_query(
                "UPDATE ?:travel_bookings SET user_id = ?i WHERE provider = 'sphinx' AND provider_booking_id = ?s AND (user_id IS NULL OR user_id = 0)",
                (int)$user_id, (string)$bid
            );
        }
    }
}

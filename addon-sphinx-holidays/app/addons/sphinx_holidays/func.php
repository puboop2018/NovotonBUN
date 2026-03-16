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

    // Add columns for order status sync (safe for existing installs)
    $columns = db_get_fields("SHOW COLUMNS FROM ?:sphinx_bookings");
    if (!in_array('payment_terms_json', $columns, true)) {
        db_query("ALTER TABLE ?:sphinx_bookings ADD COLUMN `payment_terms_json` JSON DEFAULT NULL COMMENT 'Payment terms from Orders API' AFTER `api_response`");
    }
    if (!in_array('cancellation_fees_json', $columns, true)) {
        db_query("ALTER TABLE ?:sphinx_bookings ADD COLUMN `cancellation_fees_json` JSON DEFAULT NULL COMMENT 'Cancellation fees from Orders API' AFTER `payment_terms_json`");
    }
    if (!in_array('last_status_check', $columns, true)) {
        db_query("ALTER TABLE ?:sphinx_bookings ADD COLUMN `last_status_check` DATETIME DEFAULT NULL COMMENT 'Last time status was polled from API' AFTER `cancellation_fees_json`");
    }

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

                $booking_type = $product['extra']['booking_type'] ?? 'hotel';
                $bookResult = fn_sphinx_holidays_submit_booking($api, $booking_type, $offer_id, $product, $guests_data);

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

                $hotelName = $product['extra']['hotel_name'] ?? $product['product'] ?? '';
                $errorMsg = $e->getMessage();

                fn_log_event('general', 'runtime', [
                    'message' => "Sphinx book ({$booking_type}) API call failed: " . $errorMsg,
                    'booking_id' => $booking_id,
                    'order_id' => $order_id,
                ]);

                // Send admin email alert for booking failure
                fn_sphinx_holidays_send_booking_failure_email($order_id, $booking_id, $hotelName, $errorMsg);
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
 * Submit a booking to the Sphinx API based on booking type.
 *
 * Dispatches to the correct API method (bookHotel, bookCircuit, bookExperience)
 * and builds the appropriate payload shape for each type.
 *
 * @param \Tygh\Addons\SphinxHolidays\SphinxApi $api
 * @param string $booking_type 'hotel', 'circuit', or 'experience'
 * @param string $offer_id
 * @param array $product Cart product data with extra fields
 * @param array $guests_data Parsed guest data
 * @return array API response
 */
function fn_sphinx_holidays_submit_booking($api, string $booking_type, string $offer_id, array $product, array $guests_data): array
{
    $extra = $product['extra'] ?? [];
    $price = (float)($extra['total_price'] ?? $product['price'] ?? 0);
    $currency = $extra['currency'] ?? 'EUR';
    $order_id = $extra['order_id'] ?? '';

    switch ($booking_type) {
        case 'circuit':
            $occupancy = fn_sphinx_holidays_build_room_occupancy($guests_data, $extra);
            $payload = [
                'offer_id' => $offer_id,
                'price' => $price,
                'currency' => $currency,
                'occupancy' => $occupancy,
            ];
            if (!empty($order_id)) {
                $payload['reference_code'] = (string)$order_id;
            }
            $result = $api->bookCircuit($payload);
            break;

        case 'experience':
            $occupancy = fn_sphinx_holidays_build_flat_occupancy($guests_data);
            $payload = [
                'offer_id' => $offer_id,
                'price' => $price,
                'currency' => $currency,
                'occupancy' => $occupancy,
            ];
            if (!empty($order_id)) {
                $payload['reference_code'] = (string)$order_id;
            }
            $result = $api->bookExperience($payload);
            break;

        default: // hotel
            $result = $api->bookHotel([
                'offer_id' => $offer_id,
                'guests' => $guests_data ?: [],
                'contact' => [
                    'email' => $extra['contact_email'] ?? '',
                    'phone' => $extra['contact_phone'] ?? '',
                ],
            ]);
            break;
    }

    return $result ?: [];
}

/**
 * Build room-based occupancy array for circuit/package bookings.
 *
 * API expects: [{room_code: string, guests: [{first_name, last_name, birth_date, gender}]}]
 *
 * @param array $guests_data Parsed guests grouped by room
 * @param array $extra Cart extra data containing rooms_data
 * @return array Occupancy array for API
 */
function fn_sphinx_holidays_build_room_occupancy(array $guests_data, array $extra): array
{
    $rooms_data = $extra['rooms_data'] ?? [];
    if (is_string($rooms_data)) {
        $rooms_data = json_decode($rooms_data, true) ?: [];
    }

    // If guests_data is already structured with room groupings
    if (!empty($guests_data) && isset($guests_data[0]['room_code'])) {
        return $guests_data;
    }

    // Build occupancy from rooms_data + flat guest list
    $occupancy = [];
    if (!empty($rooms_data)) {
        foreach ($rooms_data as $idx => $room) {
            $room_code = $room['room_id'] ?? $room['code'] ?? 'room-' . ($idx + 1);
            $room_guests = [];

            // Collect guests for this room from flat list
            $room_adults = (int)($room['adults'] ?? 2);
            $room_children = (int)($room['children'] ?? 0);
            $total_in_room = $room_adults + $room_children;

            $offset = 0;
            for ($i = 0; $i < $idx; $i++) {
                $offset += (int)($rooms_data[$i]['adults'] ?? 2) + (int)($rooms_data[$i]['children'] ?? 0);
            }

            for ($g = 0; $g < $total_in_room; $g++) {
                $guest = $guests_data[$offset + $g] ?? null;
                if ($guest) {
                    $room_guests[] = [
                        'first_name' => $guest['first_name'] ?? '',
                        'last_name'  => $guest['last_name'] ?? '',
                        'birth_date' => $guest['birth_date'] ?? '',
                        'gender'     => $guest['gender'] ?? 'm',
                    ];
                }
            }

            $occupancy[] = [
                'room_code' => $room_code,
                'guests'    => $room_guests,
            ];
        }
    } elseif (!empty($guests_data)) {
        // Fallback: single room with all guests
        $room_guests = [];
        foreach ($guests_data as $guest) {
            $room_guests[] = [
                'first_name' => $guest['first_name'] ?? '',
                'last_name'  => $guest['last_name'] ?? '',
                'birth_date' => $guest['birth_date'] ?? '',
                'gender'     => $guest['gender'] ?? 'm',
            ];
        }
        $occupancy[] = [
            'room_code' => 'standard',
            'guests'    => $room_guests,
        ];
    }

    return $occupancy;
}

/**
 * Build flat occupancy array for experience bookings.
 *
 * API expects: [{first_name, last_name, birth_date, gender}] — no room grouping.
 *
 * @param array $guests_data Parsed guests
 * @return array Flat participant array for API
 */
function fn_sphinx_holidays_build_flat_occupancy(array $guests_data): array
{
    $occupancy = [];
    foreach ($guests_data as $guest) {
        $occupancy[] = [
            'first_name' => $guest['first_name'] ?? '',
            'last_name'  => $guest['last_name'] ?? '',
            'birth_date' => $guest['birth_date'] ?? '',
            'gender'     => $guest['gender'] ?? 'm',
        ];
    }
    return $occupancy;
}

/**
 * Send admin email alert when a Sphinx booking fails.
 *
 * Uses CS-Cart's fn_send_mail() to notify the orders department.
 *
 * @param int $order_id CS-Cart order ID
 * @param int $booking_id Sphinx booking ID
 * @param string $hotel_name Hotel name for context
 * @param string $error Error message from the API
 */
function fn_sphinx_holidays_send_booking_failure_email(int $order_id, int $booking_id, string $hotel_name, string $error): void
{
    $adminEmail = db_get_field(
        "SELECT value FROM ?:settings_objects WHERE name = 'company_orders_department'"
    );

    if (empty($adminEmail) || !is_string($adminEmail)) {
        return;
    }

    $subject = "Sphinx Booking FAILED - Order #{$order_id}";
    $body = "A Sphinx hotel booking has failed and requires attention.\n\n"
        . "Order ID: #{$order_id}\n"
        . "Booking ID: #{$booking_id}\n"
        . "Hotel: {$hotel_name}\n"
        . "Error: {$error}\n\n"
        . "The booking has been marked as 'failed'. Please review in the admin panel\n"
        . "and retry the booking if appropriate.";

    @fn_send_mail([
        'to'      => $adminEmail,
        'from'    => 'default_company_orders_department',
        'subject' => $subject,
        'body'    => $body,
    ], 'A');
}

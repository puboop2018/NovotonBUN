<?php
declare(strict_types=1);
/***************************************************************************
 *                                                                          *
 *   (c) 2024-2026 VacanteLitoral.ro                                       *
 *                                                                          *
 *   Location: app/addons/travel_core/func.php                             *
 *                                                                          *
 ***************************************************************************/

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

/**
 * Addon uninstall function.
 * Drops shared tables and cleans up language variables.
 */
function fn_travel_core_uninstall(): bool
{
    // Block uninstall if provider addons are still active
    $active_providers = [];
    $provider_addons = ['novoton_holidays', 'sphinx_holidays'];
    foreach ($provider_addons as $addon) {
        $status = db_get_field("SELECT status FROM ?:addons WHERE addon = ?s", $addon);
        if ($status === 'A') {
            $active_providers[] = $addon;
        }
    }

    if (!empty($active_providers)) {
        fn_set_notification('E', __('error'),
            'Cannot disable travel_core: the following addons depend on it: '
            . implode(', ', $active_providers)
            . '. Please disable them first.'
        );
        return false;
    }

    // Drop shared tables (order matters: aliases reference feature_map)
    db_query("DROP TABLE IF EXISTS ?:travel_api_alias");
    db_query("DROP TABLE IF EXISTS ?:travel_bookings");
    db_query("DROP TABLE IF EXISTS ?:travel_feature_map");

    // Remove language variables
    db_query("DELETE FROM ?:language_values WHERE name LIKE 'travel_core.%'");

    return true;
}

/**
 * Post-install function.
 * Seeds initial feature mapping data.
 */
function fn_travel_core_post_install(): bool
{
    fn_travel_core_seed_feature_map();
    fn_travel_core_seed_lang_vars();
    return true;
}

/**
 * Seed language variables used by shared templates and services.
 */
function fn_travel_core_seed_lang_vars(): void
{
    $lang_vars = [
        'travel_core.package' => 'Package',
        'travel_core.dates'   => 'Dates',
        'travel_core.nights'  => 'nights',
        'travel_core.room'    => 'Room',
        'travel_core.board'   => 'Meal Plan',
        'travel_core.guests'  => 'Guests',
        'travel_core.holder'  => 'Booking Holder',
        'travel_core.complete_booking' => 'Complete Booking',
        'travel_core.search_results'   => 'Search Results',
    ];

    $languages = db_get_array("SELECT lang_code FROM ?:languages WHERE status = 'A'");
    foreach ($languages as $lang) {
        foreach ($lang_vars as $name => $value) {
            db_query(
                "INSERT INTO ?:language_values (lang_code, name, value) VALUES (?s, ?s, ?s)
                 ON DUPLICATE KEY UPDATE value = value",
                $lang['lang_code'], $name, $value
            );
        }
    }
}

/**
 * Migrate existing cart sessions to include travel_booking flag.
 *
 * Existing novoton_booking products in active cart sessions won't have
 * the travel_booking flag. This one-time migration patches them so
 * travel_core hooks can process them uniformly.
 *
 * Safe to call multiple times (idempotent).
 *
 * @return int Number of cart sessions updated
 */
function fn_travel_core_migrate_booking_flags(): int
{
    // CS-Cart stores cart data serialized in ?:user_session_products
    // We need to find sessions with novoton_booking but without travel_booking
    $rows = db_get_array(
        "SELECT item_id, extra FROM ?:user_session_products WHERE extra LIKE '%novoton_booking%'"
    );

    $updated = 0;
    foreach ($rows as $row) {
        $extra = @unserialize($row['extra']);
        if (!is_array($extra)) {
            continue;
        }

        if (!empty($extra['novoton_booking']) && empty($extra['travel_booking'])) {
            $extra['travel_booking'] = true;
            db_query(
                "UPDATE ?:user_session_products SET extra = ?s WHERE item_id = ?i",
                serialize($extra), $row['item_id']
            );
            $updated++;
        }
    }

    return $updated;
}

/**
 * Seed the travel_feature_map table with canonical codes.
 * Idempotent — uses INSERT IGNORE to skip existing entries.
 */
function fn_travel_core_seed_feature_map(): void
{
    $seeds = [
        // Board/Meal plans
        ['board', 'AI',  'All Inclusive',      'All Inclusive'],
        ['board', 'UAI', 'Ultra All Inclusive', 'Ultra All Inclusive'],
        ['board', 'FB',  'Full Board',         'Pensiune completă'],
        ['board', 'HB',  'Half Board',         'Demipensiune'],
        ['board', 'BB',  'Bed & Breakfast',    'Mic dejun'],
        ['board', 'RO',  'Room Only',          'Fără masă'],
        ['board', 'SC',  'Self Catering',      'Self Catering'],

        // Room types
        ['room_type', 'SGL',   'Single Room',    'Cameră single'],
        ['room_type', 'DBL',   'Double Room',    'Cameră dublă'],
        ['room_type', 'TWIN',  'Twin Room',      'Cameră twin'],
        ['room_type', 'TRP',   'Triple Room',    'Cameră triplă'],
        ['room_type', 'QUAD',  'Quadruple Room', 'Cameră cvadruplă'],
        ['room_type', 'SUITE', 'Suite',          'Suită'],
        ['room_type', 'APT',   'Apartment',      'Apartament'],
        ['room_type', 'STUDIO','Studio',         'Studio'],

        // Star ratings
        ['stars', '1', '1 Star',  '1 Stea'],
        ['stars', '2', '2 Stars', '2 Stele'],
        ['stars', '3', '3 Stars', '3 Stele'],
        ['stars', '4', '4 Stars', '4 Stele'],
        ['stars', '5', '5 Stars', '5 Stele'],

        // Property types
        ['property_type', 'hotel',        'Hotel',         'Hotel'],
        ['property_type', 'villa',        'Villa',         'Vilă'],
        ['property_type', 'apartment',    'Apartment',     'Apartament'],
        ['property_type', 'resort',       'Resort',        'Resort'],
        ['property_type', 'hostel',       'Hostel',        'Hostel'],
        ['property_type', 'guest_house',  'Guest House',   'Pensiune'],
        ['property_type', 'chalet',       'Chalet',        'Cabană'],
        ['property_type', 'motel',        'Motel',         'Motel'],
    ];

    foreach ($seeds as [$featureType, $canonicalCode, $nameEn, $nameRo]) {
        db_query(
            "INSERT IGNORE INTO ?:travel_feature_map (feature_type, canonical_code, display_name_en, display_name_ro)
             VALUES (?s, ?s, ?s, ?s)",
            $featureType, $canonicalCode, $nameEn, $nameRo
        );
    }
}

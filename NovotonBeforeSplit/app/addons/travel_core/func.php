<?php
declare(strict_types=1);
/***************************************************************************
 *                                                                          *
 *   (c) 2024-2026 VacanteLitoral.ro                                       *
 *                                                                          *
 *   Location: app/addons/travel_core/func.php                             *
 *                                                                          *
 ***************************************************************************/

use Tygh\Addons\TravelCore\Services\TravelProviderRegistry;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

/**
 * Addon uninstall function.
 * Drops shared tables and cleans up language variables.
 */
function fn_travel_core_uninstall(): bool
{
    // Block uninstall if provider addons are still active
    $active_providers = [];
    $provider_addons = TravelProviderRegistry::KNOWN_PROVIDER_ADDONS;
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
 * Seed language variables used by settings, shared templates, and services.
 * Also populates ?:settings_descriptions for addon settings.
 */
function fn_travel_core_seed_lang_vars(): void
{
    // English language variables (settings labels + template vars)
    $lang_vars_en = [
        // Settings: section headers
        'travel_core.feature_mapping_header' => 'Feature Mapping',
        'travel_core.providers_header'       => 'Active Providers',
        'travel_core.currency_header'        => 'Currency',
        // Settings: field labels
        'travel_core.feature_id_property_rating' => 'CS-Cart Feature ID: Stars/Classification',
        'travel_core.feature_id_meals'           => 'CS-Cart Feature ID: Meal Plan',
        'travel_core.feature_id_room_type'       => 'CS-Cart Feature ID: Room Type',
        'travel_core.feature_id_property_type'   => 'CS-Cart Feature ID: Property Type',
        'travel_core.feature_id_location'        => 'CS-Cart Feature ID: Location',
        'travel_core.active_providers'           => 'Active providers (comma-separated)',
        'travel_core.default_currency'           => 'Default currency',
        // Template/service language vars
        'travel_core.package'           => 'Package',
        'travel_core.dates'             => 'Dates',
        'travel_core.nights'            => 'Nights',
        'travel_core.room'              => 'Room',
        'travel_core.board'             => 'Meal Plan',
        'travel_core.guests'            => 'Guests',
        'travel_core.holder'            => 'Booking Holder',
        'travel_core.complete_booking'  => 'Booking Details',
        'travel_core.search_results'    => 'Search Results',
        'travel_core.manage_bookings'   => 'Travel Bookings',
        'travel_core.check_in'          => 'Check-in',
        'travel_core.check_out'         => 'Check-out',
        'travel_core.until'             => 'until',
        'travel_core.free_cancellation' => 'Free Cancellation',
        'travel_core.free_cancellation_until' => 'Free cancellation until',
        'travel_core.on_booking'        => 'on booking',
    ];

    $lang_vars_ro = [
        // Settings: section headers
        'travel_core.feature_mapping_header' => 'Mapare Caracteristici',
        'travel_core.providers_header'       => 'Furnizori Activi',
        'travel_core.currency_header'        => 'Monedă',
        // Settings: field labels
        'travel_core.feature_id_property_rating' => 'ID Caracteristică CS-Cart: Stele/Clasificare',
        'travel_core.feature_id_meals'           => 'ID Caracteristică CS-Cart: Masă',
        'travel_core.feature_id_room_type'       => 'ID Caracteristică CS-Cart: Tip Cameră',
        'travel_core.feature_id_property_type'   => 'ID Caracteristică CS-Cart: Tip Proprietate',
        'travel_core.feature_id_location'        => 'ID Caracteristică CS-Cart: Locație',
        'travel_core.active_providers'           => 'Furnizori activi (separați prin virgulă)',
        'travel_core.default_currency'           => 'Monedă implicită',
        // Template/service language vars
        'travel_core.package'           => 'Pachet',
        'travel_core.dates'             => 'Date',
        'travel_core.nights'            => 'Nopți',
        'travel_core.room'              => 'Cameră',
        'travel_core.board'             => 'Masă',
        'travel_core.guests'            => 'Oaspeți',
        'travel_core.holder'            => 'Titular Rezervare',
        'travel_core.complete_booking'  => 'Detalii Rezervare',
        'travel_core.search_results'    => 'Rezultate Căutare',
        'travel_core.manage_bookings'   => 'Rezervări Turism',
        'travel_core.check_in'          => 'Check-in',
        'travel_core.check_out'         => 'Check-out',
        'travel_core.until'             => 'până la',
        'travel_core.free_cancellation' => 'Anulare gratuită',
        'travel_core.free_cancellation_until' => 'Anulare gratuită până la',
        'travel_core.on_booking'        => 'la rezervare',
    ];

    $translations = ['en' => $lang_vars_en, 'ro' => $lang_vars_ro];

    // Insert language variables into ?:language_values
    $languages = db_get_array("SELECT lang_code FROM ?:languages WHERE status = 'A'");
    foreach ($languages as $lang) {
        $code = $lang['lang_code'];
        $vars = $translations[$code] ?? $lang_vars_en; // Fall back to English
        foreach ($vars as $name => $value) {
            db_query(
                "INSERT INTO ?:language_values (lang_code, name, value) VALUES (?s, ?s, ?s)
                 ON DUPLICATE KEY UPDATE value = ?s",
                $code, $name, $value, $value
            );
        }
    }

    // Populate ?:settings_descriptions for addon settings
    fn_travel_core_sync_settings_descriptions();
}

/**
 * Sync settings descriptions from ?:language_values into ?:settings_descriptions.
 * This ensures settings labels display correctly in the admin panel.
 */
function fn_travel_core_sync_settings_descriptions(): void
{
    // Get all settings objects for this addon
    $settings = db_get_array(
        "SELECT object_id, name, object_type FROM ?:settings_objects WHERE addon = 'travel_core'"
    );

    // Get all sections for this addon
    $sections = db_get_array(
        "SELECT section_id, name FROM ?:settings_sections WHERE addon = 'travel_core'"
    );

    $languages = db_get_array("SELECT lang_code FROM ?:languages WHERE status = 'A'");

    // Update descriptions for settings items (type 'S' for settings, 'H' for headers)
    foreach ($settings as $setting) {
        $lang_var_name = 'travel_core.' . $setting['name'];
        foreach ($languages as $lang) {
            $value = db_get_field(
                "SELECT value FROM ?:language_values WHERE name = ?s AND lang_code = ?s",
                $lang_var_name, $lang['lang_code']
            );
            if (!empty($value)) {
                db_query(
                    "INSERT INTO ?:settings_descriptions (object_id, object_type, description, lang_code)
                     VALUES (?i, ?s, ?s, ?s)
                     ON DUPLICATE KEY UPDATE description = ?s",
                    $setting['object_id'], $setting['object_type'], $value, $lang['lang_code'], $value
                );
            }
        }
    }

    // Update descriptions for sections
    foreach ($sections as $section) {
        $lang_var_name = 'travel_core.' . $section['name'];
        foreach ($languages as $lang) {
            $value = db_get_field(
                "SELECT value FROM ?:language_values WHERE name = ?s AND lang_code = ?s",
                $lang_var_name, $lang['lang_code']
            );
            if (!empty($value)) {
                db_query(
                    "INSERT INTO ?:settings_descriptions (object_id, object_type, description, lang_code)
                     VALUES (?i, 'SECTION', ?s, ?s)
                     ON DUPLICATE KEY UPDATE description = ?s",
                    $section['section_id'], $value, $lang['lang_code'], $value
                );
            }
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
        $extra = unserialize($row['extra'], ['allowed_classes' => false]);
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

    if ($updated > 0) {
        fn_log_event('general', 'runtime', "travel_core: migrated $updated cart session(s) from novoton_booking to travel_booking flag");
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

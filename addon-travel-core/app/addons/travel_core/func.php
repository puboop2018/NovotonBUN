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
    // Drop shared tables
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
    return true;
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

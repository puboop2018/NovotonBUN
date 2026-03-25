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
    // Hardcoded to avoid autoloader dependency (init.php may not have run)
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
 * Seeds feature mapping data. Settings and their labels are handled
 * by addon.xml <settings> and PO files (same approach as novoton_holidays).
 */
function fn_travel_core_post_install(): bool
{
    fn_travel_core_seed_feature_map();
    return true;
}

/**
 * Build a dropdown list of all CS-Cart product features for addon settings.
 * Used by fn_settings_variants_addons_travel_core_feature_id_*() functions.
 *
 * @return array<int, string> feature_id => "Description #id (Type)"
 */
function fn_travel_core_get_feature_variants(): array
{
    $features = db_get_array(
        "SELECT f.feature_id, f.feature_type, fd.description
         FROM ?:product_features f
         LEFT JOIN ?:product_features_descriptions fd ON f.feature_id = fd.feature_id AND fd.lang_code = ?s
         ORDER BY fd.description",
        DESCR_SL
    );

    $result = [0 => '-- ' . __('none') . ' --'];
    foreach ($features as $f) {
        $typeLabel = match ($f['feature_type']) {
            'M' => 'Multi',
            'S' => 'Select',
            'C' => 'Checkbox',
            'T' => 'Text',
            'N' => 'Number',
            'O' => 'Date',
            default => $f['feature_type'],
        };
        $result[$f['feature_id']] = ($f['description'] ?: 'Feature') . " #{$f['feature_id']} ({$typeLabel})";
    }

    return $result;
}

// CS-Cart auto-discovers these by naming convention for <type>selectbox</type> settings
function fn_settings_variants_addons_travel_core_feature_id_property_rating(): array { return fn_travel_core_get_feature_variants(); }
function fn_settings_variants_addons_travel_core_feature_id_meals(): array { return fn_travel_core_get_feature_variants(); }
function fn_settings_variants_addons_travel_core_feature_id_room_type(): array { return fn_travel_core_get_feature_variants(); }
function fn_settings_variants_addons_travel_core_feature_id_property_type(): array { return fn_travel_core_get_feature_variants(); }
function fn_settings_variants_addons_travel_core_feature_id_location(): array { return fn_travel_core_get_feature_variants(); }
function fn_settings_variants_addons_travel_core_feature_id_region(): array { return fn_travel_core_get_feature_variants(); }
function fn_settings_variants_addons_travel_core_feature_id_city(): array { return fn_travel_core_get_feature_variants(); }
function fn_settings_variants_addons_travel_core_feature_id_travel_group(): array { return fn_travel_core_get_feature_variants(); }

/**
 * Variants function for the default_currency addon setting.
 * Pulls currencies from CS-Cart's configured currencies.
 */
function fn_settings_variants_addons_travel_core_default_currency(): array
{
    $currencies = \Tygh\Registry::get('currencies');
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
        ['property_type', 'hotel',          'Hotel',          'Hotel'],
        ['property_type', 'villa',          'Villa',          'Vilă'],
        ['property_type', 'apartment',      'Apartment',      'Apartament'],
        ['property_type', 'resort',         'Resort',         'Resort'],
        ['property_type', 'hostel',         'Hostel',         'Hostel'],
        ['property_type', 'guest_house',    'Guest House',    'Pensiune'],
        ['property_type', 'chalet',         'Chalet',         'Cabană'],
        ['property_type', 'motel',          'Motel',          'Motel'],
        ['property_type', 'boarding_house', 'Boarding House', 'Pensiune'],
        ['property_type', 'cabin',          'Cabin',          'Cabană'],

        // Travel group — target audience / travel style
        ['travel_group', 'adults_only',     'Adults Only',      'Exclusiv pentru adulți'],
        ['travel_group', 'family_friendly', 'Family Friendly',  'Potrivit pentru familii'],

        // Facilities — canonical codes shared across providers.
        // Each row can have its own cscart_feature_id (admin-assigned via UI).
        // Food & Drink
        ['facility', 'kids_menu',           "Kid's Menu",               'Meniu masă pentru copii'],
        ['facility', 'water_bottle',        'Bottle of Water',          'Sticlă de apă'],
        ['facility', 'fruits',              'Fruits',                   'Fructe'],
        ['facility', 'restaurant',          'Restaurant',               'Restaurant'],
        ['facility', 'restaurant_alacarte', 'Restaurant (à la carte)',  'Restaurant (à la carte)'],
        ['facility', 'bar',                 'Bar',                      'Bar'],
        ['facility', 'packed_lunch',        'Packed Lunch',             'Prânz la pachet'],
        ['facility', 'special_diet',        'Special Diet Menus',       'Meniuri cu dietă specială'],
        ['facility', 'room_service',        'Room Service',             'Room service'],
        ['facility', 'breakfast_in_room',   'Breakfast in Room',        'Mic dejun în cameră'],
        // Wellness & Recreation
        ['facility', 'spa',                 'Spa Facilities',           'Facilități spa'],
        ['facility', 'fitness',             'Fitness Centre',           'Fitness'],
        ['facility', 'pool',                'Swimming Pool',            'Piscină'],
        ['facility', 'massage',             'Massage',                  'Masaj'],
        ['facility', 'casino',              'Casino',                   'Cazino'],
        ['facility', 'full_body_massage',   'Full Body Massage',        'Masaj pentru tot corpul'],
        ['facility', 'ski',                 'Skiing',                   'Schi'],
        ['facility', 'hiking',              'Hiking',                   'Drumeții'],
        ['facility', 'squash',              'Squash',                   'Squash'],
        ['facility', 'cycling',             'Cycling',                  'Ciclism'],
        ['facility', 'bowling',             'Bowling',                  'Bowling'],
        ['facility', 'game_room',           'Game Room',                'Cameră de jocuri'],
        ['facility', 'aqua_park',           'Aqua Park',                'Aqua park'],
        ['facility', 'tennis',              'Tennis Court',             'Teren de tenis'],
        ['facility', 'horse_riding',        'Horse Riding',             'Călărie'],
        ['facility', 'ski_school',          'Ski School',               'Școală de schi'],
        ['facility', 'bike_rental',         'Bicycle Rental',           'Închiriere de biciclete'],
        ['facility', 'relaxation_area',     'Relaxation Area',          'Zonă de relaxare'],
        // Parking & Transport
        ['facility', 'free_parking',        'Free Parking',             'Parcare gratuită'],
        ['facility', 'secured_parking',     'Secured Parking',          'Parcare securizată'],
        ['facility', 'transfer_service',    'Transfer Service',         'Serviciu de transfer'],
        ['facility', 'airport_transfer',    'Airport Transfer',         'Transfer aeroport'],
        ['facility', 'car_rental',          'Car Rental',               'Închirieri auto'],
        ['facility', 'bike_tours',          'Bicycle Tours',            'Tururi cu bicicleta'],
        ['facility', 'walking_tours',       'Walking Tours',            'Tururi de mers pe jos'],
        ['facility', 'parking',             'Parking',                  'Parcare'],
        // Front Desk & Services
        ['facility', 'front_desk_24h',      '24-Hour Front Desk',       'Recepție non-stop'],
        ['facility', 'tour_desk',           'Tour Desk',                'Birou de turism'],
        ['facility', 'currency_exchange',   'Currency Exchange',        'Schimb valutar'],
        ['facility', 'luggage_storage',     'Luggage Storage',          'Cameră de bagaje'],
        ['facility', 'safety_deposit_box',  'Safety Deposit Box',       'Seif la recepție'],
        ['facility', 'strollers',           'Strollers',                'Cărucioare'],
        ['facility', 'dry_cleaning',        'Dry Cleaning',             'Curățătorie chimică'],
        ['facility', 'ironing_service',     'Ironing Service',          'Serviciu de călcătorie'],
        ['facility', 'laundry',             'Laundry',                  'Spălătorie'],
        ['facility', 'daily_housekeeping',  'Daily Housekeeping',       'Menaj zilnic'],
        ['facility', 'meeting_facilities',  'Meeting/Banquet Facilities','Săli de conferințe'],
        ['facility', 'business_centre',     'Business Centre',          'Business centre'],
        ['facility', 'fax',                 'Fax/Photocopying',         'Fax'],
        ['facility', 'conference_rooms',    'Conference Rooms',         'Săli de conferințe'],
        ['facility', 'wake_up_service',     'Wake-up Service',          'Serviciu de trezire'],
        ['facility', 'express_checkin',     'Express Check-in/Check-out','Check-in/check-out express'],
        ['facility', 'babysitting',         'Babysitting/Child Services','Babysitting/servicii copii'],
        ['facility', 'cafe',                'On-site Café',             'Cafenea la proprietate'],
        ['facility', 'invoice_available',   'Invoice Available',        'Factură disponibilă'],
        // Room Amenities
        ['facility', 'pets_allowed',        'Pets Allowed',             'Animale de companie permise'],
        ['facility', 'non_smoking',         'Non-smoking Throughout',   'Interzis fumatul'],
        ['facility', 'smoking_area',        'Smoking Area',             'Zonă pentru fumători'],
        ['facility', 'non_smoking_rooms',   'Non-smoking Rooms',        'Camere pentru nefumători'],
        ['facility', 'family_rooms',        'Family Rooms',             'Camere de familie'],
        ['facility', 'air_conditioning',    'Air Conditioning',         'Aer condiționat'],
        ['facility', 'heating',             'Heating',                  'Încălzire'],
        ['facility', 'free_wifi',           'Free Wi-Fi',               'Wi-Fi gratuit'],
        ['facility', 'washer',              'Washer',                   'Mașină de spălat'],
        ['facility', 'ski_storage',         'Ski Storage',              'Depozit schiuri'],
        ['facility', 'tv',                  'TV',                       'TV'],
        ['facility', 'fan',                 'Fan',                      'Ventilator'],
        ['facility', 'desk',               'Desk',                     'Birou'],
        ['facility', 'shower',              'Shower',                   'Duș'],
        ['facility', 'view',                'View',                     'Vedere'],
        ['facility', 'minibar',             'Minibar',                  'Minibar'],
        ['facility', 'toilet',              'Toilet',                   'Toaletă'],
        ['facility', 'towels',              'Towels',                   'Prosoape'],
        ['facility', 'bed_linen',           'Bed Linen',                'Lenjerie de pat'],
        ['facility', 'slippers',            'Slippers',                 'Papuci de casă'],
        ['facility', 'telephone',           'Telephone',                'Telefon'],
        ['facility', 'hair_dryer',          'Hair Dryer',               'Uscător de păr'],
        ['facility', 'alarm_clock',         'Alarm Clock',              'Ceas deșteptător'],
        ['facility', 'toilet_paper',        'Toilet Paper',             'Hârtie igienică'],
        ['facility', 'flat_screen_tv',      'Flat-screen TV',           'TV cu ecran plat'],
        ['facility', 'soundproofing',       'Soundproofing',            'Izolare fonică'],
        ['facility', 'dressing_room',       'Dressing Room',            'Dressing'],
        ['facility', 'cable_channels',      'Cable Channels',           'Canale prin cablu'],
        ['facility', 'carpet',              'Carpet',                   'Mochetă'],
        ['facility', 'free_toiletries',     'Free Toiletries',          'Articole de toaletă gratuite'],
        ['facility', 'private_bathroom',    'Private Bathroom',         'Baie privată'],
        ['facility', 'private_entrance',    'Private Entrance',         'Intrare privată'],
        ['facility', 'safe',                'In-room Safe',             'Seif'],
        ['facility', 'internet',            'Internet Services',        'Servicii de internet'],
        ['facility', 'games_puzzles',       'Games & Puzzles',          'Jocuri și puzzle-uri'],
        ['facility', 'bedside_socket',      'Socket Near Bed',          'Priză lângă pat'],
        ['facility', 'mosquito_net',        'Mosquito Net',             'Plasă de țânțari'],
        ['facility', 'fridge',              'Fridge',                   'Frigider'],
        ['facility', 'wine_champagne',      'Wine/Champagne',           'Vin/Șampanie'],
        ['facility', 'wardrobe',            'Wardrobe/Closet',          'Garderobă sau dulap'],
        ['facility', 'shared_lounge',       'Shared Lounge/TV Area',    'Lounge/cameră cu TV comună'],
        // Outdoor
        ['facility', 'outdoor_furniture',   'Outdoor Furniture',        'Mobilier exterior'],
        ['facility', 'garden',              'Garden',                   'Grădină'],
        ['facility', 'terrace',             'Terrace',                  'Terasă'],
        ['facility', 'sun_terrace',         'Sun Terrace',              'Terasă la soare'],
        // Security
        ['facility', 'security_24h',        '24-Hour Security',         'Securitate non-stop'],
        ['facility', 'soundproof_rooms',    'Soundproof Rooms',         'Camere izolate fonic'],
        ['facility', 'security_alarm',      'Security Alarm',           'Alarmă de securitate'],
        ['facility', 'fire_extinguishers',  'Fire Extinguishers',       'Extinctoare'],
        ['facility', 'co_detector',         'Carbon Monoxide Detector', 'Detector de monoxid de carbon'],
        ['facility', 'card_access',         'Card Access',              'Acces cu cardul'],
        ['facility', 'cctv_common',         'CCTV in Common Areas',     'Camere supraveghere zone comune'],
        ['facility', 'cctv_outside',        'CCTV Outside Property',    'Camere supraveghere exterior'],
        ['facility', 'smoke_alarm',         'Smoke Alarm',              'Alarmă de fum'],
        ['facility', 'key_access',          'Key Access',               'Acces cu cheia'],
        // Accessibility
        ['facility', 'disabled_access',     'Facilities for Disabled',  'Facilități pentru persoane cu dizabilități'],
        ['facility', 'stairs_only',         'Upper Floors by Stairs Only','Etaje superioare accesibile doar pe scări'],
        // Smoking Policy
        ['facility', 'no_smoking_all',      'No Smoking Everywhere',    'Fumatul interzis în toate spațiile'],
    ];

    foreach ($seeds as [$featureType, $canonicalCode, $nameEn, $nameRo]) {
        db_query(
            "INSERT IGNORE INTO ?:travel_feature_map (feature_type, canonical_code, display_name_en, display_name_ro)
             VALUES (?s, ?s, ?s, ?s)",
            $featureType, $canonicalCode, $nameEn, $nameRo
        );
    }
}

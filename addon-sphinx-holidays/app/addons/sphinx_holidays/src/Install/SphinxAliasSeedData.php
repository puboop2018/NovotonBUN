<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Install;

/**
 * Static alias seed data for fn_sphinx_holidays_seed_aliases() —
 * provider free-text / numeric IDs mapped to travel_core canonical
 * feature codes. Pure data (the seeding logic stays in func.php);
 * extracted so the 1,600-line func.php god file sheds its largest
 * inert block (enforced by GodFileRatchetTest).
 */
final class SphinxAliasSeedData
{
    /**
     * Board/meal-plan free text => canonical board code (exact match).
     *
     * @return array<int|string, string>
     */
    public static function boardAliases(): array
    {
        return [
            // Romanian free-text
            'All Inclusive' => 'AI',
            'All Inclusive Light' => 'AIL',
            'All Inclusive Soft' => 'AIL',
            'ALL INCLUSIVE PLUS' => 'AI',
            'Ultra All Inclusive' => 'UAI',
            'Pensiune completa' => 'FB',
            'Pensiune completă' => 'FB',
            'Full Board' => 'FB',
            'Demipensiune' => 'HB',
            'Half Board' => 'HB',
            'Mic dejun' => 'BB',
            'Bed & Breakfast' => 'BB',
            'Bed and Breakfast' => 'BB',
            'Fara masa' => 'RO',
            'Fără masă' => 'RO',
            'Room Only' => 'RO',
            'ROOM ONLY' => 'RO',
            'RO' => 'RO',
            'Self Catering' => 'SC',
            'SELF CATERING' => 'SC',
            'FULL BOARD' => 'FB',
            'HALF BOARD' => 'HB',
            'BED AND BREAKFAST' => 'BB',
            'BUFFET BREAKFAST' => 'BB',
            'ULTRA ALL INCLUSIVE' => 'UAI',
            'PLATINUM ALL INCLUSIVE' => 'AI',
        ];
    }

    /**
     * Room-type names => canonical room code (prefix match).
     *
     * @return array<int|string, string>
     */
    public static function roomAliases(): array
    {
        return [
            'Single Room' => 'SGL',
            'Double Room' => 'DBL',
            'Twin Room' => 'TWIN',
            'Triple Room' => 'TRP',
            'Quadruple' => 'QUAD',
            'Suite' => 'SUITE',
            'Apartment' => 'APT',
            'Studio' => 'STUDIO',
            'Family Room' => 'DBL',
        ];
    }

    /**
     * Star-rating variants => canonical 1-5 (exact match).
     *
     * @return array<int|string, string>
     */
    public static function starAliases(): array
    {
        return [
            '1' => '1',
            '2' => '2',
            '3' => '3',
            '4' => '4',
            '5' => '5',
            '1 star' => '1',
            '2 star' => '2',
            '2 stars' => '2',
            '3 star' => '3',
            '3 stars' => '3',
            '4 star' => '4',
            '4 stars' => '4',
            '5 star' => '5',
            '5 stars' => '5',
        ];
    }

    /**
     * Property-type variants => canonical type (exact match).
     *
     * @return array<int|string, string>
     */
    public static function propertyTypeAliases(): array
    {
        return [
            'hotel' => 'hotel',
            'villa' => 'villa',
            'apartment' => 'apartment',
            'resort' => 'resort',
            'hostel' => 'hostel',
            'guest_house' => 'guest_house',
            'guesthouse' => 'guest_house',
            'pension' => 'guest_house',
            'pensiune' => 'guest_house',
            'chalet' => 'chalet',
            'cabana' => 'chalet',
            'motel' => 'motel',
        ];
    }

    /**
     * Provider facility IDs => property-level amenity codes.
     *
     * @return array<int|string, string>
     */
    public static function hotelFacilityAliases(): array
    {
        return [
            // Food & Drink
            '1' => 'kids_menu',
            '4' => 'water_bottle',
            '5' => 'fruits',
            '8' => 'restaurant',
            '9' => 'restaurant_alacarte',
            '11' => 'bar',
            '14' => 'packed_lunch',
            '17' => 'special_diet',
            '18' => 'room_service',
            '19' => 'breakfast_in_room',
            // Wellness & Recreation
            '25' => 'spa',
            '31' => 'fitness',
            '37' => 'pool',
            '44' => 'massage',
            '86' => 'casino',
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
            '47' => 'free_parking',
            '48' => 'secured_parking',
            '54' => 'transfer_service',
            '57' => 'airport_transfer',
            '142' => 'car_rental',
            '143' => 'bike_tours',
            '148' => 'walking_tours',
            '199' => 'parking',
            // Front Desk & Services
            '59' => 'front_desk_24h',
            '64' => 'tour_desk',
            '65' => 'currency_exchange',
            '68' => 'luggage_storage',
            '70' => 'safety_deposit_box',
            '94' => 'strollers',
            '98' => 'dry_cleaning',
            '99' => 'ironing_service',
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
            '72' => 'outdoor_furniture',
            '75' => 'garden',
            '76' => 'terrace',
            '77' => 'sun_terrace',
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
    }

    /**
     * Provider facility IDs => in-room amenity codes.
     *
     * @return array<int|string, string>
     */
    public static function roomFacilityAliases(): array
    {
        return [
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
    }

    /**
     * Beach & location amenity IDs (none mapped yet).
     *
     * @return array<int|string, string>
     */
    public static function beachAccessAliases(): array
    {
        return [
            // No Sphinx-specific beach IDs mapped yet; add here as they appear.
        ];
    }
}

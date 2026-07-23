<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Install;

/**
 * Static alias seed data for fn_novoton_holidays_seed_travel_aliases() —
 * provider free-text mapped to travel_core canonical feature codes.
 * Pure data (the seeding logic stays in functions/install.php);
 * novoton's twin of sphinx's Install\SphinxAliasSeedData, extracted so
 * the install god file sheds its largest inert block (enforced by
 * GodFileRatchetTest).
 */
final class NovotonAliasSeedData
{
    /**
     * Board/meal-plan free text => canonical board code (exact match).
     *
     * @return array<int|string, string>
     */
    public static function boardAliases(): array
    {
        return [
            'AI' => 'AI',
            'AIL' => 'AIL',
            'ALL INCL' => 'AI',
            'ALL INCLUSIVE' => 'AI',
            'ALL INCLUSIVE LIGHT' => 'AIL',
            'ALL INCLUSIVE SOFT' => 'AIL',
            'ALLINC' => 'AI',
            'UAI' => 'UAI',
            'ULTRA ALL INCL' => 'UAI',
            'ULTRA ALL INCLUSIVE' => 'UAI',
            'FB' => 'FB',
            'FULL BOARD' => 'FB',
            'FB+' => 'FB',
            'HB' => 'HB',
            'HALF BOARD' => 'HB',
            'HB+' => 'HB',
            'BB' => 'BB',
            'BED AND BREAKFAST' => 'BB',
            'B&B' => 'BB',
            'RO' => 'RO',
            'ROOM ONLY' => 'RO',
            'SC' => 'SC',
            'SELF CATERING' => 'SC',
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
            'SGL' => 'SGL',
            'DBL' => 'DBL',
            'TWIN' => 'TWIN',
            'TRP' => 'TRP',
            'QUAD' => 'QUAD',
            'SUITE' => 'SUITE',
            'APT' => 'APT',
            'STUDIO' => 'STUDIO',
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
            'chalet' => 'chalet',
            'motel' => 'motel',
            'boarding_house' => 'boarding_house',
            'cabin' => 'cabin',
        ];
    }

    /**
     * Provider facility labels => property-level amenity codes.
     *
     * @return array<int|string, string>
     */
    public static function hotelFacilityAliases(): array
    {
        return [
            '2' => 'free_parking',          // Parking
            '3' => 'pets_allowed',          // Pets
            '6' => 'entertainment',         // Entertainment
            '7' => 'pool',                  // Outdoor swimming pool
            '8' => 'pool',                  // Indoor swimming pool
            '9' => 'aqua_park',             // Own aquapark
            '10' => 'spa',                   // SPA Center
            '11' => 'sauna',                 // Sauna
            '12' => 'fitness',               // Fitness
            '13' => 'balneology',            // Balneology
            '15' => 'terrace',              // Terrace/Balcony
            '18' => 'kids_club',            // Kids Club
            '19' => 'kids_menu',            // Childrens menu
            '20' => 'kids_pool',            // Childrens pool
            '21' => 'playground',           // Playground
            '23' => 'disabled_access',       // Suitable for people with disabilities
            '25' => 'ski_lift_transfer',     // Transport to the lift
            '26' => 'family_rooms',          // Suitable for families with children
            // 27 = All Inclusive — skipped (board type, not a facility)
            '29' => 'ev_charger',            // Electric Car Charger
            '30' => 'travel_sustainable',    // Travel Sustainable
        ];
    }

    /**
     * Provider facility labels => in-room amenity codes.
     *
     * @return array<int|string, string>
     */
    public static function roomFacilityAliases(): array
    {
        return [
            '4' => 'free_wifi',             // Wi-Fi
            '5' => 'ski_storage',           // ski wardrobe
            '14' => 'kitchenette',           // Kitchenette
            '16' => 'air_conditioning',      // Air conditioning/Heating
            '17' => 'bathtub',              // Bathtub
            '22' => 'baby_crib',            // Baby crib
        ];
    }

    /**
     * Beach & location amenity labels.
     *
     * @return array<int|string, string>
     */
    public static function beachAccessAliases(): array
    {
        return [
            '1' => 'free_beach_equipment',  // Free umbrella and sunbed
            '24' => 'beach_bar',            // Beach bar
            '28' => 'blue_flag_beach',       // Blue Flag beach
            '31' => 'first_line',            // First line
        ];
    }
}

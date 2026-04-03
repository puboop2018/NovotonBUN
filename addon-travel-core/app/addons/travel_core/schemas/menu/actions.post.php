<?php
/***************************************************************************
 *                                                                          *
 *   Travel Core - Menu Actions Schema                                      *
 *   Registers admin tab navigation for travel_core pages                   *
 *                                                                          *
 ***************************************************************************/

defined('BOOTSTRAP') or die('Access denied');

/** @var array $schema */

// Shared tab set for all travel_core admin pages
$tabs = [
    'travel_bookings' => [
        'href'     => 'travel_bookings.manage',
        'text'     => __('travel_core.manage_bookings'),
        'position' => 100,
    ],
    'travel_feature_mappings' => [
        'href'     => 'travel_feature_mappings.manage',
        'text'     => __('travel_core.feature_mappings'),
        'position' => 200,
    ],
    'travel_booking_styles' => [
        'href'     => 'travel_booking_styles.manage',
        'text'     => __('travel_core.appearance_settings'),
        'position' => 250,
    ],
    'travel_tools' => [
        'href'     => 'travel_tools.manage',
        'text'     => __('travel_core.tools_and_cron'),
        'position' => 300,
    ],
];

// Apply same tabs to all travel_core pages (DRY)
$pages = [
    'travel_bookings.manage',
    'travel_bookings.view',
    'travel_feature_mappings.manage',
    'travel_feature_mappings.edit',
    'travel_booking_styles.manage',
    'travel_tools.manage',
];

foreach ($pages as $page) {
    foreach ($tabs as $key => $tab) {
        $schema[$page][$key] = $tab;
    }
}

return $schema;

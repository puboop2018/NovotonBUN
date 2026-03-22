<?php
/***************************************************************************
 *                                                                          *
 *   Travel Core - Admin Sidebar Menu                                       *
 *   Adds "Travel" section to the CS-Cart admin navigation panel.           *
 *                                                                          *
 ***************************************************************************/

defined('BOOTSTRAP') or die('Access denied');

/** @var array $schema */

$schema['central']['travel_core'] = [
    'title' => __('travel_core.admin_menu_travel'),
    'position' => 900,
    'items' => [
        'travel_bookings' => [
            'attrs' => ['class' => 'is-addon'],
            'href' => 'travel_bookings.manage',
            'position' => 100,
            'title' => __('travel_core.manage_bookings'),
        ],
        'travel_feature_mappings' => [
            'attrs' => ['class' => 'is-addon'],
            'href' => 'travel_feature_mappings.manage',
            'position' => 200,
            'title' => __('travel_core.feature_mappings'),
        ],
        'travel_tools' => [
            'attrs' => ['class' => 'is-addon'],
            'href' => 'travel_tools.manage',
            'position' => 300,
            'title' => __('travel_core.tools_and_cron'),
        ],
    ],
];

return $schema;

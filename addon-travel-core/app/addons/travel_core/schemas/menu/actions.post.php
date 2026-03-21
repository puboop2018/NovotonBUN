<?php
/***************************************************************************
 *                                                                          *
 *   Travel Core - Menu Actions Schema                                      *
 *   Registers admin menu items for feature mappings                        *
 *                                                                          *
 ***************************************************************************/

defined('BOOTSTRAP') or die('Access denied');

/** @var array $schema */

$schema['travel_bookings.manage'] = [
    'href'     => 'travel_bookings.manage',
    'alt'      => 'travel_bookings.view',
    'text'     => __('travel_core.manage_bookings'),
    'position' => 490,
];

$schema['travel_feature_mappings.manage'] = [
    'href'     => 'travel_feature_mappings.manage',
    'alt'      => 'travel_feature_mappings.edit',
    'text'     => __('travel_core.feature_mappings'),
    'position' => 500,
];

$schema['travel_tools.manage'] = [
    'href'     => 'travel_tools.manage',
    'text'     => __('travel_core.tools_and_cron'),
    'position' => 520,
];

return $schema;

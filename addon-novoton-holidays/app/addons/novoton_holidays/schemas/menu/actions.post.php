<?php
/***************************************************************************
 *                                                                          *
 *   This is an addon for CS-Cart / Multi-Vendor                            *
 *   Copyright (c) Novoton Holidays                                         *
 *                                                                          *
 ***************************************************************************/

defined('BOOTSTRAP') or die('Access denied');

/** @var array $schema */

$schema['novoton_holidays.list_facilities']['novoton_dashboard'] = [
    'href'     => 'novoton_holidays.manage',
    'text'     => __('novoton_holidays.actions.novoton_dashboard'),
    'position' => 50,
];

$schema['novoton_holidays.list_facilities']['list_facilities'] = [
    'href'     => 'novoton_holidays.list_facilities',
    'text'     => __('novoton_holidays.actions.import_facilities'),
    'position' => 200,
];

return $schema;

<?php
/***************************************************************************
 *                                                                          *
 *   Sphinx Holidays - Admin menu action links                             *
 *                                                                          *
 ***************************************************************************/

defined('BOOTSTRAP') or die('Access denied');

/** @var array $schema */

// Dashboard page tabs
$schema['sphinx_holidays.manage']['sphinx_dashboard'] = [
    'href'     => 'sphinx_holidays.manage',
    'text'     => __('sphinx_holidays.sphinx_dashboard'),
    'position' => 50,
];

$schema['sphinx_holidays.manage']['sphinx_destinations'] = [
    'href'     => 'sphinx_holidays.destinations',
    'text'     => __('sphinx_holidays.destinations'),
    'position' => 100,
];

// Destinations page tabs
$schema['sphinx_holidays.destinations']['sphinx_dashboard'] = [
    'href'     => 'sphinx_holidays.manage',
    'text'     => __('sphinx_holidays.sphinx_dashboard'),
    'position' => 50,
];

$schema['sphinx_holidays.destinations']['sphinx_destinations'] = [
    'href'     => 'sphinx_holidays.destinations',
    'text'     => __('sphinx_holidays.destinations'),
    'position' => 100,
];

return $schema;

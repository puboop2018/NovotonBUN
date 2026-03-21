<?php
/***************************************************************************
 *                                                                          *
 *   Sphinx Holidays - Admin menu action links                             *
 *                                                                          *
 ***************************************************************************/

defined('BOOTSTRAP') or die('Access denied');

/** @var array $schema */

// Shared tab set for all sphinx_holidays pages
$tabs = [
    'sphinx_dashboard' => [
        'href'     => 'sphinx_holidays.manage',
        'text'     => __('sphinx_holidays.sphinx_dashboard'),
        'position' => 50,
    ],
    'sphinx_destinations' => [
        'href'     => 'sphinx_holidays.destinations',
        'text'     => __('sphinx_holidays.destinations'),
        'position' => 100,
    ],
    'sphinx_hotels' => [
        'href'     => 'sphinx_holidays.hotels',
        'text'     => __('sphinx_holidays.hotels'),
        'position' => 150,
    ],
    'sphinx_whitelist' => [
        'href'     => 'sphinx_holidays.whitelist',
        'text'     => __('sphinx_holidays.destination_whitelist'),
        'position' => 200,
    ],
];

// Apply same tabs to all pages (DRY)
$pages = ['sphinx_holidays.manage', 'sphinx_holidays.destinations', 'sphinx_holidays.hotels', 'sphinx_holidays.whitelist'];
foreach ($pages as $page) {
    foreach ($tabs as $key => $tab) {
        $schema[$page][$key] = $tab;
    }
}

return $schema;

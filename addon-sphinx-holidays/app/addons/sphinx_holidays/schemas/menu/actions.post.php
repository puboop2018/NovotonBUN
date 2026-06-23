<?php
/***************************************************************************
 *                                                                          *
 *   Sphinx Holidays - Admin menu action links                             *
 *                                                                          *
 ***************************************************************************/

defined('BOOTSTRAP') or die('Access denied');

/** @var array<string, mixed> $schema */

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
    'sphinx_seo_templates' => [
        'href'     => 'sphinx_seo_templates.manage',
        'text'     => __('travel_core.seo_templates'),
        'position' => 250,
    ],
    'sphinx_settings' => [
        'href'     => 'addons.update&addon=sphinx_holidays&selected_section=settings',
        'text'     => __('sphinx_holidays.addon_settings'),
        'position' => 900,
    ],
];

// Apply same tabs to all pages (DRY)
$pages = [
    'sphinx_holidays.manage',
    'sphinx_holidays.destinations',
    'sphinx_holidays.hotels',
    'sphinx_holidays.whitelist',
    'sphinx_seo_templates.manage',
];
foreach ($pages as $page) {
    $existing = isset($schema[$page]) && is_array($schema[$page]) ? $schema[$page] : [];
    $schema[$page] = array_merge($existing, $tabs);
}

return $schema;

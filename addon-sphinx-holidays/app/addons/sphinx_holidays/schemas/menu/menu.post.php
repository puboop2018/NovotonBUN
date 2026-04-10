<?php
/***************************************************************************
 *                                                                          *
 *   Sphinx Holidays — Admin Sidebar Menu                                   *
 *   Adds a Sphinx section to the CS-Cart admin navigation panel.           *
 *                                                                          *
 ***************************************************************************/

defined('BOOTSTRAP') or die('Access denied');

/** @var array $schema */

$schema['central']['sphinx_holidays'] = [
    'title' => __('sphinx_holidays.addon_name', ['[default]' => 'Sphinx Holidays']),
    'position' => 920,
    'items' => [
        'sphinx_dashboard' => [
            'attrs' => ['class' => 'is-addon'],
            'href' => 'sphinx_holidays.manage',
            'position' => 100,
            'title' => __('sphinx_holidays.sphinx_dashboard', ['[default]' => 'Dashboard']),
        ],
        'sphinx_destinations' => [
            'attrs' => ['class' => 'is-addon'],
            'href' => 'sphinx_holidays.destinations',
            'position' => 200,
            'title' => __('sphinx_holidays.destinations', ['[default]' => 'Destinations']),
        ],
        'sphinx_hotels' => [
            'attrs' => ['class' => 'is-addon'],
            'href' => 'sphinx_holidays.hotels',
            'position' => 300,
            'title' => __('sphinx_holidays.hotels', ['[default]' => 'Hotels']),
        ],
        'sphinx_whitelist' => [
            'attrs' => ['class' => 'is-addon'],
            'href' => 'sphinx_holidays.whitelist',
            'position' => 350,
            'title' => __('sphinx_holidays.destination_whitelist', ['[default]' => 'Destination whitelist']),
        ],
        'sphinx_seo_templates' => [
            'attrs' => ['class' => 'is-addon'],
            'href' => 'sphinx_seo_templates.manage',
            'position' => 400,
            'title' => __('travel_core.seo_templates', ['[default]' => 'SEO Templates']),
        ],
    ],
];

return $schema;

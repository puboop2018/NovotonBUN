<?php
/**
 * Novoton Holidays - Theme Editor Schema Extension
 * Path: app/addons/novoton_holidays/schemas/theme_editor/schema.post.php
 *
 * Extends the CS-Cart Visual Editor (Theme Editor) with addon-specific
 * color settings. The LESS variables defined here are saved into the
 * active preset file (styles/data/PRESET.less) and compiled last,
 * overriding the defaults declared in the addon LESS.
 *
 * @package NovotonHolidays
 * @since   3.1.0
 */

if (!defined('BOOTSTRAP')) { die('Access denied'); }

// Ensure $schema is an array
if (!is_array($schema)) {
    $schema = [];
}
if (!isset($schema['sections']) || !is_array($schema['sections'])) {
    $schema['sections'] = [];
}

// -------------------------------------------------------------------------
// Section: Novoton Holidays
// -------------------------------------------------------------------------
$schema['sections']['novoton_holidays'] = [
    'title' => 'Novoton Holidays',
    'fields' => [
        // Search button background
        'novoton-search-btn-bg' => [
            'title' => 'Search button',
            'description' => 'Background color of the booking search button',
            'type'  => 'color',
            'default' => '#006ce4',
        ],
        // Search button hover
        'novoton-search-btn-hover' => [
            'title' => 'Search button (hover)',
            'description' => 'Hover color of the booking search button',
            'type'  => 'color',
            'default' => '#0057b8',
        ],
        // Primary brand color
        'novoton-primary' => [
            'title' => 'Primary color',
            'description' => 'Main brand color used for headings and accents',
            'type'  => 'color',
            'default' => '#003580',
        ],
        // Accent / yellow highlight
        'novoton-accent' => [
            'title' => 'Accent color',
            'description' => 'Highlight color for form borders and badges',
            'type'  => 'color',
            'default' => '#febb02',
        ],
    ],
];

return $schema;

<?php
/**
 * Novoton Holidays - Theme Editor Schema Extension
 *
 * Adds addon-specific color, font, and background fields to the CS-Cart
 * Theme Editor. Each field name becomes a LESS variable (@field_name)
 * that can be used in the addon's styles.less.
 *
 * @package NovotonHolidays
 */

// Colors
$schema['colors']['fields']['novoton-primary'] = [
    'description' => 'theme_editor.novoton_primary_color',
];

$schema['colors']['fields']['novoton-accent'] = [
    'description' => 'theme_editor.novoton_accent_color',
];

$schema['colors']['fields']['novoton-search-btn-bg'] = [
    'description' => 'theme_editor.novoton_search_btn_color',
];

$schema['colors']['fields']['novoton-search-btn-hover'] = [
    'description' => 'theme_editor.novoton_search_btn_hover_color',
];

$schema['colors']['fields']['novoton-text'] = [
    'description' => 'theme_editor.novoton_text_color',
];

$schema['colors']['fields']['novoton-bg'] = [
    'description' => 'theme_editor.novoton_bg_color',
];

$schema['colors']['fields']['novoton-border'] = [
    'description' => 'theme_editor.novoton_border_color',
];

$schema['colors']['fields']['novoton-success'] = [
    'description' => 'theme_editor.novoton_success_color',
];

$schema['colors']['fields']['novoton-danger'] = [
    'description' => 'theme_editor.novoton_danger_color',
];

// Fonts
$schema['fonts']['fields']['novoton-font-family'] = [
    'description' => 'theme_editor.novoton_base_font',
    'properties'  => [
        'size' => [
            'match'  => 'novoton-font-size-base-value',
            'unit'   => 'px',
            'values' => [11, 12, 13, 14, 15, 16],
        ],
        'style' => [
            'B' => [
                'match'    => 'novoton-font-weight',
                'property' => 'font-weight',
                'default'  => 'normal',
            ],
        ],
    ],
];

// Backgrounds
$schema['backgrounds']['fields']['novoton-page-bg'] = [
    'description' => 'theme_editor.novoton_page_background',
    'properties'  => [
        'color' => [
            'enable' => true,
            'match'  => 'novoton-bg',
        ],
        'pattern' => 'novoton-bg-pattern',
        'repeat'  => 'novoton-bg-repeat',
    ],
    'transparent' => [
        'match' => 'novoton-bg-transparent',
    ],
];

return $schema;

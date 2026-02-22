<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Theme Editor Schema Extension
 * Path: app/addons/novoton_holidays/schemas/theme_editor/schema.post.php
 *
 * Adds addon color fields to the CS-Cart Theme Editor "Colors" section.
 * Each field name becomes a LESS variable (@field_name) that can be
 * used in the addon's styles.less.
 *
 * CS-Cart Theme Editor schema uses 4 top-level keys:
 *   general, colors, fonts, backgrounds
 *
 * @see https://docs.cs-cart.com/latest/developer_guide/addons/tutorials/theme_editor.html
 * @package NovotonHolidays
 * @since   3.1.0
 */

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

// Ensure colors section exists
if (!isset($schema['colors'])) {
    $schema['colors'] = [];
}
if (!isset($schema['colors']['fields'])) {
    $schema['colors']['fields'] = [];
}

// Novoton Holidays color fields
// Variable names become @variable_name in LESS files

// Brand colors
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

// UI colors
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

return $schema;

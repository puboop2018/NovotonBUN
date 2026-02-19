<?php
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

if (!defined('BOOTSTRAP')) { die('Access denied'); }

// Ensure colors section exists
if (!isset($schema['colors'])) {
    $schema['colors'] = [];
}
if (!isset($schema['colors']['fields'])) {
    $schema['colors']['fields'] = [];
}

// Novoton Holidays color fields
// Variable names become @variable_name in LESS files
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

return $schema;

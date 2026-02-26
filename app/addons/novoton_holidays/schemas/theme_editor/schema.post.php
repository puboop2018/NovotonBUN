<?php
/**
 * Novoton Holidays - Theme Editor Schema Extension
 *
 * Registers only addon-specific brand colors in the CS-Cart Theme Editor.
 * Each field name becomes a LESS variable (@field_name) that overrides
 * the default in styles.less when the admin customizes the theme.
 *
 * Variables inherited from the core theme (text, bg, border, status colors,
 * fonts) are NOT registered here — they adapt automatically when the
 * merchant switches theme presets.
 *
 * @package NovotonHolidays
 */

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

<?php
/**
 * Novoton Holidays - Theme Editor Schema Extension
 *
 * Extends the CS-Cart Theme Editor "Colors" section with addon-specific
 * brand color pickers.  Each entry maps to a LESS variable (via
 * variable_name) that styles.less already defines with a default value.
 *
 * When the admin picks a colour in the Theme Editor the LESS compiler
 * overrides the default in styles.less — no core files are modified.
 *
 * Chrome / status / font colours are NOT registered here — they inherit
 * from the active theme preset variables automatically.
 *
 * @package NovotonHolidays
 */

$schema['colors']['fields']['novoton-primary'] = [
    'description'   => 'theme_editor.novoton_primary_color',
    'type'          => 'color',
    'variable_name' => 'novoton-primary',
];

$schema['colors']['fields']['novoton-accent'] = [
    'description'   => 'theme_editor.novoton_accent_color',
    'type'          => 'color',
    'variable_name' => 'novoton-accent',
];

$schema['colors']['fields']['novoton-search-btn-bg'] = [
    'description'   => 'theme_editor.novoton_search_btn_color',
    'type'          => 'color',
    'variable_name' => 'novoton-search-btn-bg',
];

$schema['colors']['fields']['novoton-search-btn-hover'] = [
    'description'   => 'theme_editor.novoton_search_btn_hover_color',
    'type'          => 'color',
    'variable_name' => 'novoton-search-btn-hover',
];

// Price color is NOT registered here — it inherits from the theme's @price
// variable (already a color picker in Theme Editor).  When the admin changes
// the "Price" colour, the addon's @novoton-price-color follows automatically.

$schema['colors']['fields']['novoton-cal-cheapest-bg'] = [
    'description'   => 'theme_editor.novoton_cal_cheapest_bg',
    'type'          => 'color',
    'variable_name' => 'novoton-cal-cheapest-bg',
];

$schema['colors']['fields']['novoton-cal-cheapest-color'] = [
    'description'   => 'theme_editor.novoton_cal_cheapest_color',
    'type'          => 'color',
    'variable_name' => 'novoton-cal-cheapest-color',
];

$schema['colors']['fields']['novoton-cal-price-color'] = [
    'description'   => 'theme_editor.novoton_cal_price_color',
    'type'          => 'color',
    'variable_name' => 'novoton-cal-price-color',
];

return $schema;

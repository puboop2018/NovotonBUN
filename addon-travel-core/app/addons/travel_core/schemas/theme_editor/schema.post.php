<?php
/**
 * Travel Core - Theme Editor Schema Extension
 *
 * Extends the CS-Cart Theme Editor "Colors" section with the travel design
 * system's brand color pickers.  Each entry maps to a LESS variable (via
 * variable_name) that travel_core's styles.less defines with a default value.
 *
 * When the admin picks a colour in the Theme Editor the LESS compiler
 * overrides the default in styles.less — no core files are modified.
 *
 * NOTE: the variable names keep their historical `novoton-*` prefix on
 * purpose — Theme Editor persistence binds saved merchant colors by
 * variable_name, and these pickers moved here from novoton_holidays
 * (2026-07) when the token bridge became shared. Renaming them would
 * silently discard merchants' saved colors.
 *
 * Chrome / status / font colours are NOT registered here — they inherit
 * from the active theme preset variables automatically.
 *
 * @package TravelCore
 */

$schema['colors']['fields']['novoton-primary'] = [
    'description'   => 'theme_editor.travel_primary_color',
    'type'          => 'color',
    'variable_name' => 'novoton-primary',
];

$schema['colors']['fields']['novoton-accent'] = [
    'description'   => 'theme_editor.travel_accent_color',
    'type'          => 'color',
    'variable_name' => 'novoton-accent',
];

$schema['colors']['fields']['novoton-search-btn-bg'] = [
    'description'   => 'theme_editor.travel_search_btn_color',
    'type'          => 'color',
    'variable_name' => 'novoton-search-btn-bg',
];

$schema['colors']['fields']['novoton-search-btn-hover'] = [
    'description'   => 'theme_editor.travel_search_btn_hover_color',
    'type'          => 'color',
    'variable_name' => 'novoton-search-btn-hover',
];

// Price color is NOT registered here — it inherits from the theme's @links
// variable (already a color picker in Theme Editor).  When the admin changes
// the "Links" colour, the design system's price color follows automatically.

// The old `novoton-cal-cheapest-bg` picker was removed: it referenced a LESS
// variable that was never defined and no stylesheet consumed it (dead picker).

$schema['colors']['fields']['novoton-cal-cheapest-color'] = [
    'description'   => 'theme_editor.travel_cal_cheapest_color',
    'type'          => 'color',
    'variable_name' => 'novoton-cal-cheapest-color',
];

$schema['colors']['fields']['novoton-cal-price-color'] = [
    'description'   => 'theme_editor.travel_cal_price_color',
    'type'          => 'color',
    'variable_name' => 'novoton-cal-price-color',
];

return $schema;

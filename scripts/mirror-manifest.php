<?php

declare(strict_types=1);

/**
 * Single source of truth for the repo's template/asset mirror sets.
 *
 * Consumed by BOTH:
 *  - scripts/mirror-themes.php  (composer mirror — performs the sync)
 *  - ThemeMirrorTest            (travel_core suite — enforces the sync)
 * so the tool and the guard can never disagree about what is mirrored.
 */
return [
    // design/themes roots (repo-relative) whose nova_theme tree mirrors
    // the responsive tree byte-for-byte. sphinx ships responsive only.
    'theme_roots' => [
        'addon-novoton-holidays/design/themes',
        'addon-travel-core/design/themes',
    ],

    // nova_theme files allowed to differ from their responsive counterpart
    // (path relative to the nova_theme dir => reason). To intentionally fork
    // a file for nova_theme, add it here with a short WHY.
    'drift_allowlist' => [
        'templates/addons/novoton_holidays/blocks/booking_summary.tpl' => 'pre-existing divergence (64 lines) — pending review',
        'templates/addons/novoton_holidays/hooks/index/styles.post.tpl' => 'pre-existing divergence — pending review',
        'templates/addons/novoton_holidays/hooks/index/scripts.post.tpl' => 'pre-existing divergence — pending review',
        'templates/addons/novoton_holidays/blocks/product_tabs/novoton_hotel_prices.tpl' => 'pre-existing divergence — pending review',
    ],

    // Sets of repo-relative paths that must stay byte-identical across
    // AREAS (storefront / admin / mail — Smarty resolves templates per
    // area, so each area needs its own physical copy). First path in a
    // set is the reference the others are synced FROM.
    'area_copy_sets' => [
        [
            'addon-travel-core/design/themes/responsive/templates/addons/travel_core/components/order_booking_details.tpl',
            'addon-travel-core/design/backend/templates/addons/travel_core/components/order_booking_details.tpl',
            'addon-travel-core/design/backend/mail/templates/addons/travel_core/components/order_booking_details.tpl',
        ],
    ],
];

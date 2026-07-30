<?php

declare(strict_types=1);

/**
 * Runtime-seeded language keys for travel_core.
 *
 * Read by fn_travel_core_seed_language_keys() (func.php), which UPSERTs every
 * entry into ?:language_values whenever this file or addon.xml changes (hash
 * probe in init.php). That is what lets NEW or CHANGED labels reach stores that
 * are already installed — CS-Cart only imports addon.xml/.po at install time.
 *
 * addon.xml entries are merged on top of this file, so a key declared in both
 * takes its value from addon.xml. Keep the two in sync when a key exists in
 * both; use this file alone for runtime-only keys.
 *
 * Shape: 'lang.key' => ['en' => '...', 'ro' => '...']
 */

return [
    // BARE key by CS-Cart convention: the Appearance "Product detailed page
    // view" dropdown labels each blocks/product_templates/<file>.tpl with the
    // lang var named exactly after the file (no addon prefix).
    'travelcore_template' => [
        'en' => 'Travel Core Template',
        'ro' => 'Travel Core Template',
    ],
    // Reserve button on the Travel Core product template (replaces
    // add-to-cart for hotel products; anchors to the availability search).
    'travel_core.reserve_now' => [
        'en' => 'Reserve',
        'ro' => 'Rezervați acum',
    ],
    // Guest picker (React booking engine). The JS keys childrenAges / childNAge
    // resolve to these via the booking_config controller and functions/hotels.php.
    'travel_core.childrens_ages' => [
        'en' => "Children's ages at check-in",
        'ro' => 'Vârsta copilului la check-in',
    ],
    'travel_core.child_n_age' => [
        'en' => 'Select child [n] age',
        'ro' => 'Selectează vârsta copilului [n]',
    ],

    // Admin labels seeded here (not just addon.xml/.po) so the init.php hash
    // probe reseeds them on every store's next admin load — CS-Cart imports
    // addon.xml/.po only at install, so a label added later renders raw
    // ("_travel_core.hotel_name") on already-installed/upgraded stores until a
    // lang_keys.php change bumps the seed hash. Enforced by
    // Schema/AdminLangKeysSeededTest (every {__("travel_core.X")} in a backend
    // template must live in addon.xml or here).
    'travel_core.hotel_name' => [
        'en' => 'Hotel name',
        'ro' => 'Nume hotel',
    ],
    'travel_core.back' => [
        'en' => 'Back',
        'ro' => 'Înapoi',
    ],
    'travel_core.theme_default' => [
        'en' => '(theme default)',
        'ro' => '(implicit temă)',
    ],
    'travel_core.reset_to_default' => [
        'en' => 'Reset to default',
        'ro' => 'Resetare la implicit',
    ],
    'travel_core.default' => [
        'en' => 'Default',
        'ro' => 'Implicit',
    ],
    'travel_core.inherited_from_theme' => [
        'en' => 'Inherited from theme',
        'ro' => 'Moștenit din temă',
    ],
    'travel_core.appearance_preview' => [
        'en' => 'Live Preview',
        'ro' => 'Previzualizare',
    ],
    'travel_core.color_danger' => [
        'en' => 'Error / validation',
        'ro' => 'Eroare / validare',
    ],
    'travel_core.appearance_page_title' => [
        'en' => 'Booking Form Colors',
        'ro' => 'Culori formular rezervare',
    ],

    // Per-language SEO Templates admin (components/seo_lang_fields.tpl —
    // shared by both providers' SEO Templates pages).
    'travel_core.seo_fields_applied' => [
        'en' => 'Fields applied on import',
        'ro' => 'Câmpuri aplicate la import',
    ],
    'travel_core.seo_fields_applied_hint' => [
        'en' => 'Unchecked fields are skipped for every language.',
        'ro' => 'Câmpurile debifate sunt omise pentru toate limbile.',
    ],
    'travel_core.seo_per_language_hint' => [
        'en' => 'Each storefront language has its own template set. A language whose template was never saved uses the built-in default.',
        'ro' => 'Fiecare limbă a magazinului are propriul set de șabloane. O limbă fără șablon salvat folosește valoarea implicită.',
    ],

    // Shared hotel-identity header (components/hotel_header.tpl) — one key
    // pair for all four consuming surfaces instead of per-provider copies.
    'travel_core.location_show_map' => [
        'en' => 'Location - show map',
        'ro' => 'Locație - arată pe hartă',
    ],
    'travel_core.stars_rating' => [
        'en' => '[rating]-star rating',
        'ro' => 'Clasificare [rating] stele',
    ],

    // Shared cart booking-details card (components/cart_booking_details.tpl)
    // — one key set for both providers' cart/checkout cards.
    'travel_core.your_booking_details' => [
        'en' => 'Your booking details',
        'ro' => 'Detaliile rezervării',
    ],
    'travel_core.price_updated_badge' => [
        'en' => 'Price Updated',
        'ro' => 'Preț actualizat',
    ],
    'travel_core.price_dropped_badge' => [
        'en' => 'Price Dropped!',
        'ro' => 'Preț redus!',
    ],
    'travel_core.total_stay' => [
        'en' => 'Total stay',
        'ro' => 'Durata totală a sejurului',
    ],
    'travel_core.for' => [
        'en' => 'for',
        'ro' => 'pentru',
    ],
    'travel_core.occupancy' => [
        'en' => 'Occupancy',
        'ro' => 'Turiști',
    ],
    'travel_core.guest_names' => [
        'en' => 'Guest names',
        'ro' => 'Nume turiști',
    ],
    'travel_core.holder' => [
        'en' => 'Holder',
        'ro' => 'Lider rezervare',
    ],
    'travel_core.meal_plan' => [
        'en' => 'Meal plan',
        'ro' => 'Masă',
    ],
    'travel_core.save_changes' => [
        'en' => 'Save changes',
        'ro' => 'Salvează modificările',
    ],
    'travel_core.guest_details_invalid' => [
        'en' => 'Please fill in all guest names.',
        'ro' => 'Vă rugăm completați numele tuturor turiștilor.',
    ],

    // Booking-form summary sidebar (components/booking_sidebar.tpl) — the
    // shared left column of both providers' booking pages.
    'travel_core.available' => [
        'en' => 'Available',
        'ro' => 'Disponibil',
    ],
    'travel_core.on_request' => [
        'en' => 'On request',
        'ro' => 'La cerere',
    ],
    'travel_core.package' => [
        'en' => 'Package',
        'ro' => 'Pachet',
    ],
    'travel_core.you_selected' => [
        'en' => 'You selected',
        'ro' => 'Ați selectat',
    ],
    // CS-Cart plural forms ("[n] singular|[n] plural"): the count picks the
    // form, so Romanian keeps its own singular/plural nouns.
    'travel_core.n_nights' => [
        'en' => '[n] night|[n] nights',
        'ro' => '[n] noapte|[n] nopți',
    ],
    'travel_core.n_rooms' => [
        'en' => '[n] room|[n] rooms',
        'ro' => '[n] cameră|[n] camere',
    ],
    'travel_core.n_adults' => [
        'en' => '[n] adult|[n] adults',
        'ro' => '[n] adult|[n] adulți',
    ],
    'travel_core.n_children' => [
        'en' => '[n] child|[n] children',
        'ro' => '[n] copil|[n] copii',
    ],
    // The sentence that joins them, so word order and the connector ("and" /
    // "și") stay translatable instead of being concatenated in code.
    'travel_core.selected_line' => [
        'en' => '[nights], [rooms] for [adults]',
        'ro' => '[nights], [rooms] pentru [adults]',
    ],
    'travel_core.selected_line_with_children' => [
        'en' => '[nights], [rooms] for [adults] and [children]',
        'ro' => '[nights], [rooms] pentru [adults] și [children]',
    ],
    'travel_core.change_selection' => [
        'en' => 'Change your selection',
        'ro' => 'Schimbați-vă opțiunea',
    ],
    'travel_core.original_price' => [
        'en' => 'Original price',
        'ro' => 'Preț inițial',
    ],
    'travel_core.cancel_cost_title' => [
        'en' => 'How much will it cost to cancel?',
        'ro' => 'Cât costă să anulez?',
    ],
    'travel_core.cancel_you_will_pay' => [
        'en' => "If you cancel, you'll pay",
        'ro' => 'Dacă anulați, veți plăti',
    ],
    // "What are my booking conditions?" — the link under Add-to-Cart and its
    // modal (components/booking_conditions_modal.tpl).
    'travel_core.booking_conditions_link' => [
        'en' => 'What are my booking conditions?',
        'ro' => 'Care sunt condițiile rezervării mele?',
    ],
    'travel_core.booking_conditions_loading' => [
        'en' => 'The booking conditions are being confirmed with the provider…',
        'ro' => 'Condițiile rezervării se confirmă cu operatorul…',
    ],
    'travel_core.close' => [
        'en' => 'Close',
        'ro' => 'Închide',
    ],
];

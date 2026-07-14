<?php
/**
 * Sphinx Holidays - Block Manager Schema
 *
 * Registers the Sphinx widgets as DEDICATED block types
 * (sphinx_booking_engine, sphinx_best_deals) so they appear as their own
 * entries in the Block Manager "Add block" list.
 *
 * They must NOT be added to the shared 'template' block type: its 'templates'
 * key is the core directory-scan string 'blocks/static_templates', the pool
 * every template-driven block (including the header Search block) selects
 * from. Injecting entries there let the widgets hijack the core Search block
 * (and the previously registered paths did not even resolve to files).
 * Display names come from the block_<type> language variables in var/langs.
 *
 * @package SphinxHolidays
 */

/** @var array<string, mixed> $schema */

$schema['sphinx_booking_engine'] = [
    'templates' => [
        'addons/sphinx_holidays/blocks/booking_engine.tpl' => [],
    ],
    'wrappers'  => 'blocks/wrappers',
];

$schema['sphinx_best_deals'] = [
    'templates' => [
        'addons/sphinx_holidays/blocks/best_deals.tpl' => [],
    ],
    'settings'  => [
        'deals_type' => [
            'type' => 'selectbox',
            'values' => [
                'hotels'   => 'sphinx_deals_hotels',
                'packages' => 'sphinx_deals_packages',
                // Sellable circuits from the cron-synced sphinx_circuits
                // cache (products created by add_circuit_products) — cards
                // link to the circuit product page.
                'circuits' => 'sphinx_deals_circuits',
            ],
            'default_value' => 'hotels',
        ],
        'deals_limit' => [
            'type' => 'input',
            'default_value' => '6',
        ],
        'destination_id' => [
            'type' => 'input',
            'default_value' => '0',
        ],
    ],
    'wrappers'  => 'blocks/wrappers',
];

// Storefront entry point for the package vertical: a search form posting to
// sphinx_booking.package_search, its destination/departure/transport selects
// fed from the cron-synced sphinx_package_routes table (via the cache_deals
// JSON endpoint, type=package_routes — no provider API call).
$schema['sphinx_package_search'] = [
    'templates' => [
        'addons/sphinx_holidays/blocks/package_search.tpl' => [],
    ],
    'wrappers'  => 'blocks/wrappers',
];

return $schema;

<?php
/**
 * Novoton Holidays - Block Manager Schema
 *
 * Registers the booking widgets as DEDICATED block types
 * (novoton_homepage_booking, novoton_booking_engine) so they appear as their
 * own entries in the Block Manager "Add block" list.
 *
 * They must NOT be added to the shared 'template' block type: its 'templates'
 * key is the core directory-scan string 'blocks/static_templates', the pool
 * every template-driven block (including the header Search block) selects
 * from. Injecting entries there replaced that pool and let the booking
 * widgets hijack the core Search block. Display names come from the
 * block_<type> language variables shipped in var/langs.
 *
 * @package NovotonHolidays
 */

$schema['novoton_homepage_booking'] = [
    'templates' => [
        'addons/novoton_holidays/blocks/homepage_booking.tpl' => [],
    ],
    'wrappers'  => 'blocks/wrappers',
];

$schema['novoton_booking_engine'] = [
    'templates' => [
        'addons/novoton_holidays/blocks/booking_engine.tpl' => [],
    ],
    'wrappers'  => 'blocks/wrappers',
];

return $schema;

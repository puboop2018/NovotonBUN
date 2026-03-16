<?php
/**
 * Sphinx Holidays - Block Manager Schema
 *
 * Registers Sphinx templates in the 'template' block type
 * so they appear in the Block Manager "Add block: Template" dropdown.
 *
 * @package SphinxHolidays
 */

if (isset($schema['template']) && is_array($schema['template'])) {
    if (!isset($schema['template']['templates']) || !is_array($schema['template']['templates'])) {
        $schema['template']['templates'] = [];
    }

    $schema['template']['templates']['blocks/addons/sphinx_holidays/blocks/best_deals.tpl'] = [
        'settings' => [
            'deals_type' => [
                'type' => 'selectbox',
                'values' => ['hotels' => 'Hotels', 'packages' => 'Packages'],
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
    ];

    $schema['template']['templates']['blocks/static_templates/addons/sphinx_holidays/booking_engine.tpl'] = [
        'settings' => [],
    ];
}

return $schema;

<?php
/**
 * Novoton Holidays - Block Manager Schema
 *
 * Registers Novoton static templates in the 'template' block type
 * so they appear in the Block Manager "Add block: Template" dropdown.
 *
 * @package NovotonHolidays
 */

if (isset($schema['template']) && is_array($schema['template'])) {
    if (!isset($schema['template']['templates']) || !is_array($schema['template']['templates'])) {
        $schema['template']['templates'] = [];
    }

    $schema['template']['templates']['blocks/static_templates/addons/novoton_holidays/homepage_booking.tpl'] = [
        'settings' => [],
    ];

    $schema['template']['templates']['blocks/static_templates/addons/novoton_holidays/booking_engine.tpl'] = [
        'settings' => [],
    ];
}

return $schema;

<?php
declare(strict_types=1);
/**
 * Novoton Holidays Block Manager Schema
 * Path: app/addons/novoton_holidays/schemas/block_manager/blocks.post.php
 *
 * Registers Novoton static templates in the 'template' block type
 * so they appear in the Block Manager "Add block: Template" dropdown.
 *
 * CS-Cart block type for static templates is 'template' (singular).
 * The 'templates' key inside it lists available template files.
 */

if (!is_array($schema)) {
    $schema = [];
}

// The 'template' block type is defined by CS-Cart core (blocks.php).
// We add our addon's static templates to its template list.
if (isset($schema['template'])) {
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

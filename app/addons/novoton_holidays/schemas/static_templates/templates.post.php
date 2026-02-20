<?php
declare(strict_types=1);
/**
 * Novoton Holidays Static Templates Schema
 * Path: app/addons/novoton_holidays/schemas/static_templates/templates.post.php
 * 
 * This registers the Novoton templates as static templates in CS-Cart
 */

// Ensure $schema is an array (PHP 8.1+ compatibility)
if (!is_array($schema)) {
    $schema = [];
}

// Path: blocks/static_templates/addons/[addon_id]/ (CS-Cart standard)
$schema['blocks/static_templates/addons/novoton_holidays/homepage_booking.tpl'] = [
    'name' => 'block_novoton_holidays_homepage_booking',
];

$schema['blocks/static_templates/addons/novoton_holidays/booking_engine.tpl'] = [
    'name' => 'block_novoton_holidays_booking_engine',
];

return $schema;

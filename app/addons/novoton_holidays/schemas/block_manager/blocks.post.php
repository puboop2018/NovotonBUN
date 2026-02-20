<?php
declare(strict_types=1);
/**
 * Novoton Holidays Block Manager Schema
 * Path: app/addons/novoton_holidays/schemas/block_manager/blocks.post.php
 * 
 * This registers the Novoton templates in the block manager
 */

use Tygh\Registry;

// Ensure $schema is an array (PHP 8.1+ compatibility)
if (!is_array($schema)) {
    $schema = [];
}

// Ensure templates key exists
if (!isset($schema['templates']) || !is_array($schema['templates'])) {
    $schema['templates'] = [];
}

// Register Novoton templates as static templates
// Path: blocks/static_templates/addons/[addon_id]/ (CS-Cart standard)
$schema['templates']['blocks/static_templates/addons/novoton_holidays/homepage_booking.tpl'] = [
    'settings' => []
];

$schema['templates']['blocks/static_templates/addons/novoton_holidays/booking_engine.tpl'] = [
    'settings' => []
];

return $schema;

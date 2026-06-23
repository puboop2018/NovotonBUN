<?php
/*
 * Sphinx Holidays - Admin permissions schema
 */

/** @var array<string, mixed> $schema */

$schema['sphinx_holidays'] = [
    'permissions' => ['GET', 'POST'],
];

$schema['sphinx_booking'] = [
    'permissions' => ['GET', 'POST'],
];

$schema['sphinx_seo_templates'] = [
    'permissions' => ['GET', 'POST'],
];

return $schema;

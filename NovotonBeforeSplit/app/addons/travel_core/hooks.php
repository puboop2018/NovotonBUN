<?php
declare(strict_types=1);
/**
 * Travel Core - Hook Loader
 *
 * Loads all hook function files.
 *
 * @package TravelCore
 * @since 1.0.0
 */

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

$hooks_dir = __DIR__ . '/hooks/';

$hook_files = [
    'cart_hooks.php',
    'order_hooks.php',
    'exchange_rate_hooks.php',
];

foreach ($hook_files as $file) {
    $path = $hooks_dir . $file;
    if (file_exists($path)) {
        require_once $path;
    }
}

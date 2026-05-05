<?php

declare(strict_types=1);

use Tygh\Registry;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

if (!defined('FGO_INVOICING_VERSION')) {
    $__fgo_v_raw = Registry::get('addons.fgo_invoicing.version');
    $__fgo_v = is_string($__fgo_v_raw) && $__fgo_v_raw !== '' ? $__fgo_v_raw : '0.0.0';
    define('FGO_INVOICING_VERSION', preg_replace('/-.*$/', '', $__fgo_v) ?? '0.0.0');
    unset($__fgo_v, $__fgo_v_raw);
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Tygh\\Addons\\FgoInvoicing\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    $file = __DIR__ . '/src/' . $relative;
    if (file_exists($file)) {
        require $file;
        return;
    }
    $file = __DIR__ . '/' . $relative;
    if (file_exists($file)) {
        require $file;
    }
});

require_once __DIR__ . '/hooks.php';

fn_register_hooks(
    'place_order_post',
    'change_order_status',
    'get_order_info',
    'profile_fields_get_fields',
    'update_profile_fields_post',
);

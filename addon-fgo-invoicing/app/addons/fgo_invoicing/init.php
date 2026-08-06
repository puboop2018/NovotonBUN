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

// Self-heal language keys. CS-Cart imports addon language variables only at
// INSTALL time, so a store installed while the .po was missing or unparseable
// renders every settings label blank and every admin string as a raw key, and
// no amount of fixing the files afterwards repairs it on its own.
//
// Routed through travel_core's healer when that addon is present, so we
// inherit its stamp throttle and per-language read-back verification. fgo is
// horizontal and must also install without travel_core, hence function_exists
// rather than a hard <dependencies> edge — see LanguageSeeder's docblock for
// what still protects us on a travel_core-less store.
//
// No AREA gate (the e-mail strings are needed on the storefront too) and
// guarded, because a throw inside fn_init_addons() is a 503 everywhere.
if (
    function_exists('fn_travel_core_heal_language_keys')
    && function_exists('fn_travel_core_self_heal_guard')
    && function_exists('fn_fgo_invoicing_language_variables')
) {
    fn_travel_core_self_heal_guard('fgo_invoicing_langs', static function (): void {
        fn_travel_core_heal_language_keys(
            'fgo_invoicing',
            'fn_fgo_invoicing_language_variables',
            fn_fgo_invoicing_language_seed_hash(),
            static function (array $vars): void {
                \Tygh\Addons\FgoInvoicing\Install\LanguageSeeder::mirrorSettingsDescriptions($vars);
            },
        );
    });
}

fn_register_hooks(
    'place_order_post',
    'change_order_status',
    'get_order_info',
    'profile_fields_get_fields',
    'update_profile_fields_post',
);

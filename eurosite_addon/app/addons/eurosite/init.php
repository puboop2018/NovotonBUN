<?php

declare(strict_types=1);

/**
 * Eurosite Touring — addon bootstrap.
 *
 * Location: app/addons/eurosite/init.php
 */

use Tygh\Registry;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

// Addon version constant (strip any -pre/-beta suffix).
if (!defined('EUROSITE_VERSION')) {
    $__ev = TypeCoerce::toString(Registry::get('addons.eurosite.version') ?: '0.0.0');
    define('EUROSITE_VERSION', preg_replace('/-.*$/', '', $__ev));
    unset($__ev);
}

// PSR-4 autoloader for the Tygh\Addons\Eurosite namespace -> src/.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Tygh\\Addons\\Eurosite\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    $file = __DIR__ . '/src/' . $relative;
    if (file_exists($file)) {
        require $file;
    }
});

// Schema self-heal for installed stores (admin area only; stamp-gated so the
// catalog reads + conditional DDL run once per deployed SchemaMigrator.php,
// then every request pays a single ?:storage_data read). Dev stores symlink
// the addon and never rerun addon.xml — this is how new tables/columns land.
if (defined('AREA') && AREA === 'A' && function_exists('fn_eurosite_ensure_schema')) {
    $__eu_heal_fp = (string) @md5_file(__DIR__ . '/src/Install/SchemaMigrator.php');
    if (!function_exists('fn_travel_core_self_heal_due')
        || fn_travel_core_self_heal_due('eurosite_schema', $__eu_heal_fp)
    ) {
        if (function_exists('fn_travel_core_self_heal_guard')) {
            // Never let a heal 503 the admin — see fn_travel_core_self_heal_guard.
            fn_travel_core_self_heal_guard('eurosite_schema', 'fn_eurosite_ensure_schema');
        } else {
            fn_eurosite_ensure_schema();
        }
        if (function_exists('fn_travel_core_self_heal_stamp')) {
            fn_travel_core_self_heal_stamp('eurosite_schema', $__eu_heal_fp);
        }
    }
    unset($__eu_heal_fp);
}

// Register with the shared travel-provider registry so Eurosite participates in
// the cross-provider feature-mapping / normalization pipeline (guarded against
// travel_core not being loaded). MVP registers the normalizer only; the
// booking-admin / hotel-product / status-sync providers are follow-ups.
if (class_exists(\Tygh\Addons\TravelCore\Services\TravelProviderRegistry::class)) {
    \Tygh\Addons\TravelCore\Services\TravelProviderRegistry::register(
        'eurosite',
        'Eurosite',
        new \Tygh\Addons\Eurosite\Api\EurositeNormalizer(),
    );
}

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

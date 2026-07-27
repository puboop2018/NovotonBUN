<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for the eurosite addon.
 *
 * Registers PSR-4 autoloaders for the addon's own src/ AND the travel_core
 * src/ it depends on (TypeCoerce, ResiliencePolicy, ProviderNormalizerInterface),
 * plus a couple of CS-Cart procedural stubs the API layer touches. Keeps the
 * unit tests runnable standalone (no CS-Cart bootstrap).
 */

$addonRoot = dirname(__DIR__);
$repoRoot = dirname($addonRoot, 4); // eurosite_addon/app/addons/eurosite -> repo root

// Eurosite src/ + tests/
spl_autoload_register(static function (string $class) use ($addonRoot): void {
    foreach ([
        'Tygh\\Addons\\Eurosite\\Tests\\' => $addonRoot . '/tests/',
        'Tygh\\Addons\\Eurosite\\'        => $addonRoot . '/src/',
    ] as $prefix => $base) {
        if (strncmp($prefix, $class, strlen($prefix)) === 0) {
            $file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

// travel_core src/ (shared helpers/contracts the addon depends on).
$travelCoreSrc = $repoRoot . '/addon-travel-core/app/addons/travel_core/src';
spl_autoload_register(static function (string $class) use ($travelCoreSrc): void {
    $prefix = 'Tygh\\Addons\\TravelCore\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $file = $travelCoreSrc . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Minimal CS-Cart procedural stubs (only what the API layer references).
if (!function_exists('fn_log_event')) {
    function fn_log_event(string $type, string $action, array $data = []): void {}
}

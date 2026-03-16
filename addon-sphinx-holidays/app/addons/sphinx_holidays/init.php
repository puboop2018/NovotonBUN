<?php
declare(strict_types=1);
/***************************************************************************
 *                                                                          *
 *   (c) 2024-2026 VacanteLitoral.ro                                       *
 *                                                                          *
 *   Location: app/addons/sphinx_holidays/init.php                         *
 *                                                                          *
 ***************************************************************************/

use Tygh\Registry;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

// Addon version constant
if (!defined('SPHINX_HOLIDAYS_VERSION')) {
    $__sv = Registry::get('addons.sphinx_holidays.version') ?: '0.0.0';
    define('SPHINX_HOLIDAYS_VERSION', preg_replace('/-.*$/', '', $__sv));
    unset($__sv);
}

// Register PSR-4 autoloader for sphinx_holidays namespace.
spl_autoload_register(function ($class) {
    $prefix = 'Tygh\\Addons\\SphinxHolidays\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    $file = __DIR__ . '/src/' . $relative;

    if (file_exists($file)) {
        require $file;
    }
});

// Load function files
require_once __DIR__ . '/functions/products.php';

// Register with shared travel provider registry (guard against travel_core not being loaded)
if (class_exists(\Tygh\Addons\TravelCore\Services\TravelProviderRegistry::class)) {
    \Tygh\Addons\TravelCore\Services\TravelProviderRegistry::register(
        'sphinx',
        'Sphinx / Christian Tour',
        new \Tygh\Addons\SphinxHolidays\Api\SphinxNormalizer()
    );

    // Register admin provider for unified booking management
    if (class_exists(\Tygh\Addons\TravelCore\Contracts\BookingAdminProviderInterface::class)) {
        \Tygh\Addons\TravelCore\Services\TravelProviderRegistry::registerAdminProvider(
            'sphinx',
            new \Tygh\Addons\SphinxHolidays\Services\BookingAdminProvider()
        );
    }
}

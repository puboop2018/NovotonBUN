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

// Register with shared travel provider registry (guard against travel_core not being loaded)
if (class_exists(\Tygh\Addons\TravelCore\Services\TravelProviderRegistry::class)) {
    \Tygh\Addons\TravelCore\Services\TravelProviderRegistry::register(
        'sphinx',
        'Sphinx / Christian Tour',
        new \Tygh\Addons\SphinxHolidays\Api\SphinxNormalizer()
    );
    \Tygh\Addons\TravelCore\Services\TravelProviderRegistry::setBookingAdminProvider(
        'sphinx',
        new \Tygh\Addons\SphinxHolidays\Services\BookingAdminProvider()
    );
    \Tygh\Addons\TravelCore\Services\TravelProviderRegistry::setStatusCallbacks(
        'sphinx',
        function () {
            $api = \Tygh\Addons\SphinxHolidays\Services\Container::getApi();
            $repo = \Tygh\Addons\SphinxHolidays\Services\Container::getBookingRepository();
            $service = new \Tygh\Addons\SphinxHolidays\Services\OrderStatusSyncService($api, $repo);
            return $service->syncAll();
        },
        function (int $bookingId) {
            $provider = new \Tygh\Addons\SphinxHolidays\Services\BookingAdminProvider();
            return $provider->checkStatus((string) $bookingId);
        }
    );
}

// Register addon hooks
fn_register_hooks(
    'pre_place_order',                         // Re-verify Sphinx offer prices before order
    'place_order_post',                        // Submit booking to Sphinx API after order
    'calculate_cart_items',                     // Preserve stored price for Sphinx bookings
    'get_product_data_post',                   // Attach hotel data to Sphinx products
    'gather_additional_product_data_post',     // Pass booking form config to templates
    'user_login_post',                         // Link session bookings to logged-in user
    'create_user_post',                        // Link bookings to newly registered users
    'get_order_info',                          // Admin notification for failed bookings
    'travel_core_exchange_rates_updated'       // Log exchange rate sync to sphinx_sync_log
);

<?php
declare(strict_types=1);
/**
 * Sphinx Holidays - Backend Admin Controller
 *
 * Modes:
 *   - manage (default): Dashboard with destination + hotel stats, sync controls
 *   - destinations: List/filter destinations with pagination
 *   - hotels: List/filter hotels with pagination
 *   - sync_destinations (POST): Trigger destination sync from admin panel
 *   - sync_hotels (POST): Trigger hotel sync from admin panel
 *
 * @package SphinxHolidays
 * @since 1.0.0
 */

use Tygh\Tygh;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\DestinationSyncService;
use Tygh\Addons\SphinxHolidays\Services\HotelSyncService;
use Tygh\Addons\SphinxHolidays\Repository\DestinationRepository;
use Tygh\Addons\SphinxHolidays\Repository\HotelRepository;
use Tygh\Addons\SphinxHolidays\Cron\Commands\AddProductsCommand;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

// $mode is set automatically by CS-Cart from the dispatch parameter
// e.g. dispatch=sphinx_holidays.sync_destinations sets $mode = 'sync_destinations'

// ─── POST handlers ───

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($mode === 'sync_destinations') {
        if (!ConfigProvider::isConfigured()) {
            fn_set_notification('E', __('error'), __('sphinx_holidays.api_not_configured'));
            return [CONTROLLER_STATUS_REDIRECT, 'sphinx_holidays.manage'];
        }

        $api = Container::getApi();
        $repository = new DestinationRepository();
        $service = new DestinationSyncService($api, $repository);

        $result = $service->sync();

        if ($result['success']) {
            fn_set_notification('N', __('notice'), __('sphinx_holidays.sync_completed') . ': ' . $result['synced'] . '/' . $result['total']);
        } else {
            fn_set_notification('E', __('error'), __('sphinx_holidays.sync_failed') . ': ' . ($result['error'] ?: 'Unknown error'));
        }

        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_holidays.manage'];
    }

    if ($mode === 'sync_hotels') {
        if (!ConfigProvider::isConfigured()) {
            fn_set_notification('E', __('error'), __('sphinx_holidays.api_not_configured'));
            return [CONTROLLER_STATUS_REDIRECT, 'sphinx_holidays.manage'];
        }

        $api = Container::getApi();
        $hotelRepo = new HotelRepository();
        $destRepo = new DestinationRepository();
        $service = new HotelSyncService($api, $hotelRepo, $destRepo);

        $countryCodes = ConfigProvider::getSelectedCountryCodes();
        $result = $service->sync($countryCodes);

        if ($result['success']) {
            fn_set_notification('N', __('notice'), __('sphinx_holidays.hotel_sync_completed') . ': ' . $result['synced'] . '/' . $result['total']);
        } else {
            fn_set_notification('E', __('error'), __('sphinx_holidays.hotel_sync_failed') . ': ' . ($result['error'] ?: 'Unknown error'));
        }

        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_holidays.manage'];
    }

    if ($mode === 'add_products') {
        $hotelRepo = new HotelRepository();
        $unlinked = $hotelRepo->findUnlinked('', 1);

        if (empty($unlinked)) {
            fn_set_notification('W', __('warning'), __('sphinx_holidays.no_unlinked_hotels'));
            return [CONTROLLER_STATUS_REDIRECT, 'sphinx_holidays.manage'];
        }

        $command = new AddProductsCommand();
        $command->setOutputCallback(function($msg) {}); // silent in web context
        $result = $command->execute();

        if ($result['success']) {
            $added = $result['stats']['added'] ?? 0;
            $invalid = $result['stats']['invalid_country'] ?? 0;
            $msg = __('sphinx_holidays.products_created') . ': ' . $added;
            if ($invalid > 0) {
                $msg .= ' (' . $invalid . ' ' . __('sphinx_holidays.skipped_invalid_country') . ')';
            }
            fn_set_notification('N', __('notice'), $msg);
        } else {
            fn_set_notification('E', __('error'), __('sphinx_holidays.products_creation_failed'));
        }

        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_holidays.manage'];
    }
}

// ─── AJAX JSON handlers ───

if ($mode === 'get_regions') {
    header('Content-Type: application/json; charset=utf-8');
    $country_code = isset($_REQUEST['country_code']) ? (string) $_REQUEST['country_code'] : '';
    if ($country_code === '') {
        echo json_encode(['regions' => []]);
        exit;
    }
    $destRepo = new DestinationRepository();
    $regions = $destRepo->getRegionsByCountry($country_code);
    echo json_encode(['regions' => $regions]);
    exit;
}

if ($mode === 'get_cities') {
    header('Content-Type: application/json; charset=utf-8');
    $region_id = (int) ($_REQUEST['region_id'] ?? 0);
    if ($region_id <= 0) {
        echo json_encode(['cities' => []]);
        exit;
    }
    $destRepo = new DestinationRepository();
    $cities = $destRepo->getCitiesByParent($region_id);
    echo json_encode(['cities' => $cities]);
    exit;
}

if ($mode === 'get_destinations_tree') {
    header('Content-Type: application/json; charset=utf-8');
    $country_code = isset($_REQUEST['country_code']) ? (string) $_REQUEST['country_code'] : '';
    if ($country_code === '') {
        echo json_encode(['tree' => []]);
        exit;
    }
    $destRepo = new DestinationRepository();
    $regions = $destRepo->getRegionsByCountry($country_code);
    $tree = [];
    foreach ($regions as $region) {
        $children = $destRepo->getCitiesByParent((int) $region['destination_id']);
        $region['children'] = $children;
        $tree[] = $region;
    }
    echo json_encode(['tree' => $tree]);
    exit;
}

// ─── GET handlers ───

if ($mode === 'manage') {
    $destRepo = new DestinationRepository();
    $hotelRepo = new HotelRepository();

    // Destination stats
    $countsByType = $destRepo->getCountsByType();
    $totalDestinations = $destRepo->getTotal();
    $destLastSynced = $destRepo->getLastSyncedAt();

    // Hotel stats
    $totalHotels = $hotelRepo->getTotal();
    $hotelsByCountry = $hotelRepo->getCountsByCountry();
    $hotelLastSynced = $hotelRepo->getLastSyncedAt();

    // Product stats
    $linkedCount = $hotelRepo->countLinked();
    $unlinkedCount = $totalHotels - $linkedCount;

    // API status
    $isConfigured = ConfigProvider::isConfigured();
    $selectedCountries = ConfigProvider::getSelectedCountryCodes();

    // Recent sync logs (both types)
    $syncLogs = db_get_array(
        "SELECT * FROM ?:sphinx_sync_log ORDER BY started_at DESC LIMIT 10"
    );

    Tygh::$app['view']->assign('counts_by_type', $countsByType);
    Tygh::$app['view']->assign('total_destinations', $totalDestinations);
    Tygh::$app['view']->assign('dest_last_synced', $destLastSynced);
    Tygh::$app['view']->assign('total_hotels', $totalHotels);
    Tygh::$app['view']->assign('hotels_by_country', $hotelsByCountry);
    Tygh::$app['view']->assign('hotel_last_synced', $hotelLastSynced);
    Tygh::$app['view']->assign('linked_products', $linkedCount);
    Tygh::$app['view']->assign('unlinked_hotels', $unlinkedCount);
    Tygh::$app['view']->assign('selected_countries', $selectedCountries);
    Tygh::$app['view']->assign('is_configured', $isConfigured);
    Tygh::$app['view']->assign('sync_logs', $syncLogs);

    // Cron URLs for the dashboard
    $cron_key = ConfigProvider::getCronAccessKey();
    $base_url = fn_url('', 'C');
    $cron_urls = [
        'destinations'    => $base_url . "index.php?dispatch=sphinx_cron.run&access_key={$cron_key}&cron_mode=destinations",
        'hotels'          => $base_url . "index.php?dispatch=sphinx_cron.run&access_key={$cron_key}&cron_mode=hotels",
        'add_products'    => $base_url . "index.php?dispatch=sphinx_cron.run&access_key={$cron_key}&cron_mode=add_products",
        'package_routes'  => $base_url . "index.php?dispatch=sphinx_cron.run&access_key={$cron_key}&cron_mode=package_routes",
        'circuits'        => $base_url . "index.php?dispatch=sphinx_cron.run&access_key={$cron_key}&cron_mode=circuits",
        'experiences'     => $base_url . "index.php?dispatch=sphinx_cron.run&access_key={$cron_key}&cron_mode=experiences",
        'order_status'    => $base_url . "index.php?dispatch=sphinx_cron.run&access_key={$cron_key}&cron_mode=order_status",
        'cache_refresh'   => $base_url . "index.php?dispatch=sphinx_cron.run&access_key={$cron_key}&cron_mode=cache_refresh",
        'cleanup'         => $base_url . "index.php?dispatch=sphinx_cron.run&access_key={$cron_key}&cron_mode=cleanup",
        'discover_boards' => $base_url . "index.php?dispatch=sphinx_cron.run&access_key={$cron_key}&cron_mode=discover_boards",
        'assign_boards'   => $base_url . "index.php?dispatch=sphinx_cron.run&access_key={$cron_key}&cron_mode=assign_boards",
        'exchange_rates'  => $base_url . "index.php?dispatch=travel_cron.run&access_key=" . \Tygh\Registry::get('addons.travel_core.cron_access_key') . "&cron_mode=exchange_rates",
    ];
    Tygh::$app['view']->assign('cron_urls', $cron_urls);
    Tygh::$app['view']->assign('cron_key', $cron_key);

} elseif ($mode === 'destinations') {
    $repository = new DestinationRepository();

    $params = [
        'type'      => $_REQUEST['type'] ?? '',
        'parent_id' => (int) ($_REQUEST['parent_id'] ?? 0),
        'q'         => $_REQUEST['q'] ?? '',
        'page'      => max(1, (int) ($_REQUEST['page'] ?? 1)),
        'items_per_page' => (int) ($_REQUEST['items_per_page'] ?? 50),
    ];

    if (!empty($params['q'])) {
        $items = $repository->search($params['q'], 200);
        $total = count($items);
    } else {
        $result = $repository->getFiltered($params['type'], $params['parent_id'], $params['page'], $params['items_per_page']);
        $items = $result['items'];
        $total = $result['total'];
    }

    Tygh::$app['view']->assign('destinations', $items);
    Tygh::$app['view']->assign('search', $params);
    Tygh::$app['view']->assign('total_items', $total);

} elseif ($mode === 'hotels') {
    $hotelRepo = new HotelRepository();

    $params = [
        'country_code'   => $_REQUEST['country_code'] ?? '',
        'destination_id' => (int) ($_REQUEST['destination_id'] ?? 0),
        'region_id'      => (int) ($_REQUEST['region_id'] ?? 0),
        'sync_status'    => $_REQUEST['sync_status'] ?? '',
        'q'              => $_REQUEST['q'] ?? '',
        'page'           => max(1, (int) ($_REQUEST['page'] ?? 1)),
        'items_per_page' => (int) ($_REQUEST['items_per_page'] ?? 50),
    ];

    $result = $hotelRepo->getFiltered(
        $params['country_code'],
        $params['destination_id'],
        $params['region_id'],
        $params['sync_status'],
        $params['q'],
        $params['page'],
        $params['items_per_page']
    );

    // Get distinct countries for filter dropdown
    $distinctCountries = $hotelRepo->getDistinctCountries();

    Tygh::$app['view']->assign('hotels', $result['items']);
    Tygh::$app['view']->assign('search', $params);
    Tygh::$app['view']->assign('total_items', $result['total']);
    Tygh::$app['view']->assign('distinct_countries', $distinctCountries);
}

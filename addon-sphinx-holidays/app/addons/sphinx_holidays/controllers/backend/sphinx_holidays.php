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

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

$mode = $_REQUEST['mode'] ?? 'manage';

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
    Tygh::$app['view']->assign('selected_countries', $selectedCountries);
    Tygh::$app['view']->assign('is_configured', $isConfigured);
    Tygh::$app['view']->assign('sync_logs', $syncLogs);

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
        'sync_status'    => $_REQUEST['sync_status'] ?? '',
        'q'              => $_REQUEST['q'] ?? '',
        'page'           => max(1, (int) ($_REQUEST['page'] ?? 1)),
        'items_per_page' => (int) ($_REQUEST['items_per_page'] ?? 50),
    ];

    $result = $hotelRepo->getFiltered(
        $params['country_code'],
        $params['destination_id'],
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

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

        @set_time_limit(0);

        $api = Container::getApi();
        $repository = Container::getDestinationRepository();
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

        @set_time_limit(0);

        $api = Container::getApi();
        $hotelRepo = Container::getHotelRepository();
        $destRepo = Container::getDestinationRepository();
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
        $hotelRepo = Container::getHotelRepository();
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

    if ($mode === 'retry_skipped') {
        $hotelRepo = Container::getHotelRepository();
        $reset = $hotelRepo->resetSkipped();

        if ($reset > 0) {
            fn_set_notification('N', __('notice'), __('sphinx_holidays.skipped_reset', ['[count]' => $reset]));
        } else {
            fn_set_notification('W', __('warning'), __('sphinx_holidays.no_skipped_hotels'));
        }

        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_holidays.manage'];
    }

    if ($mode === 'save_whitelist') {
        // Single JSON field to avoid PHP max_input_vars limit
        $whitelist = json_decode($_REQUEST['whitelist_json'] ?? '[]', true) ?: [];

        db_query("START TRANSACTION");
        try {
            // Clear existing whitelist
            db_query("DELETE FROM ?:sphinx_destination_whitelist");

            if (!empty($whitelist) && is_array($whitelist)) {
                foreach ($whitelist as $entry) {
                    $destId = (int) ($entry['destination_id'] ?? 0);
                    $selType = ($entry['selection_type'] ?? 'specific') === 'all' ? 'all' : 'specific';
                    if ($destId > 0) {
                        db_query(
                            "INSERT INTO ?:sphinx_destination_whitelist (destination_id, selection_type) VALUES (?i, ?s)
                             ON DUPLICATE KEY UPDATE selection_type = ?s",
                            $destId, $selType, $selType
                        );
                    }
                }
            }

            db_query("COMMIT");
        } catch (\Exception $e) {
            db_query("ROLLBACK");
            fn_set_notification('E', __('error'), __('sphinx_holidays.whitelist_save_failed'));
            return [CONTROLLER_STATUS_REDIRECT, 'sphinx_holidays.whitelist'];
        }

        fn_set_notification('N', __('notice'), __('sphinx_holidays.whitelist_saved'));
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_holidays.whitelist'];
    }

    if ($mode === 'bulk_update_hotels') {
        $hotelIds = $_REQUEST['hotel_ids'] ?? [];
        $status = $_REQUEST['bulk_status'] ?? '';

        if (empty($hotelIds) || !is_array($hotelIds)) {
            fn_set_notification('W', __('warning'), __('sphinx_holidays.no_hotels_selected'));
            return [CONTROLLER_STATUS_REDIRECT, 'sphinx_holidays.hotels'];
        }

        $hotelRepo = Container::getHotelRepository();
        $affected = $hotelRepo->bulkUpdateStatus($hotelIds, $status);
        fn_set_notification('N', __('notice'), __('sphinx_holidays.hotels_updated') . ': ' . $affected);

        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_holidays.hotels'];
    }

    if ($mode === 'bulk_delete_hotels') {
        $hotelIds = $_REQUEST['hotel_ids'] ?? [];

        if (empty($hotelIds) || !is_array($hotelIds)) {
            fn_set_notification('W', __('warning'), __('sphinx_holidays.no_hotels_selected'));
            return [CONTROLLER_STATUS_REDIRECT, 'sphinx_holidays.hotels'];
        }

        $hotelRepo = Container::getHotelRepository();
        $affected = $hotelRepo->bulkDelete($hotelIds);
        fn_set_notification('N', __('notice'), __('sphinx_holidays.hotels_deleted') . ': ' . $affected);

        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_holidays.hotels'];
    }

    if ($mode === 'bulk_sync_images') {
        $hotelIds = $_REQUEST['hotel_ids'] ?? [];

        if (empty($hotelIds) || !is_array($hotelIds)) {
            fn_set_notification('W', __('warning'), __('sphinx_holidays.no_hotels_selected'));
            return [CONTROLLER_STATUS_REDIRECT, 'sphinx_holidays.hotels'];
        }

        @set_time_limit(0);

        $hotelRepo = Container::getHotelRepository();
        $synced = 0;

        foreach ($hotelIds as $hotelId) {
            $hotel = $hotelRepo->getById((string) $hotelId);
            if ($hotel === null || empty($hotel['product_id']) || empty($hotel['image_url'])) {
                continue;
            }
            if (fn_sphinx_holidays_add_product_image((int) $hotel['product_id'], $hotel['image_url'])) {
                $synced++;
            }
        }

        fn_set_notification('N', __('notice'), __('sphinx_holidays.images_synced') . ': ' . $synced);

        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_holidays.hotels'];
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
    $destRepo = Container::getDestinationRepository();
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
    $destRepo = Container::getDestinationRepository();
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
    $destRepo = Container::getDestinationRepository();
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

if ($mode === 'get_whitelist_children') {
    header('Content-Type: application/json; charset=utf-8');
    $countryId = (int) ($_REQUEST['country_id'] ?? 0);
    if ($countryId <= 0) {
        echo json_encode(['children' => []]);
        exit;
    }
    $countryCode = db_get_field("SELECT country_code FROM ?:sphinx_destinations WHERE destination_id = ?i", $countryId);
    if (empty($countryCode)) {
        echo json_encode(['children' => []]);
        exit;
    }
    $childIds = db_get_fields(
        "SELECT w.destination_id FROM ?:sphinx_destination_whitelist w
         JOIN ?:sphinx_destinations d ON w.destination_id = d.destination_id
         WHERE d.country_code = ?s AND d.type != 'country'",
        $countryCode
    );
    echo json_encode(['children' => array_map('intval', $childIds)]);
    exit;
}

if ($mode === 'search_destinations') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim((string) ($_REQUEST['q'] ?? ''));
    if (strlen($q) < 2) {
        echo json_encode(['results' => []]);
        exit;
    }
    $destRepo = Container::getDestinationRepository();
    $results = $destRepo->search($q, 30);
    $formatted = [];
    foreach ($results as $r) {
        $formatted[] = [
            'destination_id' => (int) $r['destination_id'],
            'name' => $r['name'],
            'type' => $r['type'],
            'country_code' => $r['country_code'] ?? '',
            'full_path' => $r['full_path'] ?? $r['name'],
        ];
    }
    echo json_encode(['results' => $formatted]);
    exit;
}

// ─── GET handlers ───

if ($mode === 'manage') {
    $destRepo = Container::getDestinationRepository();
    $hotelRepo = Container::getHotelRepository();

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
    $skippedCount = $hotelRepo->countSkipped();
    $unlinkedCount = $totalHotels - $linkedCount - $skippedCount;

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
    Tygh::$app['view']->assign('skipped_hotels', $skippedCount);
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
        'update_products' => $base_url . "index.php?dispatch=sphinx_cron.run&access_key={$cron_key}&cron_mode=update_products",
        'sync_images'     => $base_url . "index.php?dispatch=sphinx_cron.run&access_key={$cron_key}&cron_mode=sync_images",
    ];
    Tygh::$app['view']->assign('cron_urls', $cron_urls);
    Tygh::$app['view']->assign('cron_key', $cron_key);

} elseif ($mode === 'destinations') {
    $repository = Container::getDestinationRepository();

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
    [$hotels, $search] = fn_sphinx_holidays_get_hotels($_REQUEST);

    $hotelRepo = Container::getHotelRepository();
    $distinctCountries = $hotelRepo->getDistinctCountries();
    $distinctClassifications = $hotelRepo->getDistinctClassifications();
    $distinctPropertyTypes = $hotelRepo->getDistinctPropertyTypes();

    // Build sort URL base preserving all current filter params (safe URL encoding)
    $sortFilterParams = array_filter([
        'country_code'   => $search['country_code'],
        'region_id'      => $search['region_id'] ?: null,
        'destination_id' => $search['destination_id'] ?: null,
        'sync_status'    => $search['sync_status'],
        'classification' => $search['classification'],
        'property_type'  => $search['property_type'],
        'link_status'    => $search['link_status'],
        'q'              => $search['q'],
        'items_per_page' => $search['items_per_page'],
    ], static fn($v) => $v !== '' && $v !== null);
    $sortUrlBase = 'sphinx_holidays.hotels?' . http_build_query($sortFilterParams);

    Tygh::$app['view']->assign('hotels', $hotels);
    Tygh::$app['view']->assign('search', $search);
    Tygh::$app['view']->assign('total_items', $search['total_items']);
    Tygh::$app['view']->assign('sort_url_base', $sortUrlBase);
    Tygh::$app['view']->assign('distinct_countries', $distinctCountries);
    Tygh::$app['view']->assign('distinct_classifications', $distinctClassifications);
    Tygh::$app['view']->assign('distinct_property_types', $distinctPropertyTypes);

} elseif ($mode === 'whitelist') {
    $destRepo = Container::getDestinationRepository();
    $countsByType = $destRepo->getCountsByType();
    $totalDestinations = $destRepo->getTotal();

    // Get all countries for the tree
    $countries = $destRepo->getCountries();

    // Get current whitelist entries
    $whitelistRows = db_get_array("SELECT * FROM ?:sphinx_destination_whitelist");
    $whitelistMap = []; // destination_id => selection_type
    foreach ($whitelistRows as $row) {
        $whitelistMap[(int) $row['destination_id']] = $row['selection_type'];
    }

    // For each whitelisted country, get child count for badge display
    $countryData = [];
    foreach ($countries as $country) {
        $cid = (int) $country['destination_id'];
        $isWhitelisted = isset($whitelistMap[$cid]);
        $selectionType = $whitelistMap[$cid] ?? null;

        $childCount = 0;
        $whitelistedChildren = [];
        if ($isWhitelisted && $selectionType !== 'all') {
            // Get whitelisted children for this country
            $childIds = db_get_fields(
                "SELECT d.destination_id FROM ?:sphinx_destinations d
                 JOIN ?:sphinx_destination_whitelist w ON d.destination_id = w.destination_id
                 WHERE d.country_code = ?s AND d.type != 'country'",
                $country['country_code']
            );
            $whitelistedChildren = array_map('intval', $childIds);
            $childCount = count($whitelistedChildren);
        }

        $countryData[] = [
            'destination_id' => $cid,
            'name' => $country['name'],
            'country_code' => $country['country_code'],
            'is_whitelisted' => $isWhitelisted,
            'selection_type' => $selectionType,
            'whitelisted_child_count' => $childCount,
        ];
    }

    // Summary stats — single query instead of N+1
    $whitelistedTypeCounts = db_get_hash_single_array(
        "SELECT d.type, COUNT(*) as cnt FROM ?:sphinx_destination_whitelist w
         JOIN ?:sphinx_destinations d ON w.destination_id = d.destination_id
         GROUP BY d.type",
        ['type', 'cnt']
    );
    $whitelistedCountryCount = (int) ($whitelistedTypeCounts['country'] ?? 0);
    $whitelistedRegionCount = array_sum($whitelistedTypeCounts) - $whitelistedCountryCount;

    // Sample whitelisted city names for summary
    $sampleCities = db_get_fields(
        "SELECT d.name FROM ?:sphinx_destination_whitelist w
         JOIN ?:sphinx_destinations d ON w.destination_id = d.destination_id
         WHERE d.type != 'country'
         ORDER BY d.name LIMIT 5"
    );

    // Per-country whitelist summary: region full/partial counts + city count
    $whitelistSummary = [];
    foreach ($countryData as $cd) {
        if (!$cd['is_whitelisted']) {
            continue;
        }

        $regions = $destRepo->getRegionsByCountry($cd['country_code']);
        $fullRegions = 0;
        $partialRegions = 0;
        $totalCities = 0;

        if ($cd['selection_type'] === 'all') {
            $fullRegions = count($regions);
            $totalCities = (int) db_get_field(
                "SELECT COUNT(*) FROM ?:sphinx_destinations WHERE country_code = ?s AND type IN ('city','destination')",
                $cd['country_code']
            );
        } else {
            foreach ($regions as $region) {
                $rid = (int) $region['destination_id'];
                $citiesInRegion = $destRepo->getCitiesByParent($rid);
                $totalInRegion = count($citiesInRegion);
                $whitelistedInRegion = 0;
                foreach ($citiesInRegion as $city) {
                    if (isset($whitelistMap[(int) $city['destination_id']])) {
                        $whitelistedInRegion++;
                        $totalCities++;
                    }
                }
                if ($whitelistedInRegion > 0) {
                    if ($whitelistedInRegion >= $totalInRegion) {
                        $fullRegions++;
                    } else {
                        $partialRegions++;
                    }
                }
            }
        }

        $whitelistSummary[] = [
            'name'            => $cd['name'],
            'full_regions'    => $fullRegions,
            'partial_regions' => $partialRegions,
            'total_cities'    => $totalCities,
        ];
    }

    Tygh::$app['view']->assign('counts_by_type', $countsByType);
    Tygh::$app['view']->assign('total_destinations', $totalDestinations);
    Tygh::$app['view']->assign('countries', $countryData);
    Tygh::$app['view']->assign('whitelist_map', $whitelistMap);
    Tygh::$app['view']->assign('whitelisted_country_count', $whitelistedCountryCount);
    Tygh::$app['view']->assign('whitelisted_region_count', $whitelistedRegionCount);
    Tygh::$app['view']->assign('sample_cities', $sampleCities);
    Tygh::$app['view']->assign('whitelist_summary', $whitelistSummary);
}

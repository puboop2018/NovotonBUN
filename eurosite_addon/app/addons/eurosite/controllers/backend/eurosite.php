<?php

declare(strict_types=1);
/**
 * Eurosite Touring — backend admin controller.
 *
 * Modes:
 *   - manage (default): dashboard — API health, catalog counts, last syncs,
 *     recent bookings, cron URL map, quick sync actions
 *   - whitelist: destination whitelist editor (countries → cities)
 *   - get_cities (AJAX GET): synced cities of a country as JSON, with a live
 *     getCityRequest fallback for countries not yet synced
 *   - run_sync (POST): run one cron command inline (sync_type param)
 *   - save_whitelist (POST): replace the whitelist (whitelist_json field)
 *   - test_connection (POST): cheap auth probe (getRoomTypes)
 */

use Tygh\Addons\Eurosite\Cron\CronDispatcher;
use Tygh\Addons\Eurosite\Services\ConfigProvider;
use Tygh\Addons\Eurosite\Services\Container;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Tygh;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/** @var \Smarty $view */
$view = Tygh::$app['view'];

// The dashboard is a first-class entry point for schema-dependent reads —
// apply any pending deltas before touching the eurosite tables (per-request
// guarded; also covers stores whose init-time heal was stamped early).
if (function_exists('fn_eurosite_ensure_schema')) {
    fn_eurosite_ensure_schema();
}

// ─── POST handlers ───

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($mode === 'run_sync') {
        $syncType = (string) preg_replace('/[^a-z0-9_]/', '', strtolower(RequestCoerce::string($_REQUEST, 'sync_type')));
        $dispatcher = new CronDispatcher();
        if (!$dispatcher->hasMode($syncType)) {
            fn_set_notification('E', __('error'), "Unknown sync type: {$syncType}");

            return [CONTROLLER_STATUS_REDIRECT, 'eurosite.manage'];
        }

        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }
        // The dispatcher echoes progress lines (cron context); buffer them
        // away so they never leak into the admin page.
        ob_start();
        try {
            $result = $dispatcher->dispatch($syncType, []);
        } finally {
            ob_end_clean();
        }

        $success = TypeCoerce::toBool($result['success'] ?? false);
        $stats = TypeCoerce::toStringMap($result['stats'] ?? []);
        $summary = TypeCoerce::toInt($stats['synced'] ?? 0) . '/' . TypeCoerce::toInt($stats['total'] ?? 0) . ' synced';
        if (!empty($result['busy'])) {
            fn_set_notification('W', __('warning'), TypeCoerce::toString($result['message'] ?? 'Sync already running.'));
        } elseif ($success) {
            fn_set_notification('N', __('notice'), "Eurosite sync '{$syncType}' completed: {$summary}");
        } else {
            fn_set_notification('E', __('error'), "Eurosite sync '{$syncType}' failed: " . TypeCoerce::toString($result['error'] ?? 'unknown error'));
        }

        return [CONTROLLER_STATUS_REDIRECT, 'eurosite.manage'];
    }

    if ($mode === 'save_whitelist') {
        $raw = RequestCoerce::string($_REQUEST, 'whitelist_json');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            fn_set_notification('E', __('error'), 'Invalid whitelist payload.');

            return [CONTROLLER_STATUS_REDIRECT, 'eurosite.whitelist'];
        }
        $entries = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $entries[] = [
                'country_code'   => strtoupper(TypeCoerce::toString($entry['country_code'] ?? '')),
                'city_code'      => strtoupper(TypeCoerce::toString($entry['city_code'] ?? '')),
                'selection_type' => TypeCoerce::toString($entry['selection_type'] ?? 'specific'),
            ];
        }
        Container::whitelist()->replaceAll($entries);
        fn_set_notification('N', __('notice'), 'Eurosite destination whitelist saved (' . count($entries) . ' entries).');

        return [CONTROLLER_STATUS_REDIRECT, 'eurosite.whitelist'];
    }

    if ($mode === 'seed_menu') {
        $created = function_exists('fn_eurosite_seed_storefront_menu') ? fn_eurosite_seed_storefront_menu() : 0;
        if ($created > 0) {
            fn_set_notification('N', __('notice'), "Storefront menu seeded ({$created} items). Review it under Design > Menus.");
        } else {
            fn_set_notification('W', __('warning'), 'Storefront menu items already exist (or seeding is unsupported on this build) — manage them under Design > Menus.');
        }

        return [CONTROLLER_STATUS_REDIRECT, 'eurosite.manage'];
    }

    if ($mode === 'test_connection') {
        try {
            $rooms = Container::getApi()->getRoomTypes();
            fn_set_notification('N', __('notice'), 'Eurosite API OK — room-type catalog answered with ' . count($rooms) . ' entries.');
        } catch (\Throwable $e) {
            fn_set_notification('E', __('error'), 'Eurosite API error: ' . $e->getMessage());
        }

        return [CONTROLLER_STATUS_REDIRECT, 'eurosite.manage'];
    }

    return [CONTROLLER_STATUS_REDIRECT, 'eurosite.manage'];
}

// ─── GET modes ───

if ($mode === 'get_cities') {
    // AJAX: cities of one country for the whitelist editor. Synced rows
    // first; live fallback so the editor works before the first city sync.
    $country = strtoupper((string) preg_replace('/[^A-Za-z]/', '', RequestCoerce::string($_REQUEST, 'country')));
    $cities = [];
    $source = 'db';
    if ($country !== '') {
        foreach (Container::cities()->getByCountry($country) as $row) {
            $cities[] = [
                'code'   => TypeCoerce::toString($row['city_code'] ?? ''),
                'name'   => TypeCoerce::toString($row['name'] ?? ''),
                'is_own' => TypeCoerce::toString($row['is_own'] ?? 'N') === 'Y',
            ];
        }
        if ($cities === []) {
            $source = 'live';
            try {
                foreach (Container::getApi()->getCities($country) as $city) {
                    $cities[] = ['code' => $city['code'], 'name' => $city['name'], 'is_own' => false];
                }
            } catch (\Throwable $e) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                exit;
            }
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'source' => $source, 'cities' => $cities]);
    exit;
}

if ($mode === 'whitelist') {
    $countries = Container::countries()->findAll();
    $entries = Container::whitelist()->findAll();

    // country => ['all' => bool, 'cities' => list<string>]
    $whitelistMap = [];
    foreach ($entries as $entry) {
        $cc = TypeCoerce::toString($entry['country_code'] ?? '');
        $city = TypeCoerce::toString($entry['city_code'] ?? '');
        if ($cc === '') {
            continue;
        }
        $whitelistMap[$cc] = $whitelistMap[$cc] ?? ['all' => false, 'cities' => []];
        if ($city === '') {
            $whitelistMap[$cc]['all'] = true;
        } else {
            $whitelistMap[$cc]['cities'][] = $city;
        }
    }

    $view->assign('eurosite_countries', $countries);
    $view->assign('eurosite_whitelist_map', $whitelistMap);
    $view->assign('eurosite_whitelist_json', json_encode($whitelistMap));
    $view->assign('eurosite_countries_synced', $countries !== []);

    return;
}

if ($mode === 'manage' || empty($mode)) {
    // Never 503 the dashboard: any data failure (missing table on a store
    // whose heal has not run yet, DB hiccough) degrades to an error banner
    // on an otherwise-rendered page, so the admin sees WHAT is wrong.
    $counts = [
        'countries' => 0, 'cities' => 0, 'own_cities' => 0, 'hotels' => 0,
        'room_types' => 0, 'tags' => 0, 'cache' => 0, 'whitelist' => 0, 'bookings' => 0,
    ];
    $lastSyncs = [];
    $recentBookings = [];
    try {
        $syncLog = Container::syncLog();

        $counts = [
            'countries'  => Container::countries()->count(),
            'cities'     => Container::cities()->count(),
            'own_cities' => Container::cities()->count(true),
            'hotels'     => Container::hotels()->count(),
            'room_types' => Container::roomTypes()->count(),
            'tags'       => Container::tags()->count(),
            'cache'      => Container::productInfoCache()->count(),
            'whitelist'  => Container::whitelist()->count(),
            'bookings'   => Container::bookings()->count(),
        ];

        // Pre-format for Smarty 5 (modifiers throw inside the admin capture).
        foreach ($syncLog->getLastPerType() as $type => $row) {
            $lastSyncs[$type] = [
                'status'     => TypeCoerce::toString($row['status'] ?? ''),
                'synced'     => TypeCoerce::toInt($row['items_synced'] ?? 0),
                'total'      => TypeCoerce::toInt($row['items_total'] ?? 0),
                'started_at' => TypeCoerce::toString($row['started_at'] ?? ''),
                'duration_s' => round(TypeCoerce::toFloat($row['duration_ms'] ?? 0) / 1000, 1),
                'error'      => TypeCoerce::toString($row['error_message'] ?? ''),
            ];
        }

        foreach (Container::bookings()->getRecent(10) as $row) {
            $recentBookings[] = [
                'booking_id'  => TypeCoerce::toInt($row['booking_id'] ?? 0),
                'hotel_name'  => TypeCoerce::toString($row['hotel_name'] ?? ''),
                'check_in'    => TypeCoerce::toString($row['check_in'] ?? ''),
                'status'      => TypeCoerce::toString($row['status'] ?? ''),
                'total'       => number_format(TypeCoerce::toFloat($row['total_price'] ?? 0), 2)
                    . ' ' . TypeCoerce::toString($row['currency'] ?? 'EUR'),
                'order_id'    => TypeCoerce::toInt($row['order_id'] ?? 0),
                'created_at'  => TypeCoerce::toString($row['created_at'] ?? ''),
            ];
        }
    } catch (\Throwable $e) {
        fn_set_notification('E', __('error'), 'Eurosite dashboard data unavailable: ' . $e->getMessage());
        fn_log_event('general', 'runtime', ['message' => 'Eurosite dashboard error: ' . $e->getMessage()]);
    }

    $syncModes = CronDispatcher::getAvailableModes();

    // One prepared row per catalog (the template stays expression-free —
    // Smarty-5 array literals in {foreach} were a 503 risk).
    $catalogRows = [];
    foreach (['countries', 'cities', 'own_cities', 'hotels', 'room_types', 'tags', 'product_info'] as $catalog) {
        $countKey = $catalog === 'product_info' ? 'cache' : $catalog;
        $catalogRows[] = [
            'key'      => $catalog,
            'count'    => $counts[$countKey] ?? 0,
            'last'     => $lastSyncs[$catalog] ?? null,
            'syncable' => isset($syncModes[$catalog]),
        ];
    }

    $cronKey = ConfigProvider::getCronAccessKey();
    $baseUrl = TypeCoerce::toString(\Tygh\Registry::get('config.http_location')) . '/';
    $cronUrls = [];
    foreach (array_keys($syncModes) as $m) {
        $cronUrls[$m] = $baseUrl . "index.php?dispatch=eurosite_cron.run&access_key={$cronKey}&cron_mode={$m}";
    }

    $apiUser = ConfigProvider::getApiUser();
    $view->assign('eurosite_counts', $counts);
    $view->assign('eurosite_catalog_rows', $catalogRows);
    $view->assign('eurosite_recent_bookings', $recentBookings);
    $view->assign('eurosite_sync_modes', $syncModes);
    $view->assign('eurosite_cron_urls', $cronUrls);
    $view->assign('eurosite_cron_key', $cronKey);
    $view->assign('eurosite_api_url', ConfigProvider::getApiUrl());
    $view->assign('eurosite_api_user', $apiUser);
    $view->assign('eurosite_is_configured', $apiUser !== '' && $apiUser !== 'YourUser');

    return;
}

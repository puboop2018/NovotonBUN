<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Main Backend Controller
 *
 * All template-rendering modes live directly in this file so CS-Cart's
 * dispatch can always resolve the template at:
 *   views/novoton_holidays/{mode}.tpl
 *
 * Modes that call exit() (streaming) or return status arrays (redirects)
 * are delegated to sub-controller files via include — those never reach
 * CS-Cart's template resolver so the include mechanism works fine.
 *
 * @package NovotonHolidays
 * @since 2.8.0
 */

use Tygh\Registry;
use Tygh\Tygh;
use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\NovotonApi;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\Container;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

// ============================================================================
// DELEGATION: Modes that never need CS-Cart template resolution
// (they either call exit() or return a status array).
// These are safe to include from sub-controller files.
// ============================================================================

// Hotels: exit/redirect modes only
$hotels_delegate = [
    'sync_facilities', 'sync_hotel_facilities', 'save_facilities', 'check_packages'
];

// Hotels: add_hotels_as_products with &run= streams output and calls exit()
if ($mode === 'add_hotels_as_products' && isset($_REQUEST['run'])) {
    $result = include __DIR__ . '/novoton_hotels.php';
    if (is_array($result)) { return $result; }
    // The run branch calls exit(), so we never reach here
}

// Prices: all modes either exit() or return redirect
$prices_delegate = [
    'update_prices', 'check_prices', 'check_prices_hotel',
    'download_active_prices_csv', 'cron_offers_update'
];

// Tools: exit/redirect modes only
$tools_delegate = [
    'test_api', 'test_formats', 'test_product', 'test_hotel_list', 'test_room_price',
    'test_search', 'test_facilities',
    'export_hotel_features_csv', 'get_hotel_features_csv',
    'cron_export_hotel_features',
    'export_hotel_features_xml', 'download_hotel_features_xml'
];

$include_map = [
    ['modes' => $hotels_delegate, 'file' => __DIR__ . '/novoton_hotels.php'],
    ['modes' => $prices_delegate, 'file' => __DIR__ . '/novoton_prices.php'],
    ['modes' => $tools_delegate,  'file' => __DIR__ . '/novoton_tools.php'],
];

foreach ($include_map as $entry) {
    if (in_array($mode, $entry['modes'], true)) {
        $result = include $entry['file'];
        if (is_array($result)) {
            return $result;
        }
        // These modes should always exit() or return arrays — never reach here.
        // But if they do, fall through to end of file for safety.
        break;
    }
}

// ============================================================================
// TEMPLATE-RENDERING MODES
// These assign Smarty variables and fall through to end of file.
// CS-Cart then renders views/novoton_holidays/{mode}.tpl
// ============================================================================

// ============================================================================
// POST HANDLERS
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($mode === 'save_excluded_resorts') {
        if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
            return [CONTROLLER_STATUS_DENIED];
        }

        $excluded = isset($_POST['excluded_resorts']) ? $_POST['excluded_resorts'] : [];

        $clean_excluded = [];
        if (is_array($excluded)) {
            foreach ($excluded as $resort) {
                $resort = trim($resort);
                if (!empty($resort)) {
                    $clean_excluded[] = $resort;
                }
            }
        }

        $value = json_encode(array_unique($clean_excluded));
        db_query(
            "UPDATE ?:addon_options SET value = ?s WHERE addon = ?s AND option_id = 'excluded_resorts'",
            $value,
            \Tygh\Addons\NovotonHolidays\Constants::ADDON_ID
        );

        Registry::del('addons.novoton_holidays');

        fn_set_notification('N', __('notice'), 'Excluded resorts saved: ' . count($clean_excluded) . ' resorts');

        return [CONTROLLER_STATUS_REDIRECT, 'novoton_holidays.manage'];
    }
}

/**
 * Mode: fix_tab
 */
if ($mode === 'fix_tab') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    $result = fn_novoton_holidays_fix_tab_name();

    if ($result) {
        fn_set_notification('N', __('notice'), 'Product tab name fixed successfully');
    } else {
        fn_set_notification('W', __('warning'), 'No tab found to fix');
    }

    return [CONTROLLER_STATUS_REDIRECT, 'addons.update&addon=novoton_holidays'];
}

/**
 * Mode: recompute_calendar_prices
 */
if ($mode === 'recompute_calendar_prices') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    $hotelRepo = Container::getInstance()->hotelRepository();
    $hotel_ids = $hotelRepo->findIdsWithPriceinfoData();

    $count = 0;
    $errors = 0;
    foreach ($hotel_ids as $hid) {
        try {
            \Tygh\Addons\NovotonHolidays\Services\PriceInfoService::precomputeCalendarPrices((string) $hid);
            $count++;
        } catch (\Throwable $e) {
            $errors++;
        }
    }

    $with_prices = $hotelRepo->countWithCalendarPrices();

    $msg = "Calendar prices recomputed for {$count} / " . count($hotel_ids) . " hotels."
         . " ({$with_prices} hotels now have calendar prices)";
    if ($errors > 0) {
        $msg .= " — {$errors} errors.";
    }
    fn_set_notification('N', __('notice'), $msg);
    return [CONTROLLER_STATUS_REDIRECT, 'novoton_holidays.manage'];
}

// ============================================================================
// TEMPLATE MODES — from novoton_hotels.php
// ============================================================================

/**
 * Mode: add_hotels_as_products (form display only; run branch delegated above)
 */
if ($mode === 'add_hotels_as_products') {
    try {
        $country = (string) preg_replace('/[^A-Z\s]/', '', strtoupper($_REQUEST['country'] ?? 'BULGARIA'));

        $hotelRepo = Container::getInstance()->hotelRepository();

        $stats = [
            'total' => $hotelRepo->count(['country' => $country]),
            'with_prices' => $hotelRepo->count(['country' => $country, 'has_room_price' => 'Y']),
            'with_packages' => $hotelRepo->countWithPackagesByCountry($country),
            'already_products' => $hotelRepo->count(['country' => $country, 'has_product' => true]),
        ];
        $stats['to_add'] = max(0, $stats['with_prices'] - $stats['already_products']);

        $resorts = $hotelRepo->getResortStatsByCountry($country);

        $categories = db_get_array(
            "SELECT c.category_id, cd.category, c.parent_id
             FROM ?:categories c
             LEFT JOIN ?:category_descriptions cd ON c.category_id = cd.category_id AND cd.lang_code = ?s
             WHERE c.status = 'A'
             ORDER BY cd.category",
            CART_LANGUAGE
        );

        $languages = db_get_array("SELECT lang_code, name FROM ?:languages WHERE status = 'A' ORDER BY name");

        $available_countries = ConfigProvider::getSelectedCountries();

        Tygh::$app['view']->assign('country', $country);
        Tygh::$app['view']->assign('stats', $stats);
        Tygh::$app['view']->assign('resorts', $resorts);
        Tygh::$app['view']->assign('categories', $categories);
        Tygh::$app['view']->assign('languages', $languages);
        Tygh::$app['view']->assign('available_countries', $available_countries);
    } catch (\Throwable $e) {
        fn_set_notification('E', __('error'), 'Add Hotels as Products error: ' . $e->getMessage());
        fn_log_event('general', 'runtime', ['message' => 'Novoton: add_hotels_as_products failed: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine()]);
        return [CONTROLLER_STATUS_REDIRECT, 'novoton_holidays.manage'];
    }
}

/**
 * Mode: view_hotels_to_add
 */
if ($mode === 'view_hotels_to_add') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    $country = (string) preg_replace('/[^A-Z\s]/', '', strtoupper($_REQUEST['country'] ?? 'BULGARIA'));
    $filter = in_array($_REQUEST['filter'] ?? '', ['prices', 'packages']) ? $_REQUEST['filter'] : 'prices';

    $hotelRepo = Container::getInstance()->hotelRepository();

    $hotels = $hotelRepo->findUnlinkedForAdmin($country, $filter, 500);

    $stats = [
        'total' => $hotelRepo->count(['country' => $country]),
        'with_prices' => $hotelRepo->count(['country' => $country, 'has_room_price' => 'Y']),
        'with_product' => $hotelRepo->count(['country' => $country, 'has_product' => true]),
        'ready_to_add' => count($hotels)
    ];

    $countries = $hotelRepo->getCountriesWithPriceCounts();

    Tygh::$app['view']->assign('hotels', $hotels);
    Tygh::$app['view']->assign('country', $country);
    Tygh::$app['view']->assign('filter', $filter);
    Tygh::$app['view']->assign('stats', $stats);
    Tygh::$app['view']->assign('countries', $countries);
    Tygh::$app['view']->assign('in_cart_count', $stats['with_product']);
    Tygh::$app['view']->assign('current_year', date('Y'));
}

/**
 * Mode: list_facilities
 */
if ($mode === 'list_facilities') {
    $facilityRepo = Container::getInstance()->facilityRepository();
    $facilities = $facilityRepo->findAllFull();
    $count = count($facilities);
    $last_sync = $facilityRepo->getLastSyncedAt();

    // Build feature type options with CS-Cart feature names for the dropdown.
    // Show all feature types so admins can classify facilities into any category.
    // Feature IDs are stored under addons.travel_core.feature_id_<type> settings
    // (see travel_core/func.php fn_settings_variants_addons_travel_core_feature_id_*).
    $facility_feature_types = \Tygh\Addons\NovotonHolidays\Constants::VALID_FEATURE_TYPES;
    $feature_type_options = [];
    foreach ($facility_feature_types as $ft) {
        $settingKey = 'addons.travel_core.feature_id_' . $ft;
        $featureId = (int) Registry::get($settingKey);
        $label = ucwords(str_replace('_', ' ', $ft));
        if ($featureId > 0) {
            $featureName = db_get_field(
                "SELECT fd.description FROM ?:product_features_descriptions fd WHERE fd.feature_id = ?i AND fd.lang_code = ?s",
                $featureId, DESCR_SL
            );
            if ($featureName) {
                $label .= " → {$featureName} #{$featureId}";
            } else {
                $label .= " → #{$featureId}";
            }
        } else {
            $label .= ' (not configured)';
        }
        $feature_type_options[$ft] = $label;
    }

    Tygh::$app['view']->assign('facilities', $facilities);
    Tygh::$app['view']->assign('facilities_count', $count);
    Tygh::$app['view']->assign('last_sync', $last_sync);
    Tygh::$app['view']->assign('feature_type_options', $feature_type_options);
}

// ============================================================================
// TEMPLATE MODES — from novoton_prices.php
// ============================================================================

/**
 * Mode: room_price
 */
if ($mode === 'room_price') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    $hotel_id = $_REQUEST['hotel_id'] ?? '';
    $check_in = $_REQUEST['check_in'] ?? date('Y-m-d', strtotime('+' . Constants::DEFAULT_CHECKIN_DAYS_AHEAD . ' days'));
    $check_out = $_REQUEST['check_out'] ?? date('Y-m-d', strtotime('+' . (Constants::DEFAULT_CHECKIN_DAYS_AHEAD + Constants::DEFAULT_STAY_NIGHTS) . ' days'));

    Tygh::$app['view']->assign('hotel_id', $hotel_id);
    Tygh::$app['view']->assign('check_in', $check_in);
    Tygh::$app['view']->assign('check_out', $check_out);

    if (!empty($hotel_id) && !empty($_REQUEST['check'])) {
        try {
            $api = new NovotonApi();

            $params = [
                'hotel_id' => $hotel_id,
                'check_in' => $check_in,
                'check_out' => $check_out,
                'adults' => (int)($_REQUEST['adults'] ?? 2),
                'children' => is_array($_REQUEST['children'] ?? []) ? ($_REQUEST['children'] ?? []) : []
            ];

            $result = $api->pricing()->getRoomPrice($params);

            Tygh::$app['view']->assign('result', $result);
            Tygh::$app['view']->assign('last_request', $api->getLastRequestFormatted());
            Tygh::$app['view']->assign('last_response', $api->getLastResponse());

        } catch (\Throwable $e) {
            Tygh::$app['view']->assign('error', $e->getMessage());
        }
    }
}

// ============================================================================
// TEMPLATE MODES — from novoton_tools.php
// ============================================================================

/**
 * Mode: test_hotel_request
 */
if ($mode === 'test_hotel_request') {
    $hotel_id = $_REQUEST['hotel_id'] ?? '';

    Tygh::$app['view']->assign('hotel_id', htmlspecialchars($hotel_id, ENT_QUOTES, 'UTF-8'));
    Tygh::$app['view']->assign('package_name', htmlspecialchars($_REQUEST['package_name'] ?? '', ENT_QUOTES, 'UTF-8'));
    Tygh::$app['view']->assign('check_in', htmlspecialchars($_REQUEST['check_in'] ?? '', ENT_QUOTES, 'UTF-8'));
    Tygh::$app['view']->assign('check_out', htmlspecialchars($_REQUEST['check_out'] ?? '', ENT_QUOTES, 'UTF-8'));
    Tygh::$app['view']->assign('adults', htmlspecialchars($_REQUEST['adults'] ?? '2', ENT_QUOTES, 'UTF-8'));
    Tygh::$app['view']->assign('room_id', htmlspecialchars($_REQUEST['room_id'] ?? '', ENT_QUOTES, 'UTF-8'));
    Tygh::$app['view']->assign('board_id', htmlspecialchars($_REQUEST['board_id'] ?? '', ENT_QUOTES, 'UTF-8'));
    Tygh::$app['view']->assign('holder', htmlspecialchars($_REQUEST['holder'] ?? '', ENT_QUOTES, 'UTF-8'));

    if (!empty($hotel_id)) {
        try {
            $api = new NovotonApi();

            $hotels = $api->hotels();
            $hotel_info = $hotels->getHotelInfo($hotel_id);
            $last_request = $api->getLastRequestFormatted();
            $last_response = $api->getLastResponse();

            $hotel_desc = $hotels->getHotelDescription($hotel_id, 'UK', true);

            Tygh::$app['view']->assign('hotel_info', $hotel_info);
            Tygh::$app['view']->assign('hotel_desc', $hotel_desc);
            Tygh::$app['view']->assign('last_request', $last_request);
            Tygh::$app['view']->assign('last_response', $last_response);

        } catch (\Exception $e) {
            Tygh::$app['view']->assign('error', $e->getMessage());
        }
    }
}

/**
 * Mode: test_alternative_rs
 */
if ($mode === 'test_alternative_rs') {
    $hotel_id = $_REQUEST['hotel_id'] ?? '';
    $id_num = $_REQUEST['id_num'] ?? '';
    $check_in = $_REQUEST['check_in'] ?? date('Y-m-d', strtotime('+' . Constants::DEFAULT_CHECKIN_DAYS_AHEAD . ' days'));
    $check_out = $_REQUEST['check_out'] ?? date('Y-m-d', strtotime('+' . (Constants::DEFAULT_CHECKIN_DAYS_AHEAD + Constants::DEFAULT_STAY_NIGHTS) . ' days'));

    Tygh::$app['view']->assign('hotel_id', htmlspecialchars($hotel_id, ENT_QUOTES, 'UTF-8'));
    Tygh::$app['view']->assign('id_num', htmlspecialchars($id_num, ENT_QUOTES, 'UTF-8'));
    Tygh::$app['view']->assign('check_in', htmlspecialchars($check_in, ENT_QUOTES, 'UTF-8'));
    Tygh::$app['view']->assign('check_out', htmlspecialchars($check_out, ENT_QUOTES, 'UTF-8'));

    if (!empty($_REQUEST['search']) && !empty($hotel_id)) {
        try {
            $api = new NovotonApi();

            $params = [
                'hotel_id' => $hotel_id,
                'check_in' => $check_in,
                'check_out' => $check_out,
                'adults' => (int)($_REQUEST['adults'] ?? 2),
                'children' => (int)($_REQUEST['children'] ?? 0),
            ];

            $results = $api->availability()->searchAvailability($params);

            Tygh::$app['view']->assign('results', $results);
            Tygh::$app['view']->assign('last_request', $api->getLastRequestFormatted());

        } catch (\Exception $e) {
            Tygh::$app['view']->assign('error', $e->getMessage());
        }
    }
}

// ============================================================================
// TEMPLATE MODES — managed directly in this file
// ============================================================================

/**
 * Mode: manage (default)
 * Dashboard with statistics and quick actions
 */
if ($mode === 'manage' || empty($mode)) {
    $hotelRepo = Container::getInstance()->hotelRepository();
    $bookingRepo = Container::getInstance()->bookingRepository();
    $syncLogRepo = Container::getInstance()->syncLogRepository();

    $addon_settings = ConfigProvider::all();

    $countries = fn_novoton_holidays_parse_countries();

    $stats = [
        'hotels' => [
            'total' => $hotelRepo->count(),
            'with_prices' => $hotelRepo->count(['has_verified_room_price' => true]),
            'with_products' => $hotelRepo->count(['has_product' => true]),
            'with_packages' => $hotelRepo->count(['has_packages' => true]),
            'without_packages' => $hotelRepo->count(['no_packages' => true]),
        ],
        'bookings' => $bookingRepo->getStats(),
        'by_country' => []
    ];

    foreach ($countries as $country) {
        $stats['by_country'][$country] = [
            'total' => $hotelRepo->count(['country' => $country]),
            'with_prices' => $hotelRepo->count(['country' => $country, 'has_verified_room_price' => true]),
            'with_packages' => $hotelRepo->count(['country' => $country, 'has_packages' => true]),
            'with_products' => $hotelRepo->count(['country' => $country, 'has_product' => true]),
        ];
    }

    $recent_syncs = $syncLogRepo->findRecent(10);

    $last_syncs = [
        'hotellist' => $syncLogRepo->getLastSyncDate('hotel_list'),
        'hotelinfo' => $syncLogRepo->getLastSyncDate('hotelinfo'),
        'prices' => $syncLogRepo->getLastSyncDate('sync_priceinfo'),
        'offers_update' => $syncLogRepo->getLastSyncDate('offers_update'),
        'facilities' => $syncLogRepo->getLastSyncDate('facilities'),
        'resort_list' => $syncLogRepo->getLastSyncDate('resort_list'),
    ];

    $cron_key = $addon_settings['cron_access_key'] ?? '';
    $base_url = Registry::get('config.http_location') . '/';

    $cron_urls = [
        'hotel_info_batched' => $base_url . "index.php?dispatch=novoton_cron.run&access_key={$cron_key}&mode=hotel_info_batched",
        'sync_priceinfo_batched' => $base_url . "index.php?dispatch=novoton_cron.run&access_key={$cron_key}&mode=sync_priceinfo_batched",
        'hotel_list' => $base_url . "index.php?dispatch=novoton_cron.run&access_key={$cron_key}&mode=hotel_list",
        'resort_list' => $base_url . "index.php?dispatch=novoton_cron.run&access_key={$cron_key}&mode=resort_list",
        'list_facilities' => $base_url . "index.php?dispatch=novoton_cron.run&access_key={$cron_key}&mode=list_facilities",
        'hotel_facilities_batched' => $base_url . "index.php?dispatch=novoton_cron.run&access_key={$cron_key}&mode=hotel_facilities_batched",
        'resinfo' => $base_url . "index.php?dispatch=novoton_cron.run&access_key={$cron_key}&mode=resinfo",
        'offers_update' => $base_url . "index.php?dispatch=novoton_cron.run&access_key={$cron_key}&mode=offers_update",
        'add_products' => $base_url . "index.php?dispatch=novoton_cron.run&access_key={$cron_key}&mode=add_hotels_as_products",
        'compute_prices' => $base_url . "index.php?dispatch=novoton_cron.run&access_key={$cron_key}&mode=compute_prices",
        'recompute_calendar_prices' => $base_url . "index.php?dispatch=novoton_cron.run&access_key={$cron_key}&mode=recompute_calendar_prices",
    ];

    $xml_feed_url = $base_url . "index.php?dispatch=novoton_export.hotel_features_xml&access_key={$cron_key}";

    Tygh::$app['view']->assign('stats', $stats);
    Tygh::$app['view']->assign('countries', $countries);
    Tygh::$app['view']->assign('recent_syncs', $recent_syncs);
    Tygh::$app['view']->assign('last_syncs', $last_syncs);
    Tygh::$app['view']->assign('cron_urls', $cron_urls);
    Tygh::$app['view']->assign('cron_key', $cron_key);
    Tygh::$app['view']->assign('xml_feed_url', $xml_feed_url);
    Tygh::$app['view']->assign('addon_settings', $addon_settings);
    Tygh::$app['view']->assign('addon_version', ConfigProvider::getVersion());

    $resorts_by_country = [];
    $hidden_resorts = array_map('strtoupper', ConfigProvider::getHiddenResorts());
    $resorts = $hotelRepo->getCountryCityPairs();
    foreach ($resorts as $resort) {
        if (!empty($hidden_resorts) && in_array(strtoupper($resort['city']), $hidden_resorts, true)) {
            continue;
        }
        $resorts_by_country[$resort['country']][] = $resort['city'];
    }
    Tygh::$app['view']->assign('resorts_by_country', $resorts_by_country);

    $excluded_resorts = [];
    if (!empty($addon_settings['excluded_resorts'])) {
        $excluded_resorts = json_decode($addon_settings['excluded_resorts'], true);
        if (!is_array($excluded_resorts)) {
            $excluded_resorts = [];
        }
    }
    Tygh::$app['view']->assign('excluded_resorts', $excluded_resorts);
}

/**
 * Mode: hotels
 */
if ($mode === 'hotels') {
    $hotelRepo = Container::getInstance()->hotelRepository();

    $filters = [];
    if (!empty($_REQUEST['country'])) {
        $filters['country'] = $_REQUEST['country'];
    }
    if (!empty($_REQUEST['has_room_price'])) {
        $filters['has_room_price'] = $_REQUEST['has_room_price'];
    }
    if (!empty($_REQUEST['has_product'])) {
        $filters['has_product'] = true;
    }

    $items_per_page = Registry::get('settings.Appearance.admin_elements_per_page') ?: 30;
    $page = (int)($_REQUEST['page'] ?? 1);
    $offset = ($page - 1) * $items_per_page;

    $hotels = $hotelRepo->findAll($filters, $items_per_page, $offset);
    $total = $hotelRepo->count($filters);

    $countries = $hotelRepo->getCountries();

    Tygh::$app['view']->assign('hotels', $hotels);
    Tygh::$app['view']->assign('total', $total);
    Tygh::$app['view']->assign('page', $page);
    Tygh::$app['view']->assign('items_per_page', $items_per_page);
    Tygh::$app['view']->assign('countries', $countries);
    Tygh::$app['view']->assign('filters', $filters);
}

/**
 * Mode: view_hotel
 */
if ($mode === 'view_hotel') {
    $hotel_id = $_REQUEST['hotel_id'] ?? '';

    if (empty($hotel_id)) {
        return [CONTROLLER_STATUS_REDIRECT, 'novoton_holidays.hotels'];
    }

    $hotelRepo = Container::getInstance()->hotelRepository();
    $hotel = $hotelRepo->findById($hotel_id);

    if (!$hotel) {
        fn_set_notification('E', __('error'), 'Hotel not found');
        return [CONTROLLER_STATUS_REDIRECT, 'novoton_holidays.hotels'];
    }

    $packageRepo = Container::getInstance()->hotelPackageRepository();
    $hotel['packages'] = $packageRepo->findForHotelDetail($hotel_id);

    if (!empty($hotel['hotel_data'])) {
        $hotelData = json_decode($hotel['hotel_data'], true);
        if (!empty($hotelData['rooms'])) {
            $hotel['rooms'] = isset($hotelData['rooms']['IdRoom']) ? [$hotelData['rooms']] : $hotelData['rooms'];
        }
        if (!empty($hotelData['boards'])) {
            $hotel['boards'] = isset($hotelData['boards']['IdBoard']) ? [$hotelData['boards']] : $hotelData['boards'];
        }
    }

    $facilities = fn_novoton_holidays_get_hotel_facilities($hotel_id);

    $bookingRepo = Container::getInstance()->bookingRepository();
    $bookings = $bookingRepo->findByHotelId($hotel_id);

    Tygh::$app['view']->assign('hotel', $hotel);
    Tygh::$app['view']->assign('facilities', $facilities);
    Tygh::$app['view']->assign('bookings', $bookings);
}

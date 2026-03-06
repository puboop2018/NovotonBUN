<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Main Backend Controller
 * 
 * Dashboard and routing to sub-controllers.
 * 
 * This controller has been refactored from a 4,600+ line monolith into:
 * - novoton_holidays.php (this file) - Dashboard, manage, fix_tab
 * - novoton_hotels.php - Hotel sync, add products, facilities
 * - novoton_prices.php - Price sync, updates, checks
 * - novoton_tools.php - Test modes, diagnostics, CSV exports
 * 
 * @package NovotonHolidays
 * @since 2.8.0
 */

use Tygh\Registry;
use Tygh\Tygh;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\Container;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

// ============================================================================
// ROUTING: Delegate to sub-controllers based on mode
// ============================================================================

$hotels_modes = [
    'add_hotels_as_products', 'view_hotels_to_add',
    'list_facilities', 'sync_facilities', 'sync_hotel_facilities', 'check_packages'
];

$prices_modes = [
    'update_prices', 'check_prices', 'check_prices_hotel', 'room_price', 'download_active_prices_csv',
    'cron_offers_update'
];

$tools_modes = [
    'test_api', 'test_formats', 'test_product', 'test_hotel_list', 'test_room_price',
    'test_search', 'test_hotel_request', 'test_alternative_rs', 'test_facilities',
    'export_hotel_features_csv', 'get_hotel_features_csv',
    'cron_export_hotel_features',
    'export_hotel_features_xml', 'download_hotel_features_xml'
];

// Include sub-controller files for delegated modes.
//
// Sub-controllers are organized in separate files for maintainability but
// included here so the dispatch stays as novoton_holidays.{mode}. This ensures
// CS-Cart's template resolution always works: it looks for templates at
//   views/novoton_holidays/{mode}.tpl
// which is the addon's own views directory — always discoverable.
//
// The previous redirect approach (redirecting to novoton_hotels.{mode} etc.)
// caused 404 errors because CS-Cart's addon controller cache may not discover
// controller files that don't match the addon name (novoton_holidays).
//
// Modes that call exit() (streaming modes) work directly. Template-rendering
// modes fall through and CS-Cart renders views/novoton_holidays/{mode}.tpl.
// Modes that return a status array (redirects) have their return value captured
// and propagated back to CS-Cart's dispatch mechanism.
$include_map = [
    ['modes' => $hotels_modes, 'file' => __DIR__ . '/novoton_hotels.php'],
    ['modes' => $prices_modes, 'file' => __DIR__ . '/novoton_prices.php'],
    ['modes' => $tools_modes,  'file' => __DIR__ . '/novoton_tools.php'],
];

foreach ($include_map as $entry) {
    if (in_array($mode, $entry['modes'], true)) {
        $result = include $entry['file'];
        // Propagate status arrays (e.g. [CONTROLLER_STATUS_REDIRECT, 'url'])
        if (is_array($result)) {
            return $result;
        }
        // Template-rendering modes: fall through to CS-Cart's view rendering
        // using views/novoton_holidays/{mode}.tpl
        return;
    }
}

// ============================================================================
// MODES HANDLED IN THIS FILE
// ============================================================================

// ============================================================================
// POST HANDLERS
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Save excluded resorts
    if ($mode == 'save_excluded_resorts') {
        if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
            return [CONTROLLER_STATUS_DENIED];
        }

        $excluded = isset($_POST['excluded_resorts']) ? $_POST['excluded_resorts'] : [];
        
        // Clean and validate
        $clean_excluded = [];
        if (is_array($excluded)) {
            foreach ($excluded as $resort) {
                $resort = trim($resort);
                if (!empty($resort)) {
                    $clean_excluded[] = $resort;
                }
            }
        }
        
        // Save to addon settings
        $value = json_encode(array_unique($clean_excluded));
        db_query(
            "UPDATE ?:addon_options SET value = ?s WHERE addon = 'novoton_holidays' AND option_id = 'excluded_resorts'",
            $value
        );
        
        // Clear registry cache
        Registry::del('addons.novoton_holidays');
        
        fn_set_notification('N', __('notice'), 'Excluded resorts saved: ' . count($clean_excluded) . ' resorts');
        
        return [CONTROLLER_STATUS_REDIRECT, 'novoton_holidays.manage'];
    }
}

/**
 * Mode: fix_tab
 * Fix product tab name if empty
 */
if ($mode == 'fix_tab') {
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
 * Bulk-fill calendar_prices_raw for all hotels with priceinfo_data
 */
if ($mode == 'recompute_calendar_prices') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    $hotel_ids = db_get_fields(
        "SELECT DISTINCT h.hotel_id FROM ?:novoton_hotels h
         INNER JOIN ?:novoton_hotel_packages p ON h.hotel_id = p.hotel_id
         WHERE p.priceinfo_data IS NOT NULL AND p.priceinfo_data != ''"
    );

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

    // Count how many hotels actually got calendar prices populated
    $with_prices = (int) db_get_field(
        "SELECT COUNT(*) FROM ?:novoton_hotels WHERE calendar_prices_raw IS NOT NULL AND calendar_prices_raw != ''"
    );

    $msg = "Calendar prices recomputed for {$count} / " . count($hotel_ids) . " hotels."
         . " ({$with_prices} hotels now have calendar prices)";
    if ($errors > 0) {
        $msg .= " — {$errors} errors.";
    }
    fn_set_notification('N', __('notice'), $msg);
    return [CONTROLLER_STATUS_REDIRECT, 'novoton_holidays.manage'];
}

/**
 * Mode: manage (default)
 * Dashboard with statistics and quick actions
 */
if ($mode == 'manage' || empty($mode)) {
    // Initialize repositories
    $hotelRepo = Container::getInstance()->hotelRepository();
    $bookingRepo = Container::getInstance()->bookingRepository();
    $syncLogRepo = Container::getInstance()->syncLogRepository();
    
    // Get addon settings
    $addon_settings = ConfigProvider::all();

    // Parse selected countries
    $countries = fn_novoton_holidays_parse_countries();
    
    // Gather statistics
    $stats = [
        'hotels' => [
            'total' => $hotelRepo->count(),
            'with_prices' => $hotelRepo->count(['has_room_prices' => true]),
            'with_products' => $hotelRepo->count(['has_product' => true]),
            'with_packages' => $hotelRepo->count(['has_packages' => true]),
            'without_packages' => $hotelRepo->count(['no_packages' => true]),
        ],
        'bookings' => $bookingRepo->getStats(),
        'by_country' => []
    ];

    // Per-country stats
    foreach ($countries as $country) {
        $stats['by_country'][$country] = [
            'total' => $hotelRepo->count(['country' => $country]),
            'with_prices' => $hotelRepo->count(['country' => $country, 'has_room_prices' => true]),
            'with_packages' => $hotelRepo->count(['country' => $country, 'has_packages' => true]),
            'with_products' => $hotelRepo->count(['country' => $country, 'has_product' => true]),
        ];
    }
    
    // Recent sync logs
    $recent_syncs = $syncLogRepo->findRecent(10);
    
    // Last sync dates by type
    $last_syncs = [
        'hotellist' => $syncLogRepo->getLastSyncDate('hotel_list'),
        'hotelinfo' => $syncLogRepo->getLastSyncDate('hotelinfo'),
        'prices' => $syncLogRepo->getLastSyncDate('sync_priceinfo'),
        'offers_update' => $syncLogRepo->getLastSyncDate('offers_update'),
        'facilities' => $syncLogRepo->getLastSyncDate('facilities'),
        'resort_list' => $syncLogRepo->getLastSyncDate('resort_list'),
    ];
    
    // Build cron URLs
    $cron_key = $addon_settings['cron_access_key'] ?? '';
    $base_url = Registry::get('config.http_location') . '/';
    
    // Cron URLs - organized by priority
    $cron_urls = [
        // Recommended batched sync (with resume)
        'hotel_info_batched' => $base_url . "index.php?dispatch=novoton_cron.run&access_key={$cron_key}&mode=hotel_info_batched",
        'sync_priceinfo_batched' => $base_url . "index.php?dispatch=novoton_cron.run&access_key={$cron_key}&mode=sync_priceinfo_batched",
        // Other sync modes
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

    // Live XML feed URL (for CS-Cart Advanced Import "Link to file")
    $xml_feed_url = $base_url . "index.php?dispatch=novoton_export.hotel_features_xml&access_key={$cron_key}";

    // Assign to view
    Tygh::$app['view']->assign('stats', $stats);
    Tygh::$app['view']->assign('countries', $countries);
    Tygh::$app['view']->assign('recent_syncs', $recent_syncs);
    Tygh::$app['view']->assign('last_syncs', $last_syncs);
    Tygh::$app['view']->assign('cron_urls', $cron_urls);
    Tygh::$app['view']->assign('cron_key', $cron_key);
    Tygh::$app['view']->assign('xml_feed_url', $xml_feed_url);
    Tygh::$app['view']->assign('addon_settings', $addon_settings);
    Tygh::$app['view']->assign('addon_version', ConfigProvider::getVersion());
    
    // Get available resorts for exclusion management
    $resorts_by_country = [];
    $resorts = db_get_array("SELECT DISTINCT country, city FROM ?:novoton_hotels WHERE city != '' ORDER BY country, city");
    foreach ($resorts as $resort) {
        $resorts_by_country[$resort['country']][] = $resort['city'];
    }
    Tygh::$app['view']->assign('resorts_by_country', $resorts_by_country);
    
    // Get current excluded resorts
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
 * List all hotels with filters
 */
if ($mode == 'hotels') {
    $hotelRepo = Container::getInstance()->hotelRepository();
    
    // Get filters
    $filters = [];
    if (!empty($_REQUEST['country'])) {
        $filters['country'] = $_REQUEST['country'];
    }
    if (!empty($_REQUEST['has_prices'])) {
        $filters['has_prices'] = $_REQUEST['has_prices'];
    }
    if (!empty($_REQUEST['has_product'])) {
        $filters['has_product'] = true;
    }
    
    // Pagination
    $items_per_page = Registry::get('settings.Appearance.admin_elements_per_page') ?: 30;
    $page = (int)($_REQUEST['page'] ?? 1);
    $offset = ($page - 1) * $items_per_page;
    
    // Get hotels
    $hotels = $hotelRepo->findAll($filters, $items_per_page, $offset);
    $total = $hotelRepo->count($filters);
    
    // Get countries for filter
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
 * View single hotel details
 */
if ($mode == 'view_hotel') {
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
    
    // V3: Get packages from novoton_hotel_packages table
    $hotel['packages'] = db_get_array(
        "SELECT package_id, package_name, min_price, has_early_booking, synced_at
         FROM ?:novoton_hotel_packages WHERE hotel_id = ?s ORDER BY package_name",
        $hotel_id
    );

    // V3: Get rooms and boards from hotel_data JSON
    if (!empty($hotel['hotel_data'])) {
        $hotelData = json_decode($hotel['hotel_data'], true);
        if (!empty($hotelData['rooms'])) {
            $hotel['rooms'] = isset($hotelData['rooms']['IdRoom']) ? [$hotelData['rooms']] : $hotelData['rooms'];
        }
        if (!empty($hotelData['boards'])) {
            $hotel['boards'] = isset($hotelData['boards']['IdBoard']) ? [$hotelData['boards']] : $hotelData['boards'];
        }
    }
    
    // Get facilities
    $facilities = fn_novoton_holidays_get_hotel_facilities($hotel_id);
    
    // Get bookings for this hotel
    $bookingRepo = Container::getInstance()->bookingRepository();
    $bookings = $bookingRepo->findByHotelId($hotel_id);
    
    Tygh::$app['view']->assign('hotel', $hotel);
    Tygh::$app['view']->assign('facilities', $facilities);
    Tygh::$app['view']->assign('bookings', $bookings);
}

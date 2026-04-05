<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Product Hook Functions
 *
 * Responsible for:
 *   - get_products_post: Batch prefetch hotel data for product listings
 *   - gather_additional_product_data_post: Enrich product page with hotel data
 *   - get_product_data_post: Attach hotel_id and packages to product data
 *   - delete_product_post: Clean up bookings when product is deleted
 *
 * @package NovotonHolidays
 * @since   3.0.0
 */

use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\TravelCore\Services\CurrencyService;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

/**
 * Hook: after product list is fetched.
 *
 * Batch pre-fetches hotel data for all hotel products on the page so that
 * subsequent per-product gather_additional_product_data_post calls hit the
 * in-memory cache instead of issuing 2 DB queries each (N+1 fix).
 */
function fn_novoton_holidays_get_products_post(&$products, $params = [], $lang_code = ''): void
{
    if (empty($products)) {
        return;
    }

    try {
        $addon_settings = ConfigProvider::all();
        if (empty($addon_settings) || empty($addon_settings['product_code_prefixes'])) {
            return;
        }

        $hotel_ids = [];
        foreach ($products as $product) {
            if (!empty($product['product_code']) && _nvt_is_hotel_product($product, $addon_settings)) {
                $hotel_id = _nvt_extract_hotel_id($product['product_code']);
                if (!empty($hotel_id)) {
                    $hotel_ids[] = $hotel_id;
                }
            }
        }

        if (!empty($hotel_ids)) {
            fn_novoton_holidays_prefetch_hotel_data($hotel_ids);
        }
    } catch (\Throwable $e) {
        // Prefetch is optional — don't crash
    }
}

/**
 * Hook: gather additional product data - pass prices to templates
 *
 * CRITICAL: This hook runs during template rendering — inside CS-Cart's
 * {capture name="mainbox"} block in index.tpl. ANY uncaught exception
 * or PHP error here corrupts Smarty's output buffer and crashes the page
 * with "Not matching {capture}{/capture}".
 *
 * Defence layers:
 * 1. Custom error handler converts trigger_error() to ErrorException
 *    (CS-Cart's DB layer uses trigger_error for SQL errors — not catchable
 *    by try/catch alone)
 * 2. try/catch(\Throwable) catches all exceptions
 * 3. Safe Smarty defaults assigned in catch block
 */
function fn_novoton_holidays_gather_additional_product_data_post(&$product, $auth, $params): void
{
    if (empty($product['product_id'])) {
        return;
    }

    // ────────────────────────────────────────────────────────────────────
    // CRITICAL: Do NOT modify $product or call $view->assign() here.
    //
    // This hook runs DURING Smarty template rendering. In Smarty 5,
    // $product is already wrapped in a Variable object. Modifying the
    // underlying array through the PHP reference corrupts Smarty's
    // internal scope chain, causing Data::getVariable() infinite
    // recursion that exhausts the 256 MB memory limit (Data.php:265).
    //
    // ALL data is stored in a PHP static registry (_nvt_data_registry).
    // Templates retrieve it via {$hotel_id|nvt_hotel_tab_data} modifier.
    // ────────────────────────────────────────────────────────────────────

    $addon_settings = ConfigProvider::all();
    if (empty($addon_settings) || empty($addon_settings['product_code_prefixes'])) {
        return;
    }

    if (!_nvt_is_hotel_product($product, $addon_settings)) {
        // Store in registry — do NOT modify $product
        _nvt_data_registry('__pid_' . $product['product_id'], [
            'is_hotel_product' => false,
        ]);
        return;
    }

    // Convert PHP errors (including trigger_error from CS-Cart DB layer)
    // into exceptions so our try/catch can handle them.
    $previousHandler = set_error_handler(function ($severity, $message, $file, $line) {
        // Only convert errors, not notices/deprecations
        if ($severity & (E_ERROR | E_USER_ERROR | E_WARNING | E_USER_WARNING | E_RECOVERABLE_ERROR)) {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        }
        return false; // Let PHP's default handler deal with notices
    });

    try {
        _nvt_populate_hotel_product_data($product, $addon_settings);
    } catch (\Throwable $e) {
        $error_detail = sprintf(
            'Novoton: product hook failed for product #%d: [%s] %s in %s:%d',
            $product['product_id'],
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        );

        _nvt_log_error($error_detail, $e);

        // Store safe defaults in registry — do NOT modify $product
        _nvt_data_registry('__pid_' . $product['product_id'], [
            'is_hotel_product' => false,
        ]);
    } finally {
        restore_error_handler();
    }
}

/**
 * Internal: populate all hotel product data and assign to Smarty.
 *
 * Extracted so the caller can wrap in try/catch without deeply nesting
 * the entire function body. Any \Throwable is caught by the caller.
 */
function _nvt_populate_hotel_product_data(array $product, array $addon_settings): void
{
    $hotel_id = _nvt_extract_hotel_id($product['product_code']);

    // ────────────────────────────────────────────────────────────────────
    // ────────────────────────────────────────────────────────────────────

    // Prices from packages table
    $prices = fn_novoton_holidays_get_hotel_prices((int) $product['product_id'], false, $hotel_id);

    // Last sync timestamp
    $hotelRepo = Container::getInstance()->hotelRepository();
    $last_update = !empty($hotel_id)
        ? $hotelRepo->getLatestPackageSyncedAt($hotel_id)
        : null;

    // Hotel info (rooms, packages, board, full data)
    $rooms_data = [];
    $packages_data = [];
    $board_data = [];
    $hotel_full_data = [];
    $active_package = '';

    if (!empty($hotel_id)) {
        $hotel_info = fn_novoton_holidays_get_hotel_data($hotel_id);

        if (!empty($hotel_info)) {
            if (!empty($hotel_info['rooms'])) {
                $rooms_by_id = [];
                foreach ($hotel_info['rooms'] as $room) {
                    $rid = $room['IdRoom'] ?? '';
                    if (!empty($rid)) {
                        $rooms_by_id[$rid] = $room;
                    }
                }
                $rooms_data = $rooms_by_id;
            }
            $packages_data = $hotel_info['packages'] ?? [];
            $board_data = $hotel_info['board'] ?? [];
            $active_package = _nvt_resolve_active_package($packages_data);
            $hotel_full_data = $hotel_info['full_data'] ?? [];
        }
    }

    // Season/early booking data
    $season_dates = [];
    $early_booking = [];
    if (!empty($hotel_id)) {
        $package_data = $hotelRepo->getLatestPriceinfoData($hotel_id);
        if (!empty($package_data)) {
            $priceinfo = json_decode($package_data, true);
            if ($priceinfo) {
                $season_dates = _nvt_parse_seasons($priceinfo);
                $early_booking = _nvt_parse_early_booking($priceinfo);
            }
        }
    }

    // Room age bands
    $room_age_bands = _nvt_build_room_age_bands($prices);

    // Calendar prices
    $calendar_prices_json = '{}';
    $calendar_prices_currency = '';
    $show_calendar_prices = ConfigProvider::isShowCalendarPrices() ? 'Y' : 'N';
    if (!empty($hotel_id) && $show_calendar_prices === 'Y') {
        $display_currency = CurrencyService::getDisplayCurrency();
        $priceInfoService = Container::getInstance()->priceInfoService();
        $calendarData = $priceInfoService->getCalendarPrices($hotel_id, $display_currency, 2);
        if (!empty($calendarData['prices'])) {
            $calendar_prices_json = json_encode($calendarData['prices'], JSON_UNESCAPED_UNICODE);
            $calendar_prices_currency = $calendarData['currency'];
        }
    }

    // Booking form settings
    $show_booking_form = !isset($addon_settings['show_booking_form']) || $addon_settings['show_booking_form'] === 'Y';
    $booking_form_position = $addon_settings['booking_form_position'] ?? 'before_tabs';

    // ────────────────────────────────────────────────────────────────────
    // CRITICAL: Use $product['nvt'] instead of $view->assign() calls.
    //
    // This hook runs DURING Smarty template rendering (inside CS-Cart's
    // fn_gather_additional_product_data, called from index.tpl's {capture}).
    // Calling $view->assign() here modifies the Smarty root scope while
    // child templates are actively resolving variables through Data::
    // getVariable() parent chain traversal. This causes infinite recursion
    // or exponential memory growth in Data.php:265, exhausting the 256 MB
    // memory limit.
    //
    // Instead, we attach ONE key to $product (passed by reference from
    // CS-Cart core). CS-Cart assigns $product to Smarty's $product var
    // AFTER this hook returns. Templates access via $product.nvt.prices,
    // $product.nvt.hotel_id, etc. — a single scope-chain lookup for
    // $product, then direct array access for sub-keys.
    // ────────────────────────────────────────────────────────────────────

    // Pack everything into $product['nvt'] — accessed as $product.nvt.xxx in templates.
    // Do NOT use $view->assign() — it causes the Data.php:265 crash.
    //
    // NOTE: Large data (prices, rooms_data, packages_data, hotel_full_data)
    // is stored in a static registry instead of $product to keep $product
    // small. Smarty wraps each $product key in Variable objects — if the
    // array is deeply nested, it can exhaust 256 MB during scope resolution.
    // Store ALL data in the PHP static registry — do NOT modify $product.
    // Modifying $product during Smarty rendering corrupts the scope chain.
    _nvt_data_registry('__pid_' . $product['product_id'], [
        'is_hotel_product'         => true,
        'hotel_id'                 => $hotel_id,
        'product_id'               => $product['product_id'],
        'show_booking_form'        => $show_booking_form,
        'booking_form_position'    => $booking_form_position,
        'calendar_prices_json'     => $calendar_prices_json,
        'calendar_prices_currency' => $calendar_prices_currency,
        'show_calendar_prices'     => $show_calendar_prices,
        'prices'                   => $prices,
        'rooms_data'               => $rooms_data,
        'packages_data'            => $packages_data,
        'board_data'               => $board_data,
        'hotel_full_data'          => $hotel_full_data,
        'active_package'           => $active_package,
        'season_dates'             => $season_dates,
        'early_booking'            => $early_booking,
        'room_age_bands'           => $room_age_bands,
        'last_update'              => $last_update,
    ]);
}

/**
 * Hook: after getting product data
 *
 * No-op — hotel detection and data enrichment are handled entirely in
 * gather_additional_product_data_post. This hook skeleton is kept for
 * CS-Cart's hook registration system.
 */
function fn_novoton_holidays_get_product_data_post(&$product_data, $auth, $preview, $lang_code): void
{
    if (empty($product_data)) {
        return;
    }

    // No-op: hotel detection and data enrichment are handled entirely in
    // gather_additional_product_data_post. We no longer assign addon-specific
    // keys to $product_data here to avoid polluting Smarty's $product scope
    // chain, which causes Data::getVariable() stack overflow (see #41).
}

/**
 * Hook: after deleting product
 */
function fn_novoton_holidays_delete_product_post($product_id, $product_deleted): void
{
    if (!$product_deleted) {
        return;
    }

    // Clean up bookings
    $bookingRepo = Container::getInstance()->bookingRepository();
    $bookingRepo->deleteByProductId($product_id);

    // Unlink hotel record (the hotel stays, only the CS-Cart link is removed)
    $hotelRepo = Container::getInstance()->hotelRepository();
    $hotelRepo->unlinkProduct($product_id);
}

// ============================================================================
// ERROR LOGGING
// ============================================================================

/**
 * Log an error message to the dedicated novoton_errors.log file.
 * Wrapped in try/catch so logging never crashes the calling code.
 */
function _nvt_log_error(string $message, ?\Throwable $e = null): void
{
    try {
        $logDir = defined('DIR_ROOT') ? DIR_ROOT . '/var/log/' : '';
        if ($logDir) {
            if (!is_dir($logDir)) {
                mkdir($logDir, 0775, true);
            }
            $trace = $e ? "\n" . $e->getTraceAsString() : '';
            file_put_contents(
                $logDir . 'novoton_errors.log',
                date('Y-m-d H:i:s') . ' ' . $message . $trace . "\n\n",
                FILE_APPEND
            );
        }
    } catch (\Throwable $logEx) {
        // Logging must never crash the page
    }
}

// ============================================================================
// PRODUCT HELPERS (private-by-convention)
// ============================================================================

/**
 * Check if a product is a hotel product based on product_code prefix.
 *
 * @param array $product        Product data (must contain 'product_code')
 * @param array $addon_settings Addon settings (must contain 'product_code_prefixes')
 * @return bool
 */
function _nvt_is_hotel_product(array $product, array $addon_settings): bool
{
    if (empty($product['product_code']) || empty($addon_settings['product_code_prefixes'])) {
        return false;
    }

    $prefixes = explode(',', $addon_settings['product_code_prefixes']);

    foreach ($prefixes as $prefix) {
        $prefix = trim($prefix);
        if (!empty($prefix) && str_starts_with($product['product_code'], $prefix)) {
            return true;
        }
    }

    return false;
}

/**
 * Extract hotel ID from product code by stripping known prefixes.
 *
 * @param string $product_code e.g. "NVT12345"
 * @return string|null Hotel ID or null
 */
function _nvt_extract_hotel_id(string $product_code): ?string
{
    foreach (\Tygh\Addons\NovotonHolidays\Services\ConfigProvider::getProductCodePrefixes() as $prefix) {
        if (!empty($prefix) && str_starts_with($product_code, $prefix)) {
            $remainder = substr($product_code, strlen($prefix));
            // Strip optional separator (e.g. "NVT-12345" legacy format)
            $remainder = ltrim($remainder, '-');
            return $remainder !== '' ? $remainder : null;
        }
    }

    // Fallback: extract first digit sequence
    preg_match('/\d+/', $product_code, $matches);
    return $matches[0] ?? null;
}

/**
 * Static registry for large hotel data that must NOT go into $product.
 *
 * Smarty wraps every key in $product with Variable objects. Deeply nested
 * arrays (prices with 200+ entries, full_data with rooms/facilities) cause
 * Smarty 5's Data::getVariable() to exhaust PHP's 256 MB memory limit.
 *
 * This registry stores that data outside the Smarty scope chain entirely.
 * Templates retrieve it via {$hotel_data = fn_nvt_get_data($hotel_id)} or
 * by unpacking at the top of the template.
 *
 * @param string|null $hotel_id Hotel ID to store/retrieve (null = get all)
 * @param array|null  $data     Data to store (null = retrieve)
 * @return array Stored data for the hotel, or empty array
 */
function _nvt_data_registry(?string $hotel_id = null, ?array $data = null): array
{
    static $registry = [];

    if ($hotel_id !== null && $data !== null) {
        $registry[$hotel_id] = $data;
        return $data;
    }

    if ($hotel_id !== null) {
        return $registry[$hotel_id] ?? [];
    }

    return $registry;
}

/**
 * Public accessor for hotel data registry — called from Smarty templates
 * via registered modifier or PHP function in templates.
 */
function fn_nvt_get_hotel_tab_data(string $hotel_id): array
{
    return _nvt_data_registry($hotel_id);
}

/**
 * Load hotel info from cache and assign to Smarty view.
 * @deprecated Use _nvt_populate_hotel_product_data() which packs into $product['nvt']
 */
function _nvt_assign_hotel_info_to_product(array $product, string $hotel_id): void
{
    $hotel_info = fn_novoton_holidays_get_hotel_data($hotel_id);

    if (empty($hotel_info)) {
        return;
    }

    // Assign hotel data directly to Smarty view — NOT to $product.
    // Stuffing large nested arrays (full_data, rooms, packages) into $product
    // causes Smarty's Data class to overflow PHP's stack limit during variable
    // scope resolution, triggering "Maximum call stack size reached" on product
    // detail pages. Templates access this data via dedicated Smarty variables
    // ($rooms_data, $packages_data, etc.), never via $product.novoton_*.

    if (!empty($hotel_info['rooms'])) {
        // Re-index rooms by IdRoom so template can look up by room_id
        $rooms_by_id = [];
        foreach ($hotel_info['rooms'] as $room) {
            $rid = $room['IdRoom'] ?? '';
            if (!empty($rid)) {
                $rooms_by_id[$rid] = $room;
            }
        }
        \Tygh\Tygh::$app['view']->assign('rooms_data', $rooms_by_id);
    }

    if (!empty($hotel_info['packages'])) {
        \Tygh\Tygh::$app['view']->assign('packages_data', $hotel_info['packages']);
    }

    if (!empty($hotel_info['board'])) {
        \Tygh\Tygh::$app['view']->assign('board_data', $hotel_info['board']);
    }

    // Active package name
    $active_package = _nvt_resolve_active_package($hotel_info['packages'] ?? []);
    \Tygh\Tygh::$app['view']->assign('active_package', $active_package);

    if (!empty($hotel_info['full_data'])) {
        \Tygh\Tygh::$app['view']->assign('hotel_full_data', $hotel_info['full_data']);
    }
}

/**
 * Determine the active package name from packages data.
 *
 * Prefers the first non-bracketed package name.
 */
function _nvt_resolve_active_package(array $packages_data): string
{
    foreach ($packages_data as $pkg) {
        $pkg_name = is_array($pkg) ? ($pkg['PackageName'] ?? '') : '';
        if (!empty($pkg_name) && substr($pkg_name, -1) !== ']') {
            return $pkg_name;
        }
    }

    if (!empty($packages_data[0])) {
        return is_array($packages_data[0]) ? ($packages_data[0]['PackageName'] ?? '') : '';
    }

    return '';
}

/**
 * Extract season dates and early-booking discounts from packages table
 * and assign to Smarty view.
 */
function _nvt_assign_season_and_early_booking(array $product, string $hotel_id): void
{
    $hotelRepo = Container::getInstance()->hotelRepository();
    $package_data = $hotelRepo->getLatestPriceinfoData($hotel_id);

    $season_dates  = [];
    $early_booking = [];

    if (!empty($package_data)) {
        $priceinfo = json_decode($package_data, true);

        if ($priceinfo) {
            $season_dates  = _nvt_parse_seasons($priceinfo);
            $early_booking = _nvt_parse_early_booking($priceinfo);
        }
    }

    \Tygh\Tygh::$app['view']->assign('season_dates', $season_dates);
    \Tygh\Tygh::$app['view']->assign('early_booking', $early_booking);
}

/**
 * Parse seasons from decoded priceinfo JSON.
 *
 * @return array<int, array{season_number: int, date_from: string, date_to: string, season_name: string}>
 */
function _nvt_parse_seasons(array $priceinfo): array
{
    if (!isset($priceinfo['seasons']['season'])) {
        return [];
    }

    $seasons = $priceinfo['seasons']['season'];

    // Normalize single season to array
    if (isset($seasons['IdSeason'])) {
        $seasons = [$seasons];
    }

    $result = [];
    foreach ($seasons as $idx => $season) {
        $num = isset($season['IdSeason']) ? (int) $season['IdSeason'] : ($idx + 1);
        $result[$num] = [
            'season_number' => $num,
            'date_from'     => $season['DateFrom']   ?? '',
            'date_to'       => $season['DateTo']     ?? '',
            'season_name'   => $season['SeasonName'] ?? "Season {$num}",
        ];
    }

    return $result;
}

/**
 * Parse early-booking discounts from decoded priceinfo JSON.
 *
 * @return array<int, array{booking_from: string, booking_to: string, ...}>
 */
function _nvt_parse_early_booking(array $priceinfo): array
{
    if (!isset($priceinfo['early_booking'])) {
        return [];
    }

    $eb_data = $priceinfo['early_booking'];
    if (isset($eb_data['Reduction'])) {
        $eb_data = [$eb_data];
    }

    $result = [];
    foreach ($eb_data as $eb) {
        $result[] = [
            'booking_from'    => $eb['BookFrom']       ?? '',
            'booking_to'      => $eb['BookTo']         ?? '',
            'stay_from'       => $eb['StayFrom']       ?? '',
            'stay_to'         => $eb['StayTo']         ?? '',
            'reduction'       => $eb['Reduction']      ?? 0,
            'payment_date'    => $eb['PaymentDate']    ?? '',
            'payment_percent' => $eb['PaymentPercent'] ?? 0,
            'room_types'      => $eb['RoomTypes']      ?? 'all',
            'min_stay'        => $eb['MinStay']        ?? 0,
        ];
    }

    return $result;
}

/**
 * Build per-room child age bands from price data.
 *
 * Age bands are extracted dynamically from actual price entries — not
 * hardcoded — because different hotels define different age ranges.
 *
 * @param array $prices Price rows from fn_novoton_holidays_get_hotel_prices()
 * @return array<string, array{has_adult_eb: bool, child_bands: array}>
 */
function _nvt_build_room_age_bands(array $prices): array
{
    $room_age_bands = [];

    foreach ($prices as $p) {
        $rid      = $p['room_id']  ?? '';
        $age_type = strtoupper(trim($p['age_type'] ?? ''));
        if (empty($rid) || empty($age_type)) {
            continue;
        }

        if (!isset($room_age_bands[$rid])) {
            $room_age_bands[$rid] = [
                'has_adult_eb' => false,
                'child_bands'  => [],
            ];
        }

        // Child age bands
        if (str_contains($age_type, 'CHD') || str_contains($age_type, 'CHILD')) {
            if (preg_match('/(\d+(?:[.,]\d+)?)\s*-\s*(\d+(?:[.,]\d+)?)/', $age_type, $m)) {
                $from_raw   = str_replace(',', '.', $m[1]);
                $to_raw     = str_replace(',', '.', $m[2]);
                $band_key   = 'chd_' . $from_raw . '-' . $to_raw;

                // Deduplicate
                $exists = false;
                foreach ($room_age_bands[$rid]['child_bands'] as $existing) {
                    if ($existing['key'] === $band_key) {
                        $exists = true;
                        break;
                    }
                }

                if (!$exists) {
                    $room_age_bands[$rid]['child_bands'][] = [
                        'from'  => $from_raw,
                        'to'    => $to_raw,
                        'label' => $from_raw . '-' . $to_raw,
                        'key'   => $band_key,
                    ];
                }
            }
        }

        // 3RD+ ADULT on EXTRA BED
        $acc_type = strtoupper(trim($p['acc_type'] ?? ''));
        if (preg_match('/\d+\s*(ST|ND|RD|TH)\s*ADULT/i', $age_type)
            && in_array($acc_type, ['EXTRA BED', 'EB', 'EXTRABED'])) {
            $room_age_bands[$rid]['has_adult_eb'] = true;
        }
    }

    // Sort child bands by from_year ascending
    foreach ($room_age_bands as &$rb) {
        usort($rb['child_bands'], function ($a, $b) {
            return (float)($a['from']) <=> (float)($b['from']);
        });
    }
    unset($rb);

    return $room_age_bands;
}

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

    $addon_settings = ConfigProvider::all();
    if (empty($addon_settings) || empty($addon_settings['product_code_prefixes'])) {
        return;
    }

    if (!_nvt_is_hotel_product($product, $addon_settings)) {
        \Tygh\Tygh::$app['view']->assign('is_hotel_product', false);
        \Tygh\Tygh::$app['view']->assign('show_novoton_booking_form', false);
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

        // Log the REAL error to a dedicated file (wrapped so logging never crashes the page)
        try {
            $logDir = defined('DIR_ROOT') ? DIR_ROOT . '/var/log/' : '';
            if ($logDir) {
                if (!is_dir($logDir)) {
                    mkdir($logDir, 0775, true);
                }
                file_put_contents(
                    $logDir . 'novoton_errors.log',
                    date('Y-m-d H:i:s') . ' ' . $error_detail . "\n" . $e->getTraceAsString() . "\n\n",
                    FILE_APPEND
                );
            }
        } catch (\Throwable $logEx) {
            // Silently ignore — logging must never break the product page
        }

        // Assign safe defaults so templates don't crash on missing variables
        $view = \Tygh\Tygh::$app['view'];
        $view->assign('is_hotel_product', false);
        $view->assign('show_novoton_booking_form', false);
        $view->assign('prices', []);
        $view->assign('calendar_prices_json', '{}');
        $view->assign('calendar_prices_currency', '');
        $view->assign('show_calendar_prices', 'N');
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
function _nvt_populate_hotel_product_data(array &$product, array $addon_settings): void
{
    $product['is_hotel_product'] = true;
    $hotel_id = _nvt_extract_hotel_id($product['product_code']);

    // Prices from packages table — assign to Smarty view, NOT to $product.
    // Stuffing large nested arrays into $product causes Smarty stack overflow.
    $prices = fn_novoton_holidays_get_hotel_prices($product['product_id'], false, $hotel_id);

    // Last sync timestamp
    $hotelRepo = Container::getInstance()->hotelRepository();
    $product['novoton_last_update'] = !empty($hotel_id)
        ? $hotelRepo->getLatestPackageSyncedAt($hotel_id)
        : null;

    // Hotel info (rooms, packages, board, full data)
    if (!empty($hotel_id)) {
        _nvt_assign_hotel_info_to_product($product, $hotel_id);
        _nvt_assign_season_and_early_booking($product, $hotel_id);
    }

    // Build per-room child age bands from price data
    $room_age_bands = _nvt_build_room_age_bands($prices);
    \Tygh\Tygh::$app['view']->assign('room_age_bands', $room_age_bands);

    // Assign to Smarty
    \Tygh\Tygh::$app['view']->assign('prices', $prices);
    \Tygh\Tygh::$app['view']->assign('last_update', $product['novoton_last_update']);
    \Tygh\Tygh::$app['view']->assign('product_id', $product['product_id']);
    \Tygh\Tygh::$app['view']->assign('hotel_id', $hotel_id);
    \Tygh\Tygh::$app['view']->assign('is_hotel_product', true);
    \Tygh\Tygh::$app['view']->assign('addon_settings', $addon_settings);

    // Booking form settings
    $show_booking_form    = !isset($addon_settings['show_booking_form']) || $addon_settings['show_booking_form'] === 'Y';
    $booking_form_position = $addon_settings['booking_form_position'] ?? 'before_tabs';

    \Tygh\Tygh::$app['view']->assign('show_novoton_booking_form', $show_booking_form);
    \Tygh\Tygh::$app['view']->assign('novoton_booking_form_position', $booking_form_position);

    // Calendar prices
    $calendar_prices_json = '{}';
    $calendar_prices_currency = '';
    if (!empty($hotel_id) && ConfigProvider::isShowCalendarPrices()) {
        $display_currency = CurrencyService::getDisplayCurrency();
        $priceInfoService = Container::getInstance()->priceInfoService();
        $calendarData = $priceInfoService->getCalendarPrices($hotel_id, $display_currency, 2);
        if (!empty($calendarData['prices'])) {
            $calendar_prices_json = json_encode($calendarData['prices'], JSON_UNESCAPED_UNICODE);
            $calendar_prices_currency = $calendarData['currency'];
        }
    }
    \Tygh\Tygh::$app['view']->assign('calendar_prices_json', $calendar_prices_json);
    \Tygh\Tygh::$app['view']->assign('calendar_prices_currency', $calendar_prices_currency);
    \Tygh\Tygh::$app['view']->assign('show_calendar_prices', ConfigProvider::isShowCalendarPrices() ? 'Y' : 'N');
}

/**
 * Hook: after getting product data
 *
 * Wrapped in try/catch — this fires during controller phase, but any crash
 * prevents product data from loading correctly, which cascades to template
 * failures inside the {capture} block.
 */
function fn_novoton_holidays_get_product_data_post(&$product_data, $auth, $preview, $lang_code): void
{
    if (empty($product_data)) {
        return;
    }

    try {
        $addon_settings = ConfigProvider::all();
        if (empty($addon_settings) || empty($addon_settings['product_code_prefixes'])) {
            return;
        }

        if (!_nvt_is_hotel_product($product_data, $addon_settings)) {
            return;
        }

        $hotel_id = _nvt_extract_hotel_id($product_data['product_code']);

        if (!empty($hotel_id)) {
            $product_data['hotel_id'] = $hotel_id;
        }

        $product_data['is_hotel_product'] = true;
    } catch (\Throwable $e) {
        // Log but don't crash — product page will work without hotel data
        try {
            $logDir = defined('DIR_ROOT') ? DIR_ROOT . '/var/log/' : '';
            if ($logDir) {
                if (!is_dir($logDir)) {
                    mkdir($logDir, 0775, true);
                }
                file_put_contents(
                    $logDir . 'novoton_errors.log',
                    date('Y-m-d H:i:s') . ' get_product_data_post: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\n",
                    FILE_APPEND
                );
            }
        } catch (\Throwable $logEx) {
            // Silently ignore — logging must never break the product page
        }
    }
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
 * Load hotel info from cache and assign to product + Smarty.
 */
function _nvt_assign_hotel_info_to_product(array &$product, string $hotel_id): void
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
    $product['novoton_active_package'] = $active_package;
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
 * and assign to product + Smarty.
 */
function _nvt_assign_season_and_early_booking(array &$product, string $hotel_id): void
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

    $product['novoton_season_dates']  = $season_dates;
    $product['novoton_early_booking'] = $early_booking;

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

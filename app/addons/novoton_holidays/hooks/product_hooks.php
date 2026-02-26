<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Product Hook Functions
 *
 * Responsible for:
 *   - gather_additional_product_data_post: Enrich product page with hotel data
 *   - get_product_data_post: Attach hotel_id and packages to product data
 *   - get_product_tabs_post: Add custom tab in admin product edit
 *   - update_product_pre: Before updating product data
 *   - delete_product_post: Clean up bookings when product is deleted
 *
 * @package NovotonHolidays
 * @since   3.0.0
 */

use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\NovotonHolidays\Services\PriceInfoService;
use Tygh\Addons\NovotonHolidays\Services\RoomPriceService;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

/**
 * Hook: gather additional product data - pass prices to templates
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

    $product['is_hotel_product'] = true;
    $hotel_id = _nvt_extract_hotel_id($product['product_code']);

    // Prices from packages table
    $prices = fn_novoton_holidays_get_hotel_prices($product['product_id'], false, $hotel_id);
    $product['novoton_prices'] = $prices;

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
    $show_booking_form    = !isset($addon_settings['show_booking_form']) || $addon_settings['show_booking_form'] == 'Y';
    $booking_form_position = $addon_settings['booking_form_position'] ?? 'before_tabs';

    \Tygh\Tygh::$app['view']->assign('show_novoton_booking_form', $show_booking_form);
    \Tygh\Tygh::$app['view']->assign('novoton_booking_form_position', $booking_form_position);

    // Calendar prices: per-date approximate totals for the cheapest room
    // Computed for the product page calendar (before user selects a room)
    $calendar_prices_json = '{}';
    $calendar_prices_currency = '';
    if (!empty($hotel_id) && ConfigProvider::isShowCalendarPrices()) {
        $display_currency = RoomPriceService::getDisplayCurrency();
        $priceInfoService = new PriceInfoService();
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
 */
function fn_novoton_holidays_get_product_data_post(&$product_data, $auth, $preview, $lang_code): void
{
    if (empty($product_data)) {
        return;
    }

    $addon_settings = ConfigProvider::all();
    if (empty($addon_settings) || empty($addon_settings['product_code_prefixes'])) {
        return;
    }

    if (!_nvt_is_hotel_product($product_data, $addon_settings)) {
        return;
    }

    $hotel_id = _nvt_extract_hotel_id($product_data['product_code']);

    if (!empty($hotel_id)) {
        $product_data['hotel_id']       = $hotel_id;
        $product_data['hotel_packages'] = fn_novoton_holidays_get_hotel_prices($product_data['product_id'], false, $hotel_id);
    }

    $product_data['is_hotel_product'] = true;
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
        if (!empty($prefix) && strpos($product['product_code'], $prefix) === 0) {
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
        if (!empty($prefix) && strpos($product_code, $prefix) === 0) {
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

    $product['novoton_hotel_info'] = $hotel_info;

    if (!empty($hotel_info['rooms'])) {
        // Re-index rooms by IdRoom so template can look up by room_id
        $rooms_by_id = [];
        foreach ($hotel_info['rooms'] as $room) {
            $rid = $room['IdRoom'] ?? '';
            if (!empty($rid)) {
                $rooms_by_id[$rid] = $room;
            }
        }
        $product['novoton_rooms'] = $rooms_by_id;
        \Tygh\Tygh::$app['view']->assign('rooms_data', $rooms_by_id);
    }

    if (!empty($hotel_info['packages'])) {
        $product['novoton_packages'] = $hotel_info['packages'];
        \Tygh\Tygh::$app['view']->assign('packages_data', $hotel_info['packages']);
    }

    if (!empty($hotel_info['board'])) {
        $product['novoton_board'] = $hotel_info['board'];
        \Tygh\Tygh::$app['view']->assign('board_data', $hotel_info['board']);
    }

    // Active package name
    $active_package = _nvt_resolve_active_package($hotel_info['packages'] ?? []);
    $product['novoton_active_package'] = $active_package;
    \Tygh\Tygh::$app['view']->assign('active_package', $active_package);

    if (!empty($hotel_info['full_data'])) {
        $product['novoton_hotel_full'] = $hotel_info['full_data'];
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
        if (strpos($age_type, 'CHD') !== false || strpos($age_type, 'CHILD') !== false) {
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

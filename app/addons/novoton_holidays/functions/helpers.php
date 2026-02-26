<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Core Helper Functions
 * 
 * Common utility functions used across the addon.
 * 
 * @package NovotonHolidays
 * @since 2.8.0
 */

use Tygh\Registry;
use Tygh\Addons\NovotonHolidays\NovotonApi;
use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

/**
 * Parse selected countries from addon settings
 * 
 * If no countries are selected, returns ALL available countries.
 * If countries are selected, returns only those countries.
 * 
 * @param mixed $selected_countries Countries from settings (array or comma-separated string)
 * @return array List of country names in uppercase
 */
if (!function_exists('fn_novoton_holidays_parse_countries')) {
function fn_novoton_holidays_parse_countries($selected_countries = null): array
{
    // When called without argument, delegate to ConfigProvider (single source of truth)
    if ($selected_countries === null) {
        return ConfigProvider::getSelectedCountries();
    }

    $countries = [];

    // Parse the selected countries from raw settings value
    if (is_array($selected_countries)) {
        foreach ($selected_countries as $key => $value) {
            if ($value === 'Y' || $value === '1' || $value === 1) {
                $countries[] = strtoupper(trim($key));
            } elseif (is_string($value) && strlen($value) > 2) {
                $countries[] = strtoupper(trim($value));
            }
        }
    } elseif (!empty($selected_countries) && is_string($selected_countries)) {
        $countries = array_map(function($c) {
            return strtoupper(trim($c));
        }, explode(',', $selected_countries));
    }

    $countries = array_filter($countries);

    // If no countries resolved, fallback to all available countries
    if (empty($countries)) {
        return Constants::COUNTRIES;
    }

    return $countries;
}
} // end function_exists

/**
 * Check if debug mode is enabled
 * 
 * @return bool
 */
function fn_novoton_holidays_is_debug(): bool
{
    // Debug via URL parameter requires authenticated admin session
    if (!empty($_REQUEST['debug_novoton'])) {
        $auth = \Tygh\Tygh::$app['session']['auth'] ?? [];
        if (!empty($auth['user_id']) && ($auth['area'] ?? '') === 'A') {
            return true;
        }
        // Silently ignore for non-admin users (no information leak)
    }

    return ConfigProvider::isDebugMode();
}

/**
 * Get Novoton API instance (singleton)
 * 
 * @return NovotonApi|null
 */
function fn_novoton_holidays_get_api(): ?NovotonApi
{
    static $api = null;
    
    if ($api === null) {
        $src_dir = Registry::get('config.dir.addons') . 'novoton_holidays/src/';
        
        if (!class_exists('Tygh\Addons\NovotonHolidays\NovotonApi')) {
            if (file_exists($src_dir . 'NovotonApi.php')) {
                require_once($src_dir . 'NovotonApi.php');
            } else {
                return null;
            }
        }
        
        try {
            $api = new NovotonApi();
        } catch (\Exception $e) {
            fn_log_event('novoton', 'error', 'Failed to initialize API: ' . $e->getMessage());
            return null;
        }
    }
    
    return $api;
}

/**
 * Update product prices from API
 * V3: Stores packages in novoton_hotel_packages table
 *
 * @param int $product_id Product ID
 * @return bool|string True on success, 'no_data', or false on failure
 */
function fn_novoton_holidays_update_product_prices($product_id): bool|string
{
    $api = fn_novoton_holidays_get_api();
    if (!$api) {
        return false;
    }

    try {
        // Get product info
        $product = db_get_row(
            "SELECT p.product_id, p.product_code, pd.product
             FROM ?:products AS p
             LEFT JOIN ?:product_descriptions AS pd ON p.product_id = pd.product_id AND pd.lang_code = ?s
             WHERE p.product_id = ?i",
            CART_LANGUAGE,
            $product_id
        );

        if (empty($product)) {
            return false;
        }

        // Get hotel_id from novoton_hotels table
        $hotel_id = fn_novoton_holidays_get_hotel_id_by_product($product_id);

        if (empty($hotel_id)) {
            // Try extracting from product code (NVT-XXXX or NVT format)
            if (!empty($product['product_code'])) {
                preg_match('/\d+/', $product['product_code'], $matches);
                $hotel_id = $matches[0] ?? null;
            }
        }

        if (empty($hotel_id)) {
            return 'no_data';
        }

        // Get hotel info from API (includes packages)
        $hotel_info = $api->getHotelInfo($hotel_id);

        if (empty($hotel_info) || !isset($hotel_info->packages)) {
            return 'no_data';
        }

        // Convert to array
        $hotelData = json_decode(json_encode($hotel_info), true);

        // Normalize packages array
        $packages = [];
        if (isset($hotelData['packages']['IdCont'])) {
            $packages = [$hotelData['packages']];
        } elseif (!empty($hotelData['packages'])) {
            $packages = $hotelData['packages'];
        }

        if (empty($packages)) {
            return 'no_data';
        }

        $packagesUpdated = 0;
        $minPrice = null;

        // V3: Store each package in novoton_hotel_packages table
        foreach ($packages as $pkg) {
            $packageId = $pkg['IdCont'] ?? '';
            $packageName = $pkg['PackageName'] ?? '';

            if (empty($packageId) || empty($packageName)) {
                continue;
            }

            // Get priceinfo for this package
            $priceInfo = $api->getPriceInfo($hotel_id, $packageName);
            $priceData = !empty($priceInfo) ? json_decode(json_encode($priceInfo), true) : [];

            // Calculate metadata
            $hasEarlyBooking = !empty($priceData['early_booking']) ? 'Y' : 'N';
            $seasonsCount = 0;
            $pkgMinPrice = null;

            // Count seasons and find min price
            if (isset($priceData['seasons']['season'])) {
                $seasons = $priceData['seasons']['season'];
                $seasonsCount = isset($seasons['IdSeason']) ? 1 : count($seasons);
            }

            if (isset($priceData['season_price'])) {
                $seasonPrices = $priceData['season_price'];
                if (isset($seasonPrices['Code'])) {
                    $seasonPrices = [$seasonPrices];
                }
                foreach ($seasonPrices as $sp) {
                    for ($i = 1; $i <= 20; $i++) {
                        $priceKey = 'Price' . $i;
                        if (isset($sp[$priceKey]) && (float)($sp[$priceKey]) > 0) {
                            $price = (float)($sp[$priceKey]);
                            if ($pkgMinPrice === null || $price < $pkgMinPrice) {
                                $pkgMinPrice = $price;
                            }
                        }
                    }
                }
            }

            // Track overall min price
            if ($pkgMinPrice !== null && ($minPrice === null || $pkgMinPrice < $minPrice)) {
                $minPrice = $pkgMinPrice;
            }

            // Save to novoton_hotel_packages
            $packageData = [
                'hotel_id' => (string) $hotel_id,
                'package_id' => (string) $packageId,
                'package_name' => (string) $packageName,
                'seasons_count' => $seasonsCount,
                'has_early_booking' => $hasEarlyBooking,
                'synced_at' => date('Y-m-d H:i:s')
            ];
            // Only include nullable fields when they have values
            // (avoids passing null to real_escape_string on PHP 8.1+)
            if (!empty($priceData)) {
                $packageData['priceinfo_data'] = json_encode($priceData);
            }
            if ($pkgMinPrice !== null) {
                $packageData['min_price'] = $pkgMinPrice;
            }

            // Atomic upsert — avoids SELECT-then-INSERT/UPDATE race condition
            $priceinfoValue = !empty($priceData) ? json_encode($priceData) : '';
            $minPriceValue = $pkgMinPrice ?? 0;
            db_query(
                "INSERT INTO ?:novoton_hotel_packages
                 (hotel_id, package_id, package_name, priceinfo_data, seasons_count, has_early_booking, min_price, synced_at)
                 VALUES (?s, ?s, ?s, ?s, ?i, ?s, ?d, ?s)
                 ON DUPLICATE KEY UPDATE
                 package_name = VALUES(package_name),
                 priceinfo_data = VALUES(priceinfo_data),
                 seasons_count = VALUES(seasons_count),
                 has_early_booking = VALUES(has_early_booking),
                 min_price = VALUES(min_price),
                 synced_at = VALUES(synced_at)",
                $packageData['hotel_id'],
                $packageData['package_id'],
                $packageData['package_name'],
                $priceinfoValue,
                $packageData['seasons_count'],
                $packageData['has_early_booking'],
                $minPriceValue,
                $packageData['synced_at']
            );

            $packagesUpdated++;
        }

        // Update hotel record
        $update_data = [
            'has_prices' => $packagesUpdated > 0 ? 'Y' : 'N',
            'packages_count' => $packagesUpdated,
            'last_price_check' => date('Y-m-d H:i:s')
        ];
        db_query("UPDATE ?:novoton_hotels SET ?u WHERE hotel_id = ?s", $update_data, $hotel_id);

        if ($packagesUpdated > 0) {
            \Tygh\Addons\NovotonHolidays\Services\PriceInfoService::precomputeCalendarPrices((string) $hotel_id);
        }

        // Update product price if we have min price
        if ($minPrice !== null && $minPrice > 0) {
            $price_with_commission = $api->applyCommission($minPrice);
            db_query(
                "UPDATE ?:products SET price = ?d WHERE product_id = ?i",
                $price_with_commission, $product_id
            );
        }

        return $packagesUpdated > 0 ? true : 'no_data';

    } catch (\Exception $e) {
        fn_log_event('novoton', 'error', 'Price update failed: ' . $e->getMessage());
        return false;
    }
}

// ============================================================================
// STREAMING HTML HELPERS
// ============================================================================

/**
 * Output the opening HTML for a streaming progress page.
 *
 * Used by controllers that echo real-time progress (check_prices, check_packages,
 * add_hotels_as_products, etc.) so they share one consistent layout and CSS.
 *
 * @param string $title  Page title
 * @param string $extra_css  Optional additional CSS rules
 */
function fn_novoton_holidays_stream_page_open(string $title, string $extra_css = ''): void
{
    header('Content-Type: text/html; charset=utf-8');

    echo '<!DOCTYPE html><html><head><title>' . htmlspecialchars($title) . '</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1000px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { color: #003580; margin-top: 0; }
        .log { background: #f8f9fa; padding: 15px; border-radius: 4px; font-family: monospace; font-size: 11px; max-height: 600px; overflow-y: auto; }
        .success { color: green; }
        .error { color: red; }
        .warning { color: orange; }
        .skip { color: #999; }
        .resort-header { color: #003580; font-weight: bold; margin-top: 8px; border-bottom: 1px solid #ddd; padding-bottom: 2px; }
        .country-header { color: #fff; background: #003580; font-weight: bold; margin-top: 12px; padding: 6px 10px; border-radius: 4px; font-size: 13px; }
        .btn { display: inline-block; padding: 10px 20px; background: #003580; color: white; text-decoration: none; border-radius: 4px; margin-top: 20px; margin-right: 10px; }
        .btn-run { background: #28a745; font-size: 14px; border: none; cursor: pointer; color: white; padding: 10px 25px; border-radius: 4px; }
        .progress { margin: 10px 0; padding: 8px; background: #e3f2fd; border-radius: 4px; font-weight: bold; }
        .form-row { display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 20px; padding: 15px; background: #f0f0f0; border-radius: 6px; }
        .form-group { display: flex; flex-direction: column; gap: 4px; }
        .form-group label { font-size: 12px; font-weight: bold; color: #333; }
        .form-group input, .form-group select { padding: 6px 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; }
        .info-box { background: #fff3cd; border: 1px solid #ffc107; padding: 12px; border-radius: 6px; margin-bottom: 15px; }
        .country-checkboxes { display: flex; flex-wrap: wrap; gap: 6px 14px; }
        .country-checkboxes label { font-size: 12px; font-weight: normal; cursor: pointer; display: flex; align-items: center; gap: 4px; }
        .country-checkboxes input[type="checkbox"] { margin: 0; }
        .summary-table { border-collapse: collapse; width: 100%; margin-top: 15px; }
        .summary-table th, .summary-table td { border: 1px solid #dee2e6; padding: 6px 10px; text-align: left; font-size: 12px; }
        .summary-table th { background: #f8f9fa; color: #003580; }
        .hint { color: #666; font-size: 13px; }
        ' . $extra_css . '
    </style></head><body><div class="container"><h1>' . htmlspecialchars($title) . '</h1>';
}

/**
 * Output the closing HTML for a streaming progress page.
 *
 * @param string $back_url  URL for the "Back" button (defaults to manage page)
 * @param array  $extra_buttons  Optional additional buttons [['url' => ..., 'label' => ...], ...]
 */
function fn_novoton_holidays_stream_page_close(string $back_url = '', array $extra_buttons = []): void
{
    if (empty($back_url)) {
        $back_url = fn_url('novoton_holidays.manage');
    }

    echo '<a href="' . htmlspecialchars($back_url) . '" class="btn">&larr; Back</a>';

    foreach ($extra_buttons as $btn) {
        echo '<a href="' . htmlspecialchars($btn['url']) . '" class="btn">' . htmlspecialchars($btn['label']) . '</a>';
    }

    echo '</div></body></html>';
}

/**
 * Build a hidden-field set from a CS-Cart fn_url() result.
 *
 * Streaming forms need to preserve the dispatch & security hash that
 * fn_url() returns as query parameters. This helper generates the
 * hidden inputs so they don't have to be repeated in every controller.
 *
 * @param string $dispatch_url  Full URL from fn_url()
 * @return array{action: string, hidden_fields: string}
 */
function fn_novoton_holidays_stream_form_fields(string $dispatch_url): array
{
    $action = htmlspecialchars(strtok($dispatch_url, '?'));
    $hidden = '';
    $url_parts = parse_url($dispatch_url);

    if (!empty($url_parts['query'])) {
        parse_str($url_parts['query'], $qs);
        foreach ($qs as $k => $v) {
            $hidden .= '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars($v) . '">';
        }
    }

    return ['action' => $action, 'hidden_fields' => $hidden];
}

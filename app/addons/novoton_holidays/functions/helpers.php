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

if (!defined('BOOTSTRAP')) { die('Access denied'); }

/**
 * Parse selected countries from addon settings
 * 
 * If no countries are selected, returns ALL available countries.
 * If countries are selected, returns only those countries.
 * 
 * @param mixed $selected_countries Countries from settings (array or comma-separated string)
 * @return array List of country names in uppercase
 */
if (!function_exists('fn_novoton_parse_countries')) {
function fn_novoton_parse_countries($selected_countries = null): array
{
    // If null passed, get from settings
    if ($selected_countries === null) {
        $selected_countries = ConfigProvider::get('selected_countries', '');
    }
    
    $countries = [];
    
    // Parse the selected countries from settings
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
    
    // If no countries selected, return ALL available countries
    if (empty($countries)) {
        // Use Constants if available
        if (class_exists('\\Tygh\\Addons\\NovotonHolidays\\Constants')) {
            return Constants::COUNTRIES;
        }
        // Fallback list of all Novoton-supported countries
        return [
            'ALBANIA',
            'BULGARIA', 
            'CYPRUS',
            'EGYPT',
            'FRANCE',
            'GREECE',
            'ITALY',
            'MALDIVES',
            'SPAIN',
            'TURKEY',
            'UNITED ARAB EMIRATES',
            'UNITED KINGDOM',
        ];
    }
    
    return $countries;
}
} // end function_exists

/**
 * Check if debug mode is enabled
 * 
 * @return bool
 */
function fn_novoton_is_debug(): bool
{
    if (!empty($_REQUEST['debug_novoton'])) {
        return true;
    }
    
    return ConfigProvider::isDebugMode();
}

/**
 * Get Novoton API instance (singleton)
 * 
 * @return NovotonApi|null
 */
function fn_novoton_get_api(): ?NovotonApi
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
    $api = fn_novoton_get_api();
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
        $hotel_id = fn_novoton_get_hotel_id_by_product($product_id);

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
                        if (isset($sp[$priceKey]) && floatval($sp[$priceKey]) > 0) {
                            $price = floatval($sp[$priceKey]);
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
                'hotel_id' => $hotel_id,
                'package_id' => $packageId,
                'package_name' => $packageName,
                'priceinfo_data' => !empty($priceData) ? json_encode($priceData) : null,
                'seasons_count' => $seasonsCount,
                'has_early_booking' => $hasEarlyBooking,
                'min_price' => $pkgMinPrice,
                'synced_at' => date('Y-m-d H:i:s')
            ];

            $existingId = db_get_field(
                "SELECT id FROM ?:novoton_hotel_packages WHERE hotel_id = ?s AND package_id = ?s",
                $hotel_id, $packageId
            );

            if ($existingId) {
                db_query("UPDATE ?:novoton_hotel_packages SET ?u WHERE id = ?i", $packageData, $existingId);
            } else {
                db_query("INSERT INTO ?:novoton_hotel_packages ?e", $packageData);
            }

            $packagesUpdated++;
        }

        // Update hotel record
        $update_data = [
            'has_prices' => $packagesUpdated > 0 ? 'Y' : 'N',
            'packages_count' => $packagesUpdated,
            'last_price_check' => date('Y-m-d H:i:s')
        ];
        db_query("UPDATE ?:novoton_hotels SET ?u WHERE hotel_id = ?s", $update_data, $hotel_id);

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

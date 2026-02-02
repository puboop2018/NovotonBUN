<?php
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
function fn_novoton_parse_countries($selected_countries = null)
{
    // If null passed, get from settings
    if ($selected_countries === null) {
        $addon_settings = Registry::get('addons.novoton_holidays') ?? [];
        $selected_countries = $addon_settings['selected_countries'] ?? '';
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
function fn_novoton_is_debug()
{
    if (!empty($_REQUEST['debug_novoton'])) {
        return true;
    }
    
    $addon_settings = Registry::get('addons.novoton_holidays') ?? [];
    return ($addon_settings['debug_mode'] ?? 'N') === 'Y';
}

/**
 * Get Novoton API instance (singleton)
 * 
 * @return NovotonApi|null
 */
function fn_novoton_get_api()
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
 * 
 * @param int $product_id Product ID
 * @return bool|string True on success, 'no_data', or false on failure
 */
function fn_novoton_holidays_update_product_prices($product_id)
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
            // Try extracting from product code (NVT-XXXX format)
            if (!empty($product['product_code']) && strpos($product['product_code'], 'NVT-') === 0) {
                $hotel_id = substr($product['product_code'], 4);
            }
        }
        
        if (empty($hotel_id)) {
            return 'no_data';
        }
        
        // Get price info from API
        $price_info = $api->getHotelDescription($hotel_id, 'UK', true);
        
        if (empty($price_info)) {
            return 'no_data';
        }
        
        // Store packages data
        $packages = [];
        $rooms = [];
        $boards = [];
        
        // Parse the response for packages
        if (isset($price_info->Packages->Package)) {
            foreach ($price_info->Packages->Package as $package) {
                $pkg = [
                    'name' => (string)($package['Name'] ?? ''),
                    'from' => (string)($package['From'] ?? ''),
                    'to' => (string)($package['To'] ?? ''),
                    'min_price' => floatval($package['MinPrice'] ?? 0),
                ];
                
                if (!empty($pkg['name'])) {
                    $packages[] = $pkg;
                }
                
                // Extract rooms and boards from package
                if (isset($package->Rooms->Room)) {
                    foreach ($package->Rooms->Room as $room) {
                        $room_id = (string)($room['Id'] ?? $room['id'] ?? '');
                        if (!empty($room_id) && !in_array($room_id, $rooms)) {
                            $rooms[] = $room_id;
                        }
                    }
                }
                
                if (isset($package->Boards->Board)) {
                    foreach ($package->Boards->Board as $board) {
                        $board_id = (string)($board['Id'] ?? $board['id'] ?? $board);
                        if (!empty($board_id) && !in_array($board_id, $boards)) {
                            $boards[] = $board_id;
                        }
                    }
                }
            }
        }
        
        // Update hotel record
        $update_data = [
            'packages_data' => json_encode($packages),
            'rooms_data' => json_encode($rooms),
            'board_data' => json_encode($boards),
            'has_prices' => !empty($packages) ? 'Y' : 'N',
            'last_price_check' => date('Y-m-d H:i:s')
        ];
        
        db_query("UPDATE ?:novoton_hotels SET ?u WHERE hotel_id = ?s", $update_data, $hotel_id);
        
        // Update product price if we have min price
        if (!empty($packages)) {
            $min_price = PHP_FLOAT_MAX;
            foreach ($packages as $pkg) {
                if ($pkg['min_price'] > 0 && $pkg['min_price'] < $min_price) {
                    $min_price = $pkg['min_price'];
                }
            }
            
            if ($min_price < PHP_FLOAT_MAX) {
                $price_with_commission = $api->applyCommission($min_price);
                db_query(
                    "UPDATE ?:products SET price = ?d WHERE product_id = ?i",
                    $price_with_commission, $product_id
                );
            }
        }
        
        return true;
        
    } catch (\Exception $e) {
        fn_log_event('novoton', 'error', 'Price update failed: ' . $e->getMessage());
        return false;
    }
}

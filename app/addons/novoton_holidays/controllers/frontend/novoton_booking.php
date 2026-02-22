<?php
declare(strict_types=1);
/**
 * Novoton Booking Controller
 * Path: app/addons/novoton_holidays/controllers/frontend/novoton_booking.php
 *
 * Refactored v2.7.0-A72:
 * - Service getters for lazy-loaded singletons
 * - Proper delegation to existing services (no duplicate helpers)
 * - _nvt_get_cached_hotel_info() adds caching to NovotonApi::getHotelInfo
 * - Validation helpers for DOB and child age checking
 *
 * Removed duplicate helpers - use services directly:
 * - CacheService::remember() instead of _nvt_cached_fetch()
 * - SearchService::parseSearchParams() for search parameter parsing
 * - SearchService::calculateRoomTotals() for room totals
 * - BookingService::createBooking() and addToCart() for bookings
 * - RoomPriceService::getRoomPrice() for room prices (has built-in caching)
 *
 * v2.8.0: Split into mode handler files under novoton_booking/ directory.
 * Each mode is in its own file for maintainability (~350 lines dispatcher
 * instead of ~3,400 lines monolith). Mode files:
 *   novoton_booking/search.php              - Hotel availability search
 *   novoton_booking/booking_form.php        - Guest entry form
 *   novoton_booking/add_to_cart.php         - Process booking into cart
 *   novoton_booking/edit_booking.php        - Edit existing cart booking
 *   novoton_booking/update_booking.php      - Save edited booking
 *   novoton_booking/request_alternatives.php - Alternative hotel request
 *   novoton_booking/ajax_recalculate_price.php - AJAX price recalculation
 */

use Tygh\Registry;
use Tygh\Tygh;
use Tygh\Addons\NovotonHolidays\Services\GuestDataNormalizer;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\RoomPriceService;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

//=============================================================================
// SERVICE LOADER
// A79: Load all services via ServiceLoader.php for lazy-loaded singletons
// Available functions:
//   _nvt_booking_service()   - Booking creation, cart, orders
//   _nvt_guest_service()     - Guest data parsing, validation
//   _nvt_search_service()    - Search parameter parsing
//   _nvt_price_service()     - Price calculations, commission
//   _nvt_security_service()  - Input validation, sanitization
//   _nvt_cache_service()     - API response caching
//   _nvt_hotel_repo()        - Hotel database access
//   _nvt_booking_repo()      - Booking database access
//=============================================================================

$service_loader = Registry::get('config.dir.addons') . 'novoton_holidays/src/Services/ServiceLoader.php';
if (file_exists($service_loader)) {
    require_once $service_loader;
}

// Legacy alias for backward compatibility
if (!function_exists('_nvt_get_cache_service')) {
    function _nvt_get_cache_service() {
        return _nvt_cache_service();
    }
}

// Service delegation helpers (wired up for gradual migration from inline code)
if (!function_exists('_nvt_get_search_service')) {
    function _nvt_get_search_service() {
        return _nvt_search_service();
    }
}
if (!function_exists('_nvt_get_booking_service')) {
    function _nvt_get_booking_service() {
        return _nvt_booking_service();
    }
}
if (!function_exists('_nvt_get_price_service')) {
    function _nvt_get_price_service() {
        return _nvt_price_service();
    }
}
if (!function_exists('_nvt_get_security_service')) {
    function _nvt_get_security_service() {
        return _nvt_security_service();
    }
}

//=============================================================================
// SANITIZE $_REQUEST / $_GET — prevent "Array to string conversion" warnings
// When the URL contains children_ages[]=5&children_ages[]=8, PHP parses these
// into arrays. CS-Cart's __() translation function (and other internal calls)
// may cast these to string, triggering warnings that corrupt JSON output.
// Fix: flatten known array params to comma-separated strings at controller top.
//=============================================================================
$_nvt_array_params = ['children_ages', 'ages'];
foreach ($_nvt_array_params as $_nvt_param) {
    foreach ([&$_REQUEST, &$_GET] as &$_nvt_superglobal) {
        if (isset($_nvt_superglobal[$_nvt_param]) && is_array($_nvt_superglobal[$_nvt_param])) {
            $_nvt_superglobal[$_nvt_param] = implode(',', array_map('intval', $_nvt_superglobal[$_nvt_param]));
        }
    }
    unset($_nvt_superglobal);
}
unset($_nvt_array_params, $_nvt_param);

//=============================================================================
// UTILITY HELPERS
//=============================================================================

/**
 * Get value from XML array at index $i, fallback to index 0, or return default
 */
if (!function_exists('_nvt_get_xml_value')) {
function _nvt_get_xml_value($array, $i, $default = '', $cast = 'string') {
    $value = isset($array[$i]) ? $array[$i] : (isset($array[0]) ? $array[0] : null);
    if ($value === null) return $default;
    switch ($cast) {
        case 'float': return (float)((string)$value);
        case 'int': return (int)((string)$value);
        default: return (string)$value;
    }
}
}

/**
 * Parse and validate guest data from form submission
 *
 * @param array $guests Raw guests array from form
 * @param string $check_in Check-in date for age calculation
 * @param int $booking_id Booking ID for error redirect
 * @param string $cart_id Cart ID for error redirect
 * @return array|false Parsed guests data or false if validation fails
 */
if (!function_exists('_nvt_parse_and_validate_guests')) {
function _nvt_parse_and_validate_guests($guests, $check_in = '', $booking_id = 0, $cart_id = '') {
    $guest_names = [];
    $guests_data = [];

    foreach ($guests as $key => $guest) {
        $first_name = trim($guest['first_name'] ?? '');
        $last_name = trim($guest['last_name'] ?? '');
        $name = trim($guest['name'] ?? '');

        // Parse date of birth using helper
        $birthday = _nvt_parse_dob($guest);

        // Validate DOB: not in future
        if (!empty($birthday)) {
            $dob_timestamp = strtotime($birthday);
            $today_midnight = strtotime('today midnight');
            if ($dob_timestamp && $dob_timestamp > $today_midnight) {
                $birthday = '';
                fn_log_event('general', 'runtime', [
                    'message' => 'Novoton: Rejected future DOB',
                    'guest_key' => $key,
                    'invalid_dob' => $guest['dob'] ?? $guest['birthday'] ?? 'unknown'
                ]);
            }

            // Validate child age: must be under 18 at check-in
            $guest_type = strtolower($guest['type'] ?? '');
            $is_child_guest = (strpos($key, 'child') !== false || $guest_type === 'child');
            if ($dob_timestamp && $is_child_guest && !empty($check_in)) {
                try {
                    $dob_date = new DateTime($birthday);
                    $check_in_date = new DateTime($check_in);
                    $age_at_checkin = $dob_date->diff($check_in_date)->y;

                    if ($age_at_checkin >= 18) {
                        fn_log_event('general', 'runtime', [
                            'message' => 'Novoton: Child age >= 18 at check-in (blocked)',
                            'guest_key' => $key,
                            'birthday' => $birthday,
                            'check_in' => $check_in,
                            'calculated_age' => $age_at_checkin
                        ]);
                        fn_set_notification('E', __('error'), __('novoton_holidays.child_must_be_under_18'));
                        return false;
                    }
                } catch (\Exception $e) {
                    $birthday = '';
                }
            }
        }

        // Build guest entry
        if (!empty($last_name) || !empty($first_name)) {
            $display_name = '';
            $api_name = '';
            if (!empty($last_name) && !empty($first_name)) {
                $display_name = $last_name . ', ' . $first_name;
                $api_name = $first_name . ' ' . $last_name;
            } elseif (!empty($last_name)) {
                $display_name = $last_name;
                $api_name = $last_name;
            } else {
                $display_name = $first_name;
                $api_name = $first_name;
            }
            $guest_names[] = $display_name;

            // Calculate age from DOB if available, otherwise use form value or 0
            $guest_age = (int)($guest['age'] ?? 0);
            if (!empty($birthday)) {
                try {
                    $dob_date = new DateTime($birthday);
                    $today = new DateTime();
                    $guest_age = $dob_date->diff($today)->y;
                } catch (\Exception $e) {
                    // Invalid date format — keep form-supplied age
                }
            }

            $guests_data[$key] = [
                'name' => $display_name,
                'api_name' => $api_name,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'type' => $guest['type'] ?? 'adult',
                'age' => $guest_age,
                'birthday' => $birthday,
                'dob' => !empty($birthday) ? date('d/m/Y', strtotime($birthday)) : '',  // Display format DD/MM/YYYY
                'room' => (int)($guest['room'] ?? 1),
                'is_holder' => !empty($guest['is_holder']) ? 1 : 0
            ];
        } elseif (!empty($name)) {
            $guest_names[] = $name;

            // Calculate age from DOB if available, otherwise use form value or 0
            $guest_age = (int)($guest['age'] ?? 0);
            if (!empty($birthday)) {
                try {
                    $dob_date = new DateTime($birthday);
                    $today = new DateTime();
                    $guest_age = $dob_date->diff($today)->y;
                } catch (\Exception $e) {
                    // Invalid date format — keep form-supplied age
                }
            }

            $guests_data[$key] = [
                'name' => $name,
                'api_name' => $name,
                'first_name' => '',
                'last_name' => '',
                'type' => $guest['type'] ?? 'adult',
                'age' => $guest_age,
                'birthday' => $birthday,
                'dob' => !empty($birthday) ? date('d/m/Y', strtotime($birthday)) : '',  // Display format DD/MM/YYYY
                'room' => (int)($guest['room'] ?? 1),
                'is_holder' => !empty($guest['is_holder']) ? 1 : 0
            ];
        }
    }

    return [
        'guests_data' => $guests_data,
        'guest_names' => $guest_names,
        'guest_list' => implode(', ', $guest_names),
        'holder_name' => $guest_names[0] ?? ''
    ];
}
} // end function_exists _nvt_parse_and_validate_guests

/**
 * Parse DOB from various form formats
 */
if (!function_exists('_nvt_parse_dob')) {
function _nvt_parse_dob($guest) {
    $birthday = '';

    if (!empty($guest['dob'])) {
        $dob_value = trim($guest['dob']);
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $dob_value, $matches)) {
            $dob_day = (int)($matches[1]);
            $dob_month = (int)($matches[2]);
            $dob_year = (int)($matches[3]);

            $current_year = (int)(date('Y'));
            if ($dob_day >= 1 && $dob_day <= 31 &&
                $dob_month >= 1 && $dob_month <= 12 &&
                $dob_year >= 1925 && $dob_year <= $current_year) {
                if (checkdate($dob_month, $dob_day, $dob_year)) {
                    $birthday = sprintf('%04d-%02d-%02d', $dob_year, $dob_month, $dob_day);
                }
            }
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob_value)) {
            $parts = explode('-', $dob_value);
            if (checkdate((int)($parts[1]), (int)($parts[2]), (int)($parts[0]))) {
                $birthday = $dob_value;
            }
        }
    } elseif (!empty($guest['dob_day']) && !empty($guest['dob_month']) && !empty($guest['dob_year'])) {
        $dob_day = (int)($guest['dob_day']);
        $dob_month = (int)($guest['dob_month']);
        $dob_year = (int)($guest['dob_year']);
        $current_year = (int)(date('Y'));
        if ($dob_day >= 1 && $dob_day <= 31 &&
            $dob_month >= 1 && $dob_month <= 12 &&
            $dob_year >= 1925 && $dob_year <= $current_year &&
            checkdate($dob_month, $dob_day, $dob_year)) {
            $birthday = sprintf('%04d-%02d-%02d', $dob_year, $dob_month, $dob_day);
        }
    } elseif (!empty($guest['birthday'])) {
        $raw = trim($guest['birthday']);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            $parts = explode('-', $raw);
            if (checkdate((int)($parts[1]), (int)($parts[2]), (int)($parts[0]))) {
                $birthday = $raw;
            }
        }
    }

    return $birthday;
}
} // end function_exists _nvt_parse_dob

// API is now lazy-loaded via fn_novoton_holidays_get_api() when needed

/**
 * Get hotel info from API with caching
 * Caches the room/board structure for 30 minutes
 *
 * @param string $hotel_id Hotel ID
 * @param bool $force Force fresh fetch
 * @return object|null Hotel info XML or null
 */
if (!function_exists('_nvt_get_cached_hotel_info')) {
function _nvt_get_cached_hotel_info($hotel_id, $force = false) {
    $cache_key = 'nvt_hotel_info_' . $hotel_id;

    // Try cache first (unless forced)
    if (!$force) {
        $cache = _nvt_get_cache_service();
        $cached = $cache->get($cache_key);
        if ($cached !== null) {
            // Convert cached array back to SimpleXMLElement if needed
            if (is_string($cached)) {
                try {
                    return simplexml_load_string($cached, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
                } catch (Exception $e) {
                    // Cache corrupted, fetch fresh
                }
            }
            return $cached;
        }
    }

    // Fetch from API
    $api = fn_novoton_holidays_get_api();
    if (!$api) {
        return null;
    }

    $hotelInfo = $api->getHotelInfo($hotel_id);

    // Cache the XML string (30 minutes - hotel room/board structure doesn't change often)
    if ($hotelInfo instanceof \SimpleXMLElement) {
        $cache = _nvt_get_cache_service();
        $cache->set($cache_key, $hotelInfo->asXML(), 1800);
    }

    return $hotelInfo;
}
} // end function_exists _nvt_get_cached_hotel_info

// A72: Use RoomPriceService::getRoomPrice() for room prices (it has built-in caching)
// Example: $priceService = _nvt_get_price_service(); $price = $priceService->getRoomPrice($params);

//=============================================================================
// MODE DISPATCHER
// Each mode handler lives in its own file under novoton_booking/ directory.
// The include returns a value if the mode handler uses return (e.g. redirects);
// we propagate that back to CS-Cart's controller framework.
//=============================================================================

$_nvt_mode_dir = __DIR__ . '/novoton_booking';

if ($mode == 'search') {
    $__nvt_result = include($_nvt_mode_dir . '/search.php');
    if ($__nvt_result !== 1) return $__nvt_result;

} elseif ($mode == 'booking_form') {
    $__nvt_result = include($_nvt_mode_dir . '/booking_form.php');
    if ($__nvt_result !== 1) return $__nvt_result;

} elseif ($mode == 'add_to_cart') {
    $__nvt_result = include($_nvt_mode_dir . '/add_to_cart.php');
    if ($__nvt_result !== 1) return $__nvt_result;

} elseif ($mode == 'book') {
    // Legacy redirect — 3 lines, kept inline
    $bookingData = $_REQUEST;
    $redirect_url = 'novoton_booking.booking_form?' . http_build_query($bookingData);
    return [CONTROLLER_STATUS_REDIRECT, $redirect_url];

} elseif ($mode == 'edit_booking') {
    $__nvt_result = include($_nvt_mode_dir . '/edit_booking.php');
    if ($__nvt_result !== 1) return $__nvt_result;

} elseif ($mode == 'update_booking') {
    $__nvt_result = include($_nvt_mode_dir . '/update_booking.php');
    if ($__nvt_result !== 1) return $__nvt_result;

} elseif ($mode == 'request_alternatives') {
    $__nvt_result = include($_nvt_mode_dir . '/request_alternatives.php');
    if ($__nvt_result !== 1) return $__nvt_result;

} elseif ($mode == 'ajax_recalculate_price') {
    // This mode always dies with JSON output; include never returns
    include($_nvt_mode_dir . '/ajax_recalculate_price.php');
}

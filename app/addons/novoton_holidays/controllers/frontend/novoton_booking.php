<?php
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
 * - PriceService::getRoomPrice() for room prices (has built-in caching)
 */

use Tygh\Registry;
use Tygh\Tygh;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

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

$service_loader = Registry::get('config.dir.addons') . 'novoton_holidays/services/ServiceLoader.php';
if (file_exists($service_loader)) {
    require_once $service_loader;
}

// Legacy alias for backward compatibility
function _nvt_get_cache_service() {
    return _nvt_cache_service();
}

//=============================================================================
// CACHING HELPERS
// A72: Use CacheService::remember() for caching. Example:
//   $cache = _nvt_get_cache_service();
//   $data = $cache->remember('cache_key', function() { return fetch_data(); }, 300);
//=============================================================================

//=============================================================================
// SEARCH HELPERS
// A72: Use SearchService methods directly:
//   $searchService = _nvt_get_search_service();
//   $params = $searchService->parseSearchParams($request);
//   $totals = $searchService->calculateRoomTotals($rooms_data);
//=============================================================================

//=============================================================================
// BOOKING HELPERS
// A72: Use BookingService methods directly:
//   $bookingService = _nvt_get_booking_service();
//   $booking_id = $bookingService->createBooking($bookingData, $product_id);
//   $bookingService->addToCart($booking_id, $product_id, $bookingData);
//=============================================================================

//=============================================================================
// UTILITY HELPERS
//=============================================================================

/**
 * Get value from XML array at index $i, fallback to index 0, or return default
 */
function _nvt_get_xml_value($array, $i, $default = '', $cast = 'string') {
    $value = isset($array[$i]) ? $array[$i] : (isset($array[0]) ? $array[0] : null);
    if ($value === null) return $default;
    switch ($cast) {
        case 'float': return floatval((string)$value);
        case 'int': return intval((string)$value);
        default: return (string)$value;
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
            $guest_age = intval($guest['age'] ?? 0);
            if (!empty($birthday)) {
                $dob_date = new DateTime($birthday);
                $today = new DateTime();
                $guest_age = $dob_date->diff($today)->y;
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
                'room' => intval($guest['room'] ?? 1),
                'is_holder' => !empty($guest['is_holder']) ? 1 : 0
            ];
        } elseif (!empty($name)) {
            $guest_names[] = $name;
            
            // Calculate age from DOB if available, otherwise use form value or 0
            $guest_age = intval($guest['age'] ?? 0);
            if (!empty($birthday)) {
                $dob_date = new DateTime($birthday);
                $today = new DateTime();
                $guest_age = $dob_date->diff($today)->y;
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
                'room' => intval($guest['room'] ?? 1),
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

/**
 * Parse DOB from various form formats
 */
function _nvt_parse_dob($guest) {
    $birthday = '';
    
    if (!empty($guest['dob'])) {
        $dob_value = trim($guest['dob']);
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $dob_value, $matches)) {
            $dob_day = intval($matches[1]);
            $dob_month = intval($matches[2]);
            $dob_year = intval($matches[3]);
            
            $current_year = intval(date('Y'));
            if ($dob_day >= 1 && $dob_day <= 31 && 
                $dob_month >= 1 && $dob_month <= 12 && 
                $dob_year >= 1925 && $dob_year <= $current_year) {
                if (checkdate($dob_month, $dob_day, $dob_year)) {
                    $birthday = sprintf('%04d-%02d-%02d', $dob_year, $dob_month, $dob_day);
                }
            }
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob_value)) {
            $birthday = $dob_value;
        }
    } elseif (!empty($guest['dob_day']) && !empty($guest['dob_month']) && !empty($guest['dob_year'])) {
        $dob_day = str_pad(intval($guest['dob_day']), 2, '0', STR_PAD_LEFT);
        $dob_month = str_pad(intval($guest['dob_month']), 2, '0', STR_PAD_LEFT);
        $dob_year = intval($guest['dob_year']);
        $birthday = "{$dob_year}-{$dob_month}-{$dob_day}";
    } elseif (!empty($guest['birthday'])) {
        $birthday = $guest['birthday'];
    }
    
    return $birthday;
}

// API is now lazy-loaded via fn_novoton_get_api() when needed

/**
 * Get hotel info from API with caching
 * Caches the room/board structure for 30 minutes
 * 
 * @param string $hotel_id Hotel ID
 * @param bool $force Force fresh fetch
 * @return object|null Hotel info XML or null
 */
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
                    return simplexml_load_string($cached);
                } catch (Exception $e) {
                    // Cache corrupted, fetch fresh
                }
            }
            return $cached;
        }
    }
    
    // Fetch from API
    $api = fn_novoton_get_api();
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

// A72: Use PriceService::getRoomPrice() for room prices (it has built-in caching)
// Example: $priceService = _nvt_get_price_service(); $price = $priceService->getRoomPrice($params);

// Search availability
if ($mode == 'search') {
    
    $searchParams = $_REQUEST;
    
    // Validate required fields
    $check_in = !empty($searchParams['check_in']) ? $searchParams['check_in'] : '';
    
    // Accept either check_out OR nights parameter
    // React form sends check_out, legacy forms may send nights
    if (!empty($searchParams['check_out'])) {
        $check_out_input = $searchParams['check_out'];
        // Calculate nights from dates
        if (!empty($check_in)) {
            $date1 = new DateTime($check_in);
            $date2 = new DateTime($check_out_input);
            $nights = $date1->diff($date2)->days;
            if ($nights < 1) $nights = 7;
        } else {
            $nights = 7;
        }
    } else {
        $nights = !empty($searchParams['nights']) ? intval($searchParams['nights']) : 7;
    }
    
    $adults = !empty($searchParams['adults']) ? intval($searchParams['adults']) : 2;
    $num_rooms = !empty($searchParams['rooms']) ? intval($searchParams['rooms']) : 1;
    
    // Flexible dates parameter (from homepage)
    $flex_days = !empty($searchParams['flex_days']) ? intval($searchParams['flex_days']) : 0;
    
    // Parse multi-room data if available
    // React form sends 'rooms_data' JSON with childrenAges inside each room
    $rooms_data = [];
    $rooms_data_source = null;
    
    // Primary: Parse rooms_data JSON (React form)
    if (!empty($searchParams['rooms_data'])) {
        $rooms_data_source = 'rooms_data';
        $decoded = json_decode($searchParams['rooms_data'], true);
        if (is_array($decoded) && !empty($decoded)) {
            $rooms_data = $decoded;
        }
    } elseif (!empty($searchParams['room_data'])) {
        $rooms_data_source = 'room_data';
        $decoded = json_decode($searchParams['room_data'], true);
        if (is_array($decoded) && !empty($decoded)) {
            $rooms_data = $decoded;
        }
    }
    
    // Normalize rooms_data - ensure childrenAges is clean array of integers
    if (!empty($rooms_data)) {
        foreach ($rooms_data as $idx => $room) {
            $clean_ages = [];
            if (!empty($room['childrenAges']) && is_array($room['childrenAges'])) {
                foreach ($room['childrenAges'] as $age) {
                    if ($age !== null && $age !== '' && $age !== 'null' && is_numeric($age)) {
                        $clean_ages[] = intval($age);
                    }
                }
            }
            $rooms_data[$idx]['adults'] = intval($room['adults'] ?? 2);
            $rooms_data[$idx]['children'] = !empty($clean_ages) ? count($clean_ages) : intval($room['children'] ?? 0);
            $rooms_data[$idx]['childrenAges'] = $clean_ages;
        }
    }
    
    // If no rooms_data provided, create from individual parameters (legacy/direct URL)
    if (empty($rooms_data)) {
        $children_count = !empty($searchParams['children']) ? intval($searchParams['children']) : 0;
        $children_ages = [];
        
        // Parse children_ages parameter (format: "5,8" or "5,8,10")
        if (!empty($searchParams['children_ages']) && is_string($searchParams['children_ages'])) {
            $ages_arr = explode(',', $searchParams['children_ages']);
            foreach ($ages_arr as $age) {
                $age = trim($age);
                if ($age !== '' && is_numeric($age)) {
                    $children_ages[] = intval($age);
                }
            }
        }
        
        // Sync children count with ages
        if (!empty($children_ages)) {
            $children_count = count($children_ages);
        }
        
        $rooms_data = [
            ['adults' => $adults, 'children' => $children_count, 'childrenAges' => $children_ages]
        ];
    }
    
    // IMPORTANT: Also parse children_ages even if rooms_data exists but childrenAges is empty
    // This handles the case where React sends both rooms_data and children_ages
    if (!empty($searchParams['children_ages']) && is_string($searchParams['children_ages'])) {
        $url_children_ages = [];
        $ages_arr = explode(',', $searchParams['children_ages']);
        foreach ($ages_arr as $age) {
            $age = trim($age);
            if ($age !== '' && is_numeric($age)) {
                $url_children_ages[] = intval($age);
            }
        }
        
        // If rooms_data has empty childrenAges, use the URL parameter
        if (!empty($url_children_ages) && !empty($rooms_data)) {
            foreach ($rooms_data as $idx => $room) {
                if (empty($room['childrenAges']) && $room['children'] > 0) {
                    // Distribute URL ages to this room
                    $rooms_data[$idx]['childrenAges'] = array_slice($url_children_ages, 0, $room['children']);
                }
            }
        }
    }
    
    // Calculate totals from rooms_data
    $total_adults = 0;
    $total_children = 0;
    $all_children_ages = [];
    
    foreach ($rooms_data as $room) {
        $total_adults += intval($room['adults'] ?? 2);
        $room_children = intval($room['children'] ?? 0);
        $total_children += $room_children;
        if (!empty($room['childrenAges'])) {
            foreach ($room['childrenAges'] as $age) {
                if ($age !== null && $age !== 'age_needed') {
                    $all_children_ages[] = intval($age);
                }
            }
        }
    }
    
    // Use totals for API calls (API doesn't support multi-room directly)
    $adults = $total_adults;
    $num_rooms = count($rooms_data);
    
    // If no check_in date provided, show empty search form or redirect
    if (empty($check_in)) {
        // Set default values to prevent null errors
        $page_title = __('novoton_holidays.search_results') ?: 'Search Results';
        Tygh::$app['view']->assign('page_title', $page_title);
        Registry::set('navigation.dynamic.page_title', $page_title);
        Registry::set('navigation.dynamic.meta_description', '');
        Registry::set('navigation.dynamic.meta_keywords', '');
        Registry::set('runtime.page_title', $page_title);
        Tygh::$app['view']->assign('meta_description', '');
        Tygh::$app['view']->assign('meta_keywords', '');
        Tygh::$app['view']->assign('canonical_url', '');
        Tygh::$app['view']->assign('og_image', '');
        Tygh::$app['view']->assign('og_title', $page_title);
        Tygh::$app['view']->assign('og_description', '');
        
        // Assign empty values for template
        Tygh::$app['view']->assign('novoton_results', []);
        Tygh::$app['view']->assign('novoton_params', [
            'check_in' => '',
            'check_out' => '',
            'nights' => 7,
            'adults' => 2,
            'children' => [],
            'meal_plan' => __('novoton_holidays.all_boards') ?: 'All Boards',
            'hotel_id' => '',
            'product_id' => 0
        ]);
        Tygh::$app['view']->assign('alternative_results', []);
        Tygh::$app['view']->assign('alternative_check_in', '');
        Tygh::$app['view']->assign('alternative_check_out', '');
        Tygh::$app['view']->assign('no_availability_message', true);
        Tygh::$app['view']->assign('hotel_name', '');
        Tygh::$app['view']->assign('hotel_city', '');
        Tygh::$app['view']->assign('hotel_country', '');
        Tygh::$app['view']->assign('hotel_package_name', '');
        
        fn_set_notification('W', __('warning'), __('novoton_holidays.please_fill_required_fields') ?: 'Please fill in required search fields');
        
        return; // Return early to show the search page with message
    }
    
    // Calculate check-out date
    $checkIn = $check_in;
    $checkOut = date('Y-m-d', strtotime($checkIn . ' +' . $nights . ' days'));
    
    // Use children ages from rooms_data
    $children = $all_children_ages;
    
    // Legacy: Process children ages from direct parameters if rooms_data was empty
    if (empty($children) && !empty($searchParams['children']) && intval($searchParams['children']) > 0) {
        $childrenCount = intval($searchParams['children']);
        for ($i = 1; $i <= $childrenCount; $i++) {
            if (isset($searchParams['child_age_' . $i])) {
                $age = $searchParams['child_age_' . $i];
                if ($age !== '' && $age !== 'age_needed') {
                    $children[] = intval($age);
                }
            }
        }
    }
    
    // Handle meal plan - empty means "All boards"
    $mealPlan = !empty($searchParams['meal_plan']) ? $searchParams['meal_plan'] : '';
    $searchAllBoards = empty($mealPlan);
    
    $results = [];
    $alternative_results = [];
    $alternative_check_in = '';
    $alternative_check_out = '';
    $no_availability_message = false;
    
    // Build children ages string for URL passing
    $children_ages_str = !empty($children) ? implode(',', $children) : '';
    $children_count = count($children);
    
    $novoton_params = [
        'check_in' => $checkIn,
        'check_out' => $checkOut,
        'nights' => $nights,
        'adults' => $adults,
        'children' => $children,
        'children_count' => count($children),
        'children_ages' => $children_ages_str,  // Alias for template use
        'children_ages_str' => $children_ages_str,
        'children_ages_array' => $children,  // Array of ages for rooms_data JSON
        'meal_plan' => $mealPlan ?: __('novoton_holidays.all_boards'),
        'hotel_id' => '',
        'product_id' => 0,
        'num_rooms' => $num_rooms,
        'rooms_data' => $rooms_data,
        'rooms_data_json' => json_encode($rooms_data),
        'flex_days' => $flex_days
    ];
    
    // Debug mode - enable to see API responses
    $debug_mode = !empty($searchParams['debug']) || defined('NOVOTON_DEBUG');
    $debug_log = [];
    
    // If hotel_id is provided (product page), search for specific hotel
    if (!empty($searchParams['hotel_id'])) {
        $hotelId = $searchParams['hotel_id'];
        $productId = !empty($searchParams['product_id']) ? intval($searchParams['product_id']) : 0;
        
        // If no product_id provided, look it up from hotel_id
        if (empty($productId)) {
            $addon_settings = Registry::get('addons.novoton_holidays') ?? [];
            $prefix = trim(explode(',', $addon_settings['product_code_prefixes'] ?? 'NVT')[0]);
            $productId = db_get_field(
                "SELECT product_id FROM ?:products WHERE product_code = ?s",
                $prefix . $hotelId
            );
        }
        
        $novoton_params['hotel_id'] = $hotelId;
        $novoton_params['product_id'] = $productId;
        
        if ($debug_mode) {
            $debug_log[] = "=== SEARCH DEBUG ===";
            $debug_log[] = "Hotel ID: " . $hotelId;
            $debug_log[] = "Product ID: " . $productId;
            $debug_log[] = "Check-in: " . $checkIn;
            $debug_log[] = "Check-out: " . $checkOut;
            $debug_log[] = "Nights: " . $nights;
            $debug_log[] = "Adults: " . $adults;
            $debug_log[] = "Children: " . json_encode($children);
            $debug_log[] = "Meal Plan: " . ($mealPlan ?: 'ALL');
            $debug_log[] = "";
        }
        
        // Get hotel services from B2B API (hotelinfo returns rooms/boards, not name/city)
        // A72: Use cached version for better performance
        $hotelInfo = _nvt_get_cached_hotel_info($hotelId);
        
        if ($debug_mode) {
            $debug_log[] = "=== HOTEL INFO RESPONSE (hotelinfo) ===";
            $debug_log[] = "(Note: hotelinfo returns rooms/board/packages, not hotel name)";
            if ($hotelInfo) {
                $debug_log[] = "Has rooms: " . (isset($hotelInfo->rooms) ? 'YES' : 'NO');
                $debug_log[] = "Has board: " . (isset($hotelInfo->board) ? 'YES' : 'NO');
                $debug_log[] = "Has packages: " . (isset($hotelInfo->packages) ? 'YES' : 'NO');
                if (isset($hotelInfo->rooms)) {
                    $debug_log[] = "Rooms structure: " . gettype($hotelInfo->rooms);
                }
                // Show raw XML for packages debugging
                if ($hotelInfo instanceof \SimpleXMLElement) {
                    $debug_log[] = "";
                    $debug_log[] = "=== RAW HOTELINFO XML (truncated) ===";
                    $rawXml = $hotelInfo->asXML();
                    $debug_log[] = substr(htmlspecialchars($rawXml), 0, 2000);
                }
            } else {
                $debug_log[] = "ERROR: No hotel info returned from API";
            }
            $debug_log[] = "";
        }
        
        if ($hotelInfo && isset($hotelInfo->rooms)) {
            // Get rooms array - XML has multiple <rooms> siblings at root level of <hotel>
            $rooms = [];
            if ($hotelInfo instanceof \SimpleXMLElement) {
                // Use xpath to get ALL <rooms> elements (siblings)
                $rooms = $hotelInfo->xpath('//rooms');
            }
            
            if ($debug_mode) {
                $debug_log[] = "=== ROOMS FOUND ===";
                $debug_log[] = "Total rooms: " . count($rooms);
                foreach ($rooms as $idx => $rm) {
                    $rmId = (string)$rm->IdRoom;
                    $rmType = (string)$rm->Type;
                    $debug_log[] = "  Room {$idx}: {$rmId} (Type: {$rmType})";
                }
                $debug_log[] = "";
            }
            
            // Board types to search - get from hotel info if available
            $boardTypes = [];
            if ($hotelInfo instanceof \SimpleXMLElement) {
                // Use xpath to get ALL <board> elements (siblings)
                $boardElements = $hotelInfo->xpath('//board');
                foreach ($boardElements as $b) {
                    $boardId = (string)$b->IdBoard;
                    if (empty($boardId)) {
                        $boardId = (string)$b; // Sometimes it's just the value
                    }
                    if (!empty($boardId)) {
                        $boardTypes[] = $boardId;
                    }
                }
            }
            
            // Fall back to standard codes if no boards found
            if (empty($boardTypes)) {
                $boardTypes = ['ALL INCL', 'AI', 'FB', 'HB', 'BB', 'RO'];
            }
            
            // If specific meal plan selected, try to find matching board
            if (!$searchAllBoards && !empty($mealPlan)) {
                // Map user selection to possible API values
                $boardMapping = [
                    'AI' => ['ALL INCL', 'AI', 'ALLINC'],
                    'UAI' => ['ULTRA ALL', 'UAI'],
                    'FB' => ['FB', 'FULL BOARD'],
                    'HB' => ['HB', 'HALF BOARD'],
                    'BB' => ['BB', 'BED BREAKFAST', 'B&B'],
                    'RO' => ['RO', 'ROOM ONLY']
                ];
                
                $preferredBoards = $boardMapping[$mealPlan] ?? [$mealPlan];
                
                // Reorder to put preferred boards first
                $reordered = [];
                foreach ($preferredBoards as $pb) {
                    foreach ($boardTypes as $bt) {
                        if (stripos($bt, $pb) !== false || stripos($pb, $bt) !== false) {
                            $reordered[] = $bt;
                        }
                    }
                }
                // Add remaining boards
                foreach ($boardTypes as $bt) {
                    if (!in_array($bt, $reordered)) {
                        $reordered[] = $bt;
                    }
                }
                $boardTypes = array_unique($reordered);
            }
            
            // Get packages from hotelinfo - REQUIRED for room_price API
            // XML has multiple <packages> siblings at root level of <hotel>
            $packages = [];
            
            if ($hotelInfo instanceof \SimpleXMLElement) {
                // Use xpath to get ALL <packages> elements (siblings)
                $packageElements = $hotelInfo->xpath('//packages');
                
                if ($debug_mode) {
                    $debug_log[] = "=== RAW PACKAGES STRUCTURE ===";
                    $debug_log[] = "xpath //packages found: " . count($packageElements) . " elements";
                }
                
                foreach ($packageElements as $pkg) {
                    $pkgName = (string)$pkg->PackageName;
                    $pkgIdCont = (string)$pkg->IdCont;
                    if (!empty($pkgName)) {
                        $packages[] = [
                            'name' => $pkgName,
                            'id_cont' => $pkgIdCont
                        ];
                    }
                }
            }
            
            // If no packages found, try with empty package name (will search all)
            if (empty($packages)) {
                $packages[] = ['name' => '', 'id_cont' => ''];
            }
            
            if ($debug_mode) {
                $debug_log[] = "=== SEARCHING PRICES ===";
                $debug_log[] = "Packages found: " . count($packages);
                foreach ($packages as $pkg) {
                    $debug_log[] = "  - {$pkg['name']} (IdCont: {$pkg['id_cont']})";
                }
                $debug_log[] = "";
            }
            
            // Call room_price API - MULTI-ROOM SUPPORT
            // For multi-room bookings, we need to search for each room's occupancy separately
            // This matches how Novoton's website works
            
            $all_room_results = []; // Store results per room
            
            if ($num_rooms > 1 && count($rooms_data) > 1) {
                // MULTI-ROOM MODE: Make separate API call for each room
                if ($debug_mode) {
                    $debug_log[] = "=== MULTI-ROOM SEARCH MODE ===";
                    $debug_log[] = "Making {$num_rooms} separate API calls, one per room occupancy";
                    $debug_log[] = "";
                }
                
                foreach ($rooms_data as $room_idx => $room_occupancy) {
                    $room_num = $room_idx + 1;
                    $room_adults = intval($room_occupancy['adults'] ?? 2);
                    $room_children_count = intval($room_occupancy['children'] ?? 0);
                    $room_children_ages = [];
                    
                    if (!empty($room_occupancy['childrenAges'])) {
                        foreach ($room_occupancy['childrenAges'] as $age) {
                            if ($age !== null && $age !== '' && $age !== 'age_needed') {
                                $room_children_ages[] = intval($age);
                            }
                        }
                    }
                    
                    $priceParams = [
                        'hotel_id' => $hotelId,
                        'room_id' => '',      // Empty = return all room types
                        'board_id' => '',     // Empty = return all board types
                        'star_rating' => '',
                        'check_in' => $checkIn,
                        'check_out' => $checkOut,
                        'adults' => $room_adults,
                        'children' => $room_children_ages
                    ];
                    
                    if ($debug_mode) {
                        $debug_log[] = "--- Room #{$room_num}: {$room_adults} adults, {$room_children_count} children ---";
                        if (!empty($room_children_ages)) {
                            $debug_log[] = "Children ages: " . implode(', ', $room_children_ages);
                        }
                    }
                    
                    // Get prices from room_price API for this room
                    $priceData = fn_novoton_get_api()->getRoomPrice($priceParams);
                    
                    $room_results = [];
                    
                    if ($priceData) {
                        $rawXml = fn_novoton_get_api()->getLastResponse();
                        
                        if ($debug_mode) {
                            $debug_log[] = "  API Response received (parsing...)";
                        }
                        
                        libxml_use_internal_errors(true);
                        $xml = simplexml_load_string($rawXml);
                        
                        if ($xml !== false) {
                            // Parse results - handle both single and multiple results
                            $priceElements = $xml->xpath('//Price');
                            
                            if (count($priceElements) > 1) {
                                // Multiple results
                                $idRooms = $xml->xpath('//IdRoom');
                                $boards = $xml->xpath('//Board');
                                $prices = $xml->xpath('//Price');
                                $packageNames = $xml->xpath('//PackageName');
                                
                                for ($i = 0; $i < count($prices); $i++) {
                                    $roomId = isset($idRooms[$i]) ? (string)$idRooms[$i] : '';
                                    $boardId = isset($boards[$i]) ? (string)$boards[$i] : '';
                                    $price = isset($prices[$i]) ? floatval((string)$prices[$i]) : 0;
                                    $packageName = isset($packageNames[$i]) ? (string)$packageNames[$i] : '';
                                    
                                    if (empty($roomId) || $price <= 0) continue;
                                    
                                    // Filter by meal plan if specified
                                    if (!$searchAllBoards && !empty($mealPlan)) {
                                        $boardMapping = [
                                            'AI' => ['ALL INCL', 'AI', 'ALLINC'],
                                            'FB' => ['FB', 'FULL BOARD'],
                                            'HB' => ['HB', 'HALF BOARD'],
                                            'BB' => ['BB', 'BED BREAKFAST', 'B&B'],
                                            'RO' => ['RO', 'ROOM ONLY']
                                        ];
                                        $preferredBoards = $boardMapping[$mealPlan] ?? [$mealPlan];
                                        $boardMatch = false;
                                        foreach ($preferredBoards as $pb) {
                                            if (stripos($boardId, $pb) !== false) {
                                                $boardMatch = true;
                                                break;
                                            }
                                        }
                                        if (!$boardMatch) continue;
                                    }
                                    
                                    $finalPrice = fn_novoton_get_api()->applyCommission($price);
                                    
                                    $room_results[] = [
                                        'room_id' => $roomId,
                                        'room_name' => str_replace(['%2b', '%2B'], '+', $roomId),
                                        'room_type_display' => fn_novoton_format_room_type($roomId),
                                        'board_id' => $boardId,
                                        'board_name' => fn_novoton_format_board_name($boardId),
                                        'package_name' => urldecode($packageName),
                                        'nights' => $nights,
                                        'total_price' => $finalPrice,
                                        'price_per_night' => round($finalPrice / $nights, 2),
                                        'check_in' => $checkIn,
                                        'check_out' => $checkOut,
                                        'for_room' => $room_num,
                                        'occupancy' => "{$room_adults} adults" . ($room_children_count > 0 ? ", {$room_children_count} children" : '')
                                    ];
                                }
                            } elseif (count($priceElements) == 1) {
                                // Single result
                                $roomId = (string)$xml->IdRoom;
                                $boardId = (string)$xml->Board;
                                $price = floatval((string)$xml->Price);
                                $packageName = (string)$xml->PackageName;
                                
                                if (!empty($roomId) && $price > 0) {
                                    $includeMeal = true;
                                    if (!$searchAllBoards && !empty($mealPlan)) {
                                        $boardMapping = [
                                            'AI' => ['ALL INCL', 'AI', 'ALLINC'],
                                            'FB' => ['FB', 'FULL BOARD'],
                                            'HB' => ['HB', 'HALF BOARD'],
                                            'BB' => ['BB', 'BED BREAKFAST', 'B&B'],
                                            'RO' => ['RO', 'ROOM ONLY']
                                        ];
                                        $preferredBoards = $boardMapping[$mealPlan] ?? [$mealPlan];
                                        $includeMeal = false;
                                        foreach ($preferredBoards as $pb) {
                                            if (stripos($boardId, $pb) !== false) {
                                                $includeMeal = true;
                                                break;
                                            }
                                        }
                                    }
                                    
                                    if ($includeMeal) {
                                        $finalPrice = fn_novoton_get_api()->applyCommission($price);
                                        
                                        $room_results[] = [
                                            'room_id' => $roomId,
                                            'room_name' => str_replace(['%2b', '%2B'], '+', $roomId),
                                            'room_type_display' => fn_novoton_format_room_type($roomId),
                                            'board_id' => $boardId,
                                            'board_name' => fn_novoton_format_board_name($boardId),
                                            'package_name' => urldecode($packageName),
                                            'nights' => $nights,
                                            'total_price' => $finalPrice,
                                            'price_per_night' => round($finalPrice / $nights, 2),
                                            'check_in' => $checkIn,
                                            'check_out' => $checkOut,
                                            'for_room' => $room_num,
                                            'occupancy' => "{$room_adults} adults" . ($room_children_count > 0 ? ", {$room_children_count} children" : '')
                                        ];
                                    }
                                }
                            }
                        }
                    }
                    
                    // Store results for this room
                    $all_room_results[$room_num] = $room_results;
                    
                    if ($debug_mode) {
                        $debug_log[] = "  Found " . count($room_results) . " options for Room #{$room_num}";
                        $debug_log[] = "";
                    }
                }
                
                // For multi-room, we need to display options per room
                // Pass all_room_results to template
                Tygh::$app['view']->assign('all_room_results', $all_room_results);
                Tygh::$app['view']->assign('is_multi_room_search', true);
                
                // Fetch early booking discounts for this hotel from database
                $early_booking_discounts = [];
                
                // A73: Optimized query - select only needed columns, add LIMIT
                $db_early_bookings = db_get_array(
                    "SELECT reduction, room_types, package_id, min_stay, booking_to 
                     FROM ?:novoton_early_booking 
                     WHERE hotel_id = ?s 
                     AND (booking_to IS NULL OR booking_to >= CURDATE())
                     AND (stay_from IS NULL OR stay_from <= ?s)
                     AND (stay_to IS NULL OR stay_to >= ?s)
                     ORDER BY reduction DESC
                     LIMIT 10",
                    $hotelId, $checkOut, $checkIn
                );
                
                // Parse DB early bookings
                foreach ($db_early_bookings as $db_eb) {
                    $early_booking_discounts[] = [
                        'discount' => floatval($db_eb['reduction']),
                        'room_types' => $db_eb['room_types'] ?? 'all',
                        'package' => $db_eb['package_id'] ?? '',
                        'min_stay' => intval($db_eb['min_stay'] ?? 0),
                        'booking_to' => $db_eb['booking_to'] ?? ''
                    ];
                }
                
                // Calculate discount range (min and max)
                $discount_range = [];
                if (!empty($early_booking_discounts)) {
                    $discounts = array_column($early_booking_discounts, 'discount');
                    $discount_range = [
                        'min' => min($discounts),
                        'max' => max($discounts),
                        'all' => array_unique($discounts)
                    ];
                    sort($discount_range['all']);
                }
                
                Tygh::$app['view']->assign('early_booking_discounts', $early_booking_discounts);
                Tygh::$app['view']->assign('early_booking_range', $discount_range);
                
                // Check if ALL rooms have results
                $total_options = 0;
                $rooms_with_no_results = [];
                foreach ($all_room_results as $rn => $rr) {
                    $total_options += count($rr);
                    if (empty($rr)) {
                        $rooms_with_no_results[] = $rn;
                    }
                }
                
                // Only set no_availability if ALL rooms have no results
                if ($total_options == 0) {
                    $no_availability_message = true;
                    if ($debug_mode) {
                        $debug_log[] = "WARNING: No availability for ANY rooms";
                    }
                } else {
                    // Has results - make sure no_availability is false
                    $no_availability_message = false;
                    if ($debug_mode && !empty($rooms_with_no_results)) {
                        $debug_log[] = "NOTE: Some rooms have no results: " . implode(', ', $rooms_with_no_results);
                    }
                }
                
                // For multi-room, put first room results in $results for template compatibility
                // This prevents "No Availability" message when there are results
                $results = [];
                foreach ($all_room_results as $room_results) {
                    if (!empty($room_results)) {
                        $results = $room_results;
                        break;
                    }
                }
                
                // Also assign total room count for template
                Tygh::$app['view']->assign('multi_room_total_options', $total_options);
                
                // A73q: Calculate max room capacity from available results
                $max_adults = 0;
                $max_children = 0;
                foreach ($results as $result) {
                    if (preg_match('/(\d+)\+(\d+)/', $result['room_id'], $matches)) {
                        $room_adults = intval($matches[1]);
                        $room_children = intval($matches[2]);
                        if ($room_adults > $max_adults) {
                            $max_adults = $room_adults;
                        }
                        if ($room_children > $max_children) {
                            $max_children = $room_children;
                        }
                    }
                }
                // Default if no capacity found
                if ($max_adults == 0) {
                    $max_adults = 2;
                    $max_children = 2;
                }
                Tygh::$app['view']->assign('max_room_capacity', [
                    'adults' => $max_adults,
                    'children' => $max_children,
                    'total' => $max_adults + $max_children
                ]);
                
            } else {
                // SINGLE ROOM MODE: Original logic
                // Get children ages from rooms_data or fallback to parsed children_ages
                $single_room_children = $children;
                if (empty($single_room_children) && !empty($rooms_data[0]['childrenAges'])) {
                    $single_room_children = $rooms_data[0]['childrenAges'];
                }
                
                $priceParams = [
                    'hotel_id' => $hotelId,
                    'room_id' => '',      // Empty = return all room types
                    'board_id' => '',     // Empty = return all board types
                    'star_rating' => '',
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                    'adults' => $adults,
                    'children' => $single_room_children
                ];
                
                if ($debug_mode) {
                    $debug_log[] = "=== SINGLE ROOM SEARCH MODE ===";
                    $debug_log[] = "Adults: {$adults}, Children count: " . count($single_room_children);
                    if (!empty($single_room_children)) {
                        $debug_log[] = "Children ages: " . implode(', ', $single_room_children);
                    }
                }
                
                // Get prices from room_price API
                $priceData = fn_novoton_get_api()->getRoomPrice($priceParams);
            
            // Show raw request/response in debug
            if ($debug_mode) {
                $lastReq = fn_novoton_get_api()->getLastRequestFormatted();
                $debug_log[] = "  -> API Request: hotel_id={$hotelId}, check_in={$lastReq['check_in']}, check_out={$lastReq['check_out']}";
                $rawResponse = fn_novoton_get_api()->getLastResponse();
                if ($rawResponse) {
                    $debug_log[] = "  -> Raw Response (first 2000 chars): " . substr(htmlspecialchars($rawResponse), 0, 2000);
                } else {
                    $debug_log[] = "  -> Raw Response: EMPTY or FALSE";
                }
            }
            
            // Parse the response - room_price returns multiple <room_price> elements
            // Each element contains: IdRoom, Board, Price, PackageName, etc.
            if ($priceData) {
                // The response could be a single room_price or multiple
                // Use xpath to get all room_price results
                $rawXml = fn_novoton_get_api()->getLastResponse();
                
                if ($debug_mode) {
                    $debug_log[] = "";
                    $debug_log[] = "=== PARSING ROOM_PRICE RESPONSE ===";
                }
                
                // ========================================
                // FETCH ROOM QUOTA FOR ALL ROOMS AT ONCE
                // ========================================
                $quotaMap = [];
                try {
                    $quotaMap = fn_novoton_get_api()->getHotelQuotaAll($hotelId, $checkIn, $checkOut);
                    if ($debug_mode) {
                        $debug_log[] = "=== ROOM QUOTA (hotel_quota API) ===";
                        foreach ($quotaMap as $qRoom => $qValue) {
                            $debug_log[] = "  {$qRoom}: {$qValue}";
                        }
                    }
                } catch (Exception $e) {
                    if ($debug_mode) {
                        $debug_log[] = "=== QUOTA FETCH ERROR: " . $e->getMessage() . " ===";
                    }
                }
                
                // Parse the raw XML to get all room_price elements
                libxml_use_internal_errors(true);
                $xml = simplexml_load_string($rawXml);
                
                if ($xml !== false) {
                    // Get all room_price elements using xpath
                    $roomPrices = $xml->xpath('//room_price') ?: [$xml];
                    
                    // If the root is room_price itself, check if there are sibling room_price elements
                    if ($xml->getName() === 'room_price') {
                        // Single result or need to check parent
                        $roomPrices = [$xml];
                        
                        // Check if there are multiple Price elements (indicating multiple results)
                        $priceElements = $xml->xpath('//Price');
                        if (count($priceElements) > 1) {
                            // Multiple results in single response - need to parse differently
                            // Each set of IdRoom, Board, Price represents one result
                            $idRooms = $xml->xpath('//IdRoom');
                            $boards = $xml->xpath('//Board');
                            $prices = $xml->xpath('//Price');
                            $packageNames = $xml->xpath('//PackageName');
                            $remarks = $xml->xpath('//remark');
                            $earlyBookings = $xml->xpath('//early_booking');
                            $extrasElements = $xml->xpath('//extras');
                            $moreInfoElements = $xml->xpath('//MoreInfo');
                            $importantElements = $xml->xpath('//Important');
                            
                            // Get terms (usually same for all)
                            $termsPayment = $xml->xpath('//TermsOfPayment');
                            $termsCancellation = $xml->xpath('//TermsOfCancellation');
                            
                            if ($debug_mode) {
                                $debug_log[] = "Multiple results found: " . count($prices) . " price entries";
                            }
                            
                            // Process each result
                            for ($i = 0; $i < count($prices); $i++) {
                                $roomId = isset($idRooms[$i]) ? (string)$idRooms[$i] : '';
                                $boardId = isset($boards[$i]) ? (string)$boards[$i] : '';
                                $price = isset($prices[$i]) ? floatval((string)$prices[$i]) : 0;
                                $packageName = _nvt_get_xml_value($packageNames, $i);
                                $remark = _nvt_get_xml_value($remarks, $i);
                                
                                if (empty($roomId) || $price <= 0) continue;
                                
                                // Filter by meal plan if specified
                                if (!$searchAllBoards && !empty($mealPlan)) {
                                    $boardMapping = [
                                        'AI' => ['ALL INCL', 'AI', 'ALLINC'],
                                        'UAI' => ['ULTRA ALL', 'UAI'],
                                        'FB' => ['FB', 'FULL BOARD'],
                                        'HB' => ['HB', 'HALF BOARD'],
                                        'BB' => ['BB', 'BED BREAKFAST', 'B&B'],
                                        'RO' => ['RO', 'ROOM ONLY']
                                    ];
                                    $preferredBoards = $boardMapping[$mealPlan] ?? [$mealPlan];
                                    $boardMatch = false;
                                    foreach ($preferredBoards as $pb) {
                                        if (stripos($boardId, $pb) !== false) {
                                            $boardMatch = true;
                                            break;
                                        }
                                    }
                                    if (!$boardMatch) continue;
                                }
                                
                                // Apply commission
                                $finalPrice = fn_novoton_get_api()->applyCommission($price);
                                
                                // Get quota for this room from the pre-fetched quotaMap
                                $availability = null;
                                $isOnRequest = false;
                                
                                if (!empty($quotaMap)) {
                                    // Look up quota for this room ID
                                    $quotaValue = isset($quotaMap[$roomId]) ? $quotaMap[$roomId] : null;
                                    
                                    if ($quotaValue !== null) {
                                        $quotaValue = trim($quotaValue);
                                        if (strtoupper($quotaValue) === 'RQ' || strtoupper($quotaValue) === 'REQUEST' || $quotaValue === '') {
                                            $isOnRequest = true;
                                            $availability = 0;
                                        } else {
                                            $quotaInt = intval($quotaValue);
                                            if ($quotaInt === 0) {
                                                $isOnRequest = true;
                                                $availability = 0;
                                            } else {
                                                $availability = $quotaInt;
                                            }
                                        }
                                        
                                        if ($debug_mode) {
                                            $debug_log[] = "  Room {$roomId}: quota={$quotaValue}, availability={$availability}";
                                        }
                                    }
                                }
                                
                                $result_item = [
                                    'room' => null,
                                    'room_id' => $roomId,
                                    'room_name' => str_replace(['%2b', '%2B'], '+', $roomId),
                                    'room_type_display' => fn_novoton_format_room_type($roomId),
                                    'board_id' => $boardId,
                                    'board_name' => fn_novoton_format_board_name($boardId),
                                    'package_name' => urldecode($packageName),
                                    'price_data' => null,
                                    'nights' => $nights,
                                    'total_price' => $finalPrice,
                                    'price_per_night' => round($finalPrice / $nights, 2),
                                    'check_in' => $checkIn,
                                    'check_out' => $checkOut,
                                    'rooms_available' => $availability,
                                    'is_on_request' => $isOnRequest,
                                    'remark' => $remark,
                                    'important' => _nvt_get_xml_value($importantElements, $i),
                                    'more_info' => _nvt_get_xml_value($moreInfoElements, $i),
                                    'early_booking_discount' => _nvt_get_xml_value($earlyBookings, $i, 0, 'float'),
                                    'extras' => _nvt_get_xml_value($extrasElements, $i),
                                    'terms_of_payment' => isset($termsPayment[0]) ? $termsPayment[0]->asXML() : '',
                                    'terms_of_cancellation' => isset($termsCancellation[0]) ? $termsCancellation[0]->asXML() : '',
                                    'free_cancellation_date' => isset($termsCancellation[0]) ? fn_novoton_get_free_cancellation_date($termsCancellation[0]->asXML()) : null
                                ];
                                
                                $results[] = $result_item;
                                
                                if ($debug_mode) {
                                    $status = $isOnRequest ? 'ON REQUEST' : ($availability !== null ? "{$availability} rooms" : 'available');
                                    $debug_log[] = "  -> ADDED: Room={$roomId}, Board={$boardId}, Price={$finalPrice}€, {$status}";
                                }
                            }
                        } else {
                            // Single result
                            $roomId = (string)$xml->IdRoom;
                            $boardId = (string)$xml->Board;
                            $price = floatval((string)$xml->Price);
                            $packageName = (string)$xml->PackageName;
                            $remark = isset($xml->remark) ? (string)$xml->remark : '';
                            
                            if (!empty($roomId) && $price > 0) {
                                // Filter by meal plan if specified
                                $includeMeal = true;
                                if (!$searchAllBoards && !empty($mealPlan)) {
                                    $boardMapping = [
                                        'AI' => ['ALL INCL', 'AI', 'ALLINC'],
                                        'UAI' => ['ULTRA ALL', 'UAI'],
                                        'FB' => ['FB', 'FULL BOARD'],
                                        'HB' => ['HB', 'HALF BOARD'],
                                        'BB' => ['BB', 'BED BREAKFAST', 'B&B'],
                                        'RO' => ['RO', 'ROOM ONLY']
                                    ];
                                    $preferredBoards = $boardMapping[$mealPlan] ?? [$mealPlan];
                                    $includeMeal = false;
                                    foreach ($preferredBoards as $pb) {
                                        if (stripos($boardId, $pb) !== false) {
                                            $includeMeal = true;
                                            break;
                                        }
                                    }
                                }
                                
                                if ($includeMeal) {
                                    $finalPrice = fn_novoton_get_api()->applyCommission($price);
                                    
                                    // Get quota for this room from the pre-fetched quotaMap
                                    $availability = null;
                                    $isOnRequest = false;
                                    
                                    if (!empty($quotaMap)) {
                                        $quotaValue = isset($quotaMap[$roomId]) ? $quotaMap[$roomId] : null;
                                        
                                        if ($quotaValue !== null) {
                                            $quotaValue = trim($quotaValue);
                                            if (strtoupper($quotaValue) === 'RQ' || strtoupper($quotaValue) === 'REQUEST' || $quotaValue === '') {
                                                $isOnRequest = true;
                                                $availability = 0;
                                            } else {
                                                $quotaInt = intval($quotaValue);
                                                if ($quotaInt === 0) {
                                                    $isOnRequest = true;
                                                    $availability = 0;
                                                } else {
                                                    $availability = $quotaInt;
                                                }
                                            }
                                        }
                                    }
                                    
                                    $result_item = [
                                        'room' => null,
                                        'room_id' => $roomId,
                                        'room_name' => str_replace(['%2b', '%2B'], '+', $roomId),
                                        'room_type_display' => fn_novoton_format_room_type($roomId),
                                        'board_id' => $boardId,
                                        'board_name' => fn_novoton_format_board_name($boardId),
                                        'package_name' => urldecode($packageName),
                                        'price_data' => $xml,
                                        'nights' => $nights,
                                        'total_price' => $finalPrice,
                                        'price_per_night' => round($finalPrice / $nights, 2),
                                        'check_in' => $checkIn,
                                        'check_out' => $checkOut,
                                        'rooms_available' => $availability,
                                        'is_on_request' => $isOnRequest,
                                        'remark' => $remark,
                                        'important' => isset($xml->Important) ? (string)$xml->Important : '',
                                        'more_info' => isset($xml->MoreInfo) ? (string)$xml->MoreInfo : '',
                                        'early_booking_discount' => isset($xml->early_booking) ? floatval((string)$xml->early_booking) : 0,
                                        'extras' => isset($xml->extras) ? (string)$xml->extras : '',
                                        'terms_of_payment' => isset($xml->TermsOfPayment) ? $xml->TermsOfPayment->asXML() : '',
                                        'terms_of_cancellation' => isset($xml->TermsOfCancellation) ? $xml->TermsOfCancellation->asXML() : '',
                                        'free_cancellation_date' => isset($xml->TermsOfCancellation) ? fn_novoton_get_free_cancellation_date($xml->TermsOfCancellation->asXML()) : null
                                    ];
                                    
                                    $results[] = $result_item;
                                    
                                    if ($debug_mode) {
                                        $status = $isOnRequest ? 'ON REQUEST' : ($availability !== null ? "{$availability} rooms" : 'available');
                                        $debug_log[] = "  -> ADDED (single): Room={$roomId}, Board={$boardId}, Price={$finalPrice}€, {$status}";
                                    }
                                }
                            }
                        }
                    }
                } else {
                    if ($debug_mode) {
                        $debug_log[] = "  -> Failed to parse XML response";
                    }
                }
            } else {
                if ($debug_mode) {
                    $debug_log[] = "  -> No response from room_price API";
                }
            }
            
            // Deduplicate results - keep best price for each room/board/package combination
            $uniqueResults = [];
            foreach ($results as $result) {
                // Include package_name in key so different packages show separately
                $key = $result['room_id'] . '|' . $result['board_id'] . '|' . ($result['package_name'] ?? '');
                if (!isset($uniqueResults[$key]) || 
                    ($result['total_price'] > 0 && $result['total_price'] < $uniqueResults[$key]['total_price'])) {
                    $uniqueResults[$key] = $result;
                }
            }
            $results = array_values($uniqueResults);
            
            if ($debug_mode) {
                $debug_log[] = "";
                $debug_log[] = "=== RESULTS SUMMARY (from room_price + hotel_quota API) ===";
                $debug_log[] = "Total results found: " . count($results);
            }
            
            } // End of SINGLE ROOM MODE else block
            
            // No fallback to stored prices - real-time API only for booking search
            
            // If no results, try alternative dates (using flex_days if provided)
            if (empty($results)) {
                $no_availability_message = true;
                
                // Use flex_days if provided (from homepage), otherwise check ±10 days
                $search_range = ($flex_days > 0) ? $flex_days : 10;
                
                // Build alternative dates list
                $alternative_dates = [];
                $base_date = strtotime($checkIn);
                
                // Try dates after the selected date
                for ($i = 1; $i <= $search_range; $i++) {
                    $try_date = date('Y-m-d', strtotime("+{$i} days", $base_date));
                    // Skip dates in the past
                    if (strtotime($try_date) < strtotime('today')) continue;
                    $alternative_dates[] = $try_date;
                }
                
                // Try dates before the selected date (if not in the past)
                for ($i = 1; $i <= $search_range; $i++) {
                    $try_date = date('Y-m-d', strtotime("-{$i} days", $base_date));
                    if (strtotime($try_date) >= strtotime('today')) {
                        array_unshift($alternative_dates, $try_date);
                    }
                }
                
                if ($debug_mode) {
                    $debug_log[] = "No results for {$checkIn}. Searching alternative dates (±{$search_range} days)...";
                    $debug_log[] = "Alternative dates to try: " . implode(', ', array_slice($alternative_dates, 0, 5)) . "...";
                }
                
                // Search for alternative dates
                foreach ($alternative_dates as $alt_check_in) {
                    $alt_check_out = date('Y-m-d', strtotime($alt_check_in . ' +' . $nights . ' days'));
                    
                    foreach ($rooms as $room) {
                        if (!is_object($room) && !is_array($room)) continue;
                        
                        $roomId = is_object($room) ? (string)$room->IdRoom : ($room['IdRoom'] ?? '');
                        $roomName = is_object($room) ? (string)$room->Room : ($room['Room'] ?? '');
                        if (empty($roomId)) continue;
                        
                        foreach ($boardTypes as $tryBoard) {
                            $priceParams = [
                                'hotel_id' => $hotelId,
                                'room_id' => $roomId,
                                'board_id' => $tryBoard,
                                'star_rating' => '4*',
                                'check_in' => $alt_check_in,
                                'check_out' => $alt_check_out,
                                'adults' => $adults,
                                'children' => $children
                            ];
                            
                            $priceData = fn_novoton_get_api()->getRoomPrice($priceParams);
                            
                            if ($priceData && isset($priceData->Price)) {
                                $rawPrice = floatval((string)$priceData->Price);
                                if ($rawPrice > 0) {
                                    // Found availability on alternative date!
                                    $alternative_check_in = $alt_check_in;
                                    $alternative_check_out = $alt_check_out;
                                    
                                    $altPrice = fn_novoton_get_api()->applyCommission($rawPrice);
                                    $alternative_results[] = [
                                        'room' => $room,
                                        'room_id' => $roomId,
                                        'room_name' => $roomName ?: str_replace(['%2b', '%2B'], '+', $roomId),
                                        'board_id' => $tryBoard,
                                        'board_name' => fn_novoton_format_board_name($tryBoard),
                                        'price_data' => $priceData,
                                        'nights' => $nights,
                                        'total_price' => $altPrice,
                                        'price_per_night' => round($altPrice / $nights, 2),
                                        'check_in' => $alt_check_in,
                                        'check_out' => $alt_check_out
                                    ];
                                    break; // Found for this room, move to next
                                }
                            }
                        }
                    }
                    
                    // If we found results, stop searching
                    if (!empty($alternative_results)) {
                        break;
                    }
                }
            }
        }
        
    } else {
        // Homepage search - redirect to product search with filters
        $destination = !empty($searchParams['destination']) ? $searchParams['destination'] : '';
        $searchQuery = !empty($searchParams['q']) ? $searchParams['q'] : '';
        
        // Redirect to product search with novoton filters
        $redirect_params = [
            'q' => $searchQuery ?: $destination,
            'novoton_check_in' => $checkIn,
            'novoton_check_out' => $checkOut,
            'novoton_adults' => $adults
        ];
        
        return [CONTROLLER_STATUS_REDIRECT, 'products.search?' . http_build_query($redirect_params)];
    }
    
    // Assign to view - ensure no null values
    Tygh::$app['view']->assign('novoton_results', $results ?: []);
    Tygh::$app['view']->assign('novoton_params', $novoton_params ?: []);
    
    // Alternative dates results - ensure no null values
    Tygh::$app['view']->assign('alternative_results', $alternative_results ?: []);
    Tygh::$app['view']->assign('alternative_check_in', $alternative_check_in ?: '');
    Tygh::$app['view']->assign('alternative_check_out', $alternative_check_out ?: '');
    Tygh::$app['view']->assign('no_availability_message', $no_availability_message ?: false);
    Tygh::$app['view']->assign('flex_days', $flex_days);
    Tygh::$app['view']->assign('flex_dates_searched', ($flex_days > 0 && !empty($alternative_check_in)));
    
    // Get hotel info for display
    $hotel_name_display = '';
    $hotel_city_display = '';
    $hotel_region_display = '';
    $hotel_country_display = '';
    
    if (!empty($hotelId)) {
        // A73: Optimized query - select only display columns, not JSON blobs
        $hotel_info = db_get_row(
            "SELECT hotel_id, hotel_name, city, region, country, stars, 
                    packages_data, ages_data
             FROM ?:novoton_hotels WHERE hotel_id = ?s",
            $hotelId
        );
        if ($hotel_info) {
            $hotel_name_display = $hotel_info['hotel_name'] ?? '';
            $hotel_city_display = $hotel_info['city'] ?? '';
            $hotel_region_display = $hotel_info['region'] ?? '';
            $hotel_country_display = $hotel_info['country'] ?? '';
            
            // Get package name
            if (!empty($hotel_info['packages_data'])) {
                $packages = json_decode($hotel_info['packages_data'], true);
                if (!empty($packages)) {
                    // Use first non-bracketed package or just first
                    $package_name = '';
                    foreach ($packages as $pkg) {
                        $pname = is_array($pkg) ? ($pkg['PackageName'] ?? '') : '';
                        if (!empty($pname) && substr($pname, -1) != ']') {
                            $package_name = $pname;
                            break;
                        }
                    }
                    if (empty($package_name) && !empty($packages[0])) {
                        $package_name = is_array($packages[0]) ? ($packages[0]['PackageName'] ?? '') : '';
                    }
                    Tygh::$app['view']->assign('hotel_package_name', $package_name ?? '');
                    
                    // Also assign all packages for multi-room display
                    Tygh::$app['view']->assign('hotel_all_packages', $packages);
                } else {
                    Tygh::$app['view']->assign('hotel_package_name', '');
                    Tygh::$app['view']->assign('hotel_all_packages', []);
                }
            } else {
                Tygh::$app['view']->assign('hotel_package_name', '');
                Tygh::$app['view']->assign('hotel_all_packages', []);
            }
            
            // Check for active early booking
            // A73: Optimized query - select only needed columns
            $current_date = date('Y-m-d');
            $active_eb = db_get_row(
                "SELECT id, reduction, booking_from, booking_to, stay_from, stay_to,
                        payment_date, payment_percent, room_types, min_stay
                 FROM ?:novoton_early_booking 
                 WHERE hotel_id = ?s AND booking_from <= ?s AND booking_to >= ?s 
                 ORDER BY reduction DESC LIMIT 1",
                $hotelId, $current_date, $current_date
            );
            if ($active_eb) {
                Tygh::$app['view']->assign('active_early_booking', $active_eb);
            }
        } else {
            // Hotel not found in novoton_hotels - try product name
            Tygh::$app['view']->assign('hotel_package_name', '');
        }
        
        // Fallback: if hotel_name still empty, get from product
        if (empty($hotel_name_display) && !empty($productId)) {
            $product_name = db_get_field(
                "SELECT product FROM ?:product_descriptions WHERE product_id = ?i AND lang_code = ?s",
                $productId,
                CART_LANGUAGE
            );
            if (!empty($product_name)) {
                $hotel_name_display = $product_name;
            }
        }
        
        // Ultimate fallback
        if (empty($hotel_name_display)) {
            $hotel_name_display = 'Hotel #' . $hotelId;
        }
    }
    
    // Assign hotel display values
    Tygh::$app['view']->assign('hotel_name', $hotel_name_display);
    Tygh::$app['view']->assign('hotel_city', $hotel_city_display ?: 'GOLDEN SANDS');
    Tygh::$app['view']->assign('hotel_region', $hotel_region_display ?: '');
    Tygh::$app['view']->assign('hotel_country', $hotel_country_display ?: 'BULGARIA');
    
    // Extract terms from first result (usually same for all rooms)
    $terms_payment_raw = '';
    $terms_cancellation_raw = '';
    $early_booking_details = '';
    
    if (!empty($results)) {
        foreach ($results as $r) {
            if (!empty($r['terms_of_payment']) && empty($terms_payment_raw)) {
                $terms_payment_raw = $r['terms_of_payment'];
            }
            if (!empty($r['terms_of_cancellation']) && empty($terms_cancellation_raw)) {
                $terms_cancellation_raw = $r['terms_of_cancellation'];
            }
        }
    }
    
    // Parse terms into human-readable format
    $check_in_for_terms = $searchParams['check_in'] ?? '';
    
    // Load func.php if not loaded
    if (!function_exists('fn_novoton_parse_payment_terms')) {
        require_once(Registry::get('config.dir.addons') . 'novoton_holidays/func.php');
    }
    
    $terms_payment_parsed = fn_novoton_parse_payment_terms($terms_payment_raw);
    $terms_cancellation_parsed = fn_novoton_parse_cancellation_terms($terms_cancellation_raw, $check_in_for_terms);
    
    // Format for display
    $terms_payment = fn_novoton_format_payment_terms($terms_payment_raw);
    $terms_cancellation = fn_novoton_format_cancellation_terms($terms_cancellation_raw, $check_in_for_terms);
    
    // Get early booking details from database for tooltip
    if (!empty($hotelId)) {
        $eb_list = db_get_array(
            "SELECT * FROM ?:novoton_early_booking WHERE hotel_id = ?s ORDER BY booking_to",
            $hotelId
        );
        if (!empty($eb_list)) {
            $eb_details = [];
            foreach ($eb_list as $eb) {
                $eb_details[] = "-{$eb['reduction']}% Early Booking discount till {$eb['booking_to']} -- PAYMENT till " . 
                    date('d.m.Y', strtotime($eb['booking_to'] . ' +5 days')) . 
                    " -- STAY in {$eb['stay_from']} - {$eb['stay_to']}";
            }
            $early_booking_details = implode("\n", $eb_details);
        }
    }
    
    Tygh::$app['view']->assign('terms_of_payment', $terms_payment);
    Tygh::$app['view']->assign('terms_of_cancellation', $terms_cancellation);
    Tygh::$app['view']->assign('terms_of_payment_raw', $terms_payment_raw);
    Tygh::$app['view']->assign('terms_of_cancellation_raw', $terms_cancellation_raw);
    Tygh::$app['view']->assign('parsed_payment_terms', $terms_payment_parsed);
    Tygh::$app['view']->assign('parsed_cancellation_terms', $terms_cancellation_parsed);
    Tygh::$app['view']->assign('early_booking_details', $early_booking_details);
    
    // Also pass hotel URL for search form
    if (!empty($productId)) {
        Tygh::$app['view']->assign('hotel_url', fn_url("products.view?product_id={$productId}"));
    } else {
        Tygh::$app['view']->assign('hotel_url', '');
    }
    
    // Debug log if enabled
    if ($debug_mode && !empty($debug_log)) {
        Tygh::$app['view']->assign('novoton_debug', $debug_log);
    }
    
    // Set page title and meta - prevent null errors
    $page_title = __('novoton_holidays.search_results') ?: 'Search Results';
    Tygh::$app['view']->assign('page_title', $page_title);
    
    // Set all navigation and meta variables to prevent null errors in meta.tpl
    Registry::set('navigation.dynamic.page_title', $page_title);
    Registry::set('navigation.dynamic.meta_description', '');
    Registry::set('navigation.dynamic.meta_keywords', '');
    Registry::set('runtime.page_title', $page_title);
    
    // Assign empty strings for any potentially null variables used in meta.tpl
    Tygh::$app['view']->assign('meta_description', '');
    Tygh::$app['view']->assign('meta_keywords', '');
    Tygh::$app['view']->assign('canonical_url', '');
    Tygh::$app['view']->assign('og_image', '');
    Tygh::$app['view']->assign('og_title', $page_title);
    Tygh::$app['view']->assign('og_description', '');
    Tygh::$app['view']->assign('og_type', 'website');
    Tygh::$app['view']->assign('twitter_card', '');
    Tygh::$app['view']->assign('twitter_title', $page_title);
    Tygh::$app['view']->assign('twitter_description', '');
    
    // Set breadcrumbs
    fn_add_breadcrumb($page_title);
}

// Booking form - show guest entry form before adding to cart
if ($mode == 'booking_form') {
    
    $bookingData = $_REQUEST;
    
    // Fix room_id: PHP URL decoding converts + to space, restore it
    // Pattern: "DBL 2 1)" should be "DBL 2+1)"
    if (!empty($bookingData['room_id'])) {
        $bookingData['room_id'] = preg_replace('/(\d)\s+(\d)/', '$1+$2', $bookingData['room_id']);
    }
    
    // Check if this is a multi-room booking
    $is_multi_room = !empty($bookingData['multi_room']) || !empty($bookingData['rooms_data']);
    
    // Parse rooms_data if it's a string (from form submission)
    $rooms_data = [];
    if (!empty($bookingData['rooms_data'])) {
        $rooms_data = is_string($bookingData['rooms_data']) 
            ? json_decode(urldecode($bookingData['rooms_data']), true) 
            : $bookingData['rooms_data'];
        if (!is_array($rooms_data)) {
            $rooms_data = [];
        }
        // Fix room_id in each room (+ converted to space by URL decoding)
        foreach ($rooms_data as &$room) {
            if (!empty($room['room_id'])) {
                $room['room_id'] = preg_replace('/(\d)\s+(\d)/', '$1+$2', $room['room_id']);
            }
        }
        unset($room);
    }
    
    // For multi-room, derive room_id from rooms_data if not directly provided
    if ($is_multi_room && empty($bookingData['room_id']) && !empty($rooms_data[0]['room_id'])) {
        $bookingData['room_id'] = $rooms_data[0]['room_id'];
        $bookingData['board_id'] = $rooms_data[0]['board_id'] ?? 'AI';
    }
    
    // Validate required data
    if (empty($bookingData['hotel_id']) || empty($bookingData['check_in']) || empty($bookingData['check_out'])) {
        fn_set_notification('E', __('error'), __('novoton_holidays.invalid_booking_data') . ' (missing hotel_id, check_in, or check_out)');
        return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
    }
    
    // For non-multi-room, room_id is required
    if (!$is_multi_room && empty($bookingData['room_id'])) {
        fn_set_notification('E', __('error'), __('novoton_holidays.invalid_booking_data') . ' (missing room_id)');
        return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
    }
    
    // Get product and hotel info
    $addon_settings = Registry::get('addons.novoton_holidays') ?? [];
    $prefix = trim(explode(',', $addon_settings['product_code_prefixes'] ?? 'NVT')[0]);
    $product_code = $prefix . $bookingData['hotel_id'];
    
    $product_id = db_get_field(
        "SELECT product_id FROM ?:products WHERE product_code = ?s",
        $product_code
    );
    
    // Get hotel info from novoton_hotels table
    $hotel_info = db_get_row(
        "SELECT * FROM ?:novoton_hotels WHERE hotel_id = ?s",
        $bookingData['hotel_id']
    );
    
    // If hotel not found in novoton_hotels, try to get name from product
    if (empty($hotel_info['hotel_name']) && !empty($product_id)) {
        $product_name = db_get_field(
            "SELECT product FROM ?:product_descriptions WHERE product_id = ?i AND lang_code = ?s",
            $product_id,
            CART_LANGUAGE
        );
        if (!empty($product_name)) {
            if (empty($hotel_info)) {
                $hotel_info = [];
            }
            $hotel_info['hotel_name'] = $product_name;
        }
    }
    
    // Fallback: if still no hotel name, use hotel_id
    if (empty($hotel_info['hotel_name'])) {
        $hotel_info['hotel_name'] = 'Hotel #' . $bookingData['hotel_id'];
    }
    
    // Prepare booking data for template
    $booking = [
        'hotel_id' => $bookingData['hotel_id'],
        'room_id' => $bookingData['room_id'],
        'board_id' => $bookingData['board_id'] ?? 'BB',
        'check_in' => $bookingData['check_in'],
        'check_out' => $bookingData['check_out'],
        'nights' => intval($bookingData['nights'] ?? 7),
        'adults' => intval($bookingData['adults'] ?? 2),
        'children' => intval($bookingData['children'] ?? 0),
        'total_price' => floatval($bookingData['total_price'] ?? $bookingData['price'] ?? 0),
        'children_ages' => $bookingData['children_ages'] ?? '',
        'package_name' => $bookingData['package_name'] ?? '',
        'num_rooms' => intval($bookingData['num_rooms'] ?? 1),
        'rooms_data' => []
    ];
    
    // Parse rooms_data if provided
    if (!empty($bookingData['rooms_data'])) {
        $rooms_data = is_string($bookingData['rooms_data']) ? json_decode($bookingData['rooms_data'], true) : $bookingData['rooms_data'];
        if (is_array($rooms_data)) {
            // Fix room_id in each room (+ converted to space by URL decoding)
            foreach ($rooms_data as &$rm) {
                if (!empty($rm['room_id'])) {
                    $rm['room_id'] = preg_replace('/(\d)\s+(\d)/', '$1+$2', $rm['room_id']);
                }
            }
            unset($rm);
            $booking['rooms_data'] = $rooms_data;
            $booking['num_rooms'] = count($rooms_data);
        }
    }
    
    // If no rooms_data, create default based on adults/children
    if (empty($booking['rooms_data'])) {
        $children_ages_arr = [];
        if (!empty($booking['children_ages'])) {
            $children_ages_arr = is_string($booking['children_ages']) 
                ? array_map('intval', array_filter(explode(',', $booking['children_ages']), function($v) { return $v !== ''; }))
                : (array)$booking['children_ages'];
        }
        
        // Z3: Format board name consistently
        $formatted_board_name = fn_novoton_format_board_name($booking['board_id']);
        if ($formatted_board_name === $booking['board_id'] && !empty($bookingData['board_name'])) {
            // If format function didn't change it, try the passed board_name
            $formatted_board_name = fn_novoton_format_board_name($bookingData['board_name']);
        }
        
        $booking['rooms_data'] = [
            [
                'room_id' => $booking['room_id'],
                'room_name' => $bookingData['room_name'] ?? $booking['room_id'],
                'board_id' => $booking['board_id'],
                'board_name' => $formatted_board_name,
                'adults' => $booking['adults'],
                'children' => $booking['children'],
                'childrenAges' => $children_ages_arr,
                'price' => $booking['total_price']
            ]
        ];
    }
    
    // Parse children ages if string (for legacy support)
    $children_ages_array = [];
    if (!empty($booking['children_ages'])) {
        if (is_string($booking['children_ages'])) {
            $children_ages_array = array_map('intval', array_filter(explode(',', $booking['children_ages']), function($v) { return $v !== ''; }));
        } else {
            $children_ages_array = (array)$booking['children_ages'];
        }
    }
    $booking['children_ages_array'] = $children_ages_array;
    
    // Get package name - only from passed parameters (tied to specific room/price)
    $package_name = '';
    
    // First check rooms_data (from multi-room booking)
    if (!empty($booking['rooms_data'][0]['package_name'])) {
        $package_name = $booking['rooms_data'][0]['package_name'];
    }
    // Then check URL parameter (from single room booking)
    elseif (!empty($bookingData['package_name'])) {
        $package_name = $bookingData['package_name'];
    }
    // Decode URL encoding (e.g., %2b -> +)
    $package_name = urldecode($package_name);
    // Do NOT fall back to database - package_name must come from room_price result
    
    // Get hotel stars
    $hotel_stars = '';
    if (!empty($hotel_info['star_rating'])) {
        $hotel_stars = str_repeat('★', intval($hotel_info['star_rating']));
    } elseif (!empty($hotel_info['hotel_name'])) {
        // Try to extract stars from hotel name (e.g. "Hotel Name ****")
        if (preg_match('/(\*+)/', $hotel_info['hotel_name'], $matches)) {
            $hotel_stars = $matches[1];
        }
    }
    
    // Get all packages for display
    $all_packages = [];
    if (!empty($hotel_info['packages_data'])) {
        $all_packages = json_decode($hotel_info['packages_data'], true) ?: [];
    }
    
    // Get age categories and room limits from hotel info or fetch from API
    $age_categories = [];
    $room_limits = [];
    
    // Try to get from database first
    if (!empty($hotel_info['ages_data'])) {
        $age_categories = json_decode($hotel_info['ages_data'], true) ?: [];
    }
    if (!empty($hotel_info['rooms_data'])) {
        $rooms_db = json_decode($hotel_info['rooms_data'], true) ?: [];
        if (!empty($rooms_db) && isset($rooms_db[0])) {
            foreach ($rooms_db as $r) {
                $rid = $r['id'] ?? $r['IdRoom'] ?? '';
                if ($rid) $room_limits[$rid] = $r;
            }
        } else {
            $room_limits = $rooms_db;
        }
    }
    
    // If not in DB, fetch from API
    if ((empty($age_categories) || empty($room_limits)) && !empty($booking['hotel_id'])) {
        $api = fn_novoton_get_api();
        if ($api) {
            $hotelInfoResponse = $api->getHotelInfo($booking['hotel_id']);
            if ($hotelInfoResponse && isset($hotelInfoResponse->hotels->hotel)) {
                $h = $hotelInfoResponse->hotels->hotel;
                
                // Parse age categories
                if (isset($h->age)) {
                    $ages = isset($h->age->IdAge) ? [$h->age] : $h->age;
                    foreach ($ages as $age) {
                        $age_data = [
                            'id' => (string)($age->IdAge ?? ''),
                            'is_child' => ((string)($age->fAge ?? '0')) === '1',
                            'from_year' => floatval((string)($age->FromYear ?? 0)),
                            'to_year' => floatval((string)($age->ToYear ?? 99))
                        ];
                        $age_categories[] = $age_data;
                    }
                }
                
                // Parse room limits
                if (isset($h->rooms)) {
                    $rooms = isset($h->rooms->IdRoom) ? [$h->rooms] : $h->rooms;
                    foreach ($rooms as $room) {
                        $room_id = (string)($room->IdRoom ?? '');
                        $room_limits[$room_id] = [
                            'id' => $room_id,
                            'type' => (string)($room->Type ?? ''),
                            'rb' => intval((string)($room->RB ?? 2)),
                            'eb' => intval((string)($room->EB ?? 0)),
                            'max_adults' => intval((string)($room->maxADT ?? 4)),
                            'max_children' => intval((string)($room->maxCHD ?? 2)),
                            'min_pax' => intval((string)($room->minPAX ?? 1))
                        ];
                    }
                }
                
                // Store in database for future use
                if (!empty($age_categories) || !empty($room_limits)) {
                    $update_data = [];
                    if (!empty($age_categories)) {
                        $update_data['ages_data'] = json_encode($age_categories);
                    }
                    if (!empty($room_limits)) {
                        $update_data['rooms_data'] = json_encode($room_limits);
                    }
                    if (!empty($update_data)) {
                        db_query("UPDATE ?:novoton_hotels SET ?u WHERE hotel_id = ?s", $update_data, $booking['hotel_id']);
                    }
                }
            }
        }
    }
    
    // Add age categories and room limits to booking data for JavaScript
    $booking['age_categories'] = $age_categories;
    $current_room_id = $booking['room_id'];
    $booking['current_room_limits'] = $room_limits[$current_room_id] ?? [
        'max_adults' => 4,
        'max_children' => 2,
        'min_pax' => 1,
        'rb' => 2,
        'eb' => 2
    ];
    
    // Assign to view
    Tygh::$app['view']->assign('booking_data', $booking);
    Tygh::$app['view']->assign('product_id', $product_id);
    Tygh::$app['view']->assign('hotel_name', $hotel_info['hotel_name'] ?? 'Hotel');
    Tygh::$app['view']->assign('hotel_city', $hotel_info['city'] ?? '');
    Tygh::$app['view']->assign('hotel_region', $hotel_info['region'] ?? '');
    Tygh::$app['view']->assign('hotel_country', $hotel_info['country'] ?? 'BULGARIA');
    Tygh::$app['view']->assign('hotel_stars', $hotel_stars);
    Tygh::$app['view']->assign('package_name', $package_name);
    Tygh::$app['view']->assign('hotel_all_packages', $all_packages);
    Tygh::$app['view']->assign('auth', Tygh::$app['session']['auth'] ?? []);
    
    // Terms are now fetched directly from API at checkout (Option A)
    // No need to pass through booking form
    
    // Page setup
    $page_title = __('novoton_holidays.complete_booking');
    Tygh::$app['view']->assign('page_title', $page_title);
    Registry::set('navigation.dynamic.page_title', $page_title);
    fn_add_breadcrumb($page_title);
}

// Add to cart (with guest details from booking form)
if ($mode == 'add_to_cart') {
    
    $bookingData = $_REQUEST;
    
    // Fix room_id: PHP URL decoding converts + to space, restore it
    // Pattern: "DBL 2 1)" should be "DBL 2+1)"
    if (!empty($bookingData['room_id'])) {
        $bookingData['room_id'] = preg_replace('/(\d)\s+(\d)/', '$1+$2', $bookingData['room_id']);
    }
    
    // Debug: Log RAW POST data for guests
    fn_log_event('general', 'runtime', [
        'message' => 'Novoton add_to_cart: RAW REQUEST DEBUG',
        'REQUEST_guests_isset' => isset($_REQUEST['guests']) ? 'YES' : 'NO',
        'POST_guests_isset' => isset($_POST['guests']) ? 'YES' : 'NO',
        'REQUEST_guests_type' => isset($_REQUEST['guests']) ? gettype($_REQUEST['guests']) : 'NOT SET',
        'REQUEST_guests_count' => isset($_REQUEST['guests']) && is_array($_REQUEST['guests']) ? count($_REQUEST['guests']) : 0,
        'REQUEST_guests_keys' => isset($_REQUEST['guests']) && is_array($_REQUEST['guests']) ? array_keys($_REQUEST['guests']) : 'NO KEYS',
        'REQUEST_guests_full' => isset($_REQUEST['guests']) ? $_REQUEST['guests'] : 'NO GUESTS IN REQUEST'
    ]);
    
    // Debug: Log incoming data including guests
    fn_log_event('general', 'runtime', [
        'message' => 'Novoton add_to_cart: incoming data',
        'num_rooms' => $bookingData['num_rooms'] ?? 'NOT SET',
        'rooms_data_raw' => substr($bookingData['rooms_data'] ?? 'NOT SET', 0, 500),
        'is_multi_room' => $bookingData['is_multi_room'] ?? 'NOT SET',
        'guests_keys' => isset($bookingData['guests']) ? array_keys($bookingData['guests']) : 'NO GUESTS',
        'guests_data' => isset($bookingData['guests']) ? $bookingData['guests'] : 'NO GUESTS'
    ]);
    
    // Validate booking data
    if (empty($bookingData['hotel_id']) || empty($bookingData['room_id']) || 
        empty($bookingData['check_in']) || empty($bookingData['check_out'])) {
        fn_set_notification('E', __('error'), __('novoton_holidays.invalid_booking_data'));
        return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
    }
    
    // Get product ID from hotel ID
    $addon_settings = Registry::get('addons.novoton_holidays') ?? [];
    $prefix = trim(explode(',', $addon_settings['product_code_prefixes'] ?? 'NVT')[0]);
    $product_code = $prefix . $bookingData['hotel_id'];
    
    $product_id = db_get_field(
        "SELECT product_id FROM ?:products WHERE product_code = ?s",
        $product_code
    );
    
    if (empty($product_id)) {
        // Try the product_id from form
        $product_id = intval($bookingData['product_id'] ?? 0);
    }
    
    if (empty($product_id)) {
        fn_set_notification('E', __('error'), __('novoton_holidays.product_not_found'));
        return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
    }
    
    // Get hotel info using repository
    $hotel_info = _nvt_hotel_repo()->findById($bookingData['hotel_id']);
    
    // Process guest information
    $guests = $bookingData['guests'] ?? [];
    $contact = $bookingData['contact'] ?? [];
    $special_requests = $bookingData['special_requests'] ?? '';
    
    // Parse guests (no full DOB validation needed at add_to_cart, that happens in update_booking)
    $parsed_guests = _nvt_parse_and_validate_guests($guests, '', 0, '');
    $guests_data = $parsed_guests['guests_data'] ?? [];
    $guest_names = $parsed_guests['guest_names'] ?? [];
    $guest_list = $parsed_guests['guest_list'] ?? '';
    $holder_name = $parsed_guests['holder_name'] ?? '';
    
    // Debug: Log processed guests
    fn_log_event('general', 'runtime', [
        'message' => 'Novoton add_to_cart: processed guests',
        'guest_names_count' => count($guest_names),
        'guest_names' => $guest_names,
        'guests_data_keys' => array_keys($guests_data),
        'guests_data' => $guests_data,
        'holder_name' => $holder_name
    ]);
    
    // Get children ages from guests_data (more reliable than form hidden field)
    $all_child_ages = [];
    foreach ($guests_data as $guest) {
        if (isset($guest['type']) && $guest['type'] == 'child' && isset($guest['age'])) {
            $all_child_ages[] = intval($guest['age']);
        }
    }
    $children_ages = !empty($all_child_ages) ? implode(',', $all_child_ages) : ($bookingData['children_ages'] ?? '');
    
    // Get package name
    $package_name = $bookingData['package_name'] ?? '';
    if (empty($package_name) && !empty($hotel_info['packages_data'])) {
        $packages = json_decode($hotel_info['packages_data'], true);
        if (!empty($packages[0]['PackageName'])) {
            $package_name = $packages[0]['PackageName'];
        }
    }
    
    // Get total price (from form or recalculate)
    $total_price = floatval($bookingData['total_price'] ?? 0);
    
    // Always call API to get terms and verify price (Option A: fetch terms at checkout)
    // IMPORTANT: Include children ages for correct price calculation
    $priceParams = [
        'hotel_id' => $bookingData['hotel_id'],
        'room_id' => $bookingData['room_id'],
        'board_id' => $bookingData['board_id'] ?? '',
        'star_rating' => '',
        'check_in' => $bookingData['check_in'],
        'check_out' => $bookingData['check_out'],
        'adults' => intval($bookingData['adults'] ?? 2),
        'children' => $all_child_ages  // Include children ages from guest form
    ];
    
    $priceData = fn_novoton_get_api()->getRoomPrice($priceParams);
    
    // Initialize terms
    $terms_of_payment = '';
    $terms_of_cancellation = '';
    $remark = '';
    $important = '';
    $base_price = 0; // API price before commission

    if ($priceData) {
        // Update price if we got one from API
        if (isset($priceData->Price)) {
            $rawPrice = floatval((string)$priceData->Price);
            $base_price = $rawPrice;
            $api_price = fn_novoton_get_api()->applyCommission($rawPrice);
            // ALWAYS use API price when children are involved (ages affect pricing)
            // Also use if we don't have one, or if it's different
            if (!empty($all_child_ages) || $total_price <= 0 || abs($total_price - $api_price) > 0.01) {
                $total_price = $api_price;
            }
        }
        
        // Extract terms from API response
        if (isset($priceData->TermsOfPayment)) {
            $terms_of_payment = $priceData->TermsOfPayment->asXML();
        }
        if (isset($priceData->TermsOfCancellation)) {
            $terms_of_cancellation = $priceData->TermsOfCancellation->asXML();
        }
        if (isset($priceData->remark)) {
            $remark = (string)$priceData->remark;
        }
        if (isset($priceData->Important)) {
            $important = (string)$priceData->Important;
        }
    }
    
    if ($total_price <= 0) {
        fn_set_notification('E', __('error'), __('novoton_holidays.price_unavailable'));
        return [CONTROLLER_STATUS_REDIRECT, 'products.view?product_id=' . $product_id];
    }
    
    // Calculate nights
    $nights = intval($bookingData['nights'] ?? 0);
    if ($nights <= 0) {
        $check_in_ts = strtotime($bookingData['check_in']);
        $check_out_ts = strtotime($bookingData['check_out']);
        $nights = ($check_out_ts - $check_in_ts) / 86400;
    }
    
    // Format board name for display
    $board_id = $bookingData['board_id'] ?? 'BB';
    $board_name = $board_id;
    $board_map = [
        'AI' => 'All Inclusive',
        'ALL INCL' => 'All Inclusive',
        'FB' => 'Full Board',
        'HB' => 'Half Board',
        'BB' => 'Bed & Breakfast',
        'RO' => 'Room Only'
    ];
    if (isset($board_map[$board_id])) {
        $board_name = $board_map[$board_id];
    }
    
    // Parse rooms_data
    $num_rooms = intval($bookingData['num_rooms'] ?? 1);
    $rooms_data = [];
    if (!empty($bookingData['rooms_data'])) {
        $rooms_data = is_string($bookingData['rooms_data']) ? json_decode($bookingData['rooms_data'], true) : $bookingData['rooms_data'];
        if (!is_array($rooms_data)) {
            $rooms_data = [];
        }
        // Fix room_id in each room (+ converted to space by URL decoding)
        foreach ($rooms_data as &$rm) {
            if (!empty($rm['room_id'])) {
                $rm['room_id'] = preg_replace('/(\d)\s+(\d)/', '$1+$2', $rm['room_id']);
            }
        }
        unset($rm);
    }
    
    // If rooms_data is still empty, create default with complete info
    if (empty($rooms_data)) {
        $children_ages_arr = [];
        if (!empty($bookingData['children_ages'])) {
            $children_ages_arr = is_string($bookingData['children_ages']) 
                ? array_map('intval', array_filter(explode(',', $bookingData['children_ages']), function($v) { return $v !== ''; }))
                : (array)$bookingData['children_ages'];
        }
        $rooms_data = [
            [
                'room_id' => $bookingData['room_id'],
                'room_name' => fn_novoton_format_room_type($bookingData['room_id']),
                'room_type_display' => fn_novoton_format_room_type($bookingData['room_id']),
                'board_id' => $board_id,
                'board_name' => $board_name,
                'adults' => intval($bookingData['adults'] ?? 2),
                'children' => intval($bookingData['children'] ?? 0),
                'childrenAges' => $children_ages_arr,
                'price' => floatval($bookingData['total_price'] ?? 0)
            ]
        ];
        $num_rooms = 1;
    }
    
    // Add children_ages_str and room_type_display to each room for Smarty display
    // Also sync children ages from guest form back to rooms_data
    foreach ($rooms_data as $room_idx => &$room) {
        $room_num = $room_idx + 1;
        
        // Collect children ages from guests_data for this room
        $child_ages_for_room = [];
        foreach ($guests_data as $key => $guest) {
            if (isset($guest['room']) && $guest['room'] == $room_num && $guest['type'] == 'child') {
                $child_ages_for_room[] = intval($guest['age']);
            }
        }
        
        // If we have ages from guest form, update rooms_data
        if (!empty($child_ages_for_room)) {
            $room['childrenAges'] = $child_ages_for_room;
        }
        
        if (!empty($room['childrenAges']) && is_array($room['childrenAges'])) {
            // Filter out null values and format
            $valid_ages = array_filter($room['childrenAges'], function($age) { return $age !== null && $age !== ''; });
            $room['children_ages_str'] = !empty($valid_ages) ? implode(', ', $valid_ages) . ' ' . __('novoton_holidays.years_old') : '';
        } else {
            $room['children_ages_str'] = '';
        }
        // Ensure room_type_display is set (translated room name)
        if (empty($room['room_type_display']) && !empty($room['room_id'])) {
            $room['room_type_display'] = fn_novoton_format_room_type($room['room_id']);
            $room['room_name'] = fn_novoton_format_room_type($room['room_id']);
        }
    }
    unset($room);
    
    // Debug: Always log what we're storing
    fn_log_event('general', 'runtime', [
        'message' => 'Novoton add_to_cart: parsed rooms_data',
        'num_rooms' => $num_rooms,
        'rooms_data_count' => count($rooms_data),
        'rooms_data_sample' => array_slice($rooms_data, 0, 2)
    ]);
    
    // Check if similar booking already exists (same hotel, dates, holder, no order yet)
    // This prevents duplicates from form resubmissions
    $existing_booking_id = db_get_field(
        "SELECT booking_id FROM ?:novoton_bookings 
         WHERE order_id = 0 
         AND hotel_id = ?s 
         AND check_in = ?s 
         AND check_out = ?s 
         AND holder_name = ?s
         AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
         LIMIT 1",
        $bookingData['hotel_id'],
        $bookingData['check_in'],
        $bookingData['check_out'],
        $holder_name
    );
    
    // Extract room_id and room_type from rooms_data for database columns
    // This ensures the columns are populated even for multi-room bookings
    $room_ids_for_db = [];
    $room_types_for_db = [];
    $total_adults = 0;
    $total_children = 0;
    
    foreach ($rooms_data as $room) {
        if (!empty($room['room_id'])) {
            $room_ids_for_db[] = $room['room_id'];
        }
        if (!empty($room['room_name'])) {
            $room_types_for_db[] = $room['room_name'];
        } elseif (!empty($room['room_type_display'])) {
            $room_types_for_db[] = $room['room_type_display'];
        } elseif (!empty($room['room_id'])) {
            $room_types_for_db[] = fn_novoton_format_room_type($room['room_id']);
        }
        $total_adults += intval($room['adults'] ?? 0);
        $total_children += intval($room['children'] ?? 0);
    }
    
    // Fallback to bookingData if rooms_data didn't have room_id
    if (empty($room_ids_for_db) && !empty($bookingData['room_id'])) {
        $room_ids_for_db[] = $bookingData['room_id'];
        $room_types_for_db[] = fn_novoton_format_room_type($bookingData['room_id']);
    }
    
    // Use totals from rooms_data if available, otherwise from bookingData
    if ($total_adults == 0) {
        $total_adults = intval($bookingData['adults'] ?? 2);
    }
    if ($total_children == 0) {
        $total_children = intval($bookingData['children'] ?? 0);
    }
    
    $room_id_column = implode(', ', $room_ids_for_db);
    $room_type_column = implode(', ', $room_types_for_db);
    
    if ($existing_booking_id) {
        // Update existing booking instead of creating new one
        $booking_record = [
            'room_id' => $room_id_column,
            'room_type' => $room_type_column,
            'adults' => $total_adults,
            'children' => $total_children,
            'rooms_data' => json_encode($rooms_data),
            'guest_name' => $guest_list,
            'guests_data' => json_encode($guests_data),
            'base_price' => $base_price,
            'total_price' => $total_price,
            'guest_email' => $contact['email'] ?? '',
            'api_request' => json_encode([
                'guests' => $guests_data,
                'contact' => $contact,
                'rooms_data' => $rooms_data
            ])
        ];
        // Update user_id if now logged in
        $auth = Tygh::$app['session']['auth'] ?? [];
        if (!empty($auth['user_id'])) {
            $booking_record['user_id'] = intval($auth['user_id']);
        }
        // A79: Use BookingRepository for update
        _nvt_booking_repo()->update($existing_booking_id, $booking_record);
        $booking_id = $existing_booking_id;
    } else {
        // Get current user and session info
        $auth = Tygh::$app['session']['auth'] ?? [];
        $user_id = !empty($auth['user_id']) ? intval($auth['user_id']) : 0;
        $session_id = session_id();
        
        // Create new booking record in database
        $booking_record = [
            'order_id' => 0, // Will be updated when order is placed
            'user_id' => $user_id,
            'session_id' => $session_id,
            'product_id' => $product_id,
            'hotel_id' => $bookingData['hotel_id'],
            'hotel_name' => $hotel_info['hotel_name'] ?? '',
            'package_name' => $package_name,
            'room_id' => $room_id_column,
            'room_type' => $room_type_column,
            'board_id' => $board_id,
            'check_in' => $bookingData['check_in'],
            'check_out' => $bookingData['check_out'],
            'nights' => $nights,
            'adults' => $total_adults,
            'children' => $total_children,
            'children_ages' => $children_ages,
            'num_rooms' => $num_rooms,
            'rooms_data' => json_encode($rooms_data),
            'guest_name' => $guest_list,
            'holder_name' => $holder_name,
            'guest_email' => '',  // Will be set from order at checkout
            'guest_phone' => $contact['phone'] ?? '',
            'guests_data' => json_encode($guests_data),
            'base_price' => $base_price,
            'total_price' => $total_price,
            'currency' => 'EUR',
            'status' => 'pending',
            'special_requests' => $special_requests,
            'notes' => $special_requests,
            'api_request' => json_encode([
                'guests' => $guests_data,
                'contact' => $contact,
                'rooms_data' => $rooms_data
            ])
        ];
        
        // A79: Use BookingRepository for create
        $booking_id = _nvt_booking_repo()->create($booking_record);
    }
    
    // Add to cart with booking details
    $product = [
        'product_id' => $product_id,
        'amount' => 1,
        'extra' => [
            'novoton_booking' => true,
            'novoton_booking_id' => $booking_id,
            'hotel_id' => $bookingData['hotel_id'],
            'hotel_name' => $hotel_info['hotel_name'] ?? '',
            'hotel_city' => $hotel_info['city'] ?? '',
            'hotel_country' => $hotel_info['country'] ?? 'BULGARIA',
            'package_name' => $package_name,
            'room_id' => $bookingData['room_id'],
            'room_name' => str_replace(['%2b', '%2B'], '+', $bookingData['room_id']),
            'room_type_display' => fn_novoton_format_room_type($bookingData['room_id']),
            'board_id' => $board_id,
            'board_name' => $board_name,
            'check_in' => $bookingData['check_in'],
            'check_out' => $bookingData['check_out'],
            'nights' => $nights,
            'adults' => intval($bookingData['adults'] ?? 2),
            'children' => intval($bookingData['children'] ?? 0),
            'children_ages' => $children_ages,
            'num_rooms' => intval($num_rooms),  // Explicitly cast to int
            'rooms_data' => $rooms_data,
            'guest_names' => $guest_list,
            'holder_name' => $holder_name,
            'guests_data' => json_encode($guests_data),  // Store as JSON string to preserve associative keys
            'contact_email' => $contact['email'] ?? '',
            'contact_phone' => $contact['phone'] ?? '',
            'special_requests' => $special_requests,
            'terms_of_payment' => fn_novoton_format_payment_terms($terms_of_payment),
            'terms_of_cancellation' => fn_novoton_format_cancellation_terms($terms_of_cancellation, $bookingData['check_in']),
            'terms_of_payment_raw' => $terms_of_payment,
            'terms_of_cancellation_raw' => $terms_of_cancellation,
            'remark' => $remark,
            'important' => $important,
            'total_price' => $total_price,
            'currency' => 'EUR'
        ]
    ];
    
    // Set the price directly (override product price)
    $product['price'] = $total_price;
    
    // Add to cart
    $cart = &Tygh::$app['session']['cart'];
    $auth = &Tygh::$app['session']['auth'];
    
    // Initialize cart if needed
    if (empty($cart)) {
        fn_clear_cart($cart);
    }
    
    // Generate unique cart_id for this booking
    $cart_id = fn_generate_cart_id($product_id, $product['extra']);
    
    // Add product to cart
    $cart['products'][$cart_id] = [
        'product_id' => $product_id,
        'amount' => 1,
        'price' => $total_price,
        'base_price' => $total_price,
        'original_price' => $total_price,
        'extra' => $product['extra'],
        'stored_price' => 'Y'  // Important: use our calculated price
    ];
    
    // Recalculate cart
    fn_calculate_cart_content($cart, $auth, 'S', true, 'F', true);
    fn_save_cart_content($cart, $auth['user_id'] ?? 0);
    
    fn_set_notification('N', __('success'), __('novoton_holidays.added_to_cart'));
    
    return [CONTROLLER_STATUS_REDIRECT, 'checkout.cart'];
}

// Book now (legacy - redirect to booking form)
if ($mode == 'book') {
    
    $bookingData = $_REQUEST;
    
    // Redirect to booking_form mode
    $redirect_url = 'novoton_booking.booking_form?' . http_build_query($bookingData);
    return [CONTROLLER_STATUS_REDIRECT, $redirect_url];
}

// Edit booking - allow editing guest details for an existing cart item
if ($mode == 'edit_booking') {
    
    $booking_id = intval($_REQUEST['booking_id'] ?? 0);
    $cart_id = $_REQUEST['cart_id'] ?? '';
    
    if (empty($booking_id)) {
        fn_set_notification('E', __('error'), __('novoton_holidays.invalid_booking_data'));
        return [CONTROLLER_STATUS_REDIRECT, 'checkout.cart'];
    }
    
    // Get booking record from database
    $booking_record = db_get_row(
        "SELECT * FROM ?:novoton_bookings WHERE booking_id = ?i",
        $booking_id
    );
    
    if (empty($booking_record)) {
        fn_set_notification('E', __('error'), __('novoton_holidays.invalid_booking_data'));
        return [CONTROLLER_STATUS_REDIRECT, 'checkout.cart'];
    }
    
    // Also try to get data from cart session (more up-to-date)
    $cart = &Tygh::$app['session']['cart'];
    $cart_item = null;
    if (!empty($cart_id) && !empty($cart['products'][$cart_id])) {
        $cart_item = $cart['products'][$cart_id];
    }
    
    // Get hotel info
    $hotel_info = db_get_row(
        "SELECT * FROM ?:novoton_hotels WHERE hotel_id = ?s",
        $booking_record['hotel_id']
    );
    
    // Get hotel name - try multiple sources
    $hotel_name = $hotel_info['hotel_name'] ?? '';
    if (empty($hotel_name)) {
        // Try from booking record
        $hotel_name = $booking_record['hotel_name'] ?? '';
    }
    if (empty($hotel_name) && !empty($booking_record['product_id'])) {
        // Try from product name
        $hotel_name = db_get_field("SELECT product FROM ?:product_descriptions WHERE product_id = ?i AND lang_code = ?s", 
            $booking_record['product_id'], CART_LANGUAGE);
    }
    if (empty($hotel_name)) {
        $hotel_name = 'Hotel #' . $booking_record['hotel_id'];
    }
    
    // Build booking data for form - prefer cart data over database
    $rooms_data = [];
    $guests_data = [];
    
    // Try cart first, then database
    if ($cart_item && !empty($cart_item['extra']['rooms_data'])) {
        $rooms_data = is_string($cart_item['extra']['rooms_data']) 
            ? json_decode($cart_item['extra']['rooms_data'], true) 
            : $cart_item['extra']['rooms_data'];
    }
    if (empty($rooms_data)) {
        $rooms_data = json_decode($booking_record['rooms_data'], true) ?: [];
    }
    
    if ($cart_item && !empty($cart_item['extra']['guests_data'])) {
        $guests_data = is_string($cart_item['extra']['guests_data']) 
            ? json_decode($cart_item['extra']['guests_data'], true) 
            : $cart_item['extra']['guests_data'];
    }
    if (empty($guests_data)) {
        $guests_data = json_decode($booking_record['guests_data'], true) ?: [];
    }
    
    // Ensure dob field is in DD/MM/YYYY format for each guest (template expects this format)
    foreach ($guests_data as $key => &$guest) {
        if (empty($guest['dob']) && !empty($guest['birthday'])) {
            // Convert YYYY-MM-DD to DD/MM/YYYY
            $ts = strtotime($guest['birthday']);
            if ($ts) {
                $guest['dob'] = date('d/m/Y', $ts);
            }
        }
    }
    unset($guest);
    
    // Get special_requests from cart or database
    $special_requests = '';
    if ($cart_item && !empty($cart_item['extra']['special_requests'])) {
        $special_requests = $cart_item['extra']['special_requests'];
    } elseif (!empty($booking_record['special_requests'])) {
        $special_requests = $booking_record['special_requests'];
    }
    
    $booking = [
        'hotel_id' => $booking_record['hotel_id'],
        'room_id' => $booking_record['room_id'],
        'board_id' => $booking_record['board_id'],
        'check_in' => $booking_record['check_in'],
        'check_out' => $booking_record['check_out'],
        'nights' => $booking_record['nights'],
        'adults' => $booking_record['adults'],
        'children' => $booking_record['children'],
        'children_ages' => $booking_record['children_ages'],
        'total_price' => $booking_record['total_price'],
        'package_name' => $booking_record['package_name'],
        'num_rooms' => $booking_record['num_rooms'] ?: 1,
        'rooms_data' => $rooms_data,
        'guests_data' => $guests_data,
        'special_requests' => $special_requests
    ];
    
    // Ensure rooms_data is not empty for single room bookings
    if (empty($booking['rooms_data'])) {
        // Parse children ages
        $children_ages_arr = [];
        if (!empty($booking['children_ages'])) {
            $children_ages_arr = array_map('intval', array_filter(explode(',', $booking['children_ages']), function($v) { return $v !== ''; }));
        }
        
        // Create default rooms_data
        $booking['rooms_data'] = [
            [
                'room_id' => $booking_record['room_id'],
                'room_name' => fn_novoton_format_room_type($booking_record['room_id']),
                'room_type_display' => fn_novoton_format_room_type($booking_record['room_id']),
                'board_id' => $booking_record['board_id'],
                'board_name' => fn_novoton_format_board_name($booking_record['board_id']),
                'adults' => intval($booking_record['adults']),
                'children' => intval($booking_record['children']),
                'childrenAges' => $children_ages_arr,
                'price' => floatval($booking_record['total_price'])
            ]
        ];
    }
    
    // Parse children ages
    $children_ages_array = [];
    if (!empty($booking['children_ages'])) {
        $children_ages_array = array_map('intval', array_filter(explode(',', $booking['children_ages']), function($v) { return $v !== ''; }));
    }
    $booking['children_ages_array'] = $children_ages_array;
    
    // Get package name
    $package_name = $booking_record['package_name'];
    if (empty($package_name) && !empty($hotel_info['packages_data'])) {
        $packages = json_decode($hotel_info['packages_data'], true);
        if (!empty($packages[0]['PackageName'])) {
            $package_name = $packages[0]['PackageName'];
        }
    }
    
    // Get hotel stars
    $hotel_stars = '';
    if (!empty($hotel_info['star_rating'])) {
        $hotel_stars = str_repeat('★', intval($hotel_info['star_rating']));
    }
    
    // Get all packages for display
    $all_packages = [];
    if (!empty($hotel_info['packages_data'])) {
        $all_packages = json_decode($hotel_info['packages_data'], true) ?: [];
    }
    
    // Assign to view
    Tygh::$app['view']->assign('booking_data', $booking);
    Tygh::$app['view']->assign('booking_id', $booking_id);
    Tygh::$app['view']->assign('cart_id', $cart_id);
    Tygh::$app['view']->assign('is_edit_mode', true);
    Tygh::$app['view']->assign('product_id', $booking_record['product_id']);
    Tygh::$app['view']->assign('hotel_name', $hotel_name);
    Tygh::$app['view']->assign('hotel_city', $hotel_info['city'] ?? $booking_record['hotel_city'] ?? '');
    Tygh::$app['view']->assign('hotel_region', $hotel_info['region'] ?? '');
    Tygh::$app['view']->assign('hotel_country', $hotel_info['country'] ?? $booking_record['hotel_country'] ?? 'BULGARIA');
    Tygh::$app['view']->assign('hotel_stars', $hotel_stars);
    Tygh::$app['view']->assign('package_name', $package_name);
    Tygh::$app['view']->assign('hotel_all_packages', $all_packages);
    Tygh::$app['view']->assign('auth', Tygh::$app['session']['auth'] ?? []);
    
    // Page setup
    $page_title = __('novoton_holidays.edit_booking');
    Tygh::$app['view']->assign('page_title', $page_title);
    Registry::set('navigation.dynamic.page_title', $page_title);
    fn_add_breadcrumb($page_title);
    
    // Use booking_form template for edit mode
    // Template will be rendered automatically by CS-Cart
}

// Update booking - process edited guest details
if ($mode == 'update_booking') {
    
    $bookingData = $_REQUEST;
    $booking_id = intval($bookingData['booking_id'] ?? 0);
    $cart_id = $bookingData['cart_id'] ?? '';
    
    if (empty($booking_id)) {
        fn_set_notification('E', __('error'), __('novoton_holidays.invalid_booking_data'));
        return [CONTROLLER_STATUS_REDIRECT, 'checkout.cart'];
    }
    
    // Process guest information using helper function
    $guests = $bookingData['guests'] ?? [];
    $contact = $bookingData['contact'] ?? [];
    $special_requests = $bookingData['special_requests'] ?? '';
    
    // Get check-in date for age validation
    $existing_for_checkin = _nvt_booking_repo()->findById($booking_id);
    $check_in_for_validation = $existing_for_checkin['check_in'] ?? '';
    
    // Parse and validate guests (returns false if validation fails)
    $parsed_guests = _nvt_parse_and_validate_guests($guests, $check_in_for_validation, $booking_id, $cart_id);
    if ($parsed_guests === false) {
        return [CONTROLLER_STATUS_REDIRECT, "novoton_booking.edit_booking?booking_id={$booking_id}&cart_id={$cart_id}"];
    }
    
    // Extract parsed data
    $guests_data = $parsed_guests['guests_data'];
    $guest_names = $parsed_guests['guest_names'];
    $guest_list = $parsed_guests['guest_list'];
    $holder_name = $parsed_guests['holder_name'];
    
    // Update booking record
    // First get existing booking data to rebuild api_request - use cached value if same booking
    $existing_booking = ($existing_for_checkin && $existing_for_checkin['booking_id'] == $booking_id) 
        ? $existing_for_checkin 
        : _nvt_booking_repo()->findById($booking_id);
    
    // Build api_request with updated guest names - use api_name for Novoton XML
    $api_guests = [];
    foreach ($guests_data as $key => $guest) {
        $api_guests[] = [
            'name' => $guest['api_name'] ?? $guest['name'],  // Use api_name (First Last) for API
            'birthday' => $guest['birthday'] ?? '',
            'age' => $guest['age'],
            'type' => $guest['type'],
            'room' => $guest['room']
        ];
    }
    
    // Rebuild api_request with updated guests
    $api_request = [
        'hotel_id' => $existing_booking['hotel_id'],
        'package_name' => $existing_booking['package_name'],
        'check_in' => $existing_booking['check_in'],
        'check_out' => $existing_booking['check_out'],
        'room_id' => $existing_booking['room_id'],
        'board_id' => $existing_booking['board_id'],
        'holder' => $holder_name,
        'guests' => $api_guests,
        'order_num' => $existing_booking['order_id'] ?: '',
        'remark' => $special_requests,
        'comment' => $special_requests
    ];
    
    // If multi-room, parse rooms_data and add rooms to api_request
    if (!empty($existing_booking['rooms_data'])) {
        $rooms_data = json_decode($existing_booking['rooms_data'], true);
        if ($rooms_data && count($rooms_data) > 1) {
            $api_rooms = [];
            foreach ($rooms_data as $room_idx => $room) {
                $room_num = $room_idx + 1;
                $room_guests = [];
                foreach ($api_guests as $guest) {
                    if (isset($guest['room']) && $guest['room'] == $room_num) {
                        $room_guests[] = $guest;
                    }
                }
                $api_rooms[] = [
                    'room_id' => $room['room_id'] ?? '',
                    'board_id' => $room['board_id'] ?? '',
                    'guests' => $room_guests
                ];
            }
            $api_request['rooms'] = $api_rooms;
        }
    }
    
    db_query(
        "UPDATE ?:novoton_bookings SET 
         guest_name = ?s, holder_name = ?s, guest_email = ?s, guest_phone = ?s,
         guests_data = ?s, special_requests = ?s, notes = ?s, api_request = ?s
         WHERE booking_id = ?i",
        $guest_list, $holder_name, $contact['email'] ?? '', $contact['phone'] ?? '',
        json_encode($guests_data), $special_requests, $special_requests, json_encode($api_request), $booking_id
    );
    
    // Update cart item if cart_id provided
    if (!empty($cart_id)) {
        $cart = &Tygh::$app['session']['cart'];
        if (isset($cart['products'][$cart_id])) {
            $cart['products'][$cart_id]['extra']['guest_names'] = $guest_list;
            $cart['products'][$cart_id]['extra']['holder_name'] = $holder_name;
            $cart['products'][$cart_id]['extra']['guests_data'] = json_encode($guests_data);  // Store as JSON
            $cart['products'][$cart_id]['extra']['contact_email'] = $contact['email'] ?? '';
            $cart['products'][$cart_id]['extra']['contact_phone'] = $contact['phone'] ?? '';
            $cart['products'][$cart_id]['extra']['special_requests'] = $special_requests;
            
            // Recalculate and save cart
            $auth = &Tygh::$app['session']['auth'];
            fn_calculate_cart_content($cart, $auth, 'S', true, 'F', true);
            fn_save_cart_content($cart, $auth['user_id'] ?? 0);
        }
    }
    
    fn_set_notification('N', __('success'), __('novoton_holidays.booking_updated'));
    
    return [CONTROLLER_STATUS_REDIRECT, 'checkout.cart'];
}

/**
 * Request alternatives when no availability
 * Uses hotel_request API to request alternatives from Novoton
 */
if ($mode == 'request_alternatives') {
    $hotel_id = $_REQUEST['hotel_id'] ?? '';
    $hotel_name = $_REQUEST['hotel_name'] ?? '';
    $check_in = $_REQUEST['check_in'] ?? '';
    $check_out = $_REQUEST['check_out'] ?? '';
    $nights = intval($_REQUEST['nights'] ?? 7);
    $adults = intval($_REQUEST['adults'] ?? 2);
    $children = intval($_REQUEST['children'] ?? 0);
    $num_rooms = intval($_REQUEST['num_rooms'] ?? 1);
    $contact_email = trim($_REQUEST['contact_email'] ?? '');
    $contact_phone = trim($_REQUEST['contact_phone'] ?? '');
    $notes = trim($_REQUEST['notes'] ?? '');
    
    if (empty($hotel_id) || empty($check_in) || empty($contact_email)) {
        fn_set_notification('E', __('error'), __('novoton_holidays.missing_required_fields'));
        return [CONTROLLER_STATUS_REDIRECT, fn_url('novoton_booking.search?hotel_id=' . $hotel_id)];
    }
    
    // Validate email
    if (!filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
        fn_set_notification('E', __('error'), __('novoton_holidays.invalid_email'));
        return [CONTROLLER_STATUS_REDIRECT, fn_url('novoton_booking.search?hotel_id=' . $hotel_id)];
    }
    
    // Load API
    $src_dir = Registry::get('config.dir.addons') . 'novoton_holidays/src/';
    if (file_exists($src_dir . 'NovotonApi.php')) {
        require_once($src_dir . 'NovotonApi.php');
    }
    
    try {
        $api = new \Tygh\Addons\NovotonHolidays\NovotonApi();
        
        // Build guest data
        $guests = [];
        $room_guests = [];
        for ($i = 1; $i <= $adults; $i++) {
            $guests[] = [
                'id' => $i,
                'name' => 'Guest ' . $i,
                'birthday' => '',
                'age' => 30
            ];
            $room_guests[] = [
                'id' => $i,
                'name' => 'Guest ' . $i
            ];
        }
        
        // Build request data for hotel_request API
        $requestData = [
            'hotel_id' => $hotel_id,
            'package_name' => $hotel_name,
            'check_in' => $check_in,
            'check_out' => $check_out,
            'room_id' => '', // Any room
            'board_id' => '', // Any board
            'holder' => 'Request from ' . $contact_email,
            'remark' => $notes,
            'comment' => "Contact: {$contact_email}" . ($contact_phone ? ", Phone: {$contact_phone}" : ''),
            'guests' => $guests,
            'room_guests' => $room_guests
        ];
        
        // Send hotel_request to Novoton API - get both XML and response
        $apiResult = $api->createHotelRequest($requestData, 'UK', true);
        
        // Store the request in database with XML sent
        $request_record = [
            'hotel_id' => $hotel_id,
            'hotel_name' => $hotel_name,
            'check_in' => $check_in,
            'check_out' => $check_out,
            'nights' => $nights,
            'adults' => $adults,
            'children' => $children,
            'num_rooms' => $num_rooms,
            'contact_email' => $contact_email,
            'contact_phone' => $contact_phone,
            'notes' => $notes,
            'status' => 'pending',
            'api_request_xml' => $apiResult['xml_sent'] ?? '',
            'api_response' => $apiResult['xml_response'] ?? '',
            'novoton_request_id' => $apiResult['id_num'] ?? '',
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        db_query("INSERT INTO ?:novoton_alternative_requests ?e", $request_record);
        $request_id = db_get_field("SELECT LAST_INSERT_ID()");
        
        // Send confirmation email to customer
        $mail_data = [
            'hotel_name' => $hotel_name,
            'check_in' => $check_in,
            'check_out' => $check_out,
            'nights' => $nights,
            'adults' => $adults,
            'children' => $children,
            'request_id' => $request_id,
            'novoton_id' => $apiResult['id_num'] ?? ''
        ];
        
        // Use CS-Cart mailer
        $mailer = Tygh::$app['mailer'];
        $mailer->send([
            'to' => $contact_email,
            'from' => 'default_company_orders_department',
            'data' => $mail_data,
            'template_code' => 'novoton_alternatives_request_confirmation',
            'tpl' => 'addons/novoton_holidays/email/alternatives_request_confirmation.tpl'
        ], 'A');
        
        fn_set_notification('N', __('notice'), __('novoton_holidays.alternatives_request_sent'));
        
    } catch (Exception $e) {
        fn_log_event('general', 'runtime', [
            'message' => 'Novoton hotel_request error',
            'error' => $e->getMessage()
        ]);
        
        // Still save the request even if API fails
        $request_record = [
            'hotel_id' => $hotel_id,
            'hotel_name' => $hotel_name,
            'check_in' => $check_in,
            'check_out' => $check_out,
            'nights' => $nights,
            'adults' => $adults,
            'children' => $children,
            'num_rooms' => $num_rooms,
            'contact_email' => $contact_email,
            'contact_phone' => $contact_phone,
            'notes' => $notes,
            'status' => 'pending_manual',
            'api_response' => $e->getMessage(),
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        db_query("INSERT INTO ?:novoton_alternative_requests ?e", $request_record);
        
        fn_set_notification('N', __('notice'), __('novoton_holidays.alternatives_request_saved'));
    }
    
    return [CONTROLLER_STATUS_REDIRECT, fn_url('novoton_booking.search?hotel_id=' . $hotel_id . '&check_in=' . $check_in . '&nights=' . $nights)];
}

// AJAX endpoint to recalculate price when child age changes
if ($mode == 'ajax_recalculate_price') {
    // A74h: Immediately output JSON and die - bypass CS-Cart's output system
    
    // Debug file for troubleshooting
    $debug_file = DIR_ROOT . '/var/novoton_price_debug.log';
    $debug_log = function($msg, $data = null) use ($debug_file) {
        $line = date('Y-m-d H:i:s') . ' - ' . $msg;
        if ($data !== null) {
            $line .= ': ' . print_r($data, true);
        }
        file_put_contents($debug_file, $line . "\n", FILE_APPEND);
    };
    
    $debug_log('=== NEW PRICE RECALCULATION REQUEST ===');
    
    // Get JSON input
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    $debug_log('Raw input', $input);
    $debug_log('Decoded data', $data);
    
    // Helper function to send JSON response and exit
    $sendJson = function($response) {
        // Clear ALL output buffers
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        // Set headers
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, must-revalidate');
        // Output and die immediately
        die(json_encode($response));
    };
    
    if (empty($data)) {
        $debug_log('ERROR: Invalid request data');
        $sendJson(['success' => false, 'message' => 'Invalid request data']);
    }
    
    $hotel_id = $data['hotel_id'] ?? '';
    $room_id = $data['room_id'] ?? '';
    $board_id = $data['board_id'] ?? '';
    $check_in = $data['check_in'] ?? '';
    $nights = intval($data['nights'] ?? 7);
    $adults = intval($data['adults'] ?? 2);
    $children_ages = $data['children_ages'] ?? [];
    $package_name = $data['package_name'] ?? '';
    $original_price = floatval($data['original_price'] ?? 0);
    
    // A73s: Ensure children_ages is an array of integers
    if (!is_array($children_ages)) {
        $children_ages = [];
    } else {
        $children_ages = array_map('intval', array_values($children_ages));
    }
    
    $debug_log('Parsed children_ages', $children_ages);
    
    // Validate required fields
    if (empty($hotel_id) || empty($check_in)) {
        $debug_log('ERROR: Missing required fields');
        $sendJson(['success' => false, 'message' => 'Missing required fields']);
    }
    
    // Calculate check-out date
    $check_out = date('Y-m-d', strtotime($check_in . ' + ' . $nights . ' days'));
    
    // Get API instance
    $api = fn_novoton_get_api();
    if (!$api) {
        $debug_log('ERROR: API not available');
        $sendJson(['success' => false, 'message' => 'API not available']);
    }
    
    try {
        // Build room_price API parameters
        // A73v: Send empty room_id/board_id to get all available prices
        // The API often doesn't recognize specific room/board IDs with spaces
        $params = [
            'hotel_id' => $hotel_id,
            'room_id' => '',  // Get all rooms
            'board_id' => '', // Get all boards
            'check_in' => $check_in,
            'check_out' => $check_out,
            'adults' => $adults,
            'children' => $children_ages,  // Array of ages
            'nocache' => true  // Always get fresh price
        ];
        
        $debug_log('API params (getting all rooms/boards)', $params);
        $debug_log('Will match room_id', $room_id);
        $debug_log('Will match board_id', $board_id);
        
        // Call room_price API
        $response = $api->getRoomPrice($params);
        
        // Log raw API request/response
        $debug_log('API Last Request', $api->getLastRequest());
        $rawResponse = $api->getLastResponse();
        $debug_log('API Last Response (first 2000 chars)', substr($rawResponse, 0, 2000));
        
        // A73w: The room_price API returns a FLAT XML structure without proper nesting
        // Multiple results are just repeated fields under <room_price>
        // We need to parse it differently
        
        $new_price = 0;
        $price_found = false;
        
        // Check if we got a valid response
        if (!$response) {
            $debug_log('ERROR: No response from API');
            $sendJson([
                'success' => false, 
                'message' => __('novoton_holidays.price_not_available')
            ]);
        }
        
        // A74: DON'T urldecode the room_id from the form - it already has + in it
        // Only the API response has URL-encoded values like %2b
        $room_id_decoded = $room_id;  // Keep as-is from form
        $debug_log('Room ID for matching (from form)', $room_id_decoded);
        
        // Try to parse the flat XML structure
        // The response has multiple "records" but they're not wrapped in individual elements
        // We need to extract all Price/IdRoom/Board combinations
        
        // Method 1: Try standard structure first (with hotel wrapper)
        if (isset($response->hotel)) {
            $debug_log('Standard structure detected (hotel wrapper)');
            $hotel = $response->hotel;
            $rooms = isset($hotel->rooms->IdRoom) ? [$hotel->rooms] : ($hotel->rooms ?? []);
            
            foreach ($rooms as $room) {
                $roomId = rawurldecode((string)($room->IdRoom ?? ''));
                if (!empty($room_id) && $roomId !== $room_id_decoded && stripos($roomId, $room_id_decoded) === false) {
                    continue;
                }
                
                $boards = isset($room->board->IdBoard) ? [$room->board] : ($room->board ?? []);
                foreach ($boards as $board) {
                    $boardId = (string)($board->IdBoard ?? '');
                    if (!empty($board_id) && $boardId !== $board_id && stripos($boardId, $board_id) === false) {
                        continue;
                    }
                    
                    $price = floatval((string)($board->Price ?? $board->TotalPrice ?? 0));
                    if ($price > 0) {
                        $new_price = $price;
                        $price_found = true;
                        $debug_log('Found price (standard structure)', $price);
                        break 2;
                    }
                }
            }
        }
        
        // Method 2: Parse flat structure (direct fields under room_price)
        if (!$price_found) {
            $debug_log('Trying flat structure parsing');
            
            // Get all Price elements
            $prices = $response->xpath('//Price');
            $idRooms = $response->xpath('//IdRoom');
            $boards = $response->xpath('//Board');
            
            $debug_log('Found elements', [
                'prices' => count($prices),
                'rooms' => count($idRooms),
                'boards' => count($boards)
            ]);
            
            // Match by index - each index represents one result
            $numResults = min(count($prices), count($idRooms), count($boards));
            
            for ($i = 0; $i < $numResults; $i++) {
                $resultPrice = floatval((string)$prices[$i]);
                $resultRoom = rawurldecode((string)$idRooms[$i]);
                $resultBoard = (string)$boards[$i];

                $debug_log("Result $i", [
                    'price' => $resultPrice,
                    'room' => $resultRoom,
                    'board' => $resultBoard
                ]);
                
                // Check if room matches (exact or partial)
                $roomMatches = empty($room_id_decoded) || 
                               $resultRoom === $room_id_decoded || 
                               stripos($resultRoom, $room_id_decoded) !== false ||
                               stripos($room_id_decoded, $resultRoom) !== false;
                
                // Check if board matches (exact or partial)
                $boardMatches = empty($board_id) || 
                                $resultBoard === $board_id || 
                                stripos($resultBoard, $board_id) !== false ||
                                stripos($board_id, $resultBoard) !== false;
                
                if ($roomMatches && $boardMatches && $resultPrice > 0) {
                    $new_price = $resultPrice;
                    $price_found = true;
                    $matched_room = $resultRoom;  // Store matched room for comparison
                    $matched_board = $resultBoard; // Store matched board
                    $debug_log('MATCH FOUND!', [
                        'index' => $i,
                        'room' => $resultRoom,
                        'board' => $resultBoard,
                        'price' => $resultPrice
                    ]);
                    break;
                }
            }
            
            // If no exact match, try getting any price from response
            if (!$price_found && $numResults > 0) {
                // Get the first available price as fallback
                $new_price = floatval((string)$prices[0]);
                $matched_room = rawurldecode((string)$idRooms[0]); // Store first room
                $matched_board = (string)$boards[0]; // Store first board
                if ($new_price > 0) {
                    $price_found = true;
                    $debug_log('Using first available price as fallback', [
                        'price' => $new_price,
                        'room' => $matched_room,
                        'board' => $matched_board
                    ]);
                }
            }
        }
        
        // Method 3: Direct Price element at root
        if (!$price_found && isset($response->Price)) {
            $new_price = floatval((string)$response->Price);
            if ($new_price > 0) {
                $price_found = true;
                $matched_room = rawurldecode((string)($response->IdRoom ?? ''));
                $matched_board = (string)($response->Board ?? '');
                $debug_log('Found direct Price element', $new_price);
            }
        }
        
        if (!$price_found) {
            $debug_log('ERROR: Price not found for combination');
            $sendJson([
                'success' => false, 
                'message' => __('novoton_holidays.price_not_found_for_combination')
            ]);
        }
        
        // Check if room changed
        $room_changed = false;
        $original_room = $room_id_decoded;
        if (!empty($matched_room) && !empty($original_room)) {
            // Compare rooms (case-insensitive, trim whitespace)
            $room_changed = (strcasecmp(trim($matched_room), trim($original_room)) !== 0);
        }
        
        $debug_log('Room change check', [
            'original_room' => $original_room,
            'matched_room' => $matched_room ?? 'N/A',
            'room_changed' => $room_changed ? 'YES' : 'NO'
        ]);
        
        // Calculate price difference
        $price_difference = $new_price - $original_price;
        
        // Format price for display
        $currency = Registry::get('currencies.' . CART_PRIMARY_CURRENCY);
        $formatted_price = fn_format_price($new_price, $currency);
        
        $debug_log('SUCCESS', [
            'new_price' => $new_price,
            'original_price' => $original_price,
            'difference' => $price_difference,
            'children_ages' => $children_ages,
            'room_changed' => $room_changed,
            'new_room' => $matched_room ?? ''
        ]);
        
        // Return success response with room change info
        $sendJson([
            'success' => true,
            'new_price' => $new_price,
            'original_price' => $original_price,
            'formatted_price' => $formatted_price,
            'price_difference' => $price_difference,
            'new_adults' => $adults,
            'new_children' => count($children_ages),
            'children_ages' => $children_ages,
            'room_changed' => $room_changed,
            'original_room' => $original_room,
            'new_room' => $matched_room ?? $original_room,
            'new_board' => $matched_board ?? $board_id
        ]);
        
    } catch (Exception $e) {
        $debug_log('EXCEPTION', $e->getMessage());
        $sendJson([
            'success' => false, 
            'message' => __('novoton_holidays.price_calculation_error')
        ]);
    }
}

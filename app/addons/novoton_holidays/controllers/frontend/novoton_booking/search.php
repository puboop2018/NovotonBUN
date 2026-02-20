<?php
/**
 * Novoton Booking Controller — Search Mode
 * Extracted from novoton_booking.php for maintainability.
 * Included by the main controller when $mode == "search".
 */
if (!defined('BOOTSTRAP')) { die('Access denied'); }

use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\Services\SearchService;

    // Validate and sanitize search input via SecurityService
    $security = _nvt_get_security_service();
    $searchParams = $security->validateSearchParams($_REQUEST);

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
            'children_count' => 0,
            'children_ages' => '',
            'children_ages_str' => '',
            'children_ages_array' => [],
            'num_rooms' => 1,
            'rooms_data' => [],
            'rooms_data_json' => '[]',
            'flex_days' => 0,
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
    
    // Debug mode - gated by server-side config, not URL params
    $debug_mode = defined('NOVOTON_DEBUG') || ConfigProvider::isDebugLogging();
    $debug_log = [];
    
    // If hotel_id is provided (product page), search for specific hotel
    if (!empty($searchParams['hotel_id'])) {
        $hotelId = $searchParams['hotel_id'];
        $productId = !empty($searchParams['product_id']) ? intval($searchParams['product_id']) : 0;
        
        // If no product_id provided, look it up from hotel_id
        if (empty($productId)) {
            $prefix = ConfigProvider::getFirstProductCodePrefix();
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

            // Check API circuit breaker status
            $api = fn_novoton_get_api();
            if (method_exists($api, 'getCircuitStatus')) {
                $circuitStatus = $api->getCircuitStatus();
                $debug_log[] = "=== API CIRCUIT BREAKER STATUS ===";
                $debug_log[] = "Circuit Open: " . ($circuitStatus['is_open'] ? 'YES (BLOCKING REQUESTS!)' : 'NO');
                $debug_log[] = "Failure Count: " . $circuitStatus['failure_count'] . "/" . $circuitStatus['threshold'];
                if ($circuitStatus['last_failure']) {
                    $debug_log[] = "Last Failure: " . $circuitStatus['last_failure'];
                }
                if ($circuitStatus['is_open']) {
                    $debug_log[] = "Seconds Until Retry: " . $circuitStatus['seconds_until_retry'];
                    $debug_log[] = "WARNING: API requests are being blocked! Wait for timeout or restart PHP-FPM.";
                }
                $debug_log[] = "";
            }
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
                $boardMapping = Constants::BOARD_MAPPING;

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
            
            // ========================================
            // FETCH ROOM TYPES FROM HOTELINFO API
            // ========================================
            // hotelinfo returns <rooms> with <IdRoom> and <Type> for each room
            // We build a map: IdRoom => Type (e.g., "1-BR APP 2+2" => "APP")
            // Used to display proper room names: "Apartament (1-BR APP 2+2)" instead of raw codes
            $roomTypeMap = [];
            try {
                $hotelInfoData = fn_novoton_get_api()->getHotelInfo($hotelId);
                if ($hotelInfoData && isset($hotelInfoData->rooms)) {
                    foreach ($hotelInfoData->rooms as $roomNode) {
                        $riId = trim((string)($roomNode->IdRoom ?? ''));
                        $riType = trim((string)($roomNode->Type ?? ''));
                        if (!empty($riId) && !empty($riType)) {
                            $roomTypeMap[$riId] = $riType;
                        }
                    }
                }
                if ($debug_mode) {
                    $debug_log[] = "=== ROOM TYPE MAP (hotelinfo API) ===";
                    foreach ($roomTypeMap as $rtId => $rtType) {
                        $debug_log[] = "  {$rtId}: {$rtType}";
                    }
                }
            } catch (Exception $e) {
                if ($debug_mode) {
                    $debug_log[] = "=== HOTELINFO FETCH ERROR: " . $e->getMessage() . " ===";
                }
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
                    $api = fn_novoton_get_api();
                    $priceData = $api->getRoomPrice($priceParams);

                    $room_results = [];
                    $searchSvc = new SearchService();
                    $occupancyStr = "{$room_adults} adults" . ($room_children_count > 0 ? ", {$room_children_count} children" : '');

                    if ($priceData) {
                        $rawXml = $api->getLastResponse();

                        if ($debug_mode) {
                            $debug_log[] = "  API Response received (parsing...)";
                        }

                        $room_results = $searchSvc->parseRoomPriceResponse(
                            $rawXml, $nights, $checkIn, $checkOut,
                            $mealPlan, [], $roomTypeMap, $room_num, $occupancyStr
                        );
                    } else {
                        // API returned empty or error
                        if ($debug_mode) {
                            $debug_log[] = "  API Response: EMPTY or FALSE";
                            $lastError = $api->getLastError();
                            if ($lastError) {
                                $debug_log[] = "  API Error: " . $lastError;
                            }
                            if (method_exists($api, 'getCircuitStatus')) {
                                $circuitStatus = $api->getCircuitStatus();
                                if ($circuitStatus['is_open']) {
                                    $debug_log[] = "  CIRCUIT BREAKER IS OPEN!";
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
                // Convert prices from EUR (API currency) to display currency
                foreach ($all_room_results as $rn => $room_results) {
                    $all_room_results[$rn] = RoomPriceService::convertResultsCurrency($room_results);
                }
                // Pass all_room_results to template
                Tygh::$app['view']->assign('all_room_results', $all_room_results);
                Tygh::$app['view']->assign('is_multi_room_search', true);
                
                // V3: Fetch early booking discounts via SearchService
                $early_booking_discounts = SearchService::getEarlyBookingDiscounts($hotelId, $checkIn, $checkOut);
                $discount_range = SearchService::getDiscountRange($early_booking_discounts);
                
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
                $api = fn_novoton_get_api();
                $lastReq = $api->getLastRequestFormatted();
                $debug_log[] = "  -> API Request Params: hotel_id={$hotelId}, check_in={$lastReq['check_in']}, check_out={$lastReq['check_out']}, adults=" . ($priceParams['adults'] ?? 2);
                $debug_log[] = "  -> Children ages: " . json_encode($priceParams['children'] ?? []);
                // Show full XML request
                $fullRequest = $api->getLastRequest();
                if ($fullRequest) {
                    // Mask password in debug output
                    $maskedRequest = preg_replace('/<psw>[^<]*<\/psw>/', '<psw>***</psw>', $fullRequest);
                    $debug_log[] = "  -> Full XML Request: " . substr(htmlspecialchars($maskedRequest), 0, 1500);
                }
                $rawResponse = $api->getLastResponse();
                if ($rawResponse) {
                    $debug_log[] = "  -> Raw Response (first 2000 chars): " . substr(htmlspecialchars($rawResponse), 0, 2000);
                } else {
                    $debug_log[] = "  -> Raw Response: EMPTY or FALSE";
                    // Show detailed error info
                    $lastError = $api->getLastError();
                    if ($lastError) {
                        $debug_log[] = "  -> API Error: " . $lastError;
                    }
                    // Check circuit breaker status again after the call
                    if (method_exists($api, 'getCircuitStatus')) {
                        $circuitStatus = $api->getCircuitStatus();
                        if ($circuitStatus['is_open']) {
                            $debug_log[] = "  -> CIRCUIT BREAKER IS OPEN! Requests are blocked.";
                        }
                    }
                }
            }
            
            // Parse the response via SearchService
            if ($priceData) {
                $rawXml = fn_novoton_get_api()->getLastResponse();

                if ($debug_mode) {
                    $debug_log[] = "";
                    $debug_log[] = "=== PARSING ROOM_PRICE RESPONSE ===";
                }

                // Fetch room quota for all rooms at once
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

                // Parse results via SearchService (handles multi/single, filtering, commission, quota)
                $searchSvc = new SearchService();
                $results = $searchSvc->parseRoomPriceResponse(
                    $rawXml, $nights, $checkIn, $checkOut,
                    $mealPlan, $quotaMap, $roomTypeMap
                );

                if ($debug_mode) {
                    foreach ($results as $r) {
                        $status = $r['is_on_request'] ? 'ON REQUEST' : ($r['rooms_available'] !== null ? "{$r['rooms_available']} rooms" : 'available');
                        $debug_log[] = "  -> ADDED: Room={$r['room_id']}, Board={$r['board_id']}, Price={$r['total_price']}€, {$status}";
                    }
                }
            } else {
                if ($debug_mode) {
                    $debug_log[] = "  -> No response from room_price API";
                }
            }

            // Deduplicate results via SearchService
            $results = SearchService::deduplicateResults($results);
            
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
                                'star_rating' => '',
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
    
    // Convert prices from EUR (API currency) to CS-Cart display currency
    $results = RoomPriceService::convertResultsCurrency($results ?: []);
    $alternative_results = RoomPriceService::convertResultsCurrency($alternative_results ?: []);
    $novoton_display_currency = RoomPriceService::getDisplayCurrency();

    // Assign to view - ensure no null values
    Tygh::$app['view']->assign('novoton_results', $results);
    Tygh::$app['view']->assign('novoton_params', $novoton_params ?: []);
    Tygh::$app['view']->assign('novoton_display_currency', $novoton_display_currency);

    // Alternative dates results - ensure no null values
    Tygh::$app['view']->assign('alternative_results', $alternative_results);
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
        // V3: Use HotelRepository for display columns
        $hotelRepo = new \Tygh\Addons\NovotonHolidays\Repository\HotelRepository();
        $hotel_info = $hotelRepo->findBasicById($hotelId);
        if ($hotel_info) {
            $hotel_name_display = $hotel_info['hotel_name'] ?? '';
            $hotel_city_display = $hotel_info['city'] ?? '';
            $hotel_region_display = $hotel_info['region'] ?? '';
            $hotel_country_display = $hotel_info['country'] ?? '';

            // V3: Get packages via HotelPackageRepository
            $packageRepo = new \Tygh\Addons\NovotonHolidays\Repository\HotelPackageRepository();
            $packages = $packageRepo->findByHotelId($hotelId);

            if (!empty($packages)) {
                // Use first non-bracketed package or just first
                $package_name = '';
                foreach ($packages as $pkg) {
                    $pname = $pkg['package_name'] ?? '';
                    if (!empty($pname) && substr($pname, -1) != ']') {
                        $package_name = $pname;
                        break;
                    }
                }
                if (empty($package_name) && !empty($packages[0])) {
                    $package_name = $packages[0]['package_name'] ?? '';
                }
                Tygh::$app['view']->assign('hotel_package_name', $package_name ?? '');

                // Also assign all packages for multi-room display
                Tygh::$app['view']->assign('hotel_all_packages', $packages);
            } else {
                Tygh::$app['view']->assign('hotel_package_name', '');
                Tygh::$app['view']->assign('hotel_all_packages', []);
            }

            // V3: Check for active early booking from priceinfo_data JSON
            $current_date = date('Y-m-d');
            $active_eb = null;

            // Get first package with early booking
            foreach ($packages as $pkg) {
                if ($pkg['has_early_booking'] === 'Y' && !empty($pkg['priceinfo_data'])) {
                    $priceinfo = json_decode($pkg['priceinfo_data'], true);
                    if (!empty($priceinfo['early_booking'])) {
                        $eb_data = $priceinfo['early_booking'];
                        // Normalize single entry to array
                        if (isset($eb_data['Reduction'])) {
                            $eb_data = [$eb_data];
                        }
                        // Find active early booking
                        foreach ($eb_data as $eb) {
                            $book_from = $eb['BookFrom'] ?? '';
                            $book_to = $eb['BookTo'] ?? '';
                            if ($book_from <= $current_date && $book_to >= $current_date) {
                                $active_eb = [
                                    'reduction' => $eb['Reduction'] ?? 0,
                                    'booking_from' => $book_from,
                                    'booking_to' => $book_to,
                                    'stay_from' => $eb['StayFrom'] ?? '',
                                    'stay_to' => $eb['StayTo'] ?? '',
                                    'payment_date' => $eb['PaymentDate'] ?? '',
                                    'payment_percent' => $eb['PaymentPercent'] ?? 0,
                                    'room_types' => $eb['RoomTypes'] ?? 'all',
                                    'min_stay' => $eb['MinStay'] ?? 0
                                ];
                                break 2; // Found one, exit both loops
                            }
                        }
                    }
                }
            }

            if ($active_eb) {
                Tygh::$app['view']->assign('active_early_booking', $active_eb);
            }

            // Extract hotel season period (first season FromDate to last season ToDate)
            $season_from = '';
            $season_to = '';
            foreach ($packages as $pkg) {
                if (!empty($pkg['priceinfo_data'])) {
                    $pi = json_decode($pkg['priceinfo_data'], true);
                    if (!empty($pi['seasons']['season'])) {
                        $seasons = $pi['seasons']['season'];
                        // Normalize single season to array
                        if (isset($seasons['IdSeason']) || isset($seasons['DateFrom'])) {
                            $seasons = [$seasons];
                        }
                        if (!empty($seasons)) {
                            $first_season = reset($seasons);
                            $last_season = end($seasons);
                            $season_from = $first_season['DateFrom'] ?? $first_season['FromDate'] ?? '';
                            $season_to = $last_season['DateTo'] ?? $last_season['ToDate'] ?? '';
                        }
                    }
                    break; // Use first package with priceinfo data
                }
            }
            Tygh::$app['view']->assign('hotel_season_from', $season_from);
            Tygh::$app['view']->assign('hotel_season_to', $season_to);
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
    Tygh::$app['view']->assign('hotel_city', $hotel_city_display ?: '');
    Tygh::$app['view']->assign('hotel_region', $hotel_region_display ?: '');
    Tygh::$app['view']->assign('hotel_country', $hotel_country_display ?: '');
    
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
    
    // V3: Get early booking details via HotelPackageRepository for tooltip
    if (!empty($hotelId)) {
        $packageRepo = new \Tygh\Addons\NovotonHolidays\Repository\HotelPackageRepository();
        $eb_package = $packageRepo->findEarlyBookingPackage($hotelId);

        if (!empty($eb_package['priceinfo_data'])) {
            $priceinfo = json_decode($eb_package['priceinfo_data'], true);
            if (!empty($priceinfo['early_booking'])) {
                $eb_data = $priceinfo['early_booking'];
                if (isset($eb_data['Reduction'])) {
                    $eb_data = [$eb_data];
                }

                $eb_details = [];
                foreach ($eb_data as $eb) {
                    $reduction = $eb['Reduction'] ?? 0;
                    $bookTo = $eb['BookTo'] ?? '';
                    $stayFrom = $eb['StayFrom'] ?? '';
                    $stayTo = $eb['StayTo'] ?? '';

                    $paymentDate = !empty($bookTo) ? date('d.m.Y', strtotime($bookTo . ' +5 days')) : 'N/A';
                    $eb_details[] = "-{$reduction}% Early Booking discount till {$bookTo} -- PAYMENT till {$paymentDate} -- STAY in {$stayFrom} - {$stayTo}";
                }
                $early_booking_details = implode("\n", $eb_details);
            }
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

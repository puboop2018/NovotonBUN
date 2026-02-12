<?php
/***************************************************************************
 *                                                                          *
 *   (c) 2024 VacanteLitoral.ro                                            *
 *                                                                          *
 *   Location: app/addons/novoton_holidays/hooks.php                       *
 *                                                                          *
 ****************************************************************************/

use Tygh\Registry;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

/**
 * Add Hotel Prices tab to BACKEND (admin product edit page)
 * 
 * @param array $tabs Array of product tabs
 * @param int $product_id Product ID
 */
function fn_novoton_holidays_get_product_tabs_post(&$tabs, $product_id)
{
    // Backend tab is handled separately if needed
    // Frontend tab is auto-discovered from /blocks/product_tabs/ folder
}

/**
 * Hook: gather additional product data - pass prices to templates
 */
function fn_novoton_holidays_gather_additional_product_data_post(&$product, $auth, $params)
{
    if (empty($product['product_id'])) {
        return;
    }
    
    // Get addon settings
    $addon_settings = Registry::get('addons.novoton_holidays') ?? [];
    if (empty($addon_settings) || empty($addon_settings['product_code_prefixes'])) {
        return;
    }
    
    // Check if this is a hotel product
    $prefixes = explode(',', $addon_settings['product_code_prefixes']);
    
    $is_hotel_product = false;
    if (!empty($product['product_code'])) {
        foreach ($prefixes as $prefix) {
            $prefix = trim($prefix);
            if (!empty($prefix) && strpos($product['product_code'], $prefix) === 0) {
                $is_hotel_product = true;
                break;
            }
        }
    }
    
    // Add hotel prices data if this is a hotel product
    if ($is_hotel_product) {
        // Use cached prices function
        $prices = fn_novoton_get_hotel_prices($product['product_id']);
        
        $product['novoton_prices'] = $prices;
        $product['is_hotel_product'] = true;
        
        // V3: Get last update time from packages table
        $last_update_db = null;
        preg_match('/\d+/', $product['product_code'], $matches);
        if (!empty($matches[0])) {
            $last_update_db = db_get_field(
                "SELECT MAX(synced_at) FROM ?:novoton_hotel_packages WHERE hotel_id = ?s",
                $matches[0]
            );
        }
        $product['novoton_last_update'] = $last_update_db;
        
        // Get hotel info using cached function
        $hotel_id = null;
        preg_match('/\d+/', $product['product_code'], $matches);
        if (!empty($matches[0])) {
            $hotel_id = $matches[0];
            
            // Use cached hotel data function
            $hotel_info = fn_novoton_get_hotel_data($hotel_id);
            
            if ($hotel_info) {
                $product['novoton_hotel_info'] = $hotel_info;
                
                // Data is already parsed by fn_novoton_get_hotel_data()
                if (!empty($hotel_info['rooms'])) {
                    $product['novoton_rooms'] = $hotel_info['rooms'];
                    \Tygh\Tygh::$app['view']->assign('rooms_data', $hotel_info['rooms']);
                }
                
                // Use pre-parsed data from cache
                if (!empty($hotel_info['packages'])) {
                    $product['novoton_packages'] = $hotel_info['packages'];
                    \Tygh\Tygh::$app['view']->assign('packages_data', $hotel_info['packages']);
                }
                
                if (!empty($hotel_info['board'])) {
                    $product['novoton_board'] = $hotel_info['board'];
                    \Tygh\Tygh::$app['view']->assign('board_data', $hotel_info['board']);
                }
                
                // V3: Get active package name from packages data
                $active_package = '';
                $packages_data = $hotel_info['packages'] ?? [];
                if (!empty($packages_data)) {
                    // Use first non-bracketed package or just first
                    foreach ($packages_data as $pkg) {
                        $pkg_name = is_array($pkg) ? ($pkg['PackageName'] ?? '') : '';
                        if (!empty($pkg_name) && substr($pkg_name, -1) != ']') {
                            $active_package = $pkg_name;
                            break;
                        }
                    }
                    if (empty($active_package) && !empty($packages_data[0])) {
                        $active_package = is_array($packages_data[0]) ? ($packages_data[0]['PackageName'] ?? '') : '';
                    }
                }
                $product['novoton_active_package'] = $active_package;
                \Tygh\Tygh::$app['view']->assign('active_package', $active_package);
                
                // Use pre-parsed full data from cache
                if (!empty($hotel_info['full_data'])) {
                    $product['novoton_hotel_full'] = $hotel_info['full_data'];
                    \Tygh\Tygh::$app['view']->assign('hotel_full_data', $hotel_info['full_data']);
                }
            }
            
            // V3 Architecture: Get season dates and early_booking from packages table
            // Extract from the first package with priceinfo_data (or active package)
            $season_dates = [];
            $early_booking = [];

            // Get first package with priceinfo for this hotel
            $package_data = db_get_field(
                "SELECT priceinfo_data FROM ?:novoton_hotel_packages
                 WHERE hotel_id = ?s AND priceinfo_data IS NOT NULL
                 ORDER BY synced_at DESC LIMIT 1",
                $hotel_id
            );

            if (!empty($package_data)) {
                $priceinfo = json_decode($package_data, true);
                if ($priceinfo) {
                    // Extract seasons
                    if (isset($priceinfo['seasons']['season'])) {
                        $seasons = $priceinfo['seasons']['season'];
                        // Normalize single season to array
                        if (isset($seasons['IdSeason'])) {
                            $seasons = [$seasons];
                        }
                        foreach ($seasons as $idx => $season) {
                            $seasonNum = isset($season['IdSeason']) ? (int)$season['IdSeason'] : ($idx + 1);
                            $season_dates[$seasonNum] = [
                                'season_number' => $seasonNum,
                                'date_from' => $season['DateFrom'] ?? '',
                                'date_to' => $season['DateTo'] ?? '',
                                'season_name' => $season['SeasonName'] ?? "Season {$seasonNum}"
                            ];
                        }
                    }

                    // Extract early booking discounts
                    if (isset($priceinfo['early_booking'])) {
                        $eb_data = $priceinfo['early_booking'];
                        // Normalize single entry to array
                        if (isset($eb_data['Reduction'])) {
                            $eb_data = [$eb_data];
                        }
                        foreach ($eb_data as $eb) {
                            $early_booking[] = [
                                'booking_from' => $eb['BookFrom'] ?? '',
                                'booking_to' => $eb['BookTo'] ?? '',
                                'stay_from' => $eb['StayFrom'] ?? '',
                                'stay_to' => $eb['StayTo'] ?? '',
                                'reduction' => $eb['Reduction'] ?? 0,
                                'payment_date' => $eb['PaymentDate'] ?? '',
                                'payment_percent' => $eb['PaymentPercent'] ?? 0,
                                'room_types' => $eb['RoomTypes'] ?? 'all',
                                'min_stay' => $eb['MinStay'] ?? 0
                            ];
                        }
                    }
                }
            }

            $product['novoton_season_dates'] = $season_dates;
            \Tygh\Tygh::$app['view']->assign('season_dates', $season_dates);

            $product['novoton_early_booking'] = $early_booking;
            \Tygh\Tygh::$app['view']->assign('early_booking', $early_booking);
        }
        
        // Build per-room child age bands availability map from price data
        // This tells the template which child occupancy rows to display per room.
        // Age bands are extracted dynamically from actual price entries — not hardcoded —
        // because different hotels define different age ranges (e.g., 0-2.99/3-13.99/14-17.99).
        $room_age_bands = [];
        if (!empty($prices)) {
            foreach ($prices as $p) {
                $rid = $p['room_id'] ?? '';
                $age_type = strtoupper(trim($p['age_type'] ?? ''));
                if (empty($rid) || empty($age_type)) continue;

                if (!isset($room_age_bands[$rid])) {
                    $room_age_bands[$rid] = [
                        'has_adult_eb' => false,
                        'child_bands' => []  // [{from => '0', to => '1.99', label => '0-1.99', key => 'chd_0_1.99'}, ...]
                    ];
                }

                // Detect child age bands from price entries (dynamic extraction)
                if (strpos($age_type, 'CHD') !== false || strpos($age_type, 'CHILD') !== false) {
                    // Extract the age range pattern (e.g., "2-11,99", "0-1.99", "14-17,99")
                    if (preg_match('/(\d+(?:[.,]\d+)?)\s*-\s*(\d+(?:[.,]\d+)?)/', $age_type, $m)) {
                        $from_raw = str_replace(',', '.', $m[1]);
                        $to_raw = str_replace(',', '.', $m[2]);
                        // Normalize label with dot decimal for display
                        $band_label = $from_raw . '-' . $to_raw;
                        $band_key = 'chd_' . $band_label;

                        // Add if not already present
                        $already = false;
                        foreach ($room_age_bands[$rid]['child_bands'] as $existing) {
                            if ($existing['key'] === $band_key) {
                                $already = true;
                                break;
                            }
                        }
                        if (!$already) {
                            $room_age_bands[$rid]['child_bands'][] = [
                                'from' => $from_raw,
                                'to' => $to_raw,
                                'label' => $band_label,
                                'key' => $band_key
                            ];
                        }
                    }
                }

                // Detect 3RD+ ADULT on EXTRA BED (for rooms where older children become adults)
                $acc_type = strtoupper(trim($p['acc_type'] ?? ''));
                if (preg_match('/\d+\s*(ST|ND|RD|TH)\s*ADULT/i', $age_type) &&
                    in_array($acc_type, ['EXTRA BED', 'EB', 'EXTRABED'])) {
                    $room_age_bands[$rid]['has_adult_eb'] = true;
                }
            }

            // Sort each room's child bands by from_year ascending
            foreach ($room_age_bands as &$rb) {
                usort($rb['child_bands'], function ($a, $b) {
                    return floatval($a['from']) <=> floatval($b['from']);
                });
            }
            unset($rb);
        }
        \Tygh\Tygh::$app['view']->assign('room_age_bands', $room_age_bands);

        // Assign to Smarty for the tab template
        \Tygh\Tygh::$app['view']->assign('prices', $prices);
        \Tygh\Tygh::$app['view']->assign('last_update', $product['novoton_last_update']);
        \Tygh\Tygh::$app['view']->assign('product_id', $product['product_id']);
        \Tygh\Tygh::$app['view']->assign('hotel_id', $hotel_id);
        \Tygh\Tygh::$app['view']->assign('is_hotel_product', true);
        \Tygh\Tygh::$app['view']->assign('addon_settings', $addon_settings);
        
        // Check if booking form should be displayed - default to before_tabs
        $show_booking_form = !isset($addon_settings['show_booking_form']) || $addon_settings['show_booking_form'] == 'Y';
        $booking_form_position = $addon_settings['booking_form_position'] ?? 'before_tabs';
        
        \Tygh\Tygh::$app['view']->assign('show_novoton_booking_form', $show_booking_form);
        \Tygh\Tygh::$app['view']->assign('novoton_booking_form_position', $booking_form_position);
    } else {
        // Not a hotel product - ensure variables are set to false
        \Tygh\Tygh::$app['view']->assign('is_hotel_product', false);
        \Tygh\Tygh::$app['view']->assign('show_novoton_booking_form', false);
    }
}

/**
 * Hook: Add booking form to product page (after main content)
 */
function fn_novoton_holidays_products_view_after(&$view)
{
    // This hook doesn't exist by default in CS-Cart
    // We'll use template hooks instead
}

/**
 * Hook: after getting product data
 * 
 * @param array $product_data Product data
 * @param array $auth Auth data
 * @param array $params Request parameters
 * @param int $product_id Product ID
 */
function fn_novoton_holidays_get_product_data_post(&$product_data, $auth, $params, $product_id)
{
    if (empty($product_id) || empty($product_data)) {
        return;
    }
    
    // Get addon settings
    $addon_settings = Registry::get('addons.novoton_holidays') ?? [];
    if (empty($addon_settings) || empty($addon_settings['product_code_prefixes'])) {
        return;
    }
    
    // Check if this is a hotel product
    $prefixes = explode(',', $addon_settings['product_code_prefixes']);
    
    $is_hotel_product = false;
    if (!empty($product_data['product_code'])) {
        foreach ($prefixes as $prefix) {
            $prefix = trim($prefix);
            if (!empty($prefix) && strpos($product_data['product_code'], $prefix) === 0) {
                $is_hotel_product = true;
                break;
            }
        }
    }
    
    // Add hotel prices data if this is a hotel product
    if ($is_hotel_product) {
        // Get hotel ID from product code
        preg_match('/\d+/', $product_data['product_code'], $matches);
        if (!empty($matches[0])) {
            $product_data['hotel_id'] = $matches[0];

            // V3: Get packages with priceinfo from packages table
            $product_data['hotel_packages'] = fn_novoton_get_hotel_prices($product_id);
        }

        $product_data['is_hotel_product'] = true;
    }
}

/**
 * Hook: before updating product
 * 
 * @param array $product_data Product data to update
 * @param int $product_id Product ID
 * @param string $lang_code Language code
 * @param bool $can_update Whether update is allowed
 */
function fn_novoton_holidays_update_product_pre(&$product_data, $product_id, $lang_code, $can_update)
{
    // Add any pre-update logic here if needed
}

/**
 * Hook: after deleting product
 * 
 * @param int $product_id Product ID
 * @param bool $product_deleted Whether product was deleted
 */
function fn_novoton_holidays_delete_product_post($product_id, $product_deleted)
{
    if ($product_deleted) {
        // Clean up booking data when product is deleted
        db_query("DELETE FROM ?:novoton_bookings WHERE product_id = ?i", $product_id);
    }
}

/**
 * Hook: after getting orders
 * 
 * @param array $params Query parameters
 * @param array $orders Array of orders
 */
function fn_novoton_holidays_get_orders_post($params, &$orders)
{
    if (empty($orders)) {
        return;
    }
    
    // A73: Optimized - single query instead of N+1
    $order_ids = array_column($orders, 'order_id');
    if (empty($order_ids)) {
        return;
    }
    
    // Get all bookings for all orders in one query
    $all_bookings = db_get_array(
        "SELECT booking_id, order_id, hotel_id, hotel_name, room_type, board_id,
                check_in, check_out, nights, adults, children, total_price, 
                currency, status, novoton_status, novoton_confirm_id
         FROM ?:novoton_bookings 
         WHERE order_id IN (?n)",
        $order_ids
    );
    
    if (empty($all_bookings)) {
        return;
    }
    
    // Group bookings by order_id
    $bookings_by_order = [];
    foreach ($all_bookings as $booking) {
        $bookings_by_order[$booking['order_id']][] = $booking;
    }
    
    // Attach bookings to orders
    foreach ($orders as &$order) {
        if (!empty($order['order_id']) && isset($bookings_by_order[$order['order_id']])) {
            $order['hotel_bookings'] = $bookings_by_order[$order['order_id']];
        }
    }
}

/**
 * Hook: Format cart product info for hotel bookings
 * 
 * @param array $product Cart product data
 * @param array $cart Cart data
 * @param array $auth Auth data
 */
function fn_novoton_holidays_get_cart_product_data_post(&$product, $cart, $auth)
{
    // Check if this is a hotel booking
    if (!empty($product['extra']['novoton_booking'])) {
        fn_novoton_add_booking_display_data($product);
    }
}

/**
 * Hook: After cart is calculated - inject booking data from database
 * This ensures booking details are shown even if extra data was lost
 * Supports multiple bookings for the same hotel product
 */
function fn_novoton_holidays_calculate_cart_items(&$cart, &$cart_products, $auth)
{
    if (empty($cart_products)) {
        return;
    }
    
    // Collect all product IDs in cart
    $product_ids = [];
    foreach ($cart_products as $cart_id => $product) {
        $product_ids[] = $product['product_id'];
    }
    
    if (empty($product_ids)) {
        return;
    }
    
    // A73: Optimized query - select only columns needed for cart calculation
    $all_bookings = db_get_array(
        "SELECT booking_id, product_id, hotel_id, hotel_name, room_id, room_type, 
                board_id, check_in, check_out, nights, adults, children, children_ages,
                num_rooms, rooms_data, total_price, currency, status, guests_data,
                package_name, session_id
         FROM ?:novoton_bookings 
         WHERE product_id IN (?n) 
         AND status IN ('pending', 'confirmed')
         ORDER BY booking_id DESC",
        $product_ids
    );
    
    if (empty($all_bookings)) {
        return;
    }
    
    // Group bookings by product_id
    $bookings_by_product = [];
    foreach ($all_bookings as $booking) {
        $pid = $booking['product_id'];
        if (!isset($bookings_by_product[$pid])) {
            $bookings_by_product[$pid] = [];
        }
        $bookings_by_product[$pid][] = $booking;
    }
    
    // Track which bookings have been assigned to cart items
    $used_booking_ids = [];
    
    // Inject booking data into cart products
    foreach ($cart_products as $cart_id => &$product) {
        $product_id = $product['product_id'];
        
        // If extra already has booking data with a booking_id, use it
        if (!empty($product['extra']['novoton_booking']) && !empty($product['extra']['novoton_booking_id'])) {
            fn_novoton_add_booking_display_data($product, $cart);
            $used_booking_ids[] = $product['extra']['novoton_booking_id'];
            continue;
        }
        
        // Check if we have bookings for this product
        if (isset($bookings_by_product[$product_id]) && !empty($bookings_by_product[$product_id])) {
            // Find first unused booking for this product
            foreach ($bookings_by_product[$product_id] as $booking) {
                if (!in_array($booking['booking_id'], $used_booking_ids)) {
                    // Found an unused booking - assign it to this cart item
                    $used_booking_ids[] = $booking['booking_id'];
                    
                    // Inject booking data into extra
                    $product['extra']['novoton_booking'] = true;
                    $product['extra']['novoton_booking_id'] = $booking['booking_id'];
                    $product['extra']['hotel_id'] = $booking['hotel_id'];
                    $product['extra']['room_id'] = $booking['room_id'];
                    $product['extra']['room_name'] = fn_novoton_format_room_type($booking['room_id'], $booking['room_type'] ?? '');
                    $product['extra']['board_id'] = $booking['board_id'];
                    $product['extra']['board_name'] = fn_novoton_get_board_name($booking['board_id']);
                    $product['extra']['check_in'] = $booking['check_in'];
                    $product['extra']['check_out'] = $booking['check_out'];
                    $product['extra']['nights'] = $booking['nights'];
                    $product['extra']['adults'] = $booking['adults'];
                    $product['extra']['children'] = $booking['children'];
                    $product['extra']['children_ages'] = $booking['children_ages'] ?? '';
                    $product['extra']['holder_name'] = $booking['holder_name'] ?? '';
                    $product['extra']['guest_names'] = $booking['guest_name'] ?? '';
                    $product['extra']['guests_data'] = $booking['guests_data'] ?? ''; // Add guests_data from DB
                    $product['extra']['total_price'] = $booking['total_price'];
                    $product['extra']['package_name'] = $booking['package_name'] ?? '';
                    
                    // Add rooms_data and num_rooms from database
                    $product['extra']['num_rooms'] = intval($booking['num_rooms'] ?? 1);
                    if (!empty($booking['rooms_data'])) {
                        $rooms_data = json_decode($booking['rooms_data'], true);
                        if (is_array($rooms_data)) {
                            $product['extra']['rooms_data'] = $rooms_data;
                        }
                    }
                    
                    // Also update the cart session to preserve this data
                    if (isset($cart['products'][$cart_id])) {
                        $cart['products'][$cart_id]['extra'] = $product['extra'];
                    }
                    
                    fn_novoton_add_booking_display_data($product, $cart);
                    break; // Move to next cart item
                }
            }
        }
    }
}

/**
 * Helper: Add booking display data to product
 * Uses CS-Cart date format settings
 */
function fn_novoton_add_booking_display_data(&$product, $cart = null)
{
    // Get CS-Cart date format setting
    $date_format = Registry::get('settings.Appearance.date_format');
    if (empty($date_format)) {
        $date_format = '%d.%m.%Y'; // fallback
    }
    
    // Format dates for display using CS-Cart format
    $check_in_formatted = !empty($product['extra']['check_in']) 
        ? fn_date_format(strtotime($product['extra']['check_in']), $date_format)
        : '';
    $check_out_formatted = !empty($product['extra']['check_out']) 
        ? fn_date_format(strtotime($product['extra']['check_out']), $date_format)
        : '';
    
    // Get rooms data
    $num_rooms = intval($product['extra']['num_rooms'] ?? 1);
    $rooms_data = $product['extra']['rooms_data'] ?? [];
    if (is_string($rooms_data)) {
        $rooms_data = json_decode($rooms_data, true) ?: [];
    }
    
    // Build guests string with children ages
    $adults = intval($product['extra']['adults'] ?? 2);
    $children = intval($product['extra']['children'] ?? 0);
    
    // Build guests string - include rooms count if > 1
    $guests_str = '';
    if ($num_rooms > 1) {
        $guests_str .= $num_rooms . ' rooms, ';
    }
    $guests_str .= $adults . ' adult' . ($adults > 1 ? 's' : '');
    
    if ($children > 0) {
        $guests_str .= ', ' . $children . ' child' . ($children > 1 ? 'ren' : '');
        
        // Add children ages if available
        if (!empty($product['extra']['children_ages'])) {
            $ages_str = $product['extra']['children_ages'];
            if (is_array($ages_str)) {
                $ages_str = implode(', ', $ages_str);
            }
            // Parse ages and format nicely
            $ages_arr = array_map('trim', explode(',', $ages_str));
            $ages_arr = array_filter($ages_arr, function($a) { return $a !== '' && $a !== 'age_needed'; });
            if (!empty($ages_arr)) {
                $ages_formatted = implode(' and ', array_map(function($a) { return $a . ' y/o'; }, $ages_arr));
                $guests_str .= ' (' . $ages_formatted . ')';
            }
        }
    }
    
    // Get board name in readable format and write back to extra for templates
    $board_id = $product['extra']['board_id'] ?? '';
    $board_name = fn_novoton_get_board_name($board_id);
    $product['extra']['board_name'] = $board_name;

    // Format room name and write back to extra for templates
    $room_id = $product['extra']['room_id'] ?? '';
    $room_type = $product['extra']['room_type'] ?? '';
    $product['extra']['room_type_display'] = fn_novoton_format_room_type($room_id, $room_type);

    // Add booking-specific display fields using product_options_value
    $product['product_options_value'] = [];
    
    // Package name first (if available)
    if (!empty($product['extra']['package_name'])) {
        $product['product_options_value'][] = [
            'option_name' => __('novoton_holidays.package'),
            'value' => $product['extra']['package_name']
        ];
    }
    
    // Dates combined
    $product['product_options_value'][] = [
        'option_name' => __('novoton_holidays.dates'),
        'value' => $check_in_formatted . ' → ' . $check_out_formatted . ' (' . ($product['extra']['nights'] ?? 7) . ' ' . __('novoton_holidays.nights') . ')'
    ];
    
    // Room info (with rooms count if multiple)
    $room_name = $product['extra']['room_name'] ?? str_replace(['%2b', '%2B'], '+', $product['extra']['room_id'] ?? '');
    
    // Check if we have different room types per room
    if ($num_rooms > 1 && !empty($rooms_data)) {
        $room_types_display = [];
        $has_different_types = false;
        $first_room_id = null;
        
        foreach ($rooms_data as $idx => $room) {
            $room_id = $room['room_id'] ?? $room['room_name'] ?? '';
            if ($first_room_id === null) {
                $first_room_id = $room_id;
            } elseif ($room_id !== $first_room_id) {
                $has_different_types = true;
            }
            
            $room_display = $room['room_name'] ?? str_replace(['%2b', '%2B'], '+', $room['room_id'] ?? $room_name);
            $room_types_display[] = $room_display;
        }
        
        if ($has_different_types) {
            // Different room types - show each
            $room_name = implode(', ', $room_types_display);
        } else {
            // Same room type - show count
            $room_name = $num_rooms . 'x ' . $room_name;
        }
    }
    
    $product['product_options_value'][] = [
        'option_name' => __('novoton_holidays.room'),
        'value' => $room_name
    ];
    
    // Board/Meal plan - check if different per room
    if ($num_rooms > 1 && !empty($rooms_data)) {
        $board_types = [];
        $has_different_boards = false;
        $first_board = null;
        
        foreach ($rooms_data as $room) {
            $board = $room['board_name'] ?? fn_novoton_get_board_name($room['board_id'] ?? '');
            if (!empty($board)) {
                if ($first_board === null) {
                    $first_board = $board;
                } elseif ($board !== $first_board) {
                    $has_different_boards = true;
                }
                $board_types[] = $board;
            }
        }
        
        if ($has_different_boards) {
            // Different boards - show each
            $product['product_options_value'][] = [
                'option_name' => __('novoton_holidays.board'),
                'value' => implode(', ', $board_types)
            ];
        } else {
            // Same board
            $product['product_options_value'][] = [
                'option_name' => __('novoton_holidays.board'),
                'value' => $board_name
            ];
        }
    } else {
        $product['product_options_value'][] = [
            'option_name' => __('novoton_holidays.board'),
            'value' => $board_name
        ];
    }
    
    // Guests with children ages (and rooms count)
    $product['product_options_value'][] = [
        'option_name' => __('novoton_holidays.guests'),
        'value' => $guests_str
    ];
    
    // Per-room breakdown if multiple rooms
    if ($num_rooms > 1 && !empty($rooms_data)) {
        foreach ($rooms_data as $idx => $room) {
            $room_num = $idx + 1;
            $room_guests = intval($room['adults'] ?? 2) . ' adults';
            if (!empty($room['children']) && $room['children'] > 0) {
                $room_guests .= ', ' . $room['children'] . ' children';
                if (!empty($room['childrenAges'])) {
                    $ages = array_filter($room['childrenAges'], function($a) { return $a !== null && $a !== ''; });
                    if (!empty($ages)) {
                        $room_guests .= ' (' . implode(', ', $ages) . ' y/o)';
                    }
                }
            }
            $product['product_options_value'][] = [
                'option_name' => 'Room ' . $room_num,
                'value' => $room_guests
            ];
        }
    }
    
    // Holder name if available
    if (!empty($product['extra']['holder_name']) || !empty($product['extra']['guest_names'])) {
        $product['product_options_value'][] = [
            'option_name' => __('novoton_holidays.holder'),
            'value' => $product['extra']['holder_name'] ?? $product['extra']['guest_names']
        ];
    }
    
    // Mark as hotel booking for templates
    $product['is_hotel_booking'] = true;
    
    // Also set product_options for compatibility
    if (empty($product['product_options'])) {
        $product['product_options'] = [];
    }
}

/**
 * Helper: Get readable board name from board ID
 */
function fn_novoton_get_board_name($board_id)
{
    $board_map = [
        'AI' => 'All Inclusive',
        'ALL INCL' => 'All Inclusive',
        'ALLINC' => 'All Inclusive',
        'UAI' => 'Ultra All Inclusive',
        'ULTRA ALL INCL' => 'Ultra All Inclusive',
        'FB' => 'Full Board',
        'HB' => 'Half Board',
        'BB' => 'Bed & Breakfast',
        'RO' => 'Room Only',
        'SC' => 'Self Catering',
    ];

    $board_upper = strtoupper(trim($board_id));
    return $board_map[$board_upper] ?? $board_id;
}

/**
 * Hook: dispatch_before_display - Ensure meta variables are never null
 * This prevents "html_entity_decode(): Passing null" errors in meta.tpl
 */
function fn_novoton_holidays_dispatch_before_display()
{
    // Register Smarty modifiers (do this for ALL dispatches, not just novoton_)
    if (function_exists('fn_novoton_register_smarty_modifiers')) {
        fn_novoton_register_smarty_modifiers();
    }
    
    // Only for our addon's controllers (meta handling)
    $dispatch = isset($_REQUEST['dispatch']) ? $_REQUEST['dispatch'] : '';
    
    if (strpos($dispatch, 'novoton_') === 0) {
        $view = \Tygh\Tygh::$app['view'];
        
        // List of ALL possible meta variables that might be used in meta.tpl
        $meta_vars = [
            'meta_description' => '',
            'meta_keywords' => '',
            'page_title' => __('novoton_holidays.search_results') ?: 'Search Results',
            'canonical_url' => '',
            'og_image' => '',
            'og_title' => __('novoton_holidays.search_results') ?: 'Search Results',
            'og_description' => '',
            'og_type' => 'website',
            'og_url' => '',
            'og_site_name' => '',
            'twitter_card' => '',
            'twitter_title' => '',
            'twitter_description' => '',
            'twitter_image' => '',
            'robots' => '',
            'hreflang_links' => [],
            'schema_org' => '',
            'extra_meta' => '',
            'page_description' => '',
            'company_name' => '',
            'site_name' => '',
            'absolute_uri' => '',
        ];
        
        // Ensure none are null
        foreach ($meta_vars as $var => $default) {
            $current = $view->getTemplateVars($var);
            if ($current === null) {
                $view->assign($var, $default);
            }
        }
        
        // Also set in Registry to be safe
        $registry_vars = [
            'navigation.dynamic.meta_description' => '',
            'navigation.dynamic.meta_keywords' => '',
            'navigation.dynamic.page_title' => __('novoton_holidays.search_results') ?: 'Search Results',
            'runtime.page_title' => __('novoton_holidays.search_results') ?: 'Search Results',
        ];
        
        foreach ($registry_vars as $key => $default) {
            if (Registry::get($key) === null) {
                Registry::set($key, $default);
            }
        }
    }
    
    // A73: Always load Novoton CSS for frontend booking pages
    if (AREA == 'C') {
        $dispatch = isset($_REQUEST['dispatch']) ? $_REQUEST['dispatch'] : '';
        
        // Load CSS for booking-related pages and product pages (which may have hotel info)
        if (strpos($dispatch, 'novoton_') === 0 || 
            strpos($dispatch, 'products.') === 0 || 
            strpos($dispatch, 'checkout') === 0 ||
            strpos($dispatch, 'cart') === 0) {
            
            // Register CSS using Registry (CS-Cart standard method)
            $styles = Registry::get('runtime.styles');
            if (!is_array($styles)) {
                $styles = [];
            }
            
            // Add our main CSS file
            $css_path = 'addons/novoton_holidays/styles.css';
            if (!in_array($css_path, $styles)) {
                $styles[] = $css_path;
                Registry::set('runtime.styles', $styles);
            }
        }
    }
}

/**
 * Hook: place_order - Send booking to Novoton API after order is placed
 * For multi-room bookings:
 * - Sends ALL rooms in SINGLE API request IF same hotel, package, and dates
 * - Sends SEPARATE API calls if rooms have different packages or dates
 */
function fn_novoton_holidays_place_order(&$order_id, &$action, &$order_status, &$cart, &$auth)
{
    if (empty($order_id) || empty($cart['products'])) {
        return;
    }
    
    $addon_settings = Registry::get('addons.novoton_holidays') ?? [];
    $commission = floatval($addon_settings['commission'] ?? 8);
    $disable_api = ($addon_settings['disable_api_submission'] ?? 'N') === 'Y';
    $debug_logging = ($addon_settings['debug_logging'] ?? 'Y') === 'Y';
    
    // Load API class
    $src_dir = Registry::get('config.dir.addons') . 'novoton_holidays/src/';
    if (!class_exists('Tygh\Addons\NovotonHolidays\NovotonApi')) {
        if (file_exists($src_dir . 'NovotonApi.php')) {
            require_once($src_dir . 'NovotonApi.php');
        }
    }
    
    // Check each product for Novoton booking data
    foreach ($cart['products'] as $cart_id => $product) {
        if (empty($product['extra']['novoton_booking'])) {
            continue;
        }
        
        $booking_data = $product['extra'];
        $original_booking_id = intval($booking_data['novoton_booking_id'] ?? 0);
        
        // CRITICAL: Fetch complete booking data from database
        // This ensures we have accurate prices even if cart session data was lost
        if ($original_booking_id > 0) {
            $db_booking = db_get_row(
                "SELECT * FROM ?:novoton_bookings WHERE booking_id = ?i",
                $original_booking_id
            );
            
            if ($db_booking) {
                // Merge database data with cart data (database takes priority for critical fields)
                $booking_data['total_price'] = floatval($db_booking['total_price']);
                $booking_data['base_price'] = floatval($db_booking['base_price']);
                $booking_data['hotel_id'] = $db_booking['hotel_id'];
                $booking_data['hotel_name'] = $db_booking['hotel_name'];
                $booking_data['package_name'] = $db_booking['package_name'];
                $booking_data['room_id'] = $db_booking['room_id'];
                $booking_data['room_type'] = $db_booking['room_type'];
                $booking_data['board_id'] = $db_booking['board_id'];
                $booking_data['check_in'] = $db_booking['check_in'];
                $booking_data['check_out'] = $db_booking['check_out'];
                $booking_data['nights'] = $db_booking['nights'];
                $booking_data['adults'] = $db_booking['adults'];
                $booking_data['children'] = $db_booking['children'];
                $booking_data['children_ages'] = $db_booking['children_ages'] ?? '';
                $booking_data['num_rooms'] = $db_booking['num_rooms'] ?? 1;
                $booking_data['holder_name'] = $db_booking['holder_name'] ?? '';
                $booking_data['guest_name'] = $db_booking['guest_name'] ?? '';
                $booking_data['special_requests'] = $db_booking['special_requests'] ?? '';
                
                // Parse rooms_data and guests_data from database
                if (!empty($db_booking['rooms_data'])) {
                    $booking_data['rooms_data'] = $db_booking['rooms_data'];
                }
                if (!empty($db_booking['guests_data'])) {
                    $booking_data['guests_data'] = $db_booking['guests_data'];
                }
                
                if ($debug_logging) {
                    fn_log_event('general', 'runtime', [
                        'message' => 'Novoton - Fetched booking data from database',
                        'booking_id' => $original_booking_id,
                        'total_price' => $db_booking['total_price'],
                        'hotel_name' => $db_booking['hotel_name'],
                        'room_type' => $db_booking['room_type']
                    ]);
                }
            }
        }
        
        // Get final price - prefer database value, then cart
        $final_price = floatval($booking_data['total_price'] ?? 0);
        if ($final_price <= 0) {
            $final_price = floatval($product['price'] ?? 0);
        }
        if ($final_price <= 0) {
            $final_price = floatval($product['base_price'] ?? 0);
        }
        
        // Store final_price in booking_data for later use
        $booking_data['final_price'] = $final_price;
        
        if ($debug_logging) {
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton - Final price determined',
                'booking_id' => $original_booking_id,
                'db_total_price' => $booking_data['total_price'] ?? 'NOT SET',
                'cart_price' => $product['price'] ?? 'NOT SET',
                'final_price' => $final_price,
                'hotel_id' => $booking_data['hotel_id'] ?? 'NOT SET'
            ]);
        }
        
        $rooms_data = [];
        
        // Parse rooms_data
        if (!empty($booking_data['rooms_data'])) {
            $rooms_data = is_string($booking_data['rooms_data']) 
                ? json_decode($booking_data['rooms_data'], true) 
                : $booking_data['rooms_data'];
        }
        
        // If no rooms_data, create single room entry from database/cart data
        if (empty($rooms_data)) {
            $children_ages = [];
            if (!empty($booking_data['children_ages'])) {
                $children_ages = is_string($booking_data['children_ages'])
                    ? array_map('intval', array_filter(explode(',', $booking_data['children_ages']), function($v) { return $v !== ''; }))
                    : (array)$booking_data['children_ages'];
            }
            
            $rooms_data = [[
                'room_id' => $booking_data['room_id'],
                'room_name' => $booking_data['room_type'] ?? $booking_data['room_name'] ?? $booking_data['room_id'],
                'room_type_display' => $booking_data['room_type'] ?? $booking_data['room_name'] ?? $booking_data['room_id'],
                'board_id' => $booking_data['board_id'],
                'board_name' => $booking_data['board_name'] ?? $booking_data['board_id'],
                'package_name' => $booking_data['package_name'] ?? '',
                'check_in' => $booking_data['check_in'],
                'check_out' => $booking_data['check_out'],
                'adults' => intval($booking_data['adults'] ?? 2),
                'children' => intval($booking_data['children'] ?? 0),
                'childrenAges' => $children_ages,
                'price' => $booking_data['final_price']
            ]];
        }
        
        // Parse guests_data (keyed by room1_adult_1, etc.)
        $guests_data = [];
        if (!empty($booking_data['guests_data'])) {
            $guests_data = is_string($booking_data['guests_data']) 
                ? json_decode($booking_data['guests_data'], true) 
                : $booking_data['guests_data'];
        }
        
        // If guests_data is empty or has no names, try to fetch from database
        // This handles the case where cart session lost the guest data
        if (empty($guests_data) || !is_array($guests_data)) {
            $original_booking_id = $booking_data['novoton_booking_id'] ?? 0;
            if ($original_booking_id > 0) {
                $db_guests = db_get_field(
                    "SELECT guests_data FROM ?:novoton_bookings WHERE booking_id = ?i",
                    $original_booking_id
                );
                if (!empty($db_guests)) {
                    $guests_data = json_decode($db_guests, true) ?: [];
                    if ($debug_logging) {
                        fn_log_event('general', 'runtime', [
                            'message' => 'Novoton - Fetched guests_data from database (cart was empty)',
                            'booking_id' => $original_booking_id,
                            'guests_count' => count($guests_data)
                        ]);
                    }
                }
            }
        }
        
        // Also check if holder_name is available but guests_data is not
        // In this case, fetch from database by matching order details
        if (empty($guests_data) || !is_array($guests_data)) {
            // Try to find existing booking by hotel_id, check_in, check_out that was recently created
            $existing_booking = db_get_row(
                "SELECT guests_data, holder_name, guest_name FROM ?:novoton_bookings 
                 WHERE hotel_id = ?s AND check_in = ?s AND check_out = ?s 
                 AND order_id = 0 
                 ORDER BY booking_id DESC LIMIT 1",
                $booking_data['hotel_id'],
                $booking_data['check_in'],
                $booking_data['check_out']
            );
            if (!empty($existing_booking['guests_data'])) {
                $guests_data = json_decode($existing_booking['guests_data'], true) ?: [];
                if ($debug_logging) {
                    fn_log_event('general', 'runtime', [
                        'message' => 'Novoton - Fetched guests_data from pending booking record',
                        'holder_name' => $existing_booking['holder_name'],
                        'guests_count' => count($guests_data)
                    ]);
                }
            }
        }
        
        // Log guests_data for debugging - DETAILED
        if ($debug_logging) {
            // Check structure of first element
            $first_key = !empty($guests_data) ? array_key_first($guests_data) : 'EMPTY';
            $first_val = !empty($guests_data) ? reset($guests_data) : 'EMPTY';
            
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton - Raw guests_data from cart (DETAILED)',
                'order_id' => $order_id,
                'guests_data_type' => gettype($guests_data),
                'guests_data_count' => is_array($guests_data) ? count($guests_data) : 0,
                'first_key' => $first_key,
                'first_key_type' => gettype($first_key),
                'first_value' => $first_val,
                'all_keys' => is_array($guests_data) ? array_keys($guests_data) : [],
                'raw_guests_data' => $guests_data,
                'booking_data_holder_name' => $booking_data['holder_name'] ?? 'NOT SET',
                'booking_data_guest_names' => $booking_data['guest_names'] ?? 'NOT SET'
            ]);
        }
        
        // Get original booking record
        $original_booking_id = $booking_data['novoton_booking_id'] ?? 0;
        
        // GROUP rooms by (package_name + check_in + check_out)
        // Rooms with same grouping key can be sent in single API request
        $room_groups = [];
        $default_package = $booking_data['package_name'] ?? '';
        $default_check_in = $booking_data['check_in'];
        $default_check_out = $booking_data['check_out'];
        
        foreach ($rooms_data as $room_idx => $room) {
            // Get room's package and dates (may differ from default)
            $room_package = $room['package_name'] ?? $default_package;
            $room_check_in = $room['check_in'] ?? $default_check_in;
            $room_check_out = $room['check_out'] ?? $default_check_out;
            
            // Create grouping key
            $group_key = md5($room_package . '|' . $room_check_in . '|' . $room_check_out);
            
            if (!isset($room_groups[$group_key])) {
                $room_groups[$group_key] = [
                    'package_name' => $room_package,
                    'check_in' => $room_check_in,
                    'check_out' => $room_check_out,
                    'rooms' => []
                ];
            }
            
            // Add room index to group
            $room['original_index'] = $room_idx;
            $room_groups[$group_key]['rooms'][] = $room;
        }
        
        if ($debug_logging) {
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton - Room grouping result',
                'order_id' => $order_id,
                'total_rooms' => count($rooms_data),
                'groups_count' => count($room_groups),
                'can_combine' => count($room_groups) == 1 ? 'YES - single API call' : 'NO - multiple API calls needed'
            ]);
        }
        
        // Process each group - each group = one API call
        $api = new \Tygh\Addons\NovotonHolidays\NovotonApi();
        $group_num = 0;
        $all_booking_ids = [];
        
        foreach ($room_groups as $group_key => $group) {
            $group_num++;
            $group_rooms = $group['rooms'];
            
            // Build guests for ALL rooms in this group
            $all_guests = [];
            $api_rooms = [];
            $total_api_price = 0;
            $total_group_price = 0;
            
            foreach ($group_rooms as $room) {
                $room_idx = $room['original_index'];
                $room_num = $room_idx + 1;
                $room_guests = [];
                $adults_count = intval($room['adults'] ?? 2);
                $children_count = intval($room['children'] ?? 0);
                $children_ages = $room['childrenAges'] ?? [];
                
                // Get adult names for this room
                for ($i = 1; $i <= $adults_count; $i++) {
                    $guest_key = "room{$room_num}_adult_{$i}";
                    $name = '';
                    
                    if (isset($guests_data[$guest_key]) && !empty($guests_data[$guest_key]['api_name'])) {
                        $name = $guests_data[$guest_key]['api_name'];  // Use api_name (First Last) for API
                    } elseif (isset($guests_data[$guest_key]) && !empty($guests_data[$guest_key]['name'])) {
                        $name = $guests_data[$guest_key]['name'];  // Fallback for legacy data
                    } elseif ($room_num == 1 && $i == 1 && !empty($booking_data['holder_name'])) {
                        $name = $booking_data['holder_name'];
                    } else {
                        $name = "Adult {$i} Room {$room_num}";
                    }
                    
                    $guest = [
                        'name' => $name,  // Already has correct format from above logic
                        'birthday' => $guests_data[$guest_key]['birthday'] ?? '',
                        'age' => intval($guests_data[$guest_key]['age'] ?? 30),
                        'type' => 'adult',
                        'room' => $room_num
                    ];
                    $room_guests[] = $guest;
                    $all_guests[] = $guest;
                }
                
                // Get children names and ages for this room
                for ($i = 1; $i <= $children_count; $i++) {
                    $guest_key = "room{$room_num}_child_{$i}";
                    
                    $name = '';
                    if (isset($guests_data[$guest_key]) && !empty($guests_data[$guest_key]['api_name'])) {
                        $name = $guests_data[$guest_key]['api_name'];  // Use api_name for API
                    } elseif (isset($guests_data[$guest_key]) && !empty($guests_data[$guest_key]['name'])) {
                        $name = $guests_data[$guest_key]['name'];  // Fallback to name for legacy data
                    } else {
                        $name = "Child {$i} Room {$room_num}";
                    }
                    
                    $age = 6;
                    if (isset($guests_data[$guest_key]['age'])) {
                        $age = intval($guests_data[$guest_key]['age']);
                    } elseif (isset($children_ages[$i-1])) {
                        $age = intval($children_ages[$i-1]);
                    }
                    
                    $guest = [
                        'name' => $name,
                        'birthday' => $guests_data[$guest_key]['birthday'] ?? '',
                        'age' => $age,
                        'type' => 'child',
                        'room' => $room_num
                    ];
                    $room_guests[] = $guest;
                    $all_guests[] = $guest;
                }
                
                // Calculate room API price (without commission)
                $room_price_with_commission = floatval($room['price'] ?? 0);
                $room_api_price = $room_price_with_commission / (1 + ($commission / 100));
                $total_api_price += $room_api_price;
                $total_group_price += $room_price_with_commission;
                
                // Add to API rooms array
                $api_rooms[] = [
                    'room_id' => $room['room_id'] ?? $booking_data['room_id'],
                    'board_id' => $room['board_id'] ?? $booking_data['board_id'],
                    'guests' => $room_guests
                ];
            }
            
            // Determine order number suffix
            $order_num_suffix = count($room_groups) > 1 ? "-G{$group_num}" : '';
            
            // Prepare API request for this group
            $api_data = [
                'hotel_id' => $booking_data['hotel_id'],
                'package_name' => $group['package_name'],
                'check_in' => $group['check_in'],
                'check_out' => $group['check_out'],
                'holder' => $all_guests[0]['name'] ?? $booking_data['holder_name'] ?? 'Guest',
                'guests' => $all_guests,
                'rooms' => $api_rooms,
                'order_num' => $order_id . $order_num_suffix,
                'remark' => $booking_data['special_requests'] ?? '',
                'comment' => $booking_data['special_requests'] ?? ''
            ];
            
            // For single room in group, also set room_id and board_id directly
            if (count($group_rooms) == 1) {
                $api_data['room_id'] = $group_rooms[0]['room_id'] ?? $booking_data['room_id'];
                $api_data['board_id'] = $group_rooms[0]['board_id'] ?? $booking_data['board_id'];
            }
            
            // Calculate nights for this group
            $check_in_date = new DateTime($group['check_in']);
            $check_out_date = new DateTime($group['check_out']);
            $nights = $check_in_date->diff($check_out_date)->days;
            
            // Build room IDs string for display
            $room_ids_display = array_column($group_rooms, 'room_id');
            
            // Create booking record for this group
            $booking_record = [
                'order_id' => $order_id,
                'product_id' => $product['product_id'],
                'item_id' => $product['item_id'] ?? '',
                'hotel_id' => $booking_data['hotel_id'],
                'hotel_name' => $booking_data['hotel_name'] ?? '',
                'package_name' => $group['package_name'],
                'room_id' => implode(', ', $room_ids_display),
                'room_type' => $group_rooms[0]['room_type_display'] ?? $group_rooms[0]['room_name'] ?? '',
                'board_id' => $group_rooms[0]['board_id'] ?? $booking_data['board_id'],
                'board_name' => $group_rooms[0]['board_name'] ?? $booking_data['board_name'] ?? '',
                'check_in' => $group['check_in'],
                'check_out' => $group['check_out'],
                'nights' => $nights,
                'adults' => array_sum(array_column($group_rooms, 'adults')),
                'children' => array_sum(array_column($group_rooms, 'children')),
                'children_ages' => $booking_data['children_ages'] ?? '',
                'num_rooms' => count($group_rooms),
                'room_number' => $group_num,
                'total_rooms' => count($room_groups),
                'rooms_data' => json_encode($group_rooms),
                'guest_name' => implode(', ', array_column($all_guests, 'name')),
                'holder_name' => $all_guests[0]['name'] ?? $booking_data['holder_name'] ?? '',
                'guests_data' => json_encode($all_guests),
                'base_price' => $total_api_price,
                'total_price' => $total_group_price,
                'currency' => 'EUR',
                'status' => 'pending',
                'special_requests' => $booking_data['special_requests'] ?? '',
                'api_request' => json_encode($api_data),
                'notes' => $disable_api ? 'API submission disabled - test mode' : ''
            ];

            // SINGLE SOURCE OF TRUTH: Always try to find existing booking
            // Priority: 1) original_booking_id from cart, 2) match by order+hotel+dates
            $booking_id = 0;
            $order_info = fn_get_order_info($order_id);
            $order_user_id = intval($order_info['user_id'] ?? 0);
            $order_email = $order_info['email'] ?? '';

            // Add user tracking fields
            $booking_record['user_id'] = $order_user_id;
            $booking_record['guest_email'] = $order_email;

            if ($group_num == 1 && $original_booking_id > 0) {
                // Update existing booking from cart
                db_query("UPDATE ?:novoton_bookings SET ?u WHERE booking_id = ?i",
                    $booking_record, $original_booking_id);
                $booking_id = $original_booking_id;
            } else {
                // Try to find existing booking for this order to prevent duplicates
                $existing_booking_id = db_get_field(
                    "SELECT booking_id FROM ?:novoton_bookings
                     WHERE order_id = ?i AND hotel_id = ?s AND check_in = ?s AND check_out = ?s
                     LIMIT 1",
                    $order_id,
                    $booking_record['hotel_id'],
                    $booking_record['check_in'],
                    $booking_record['check_out']
                );

                if ($existing_booking_id) {
                    // Update existing record instead of creating duplicate
                    db_query("UPDATE ?:novoton_bookings SET ?u WHERE booking_id = ?i",
                        $booking_record, $existing_booking_id);
                    $booking_id = $existing_booking_id;
                } else {
                    // No existing record found, create new
                    $booking_record['session_id'] = session_id();
                    $booking_id = db_query("INSERT INTO ?:novoton_bookings ?e", $booking_record);
                }
            }

            $all_booking_ids[] = $booking_id;
            
            // Log the API request
            if ($debug_logging) {
                fn_log_event('general', 'runtime', [
                    'message' => 'Novoton Booking - API Request Prepared',
                    'order_id' => $order_id,
                    'booking_id' => $booking_id,
                    'group' => $group_num . ' of ' . count($room_groups),
                    'rooms_in_group' => count($group_rooms),
                    'package_name' => $group['package_name'],
                    'check_in' => $group['check_in'],
                    'check_out' => $group['check_out'],
                    'guests' => array_column($all_guests, 'name'),
                    'api_disabled' => $disable_api ? 'YES' : 'NO'
                ]);
            }
            
            // Skip API call if disabled
            if ($disable_api) {
                db_query("UPDATE ?:novoton_bookings SET notes = ?s WHERE booking_id = ?i",
                    'API submission disabled - booking saved locally only.',
                    $booking_id);
                continue;
            }
            
            // Send to API
            try {
                $response = $api->createReservation($api_data);
                
                if ($response) {
                    $novoton_id = (string)($response->IdNum ?? '');
                    $novoton_status = (string)($response->Status ?? '');
                    $novoton_price = (string)($response->Price ?? '');
                    
                    $update_data = [
                        'novoton_invoice_id' => $novoton_id,
                        'novoton_status' => $novoton_status,
                        'api_price' => !empty($novoton_price) ? floatval($novoton_price) : $total_api_price,
                        'api_response' => json_encode([
                            'IdNum' => $novoton_id,
                            'Price' => $novoton_price,
                            'Currency' => (string)($response->Currency ?? 'EUR'),
                            'Quota' => (string)($response->Quota ?? ''),
                            'Status' => $novoton_status
                        ])
                    ];
                    
                    if ($novoton_status === 'OK') {
                        $update_data['status'] = 'confirmed';
                    } elseif ($novoton_status === 'ASK') {
                        $update_data['status'] = 'ask';
                    } elseif ($novoton_status === 'ST') {
                        $update_data['status'] = 'cancelled';
                    } elseif ($novoton_status === 'WT') {
                        $update_data['status'] = 'waiting';
                    }
                    
                    db_query("UPDATE ?:novoton_bookings SET ?u WHERE booking_id = ?i", 
                        $update_data, $booking_id);
                    
                    if ($debug_logging) {
                        fn_log_event('general', 'runtime', [
                            'message' => 'Novoton Booking - API Response',
                            'order_id' => $order_id,
                            'booking_id' => $booking_id,
                            'novoton_id' => $novoton_id,
                            'status' => $novoton_status
                        ]);
                    }
                }
                
            } catch (Exception $e) {
                db_query("UPDATE ?:novoton_bookings SET status = 'failed', notes = ?s WHERE booking_id = ?i",
                    'API Error: ' . $e->getMessage(), $booking_id);
                
                fn_log_event('general', 'runtime', [
                    'message' => 'Novoton Booking API Error',
                    'order_id' => $order_id,
                    'group' => $group_num,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
}

/**
 * Hook: checkout page display - add debug info
 * This runs on checkout pages and can inject data
 */
function fn_novoton_holidays_checkout_pre_dispatch(&$cart, &$auth, $storefront_id)
{
    // Check if debug mode using centralized function
    if (fn_novoton_is_debug()) {
        // Set a flag that template can use
        Tygh::$app['view']->assign('novoton_checkout_debug', true);
        Tygh::$app['view']->assign('novoton_debug_cart_products', $cart['products'] ?? []);
    }
}

/**
 * Hook: after calculate cart - ensure rooms_data is preserved
 */
function fn_novoton_holidays_calculate_cart_items_post(&$cart, &$cart_products, $auth)
{
    // Debug logging for multi-room using centralized function
    if (fn_novoton_is_debug()) {
        fn_log_event('general', 'runtime', [
            'message' => 'Novoton calculate_cart_items_post',
            'cart_products_count' => count($cart_products)
        ]);
    }
    
    // Ensure rooms_data is properly preserved in cart products
    foreach ($cart_products as $cart_id => &$product) {
        if (!empty($product['extra']['novoton_booking'])) {
            // If rooms_data is a string, decode it
            if (!empty($product['extra']['rooms_data']) && is_string($product['extra']['rooms_data'])) {
                $decoded = json_decode($product['extra']['rooms_data'], true);
                if (is_array($decoded)) {
                    $product['extra']['rooms_data'] = $decoded;
                    // Also update in cart
                    if (isset($cart['products'][$cart_id])) {
                        $cart['products'][$cart_id]['extra']['rooms_data'] = $decoded;
                    }
                }
            }
        }
    }
}

/**
 * Smarty modifier to decode JSON in templates
 */
function smarty_modifier_json_decode($string, $assoc = true)
{
    if (empty($string)) {
        return $assoc ? [] : null;
    }
    return json_decode($string, $assoc);
}
/**
 * Hook: After user login - link session bookings to user account
 * This ensures bookings created as guest are linked when user logs in
 */
function fn_novoton_holidays_user_login_post($user_data, $auth)
{
    if (empty($auth['user_id'])) {
        return;
    }
    
    $user_id = intval($auth['user_id']);
    $session_id = session_id();
    
    // Link any bookings from current session to this user
    if (!empty($session_id)) {
        $updated = db_query(
            "UPDATE ?:novoton_bookings 
             SET user_id = ?i 
             WHERE session_id = ?s 
             AND user_id = 0 
             AND order_id = 0",
            $user_id,
            $session_id
        );
        
        if ($updated > 0) {
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton: Linked session bookings to user on login',
                'user_id' => $user_id,
                'session_id' => $session_id,
                'bookings_linked' => $updated
            ]);
        }
    }
    
    // Also link by email if user has email
    if (!empty($user_data['email'])) {
        $updated_by_email = db_query(
            "UPDATE ?:novoton_bookings 
             SET user_id = ?i 
             WHERE guest_email = ?s 
             AND user_id = 0",
            $user_id,
            $user_data['email']
        );
        
        if ($updated_by_email > 0) {
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton: Linked email bookings to user on login',
                'user_id' => $user_id,
                'email' => $user_data['email'],
                'bookings_linked' => $updated_by_email
            ]);
        }
    }
}

/**
 * Hook: After user registration - link bookings by email
 */
function fn_novoton_holidays_create_user_post($user_data)
{
    if (empty($user_data['user_id']) || empty($user_data['email'])) {
        return;
    }
    
    $user_id = intval($user_data['user_id']);
    
    // Link bookings by email
    $updated = db_query(
        "UPDATE ?:novoton_bookings 
         SET user_id = ?i 
         WHERE guest_email = ?s 
         AND user_id = 0",
        $user_id,
        $user_data['email']
    );
    
    // Also link by current session
    $session_id = session_id();
    if (!empty($session_id)) {
        db_query(
            "UPDATE ?:novoton_bookings 
             SET user_id = ?i 
             WHERE session_id = ?s 
             AND user_id = 0",
            $user_id,
            $session_id
        );
    }
    
    if ($updated > 0) {
        fn_log_event('general', 'runtime', [
            'message' => 'Novoton: Linked bookings to new user account',
            'user_id' => $user_id,
            'email' => $user_data['email'],
            'bookings_linked' => $updated
        ]);
    }
}

/**
 * Hook: After getting order info - format Novoton booking terms for display
 * A69: Adds formatted payment and cancellation terms to order products
 * A85: Enhanced with hotel location, formatted dates, payment amounts, guest names
 *
 * @param array $order Order data
 * @param bool $additional_data Whether additional data was requested
 */
function fn_novoton_holidays_get_order_info(&$order, $additional_data)
{
    // Debug: Log when hook fires
    if (!empty($_REQUEST['debug'])) {
        fn_set_notification('N', 'DEBUG', 'fn_novoton_holidays_get_order_info hook fired for order #' . ($order['order_id'] ?? '?'));
    }

    if (empty($order['products'])) {
        return;
    }

    // Get CS-Cart date format setting
    $date_format = Registry::get('settings.Appearance.date_format');
    if (empty($date_format)) {
        $date_format = '%d %b %Y'; // fallback: "05 Mar 2026"
    }

    // Get currency for display
    $currency_code = $order['secondary_currency'] ?? 'EUR';

    foreach ($order['products'] as &$product) {
        // Debug: Show extra keys
        if (!empty($_REQUEST['debug'])) {
            $extra_keys = array_keys($product['extra'] ?? []);
            fn_set_notification('N', 'DEBUG', 'Product extra keys: ' . implode(', ', $extra_keys));
        }

        if (empty($product['extra']['novoton_booking'])) {
            continue;
        }

        $hotel_id = $product['extra']['hotel_id'] ?? '';
        $check_in = $product['extra']['check_in'] ?? '';
        $check_out = $product['extra']['check_out'] ?? '';
        $total_price = floatval($product['extra']['total_price'] ?? $product['price'] ?? 0);

        // [1] Add hotel location data (city, region, country) from database
        if (!empty($hotel_id) && empty($product['extra']['city'])) {
            $hotel_data = db_get_row(
                "SELECT city, region, country FROM ?:novoton_hotels WHERE hotel_id = ?s",
                $hotel_id
            );
            if ($hotel_data) {
                $product['extra']['city'] = $hotel_data['city'] ?? '';
                $product['extra']['region'] = $hotel_data['region'] ?? '';
                $product['extra']['country'] = $hotel_data['country'] ?? '';
            }
        }

        // [2] Format dates using CS-Cart date format setting
        if (!empty($check_in)) {
            $product['extra']['check_in_formatted'] = fn_date_format(strtotime($check_in), $date_format);
        }
        if (!empty($check_out)) {
            $product['extra']['check_out_formatted'] = fn_date_format(strtotime($check_out), $date_format);
        }

        // Format Terms of Payment
        // Use _raw (XML) key first; terms_of_payment may already be formatted text
        $payment_raw = $product['extra']['terms_of_payment_raw'] ?? '';
        $payment_text = $product['extra']['terms_of_payment'] ?? '';
        $cancel_raw = $product['extra']['terms_of_cancellation_raw'] ?? '';
        $cancel_text = $product['extra']['terms_of_cancellation'] ?? '';

        // Fallback: If no terms data in order, try to fetch from API
        if (empty($payment_raw) && empty($payment_text) && empty($cancel_raw) && empty($cancel_text)) {
            $room_id = $product['extra']['room_id'] ?? '';
            $adults = $product['extra']['adults'] ?? 2;
            $children = $product['extra']['children'] ?? 0;

            if (!empty($hotel_id) && !empty($check_in) && !empty($check_out)) {
                // Debug
                if (!empty($_REQUEST['debug'])) {
                    fn_set_notification('N', 'DEBUG', "Fetching terms from API for hotel {$hotel_id}");
                }

                // Try to load NovotonApi and fetch terms
                try {
                    $src_dir = \Tygh\Registry::get('config.dir.addons') . 'novoton_holidays/src/';
                    if (file_exists($src_dir . 'NovotonApi.php')) {
                        require_once($src_dir . 'NovotonApi.php');
                        $api = new \Tygh\Addons\NovotonHolidays\NovotonApi();

                        $priceData = $api->getRoomPrice([
                            'hotel_id' => $hotel_id,
                            'check_in' => $check_in,
                            'check_out' => $check_out,
                            'adults' => $adults,
                            'children' => $children,
                            'room_id' => $room_id
                        ]);

                        if (!empty($_REQUEST['debug'])) {
                            $type = $priceData ? (($priceData instanceof \SimpleXMLElement) ? 'SimpleXMLElement' : gettype($priceData)) : 'null';
                            fn_set_notification('N', 'DEBUG', "API response type: {$type}");
                        }

                        if ($priceData instanceof \SimpleXMLElement) {
                            // Use XPath to find terms anywhere in the XML tree (like search does)
                            $termsPayment = $priceData->xpath('//TermsOfPayment');
                            $termsCancellation = $priceData->xpath('//TermsOfCancellation');

                            if (!empty($_REQUEST['debug'])) {
                                fn_set_notification('N', 'DEBUG', 'XPath results: TermsOfPayment=' . count($termsPayment) . ', TermsOfCancellation=' . count($termsCancellation));
                            }

                            if (!empty($termsPayment[0])) {
                                $payment_raw = $termsPayment[0]->asXML();
                            }
                            if (!empty($termsCancellation[0])) {
                                $cancel_raw = $termsCancellation[0]->asXML();
                            }

                            if (!empty($_REQUEST['debug'])) {
                                fn_set_notification('N', 'DEBUG', 'Terms extracted: payment=' . (!empty($payment_raw) ? 'YES (' . strlen($payment_raw) . ' chars)' : 'NO') . ', cancel=' . (!empty($cancel_raw) ? 'YES (' . strlen($cancel_raw) . ' chars)' : 'NO'));
                            }
                        }
                    }
                } catch (Exception $e) {
                    if (!empty($_REQUEST['debug'])) {
                        fn_set_notification('W', 'DEBUG', 'API error: ' . $e->getMessage());
                    }
                }
            }
        }

        // [4] Format payment terms with calculated amounts
        if (!empty($payment_raw) && $total_price > 0) {
            $product['extra']['terms_of_payment_with_amounts'] = fn_novoton_format_payment_terms_with_amounts(
                $payment_raw,
                $total_price,
                $currency_code
            );
            $product['extra']['terms_of_payment_formatted'] = fn_novoton_format_payment_terms($payment_raw);
        } elseif (!empty($payment_raw)) {
            $product['extra']['terms_of_payment_formatted'] = fn_novoton_format_payment_terms($payment_raw);
        } elseif (!empty($payment_text)) {
            // Already formatted text — use as-is
            $product['extra']['terms_of_payment_formatted'] = $payment_text;
        }

        // Format cancellation terms
        if (!empty($cancel_raw)) {
            $product['extra']['terms_of_cancellation_formatted'] = fn_novoton_format_cancellation_terms($cancel_raw, $check_in);
        } elseif (!empty($cancel_text)) {
            // Already formatted text — use as-is
            $product['extra']['terms_of_cancellation_formatted'] = $cancel_text;
        }

        // Format board display name (e.g., "ULTRA ALL INCL" -> "Ultra All Inclusive")
        $board_id = $product['extra']['board_id'] ?? $product['extra']['board'] ?? '';
        if (!empty($board_id)) {
            $product['extra']['board_display'] = fn_novoton_get_board_name($board_id);
        }

        // [3] Format guests_data for email display with display_name, type, age, is_holder
        $guests_data = $product['extra']['guests_data'] ?? null;
        if (!empty($guests_data)) {
            if (is_string($guests_data)) {
                $guests_data = json_decode($guests_data, true);
            }
            if (is_array($guests_data)) {
                $formatted_guests = [];
                $holder_name = $product['extra']['holder_name'] ?? '';
                $is_first = true;

                foreach ($guests_data as $key => $guest) {
                    if (!is_array($guest)) {
                        continue;
                    }

                    // Get display name - prefer "Last, First" format for display
                    $display_name = $guest['display_name'] ?? $guest['name'] ?? '';
                    $api_name = $guest['api_name'] ?? '';

                    // If we have api_name (First Last), convert to display format (Last, First)
                    if (empty($display_name) && !empty($api_name)) {
                        $parts = explode(' ', trim($api_name), 2);
                        if (count($parts) == 2) {
                            $display_name = $parts[1] . ', ' . $parts[0];
                        } else {
                            $display_name = $api_name;
                        }
                    }

                    $guest_type = $guest['type'] ?? 'adult';
                    $guest_age = intval($guest['age'] ?? 0);

                    // Determine if this is the holder
                    $is_holder = false;
                    if ($is_first && $guest_type === 'adult') {
                        $is_holder = true;
                        $is_first = false;
                    } elseif (!empty($holder_name) && stripos($display_name, $holder_name) !== false) {
                        $is_holder = true;
                    }

                    $formatted_guests[$key] = [
                        'display_name' => $display_name,
                        'name' => $guest['name'] ?? $display_name,
                        'type' => $guest_type,
                        'age' => $guest_age,
                        'is_holder' => $is_holder,
                        'birthday' => $guest['birthday'] ?? '',
                        'room' => $guest['room'] ?? 1
                    ];
                }

                $product['extra']['guests_data'] = $formatted_guests;
            }
        }

        // Debug: Show what was set
        if (!empty($_REQUEST['debug'])) {
            $payment_set = !empty($product['extra']['terms_of_payment_formatted']) ? 'YES' : 'NO';
            $payment_amounts = !empty($product['extra']['terms_of_payment_with_amounts']) ? 'YES' : 'NO';
            $cancel_set = !empty($product['extra']['terms_of_cancellation_formatted']) ? 'YES' : 'NO';
            fn_set_notification('N', 'DEBUG', "terms_of_payment_formatted: {$payment_set}, with_amounts: {$payment_amounts}, cancellation: {$cancel_set}");
        }
    }
}

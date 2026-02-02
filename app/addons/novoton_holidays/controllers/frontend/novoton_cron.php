<?php
/**
 * Novoton Holidays - Public Cron Controller
 * 
 * This controller handles cron jobs without requiring admin authentication.
 * Access via: index.php?dispatch=novoton_cron.run&access_key=YOUR_ACCESS_KEY
 * 
 * Modes:
 * - resinfo: Check ASK bookings status
 * - hotel_list: Hotel list sync from API
 * - update_prices: Update hotel prices
 * - room_price: Check which hotels have active prices
 * - alternative_rs: Check alternative_RS for pending requests
 * - alternative_rs_bookings: Check alternatives for RQ bookings
 * - notify_alternatives: Send email notifications for found alternatives
 * - expire_requests: Expire old alternative requests
 * - offers_update: Check for new/updated offers and add hotels
 * - add_hotels_as_products: Add hotels with prices as CS-Cart products
 * - list_facilities: Sync facilities list from API
 * - hotel_info: Sync hotel accommodation data (rooms, boards, packages, ages) from hotelinfo API
 */

use Tygh\Registry;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

// Authentication: Require access_key parameter
$provided_access_key = $_REQUEST['access_key'] ?? '';
$stored_access_key = Registry::get('addons.novoton_holidays.cron_access_key') ?? '';

if (empty($stored_access_key)) {
    header('Content-Type: text/plain');
    http_response_code(403);
    echo "ERROR: Cron Access Key not configured in addon settings.\n";
    exit;
}

if (empty($provided_access_key) || $provided_access_key !== $stored_access_key) {
    header('Content-Type: text/plain');
    http_response_code(403);
    echo "ERROR: Invalid or missing API key.\n";
    exit;
}

$mode = $_REQUEST['mode'] ?? 'resinfo';
$cron_start_time = microtime(true);

header('Content-Type: text/plain; charset=utf-8');

echo "===========================================\n";
echo "NOVOTON HOLIDAYS CRON - " . strtoupper($mode) . "\n";
echo "===========================================\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// Load API
$src_dir = Registry::get('config.dir.addons') . 'novoton_holidays/src/';
if (file_exists($src_dir . 'NovotonApi.php')) {
    require_once($src_dir . 'NovotonApi.php');
}

try {
    $api = new \Tygh\Addons\NovotonHolidays\NovotonApi();
    $mode_found = true;
    
    // =========================================
    // MODE: resinfo
    // =========================================
    if ($mode == 'resinfo') {
        echo "Checking ASK bookings status...\n\n";
        
        // Query uses novoton_status = 'ASK' for on-request bookings
        $ask_bookings = db_get_array(
            "SELECT * FROM ?:novoton_bookings WHERE novoton_status = 'ASK' AND status IN ('pending', 'ask') ORDER BY created_at DESC LIMIT 50"
        );
        
        if (empty($ask_bookings)) {
            echo "No pending ASK bookings found.\n";
        } else {
            echo "Found " . count($ask_bookings) . " pending ASK bookings.\n\n";
            
            foreach ($ask_bookings as $booking) {
                echo "Booking #{$booking['booking_id']} (Order #{$booking['order_id']})...\n";
                
                // Use novoton_confirm_id or novoton_res_num for API lookup
                $reservation_id = $booking['novoton_confirm_id'] ?: $booking['novoton_res_num'];
                
                if (!empty($reservation_id)) {
                    // Use the public getReservationInfo method to check booking status
                    $response = $api->getReservationInfo($reservation_id);
                    
                    if ($response && isset($response->Status)) {
                        $new_status = (string)$response->Status;
                        echo "  API Status: {$new_status}\n";
                        
                        if (strtolower($new_status) === 'confirmed' || strtolower($new_status) === 'ok') {
                            db_query(
                                "UPDATE ?:novoton_bookings SET status = 'confirmed', novoton_status = 'OK', last_status_check = NOW(), updated_at = NOW() WHERE booking_id = ?i",
                                $booking['booking_id']
                            );
                            echo "  -> Updated to CONFIRMED\n";
                        } elseif (strtolower($new_status) === 'cancelled' || strtolower($new_status) === 'rejected') {
                            db_query(
                                "UPDATE ?:novoton_bookings SET status = 'cancelled', novoton_status = 'CX', last_status_check = NOW(), updated_at = NOW() WHERE booking_id = ?i",
                                $booking['booking_id']
                            );
                            echo "  -> Updated to CANCELLED\n";
                        } else {
                            // Update last check time even if status unchanged
                            db_query(
                                "UPDATE ?:novoton_bookings SET last_status_check = NOW() WHERE booking_id = ?i",
                                $booking['booking_id']
                            );
                            echo "  -> Status unchanged: {$new_status}\n";
                        }
                    } else {
                        echo "  No response from API\n";
                    }
                } else {
                    echo "  No reservation ID - skipping\n";
                }
            }
        }

        // Send email report
        $duration = round(microtime(true) - $cron_start_time, 1) . 's';
        fn_novoton_send_import_report_email([], 'resinfo', [
            'updated'  => isset($ask_bookings) ? count($ask_bookings) : 0,
            'duration' => $duration,
        ]);
    }
    
    // =========================================
    // MODE: hotel_list
    // =========================================
    elseif ($mode == 'hotel_list') {
        echo "Syncing hotels from API (hotel_list)...\n\n";

        // Get countries from settings (or all if none selected)
        $countries = fn_novoton_parse_countries();

        echo "Countries: " . implode(', ', $countries) . "\n";
        if (count($countries) > 3) {
            echo "(All available countries - none specifically selected in settings)\n";
        }
        echo "\n";

        $total_hotels = 0;
        $synced_hotels = 0;
        $new_hotels = 0;

        foreach ($countries as $country) {
            echo "Fetching {$country}... ";

            $hotels = $api->getHotelList($country);

            if (!empty($hotels)) {
                $count = count($hotels);
                $total_hotels += $count;
                echo "{$count} hotels\n";

                foreach ($hotels as $hotel) {
                    $hotel_id = (string)($hotel->IdHotel ?? '');
                    $hotel_name = (string)($hotel->Hotel ?? '');
                    $city = (string)($hotel->City ?? '');

                    if (empty($hotel_id)) continue;

                    $exists = db_get_field("SELECT hotel_id FROM ?:novoton_hotels WHERE hotel_id = ?s", $hotel_id);

                    // Extract all available fields from hotel_list response
                    $hotelType = (string)($hotel->HotelType ?? '');

                    $data = [
                        'hotel_id' => $hotel_id,
                        'hotel_name' => $hotel_name,
                        'city' => $city,
                        'region' => (string)($hotel->Region ?? ''),
                        'country' => (string)($hotel->Country ?? $country),
                        'hotel_type' => $hotelType,
                        'latitude' => (string)($hotel->Lat ?? ''),
                        'longitude' => (string)($hotel->Lng ?? ''),
                        'hotel_list_synced_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ];

                    if ($exists) {
                        db_query("UPDATE ?:novoton_hotels SET ?u WHERE hotel_id = ?s", $data, $hotel_id);
                    } else {
                        $data['created_at'] = date('Y-m-d H:i:s');
                        db_query("INSERT INTO ?:novoton_hotels ?e", $data);
                        $new_hotels++;
                    }

                    $synced_hotels++;
                    echo "  [{$hotel_id}] {$hotel_name} | {$city} | {$data['region']} | {$hotelType}";
                    if (!empty($data['latitude'])) echo " | {$data['latitude']},{$data['longitude']}";
                    echo "\n";
                }
            } else {
                echo "0 hotels (or error)\n";
            }
        }

        echo "\nTotal hotels: {$total_hotels}\n";
        echo "Synced: {$synced_hotels} (new: {$new_hotels})\n";

        // Send email report
        $duration = round(microtime(true) - $cron_start_time, 1) . 's';
        fn_novoton_send_import_report_email([], 'hotel_list', [
            'added'    => $new_hotels,
            'updated'  => $synced_hotels - $new_hotels,
            'duration' => $duration,
        ], implode(', ', $countries));
    }
    
    // =========================================
    // MODE: update_prices
    // =========================================
    elseif ($mode == 'update_prices') {
        echo "Updating hotel prices...\n\n";
        echo "This mode is resource-intensive. Use admin panel for full price update.\n";
        echo "URL: admin.php?dispatch=novoton_holidays.update_prices\n";
    }
    
    // =========================================
    // MODE: room_price
    // =========================================
    elseif ($mode == 'room_price') {
        $check_in = $_REQUEST['check_in'] ?? date('Y-m-d', strtotime('+7 days'));
        $nights = intval($_REQUEST['nights'] ?? 7);
        $limit = intval($_REQUEST['limit'] ?? 500);
        $country = strtoupper($_REQUEST['country'] ?? '');
        $check_out = date('Y-m-d', strtotime($check_in . ' + ' . $nights . ' days'));

        echo "Checking hotels with active prices...\n";
        echo "Check-in: {$check_in}, Check-out: {$check_out}, Nights: {$nights}, Limit: {$limit}\n";
        if ($country) echo "Country: {$country}\n";
        echo "\n";

        $where = $country ? db_quote("WHERE country = ?s", $country) : "";
        $hotels = db_get_array(
            "SELECT hotel_id, hotel_name, country FROM ?:novoton_hotels {$where} ORDER BY country, hotel_name LIMIT ?i",
            $limit
        );

        $with_prices = 0;
        $without_prices = 0;

        foreach ($hotels as $idx => $hotel) {
            $params = [
                'hotel_id' => $hotel['hotel_id'],
                'check_in' => $check_in,
                'check_out' => $check_out,
                'adults' => 2,
                'children' => 0
            ];

            $response = $api->getRoomPrice($params);

            // Find any Price element in the response (can be nested)
            $best_price = 0;
            if ($response instanceof \SimpleXMLElement) {
                $prices = $response->xpath('//Price');
                if (!empty($prices)) {
                    foreach ($prices as $p) {
                        $pv = floatval((string)$p);
                        if ($pv > 0 && ($best_price == 0 || $pv < $best_price)) {
                            $best_price = $pv;
                        }
                    }
                }
            }

            if ($best_price > 0) {
                $with_prices++;
                db_query("UPDATE ?:novoton_hotels SET has_prices = 'Y', last_price_check = NOW() WHERE hotel_id = ?s", $hotel['hotel_id']);
                echo "NVT-{$hotel['hotel_id']} | {$hotel['hotel_name']} - EUR " . number_format($best_price, 2) . "\n";
            } else {
                $without_prices++;
                db_query("UPDATE ?:novoton_hotels SET has_prices = 'N', last_price_check = NOW() WHERE hotel_id = ?s", $hotel['hotel_id']);
            }

            if (($idx + 1) % 25 == 0) {
                echo "Checked " . ($idx + 1) . "/" . count($hotels) . " hotels...\n";
            }

            usleep(100000); // 100ms delay
        }

        echo "\nResults:\n";
        echo "Hotels WITH prices: {$with_prices}\n";
        echo "Hotels WITHOUT prices: {$without_prices}\n";
        echo "Total checked: " . ($with_prices + $without_prices) . "\n";

        // Send email report
        $duration = round(microtime(true) - $cron_start_time, 1) . 's';
        fn_novoton_send_import_report_email([], 'room_price', [
            'updated'  => $with_prices,
            'skipped'  => $without_prices,
            'duration' => $duration,
        ], $country ?: 'ALL');
    }
    
    // =========================================
    // MODE: alternative_rs
    // =========================================
    elseif ($mode == 'alternative_rs') {
        echo "Checking alternative_RS for pending requests...\n\n";
        
        $pending_requests = db_get_array(
            "SELECT * FROM ?:novoton_alternative_requests 
             WHERE status = 'pending' 
             AND novoton_request_id IS NOT NULL 
             AND novoton_request_id != ''
             AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
             ORDER BY created_at ASC
             LIMIT 50"
        );
        
        if (empty($pending_requests)) {
            echo "No pending requests older than 24 hours found.\n";
        } else {
            echo "Found " . count($pending_requests) . " pending requests to check.\n\n";
            
            $found_count = 0;
            $checked_count = 0;
            $emailed_count = 0;
            
            foreach ($pending_requests as $request) {
                $checked_count++;
                echo "Checking request #{$request['request_id']} (IdNum: {$request['novoton_request_id']})... ";
                
                $response = $api->getAlternatives($request['novoton_request_id']);
                
                if ($response && isset($response->alternative)) {
                    $alternatives = [];
                    foreach ($response->alternative as $alt) {
                        $alternatives[] = [
                            'res_num' => (string)($alt->ResNum ?? ''),
                            'hotel_id' => (string)($alt->IdHotel ?? ''),
                            'package_name' => (string)($alt->PackageName ?? ''),
                            'room_id' => (string)($alt->IdRoom ?? ''),
                            'check_in' => (string)($alt->CheckIn ?? ''),
                            'check_out' => (string)($alt->CheckOut ?? ''),
                            'board_id' => (string)($alt->IdBoard ?? ''),
                            'quota' => (string)($alt->Quota ?? ''),
                            'total' => (string)($alt->Total ?? '')
                        ];
                    }
                    
                    if (!empty($alternatives)) {
                        // Update database
                        db_query(
                            "UPDATE ?:novoton_alternative_requests SET alternatives_data = ?s, status = 'alternatives_found', updated_at = NOW() WHERE request_id = ?i",
                            json_encode($alternatives),
                            $request['request_id']
                        );
                        $found_count++;
                        echo "FOUND " . count($alternatives) . " alternatives";
                        
                        // A73r: Send email notification to customer
                        if (!empty($request['contact_email'])) {
                            try {
                                // Get company data for email
                                $company_data = fn_get_company_data(fn_get_default_company_id());
                                
                                // Prepare email data
                                $mail_data = [
                                    'hotel_name' => $request['hotel_name'],
                                    'check_in' => $request['check_in'],
                                    'check_out' => $request['check_out'],
                                    'nights' => $request['nights'],
                                    'adults' => $request['adults'],
                                    'children' => $request['children'],
                                    'alternatives' => $alternatives,
                                    'request_id' => $request['request_id'],
                                    'company_data' => $company_data
                                ];
                                
                                // Send email using CS-Cart mailer
                                $mailer = Tygh::$app['mailer'];
                                $result = $mailer->send([
                                    'to' => $request['contact_email'],
                                    'from' => 'default_company_orders_department',
                                    'data' => $mail_data,
                                    'template_code' => 'novoton_alternatives_available',
                                    'tpl' => 'addons/novoton_holidays/email/alternatives_available.tpl'
                                ], 'A');
                                
                                if ($result) {
                                    $emailed_count++;
                                    echo " → Email sent to {$request['contact_email']}";
                                    
                                    // Mark as notified
                                    db_query(
                                        "UPDATE ?:novoton_alternative_requests SET status = 'notified', notified_at = NOW() WHERE request_id = ?i",
                                        $request['request_id']
                                    );
                                } else {
                                    echo " → Email FAILED";
                                }
                            } catch (Exception $e) {
                                echo " → Email ERROR: " . $e->getMessage();
                            }
                        }
                        echo "\n";
                    } else {
                        echo "no alternatives yet\n";
                    }
                } else {
                    echo "no response\n";
                }
                
                usleep(200000); // 200ms delay
            }
            
            echo "\nChecked: {$checked_count}\n";
            echo "Found alternatives: {$found_count}\n";
            echo "Emails sent: {$emailed_count}\n";
        }
    }
    
    // =========================================
    // MODE: alternative_rs_bookings
    // =========================================
    elseif ($mode == 'alternative_rs_bookings') {
        echo "Checking alternatives for RQ status bookings...\n\n";
        
        $rq_bookings = db_get_array(
            "SELECT * FROM ?:novoton_bookings 
             WHERE novoton_status = 'RQ' 
             AND alternatives_requested = 0
             ORDER BY created_at ASC
             LIMIT 50"
        );
        
        if (empty($rq_bookings)) {
            echo "No RQ bookings to check.\n";
        } else {
            echo "Found " . count($rq_bookings) . " RQ bookings to check.\n\n";
            
            foreach ($rq_bookings as $booking) {
                echo "Booking #{$booking['booking_id']}... ";
                
                if (!empty($booking['novoton_reservation_id'])) {
                    $response = $api->getAlternatives($booking['novoton_reservation_id']);
                    
                    db_query(
                        "UPDATE ?:novoton_bookings SET alternatives_requested = 1 WHERE booking_id = ?i",
                        $booking['booking_id']
                    );
                    
                    if ($response && isset($response->alternative)) {
                        echo "alternatives available\n";
                    } else {
                        echo "no alternatives yet\n";
                    }
                } else {
                    echo "no reservation ID\n";
                }
                
                usleep(200000);
            }
        }
    }
    
    // =========================================
    // MODE: notify_alternatives
    // =========================================
    elseif ($mode == 'notify_alternatives') {
        echo "Sending notifications for found alternatives...\n\n";
        
        $requests = db_get_array(
            "SELECT * FROM ?:novoton_alternative_requests 
             WHERE status = 'alternatives_found' 
             ORDER BY updated_at ASC
             LIMIT 20"
        );
        
        if (empty($requests)) {
            echo "No requests with alternatives to notify.\n";
        } else {
            echo "Found " . count($requests) . " requests to notify.\n\n";
            
            $notified = 0;
            
            foreach ($requests as $request) {
                echo "Request #{$request['request_id']} ({$request['contact_email']})... ";
                
                $alternatives = json_decode($request['alternatives_data'], true);
                
                if (!empty($alternatives)) {
                    $mail_data = [
                        'request' => $request,
                        'alternatives' => $alternatives,
                        'hotel_name' => $request['hotel_name'],
                        'check_in' => $request['check_in'],
                        'check_out' => $request['check_out']
                    ];
                    
                    $mailer = \Tygh::$app['mailer'];
                    $result = $mailer->send([
                        'to' => $request['contact_email'],
                        'from' => 'default_company_orders_department',
                        'data' => $mail_data,
                        'template_code' => 'novoton_alternatives_available',
                        'tpl' => 'addons/novoton_holidays/email/alternatives_available.tpl'
                    ], 'A');
                    
                    if ($result) {
                        db_query(
                            "UPDATE ?:novoton_alternative_requests SET status = 'notified', notified_at = NOW() WHERE request_id = ?i",
                            $request['request_id']
                        );
                        $notified++;
                        echo "SENT\n";
                    } else {
                        echo "FAILED\n";
                    }
                } else {
                    echo "no alternatives data\n";
                }
            }
            
            echo "\nNotified: {$notified}\n";
        }
    }
    
    // =========================================
    // MODE: expire_requests
    // =========================================
    elseif ($mode == 'expire_requests') {
        $days = intval($_REQUEST['days'] ?? 30);
        
        echo "Expiring requests older than {$days} days...\n\n";
        
        $result = db_query(
            "UPDATE ?:novoton_alternative_requests 
             SET status = 'expired', updated_at = NOW() 
             WHERE status IN ('pending', 'pending_manual') 
             AND created_at < DATE_SUB(NOW(), INTERVAL ?i DAY)",
            $days
        );
        
        echo "Expired {$result} requests.\n";
    }
    
    // =========================================
    // MODE: offers_update
    // =========================================
    elseif ($mode == 'offers_update') {
        echo "Checking for new/updated offers (offers_update API)...\n\n";
        
        $country = strtoupper($_REQUEST['country'] ?? 'BULGARIA');
        
        // Get last product import time from sync_log (requires prior Add Hotels as Products)
        $last_product_import = db_get_field("SELECT sync_date FROM ?:novoton_sync_log WHERE sync_type = 'product_import' AND status = 'completed' ORDER BY log_id DESC LIMIT 1");
        
        if (empty($last_product_import)) {
            echo "ERROR: No previous product import found!\n\n";
            echo "The offers_update cron mode requires that you first run:\n";
            echo "  'Add Hotels as CS-Cart Products' (Create New Products Only)\n\n";
            echo "This establishes the baseline timestamp used to determine which hotels are 'new'.\n";
            echo "Workflow:\n";
            echo "  Step 1: Run 'Add Hotels as Products' manually (first time)\n";
            echo "  Step 2: Then use 'offers_update' for daily/weekly incremental updates\n\n";
            echo "Run Step 1 via: Admin > Novoton Holidays > Tools & Cron > Add Hotels as Products\n";
            exit;
        }
        
        echo "Country: {$country}\n";
        echo "Last product import: {$last_product_import}\n";
        echo "Checking offers added/modified after this time...\n\n";
        
        $sync_start_time = date('Y-m-d\TH:i:s');
        
        $response = $api->getOffersUpdate($last_product_import, $country);
        
        if (!$response || !isset($response->Offer)) {
            echo "No new offers found.\n";
        } else {
            $offers = is_array($response->Offer) ? $response->Offer : [$response->Offer];
            echo "Found " . count($offers) . " offers to check.\n\n";
            
            $new_hotels = 0;
            $added_to_cart = 0;
            $current_year = date('Y');
            $image_base_url = 'https://booking.allinclusive.bg';
            
            foreach ($offers as $offer) {
                $hotel_id = (string)($offer->IdHotel ?? '');
                $hotel_name = (string)($offer->PackageName ?? $offer->Hotel ?? '');
                
                if (empty($hotel_id)) continue;
                
                echo "[{$hotel_id}] {$hotel_name} ... ";
                
                $existing = db_get_row("SELECT * FROM ?:novoton_hotels WHERE hotel_id = ?s", $hotel_id);
                
                if (!$existing) {
                    echo "NEW HOTEL - ";
                    
                    $hotel_info = $api->getHotelInfo($hotel_id);
                    if ($hotel_info) {
                        // hotelinfo response root IS <hotel>, properties are direct children
                        $hotel_data = [
                            'hotel_id' => $hotel_id,
                            'hotel_name' => (string)($hotel_info->Hotel ?? $hotel_name),
                            'package_name' => (string)($hotel_info->packages->PackageName ?? ''),
                            'city' => (string)($hotel_info->City ?? ''),
                            'region' => (string)($hotel_info->Region ?? ''),
                            'country' => (string)($hotel_info->Country ?? $country),
                            'hotel_type' => (string)($hotel_info->HotelType ?? $hotel_info->Stars ?? ''),
                            'has_prices' => 'N',
                            'hotel_list_synced_at' => date('Y-m-d H:i:s')
                        ];
                        
                        db_query("INSERT INTO ?:novoton_hotels ?e ON DUPLICATE KEY UPDATE ?u", $hotel_data, $hotel_data);
                        $new_hotels++;
                        $existing = $hotel_data;
                        echo "synced - ";
                    }
                }
                
                // Check if hotel has active prices
                if ($existing && ($existing['has_prices'] != 'Y' || empty($existing['last_price_check']))) {
                    $check_in = date('Y-m-d', strtotime('+30 days'));
                    $check_out = date('Y-m-d', strtotime('+37 days'));
                    
                    $price_response = $api->getRoomPrice($hotel_id, $check_in, $check_out, 2, 0, 1);
                    $has_prices = ($price_response && isset($price_response->hotel)) ? 'Y' : 'N';
                    
                    db_query(
                        "UPDATE ?:novoton_hotels SET has_prices = ?s, last_price_check = NOW() WHERE hotel_id = ?s",
                        $has_prices, $hotel_id
                    );
                    
                    $existing['has_prices'] = $has_prices;
                    echo ($has_prices == 'Y' ? 'has prices - ' : 'no prices - ');
                }
                
                // Check if should add to CS-Cart
                if ($existing && $existing['has_prices'] == 'Y' && empty($existing['product_id'])) {
                    $product_code = 'NVT' . $hotel_id;
                    
                    $existing_product = db_get_field("SELECT product_id FROM ?:products WHERE product_code = ?s", $product_code);
                    if ($existing_product) {
                        db_query("UPDATE ?:novoton_hotels SET product_id = ?i WHERE hotel_id = ?s", $existing_product, $hotel_id);
                        echo "linked to existing product\n";
                        continue;
                    }
                    
                    $category_path = "{$country}///Litoral {$country}";
                    $category_id = fn_novoton_get_or_create_category($category_path);
                    
                    $page_title = fn_novoton_build_hotel_title(
                        $existing['hotel_name'] ?? $hotel_name,
                        $existing['city'] ?? '',
                        $existing['country'] ?? $country,
                        $current_year
                    );
                    
                    $description = '';
                    try {
                        $desc_response = $api->getHotelDescription($hotel_id, 'UK');
                        if ($desc_response && isset($desc_response->Description)) {
                            $description = (string)$desc_response->Description;
                        }
                    } catch (Exception $e) {}
                    
                    $product_data = [
                        'product' => $existing['hotel_name'] ?? $hotel_name,
                        'product_code' => $product_code,
                        'price' => 0,
                        'status' => 'D',
                        'company_id' => Registry::get('runtime.company_id') ?: 1,
                        'main_category' => $category_id,
                        'category_ids' => [$category_id],
                        'full_description' => $description,
                        'page_title' => $page_title,
                        'meta_description' => $page_title,
                    ];
                    
                    $product_id = fn_update_product($product_data, 0, CART_LANGUAGE);
                    
                    if ($product_id) {
                        db_query("UPDATE ?:novoton_hotels SET product_id = ?i WHERE hotel_id = ?s", $product_id, $hotel_id);
                        
                        try {
                            $images_response = $api->getHotelImages($hotel_id);
                            if ($images_response && isset($images_response->url)) {
                                $img_count = 0;
                                foreach ($images_response->url as $url) {
                                    $image_url = $image_base_url . str_replace(' ', '%20', (string)$url);
                                    fn_novoton_add_product_image($product_id, $image_url, $img_count == 0);
                                    $img_count++;
                                    if ($img_count >= 10) break;
                                }
                            }
                        } catch (Exception $e) {}
                        
                        // Sync facilities
                        try {
                            fn_novoton_sync_hotel_facilities($hotel_id);
                        } catch (Exception $e) {}
                        
                        $added_to_cart++;
                        echo "ADDED TO CART (ID: {$product_id})\n";
                    } else {
                        echo "failed to create product\n";
                    }
                } else {
                    echo "OK\n";
                }
                
                usleep(100000);
            }
            
            echo "\nNew hotels synced: {$new_hotels}\n";
            echo "Added to CS-Cart: {$added_to_cart}\n";

            // Send email report
            $duration = round(microtime(true) - $cron_start_time, 1) . 's';
            fn_novoton_send_import_report_email([], 'offers_update', [
                'added'    => $added_to_cart,
                'updated'  => $new_hotels,
                'duration' => $duration,
            ], $country);
        }

        // Save sync timestamp for future offers_update calls
        if (isset($sync_start_time)) {
            db_query("INSERT INTO ?:novoton_sync_log (sync_type, sync_date, status, products_updated) VALUES ('product_import', NOW(), 'completed', ?i)", 
                $added_to_cart ?? 0
            );
            echo "\nSync timestamp saved: {$sync_start_time}\n";
        }
    }
    
    // =========================================
    // MODE: add_hotels_as_products
    // =========================================
    elseif ($mode == 'add_hotels_as_products') {
        $country = strtoupper($_REQUEST['country'] ?? 'BULGARIA');
        $limit = intval($_REQUEST['limit'] ?? 50);
        
        // Get excluded resorts - priority: URL parameter > addon setting
        $exclude_resorts = [];
        
        if (!empty($_REQUEST['exclude_resorts'])) {
            // URL parameter provided (override setting)
            if (is_array($_REQUEST['exclude_resorts'])) {
                $exclude_resorts = $_REQUEST['exclude_resorts'];
            } else {
                // Comma-separated string
                $exclude_resorts = array_map('trim', explode(',', $_REQUEST['exclude_resorts']));
            }
            $exclude_resorts = array_filter($exclude_resorts);
            echo "Using URL-provided exclusions\n";
        } else {
            // Read from addon setting
            $setting_value = Registry::get('addons.novoton_holidays.excluded_resorts') ?? '[]';
            if (!empty($setting_value)) {
                $decoded = json_decode($setting_value, true);
                if (is_array($decoded)) {
                    $exclude_resorts = $decoded;
                } else {
                    // Try comma-separated (legacy)
                    $exclude_resorts = array_map('trim', explode(',', $setting_value));
                }
                $exclude_resorts = array_filter($exclude_resorts);
            }
            if (!empty($exclude_resorts)) {
                echo "Using exclusions from addon settings\n";
            }
        }
        
        echo "Adding hotels as products...\n\n";
        echo "Country: {$country}\n";
        echo "Limit: {$limit}\n";
        if (!empty($exclude_resorts)) {
            echo "Excluding resorts (" . count($exclude_resorts) . "): " . implode(', ', $exclude_resorts) . "\n";
        } else {
            echo "No resort exclusions configured\n";
        }
        echo "\n";
        
        // Build query with resort exclusion
        $query = "SELECT * FROM ?:novoton_hotels 
                  WHERE has_prices = 'Y' 
                  AND country = ?s 
                  AND (product_id IS NULL OR product_id = 0)";
        
        $query_params = [$country];
        
        if (!empty($exclude_resorts)) {
            $query .= " AND (city NOT IN (?a) OR city IS NULL)";
            $query_params[] = $exclude_resorts;
        }
        
        $query .= " ORDER BY hotel_name LIMIT ?i";
        $query_params[] = $limit;
        
        $hotels = db_get_array($query, ...$query_params);
        
        echo "Found " . count($hotels) . " hotels to add.\n\n";
        
        if (empty($hotels)) {
            echo "No hotels to add.\n";
        } else {
            $category_path = "{$country}///Litoral {$country}";
            $category_id = fn_novoton_get_or_create_category($category_path);
            $current_year = date('Y');
            $image_base_url = 'https://booking.allinclusive.bg';
            $added = 0;
            
            foreach ($hotels as $hotel) {
                $hotel_id = $hotel['hotel_id'];
                $product_code = 'NVT' . $hotel_id;
                
                echo "[{$hotel_id}] {$hotel['hotel_name']} ({$hotel['city']}) ... ";
                
                $existing = db_get_field("SELECT product_id FROM ?:products WHERE product_code = ?s", $product_code);
                if ($existing) {
                    db_query("UPDATE ?:novoton_hotels SET product_id = ?i WHERE hotel_id = ?s", $existing, $hotel_id);
                    echo "LINKED\n";
                    continue;
                }
                
                $page_title = fn_novoton_build_hotel_title($hotel['hotel_name'], $hotel['city'], $hotel['country'], $current_year);
                
                $description = '';
                try {
                    $desc_response = $api->getHotelDescription($hotel_id, 'UK');
                    if ($desc_response && isset($desc_response->Description)) {
                        $description = (string)$desc_response->Description;
                    }
                } catch (Exception $e) {}
                
                $product_data = [
                    'product' => $hotel['hotel_name'],
                    'product_code' => $product_code,
                    'price' => 0,
                    'status' => 'D',
                    'company_id' => Registry::get('runtime.company_id') ?: 1,
                    'main_category' => $category_id,
                    'category_ids' => [$category_id],
                    'full_description' => $description,
                    'page_title' => $page_title,
                    'meta_description' => $page_title,
                ];
                
                $product_id = fn_update_product($product_data, 0, CART_LANGUAGE);
                
                if ($product_id) {
                    db_query("UPDATE ?:novoton_hotels SET product_id = ?i WHERE hotel_id = ?s", $product_id, $hotel_id);
                    
                    try {
                        $images_response = $api->getHotelImages($hotel_id);
                        if ($images_response && isset($images_response->url)) {
                            $img_count = 0;
                            foreach ($images_response->url as $url) {
                                $image_url = $image_base_url . str_replace(' ', '%20', (string)$url);
                                fn_novoton_add_product_image($product_id, $image_url, $img_count == 0);
                                $img_count++;
                                if ($img_count >= 10) break;
                            }
                        }
                    } catch (Exception $e) {}
                    
                    // Sync facilities
                    try {
                        fn_novoton_sync_hotel_facilities($hotel_id);
                    } catch (Exception $e) {}
                    
                    $added++;
                    echo "ADDED (ID: {$product_id})\n";
                } else {
                    echo "FAILED\n";
                }
                
                usleep(100000);
            }
            
            echo "\nAdded: {$added}\n";

            // Send email report
            $duration = round(microtime(true) - $cron_start_time, 1) . 's';
            fn_novoton_send_import_report_email([], 'add_products', [
                'added'    => $added,
                'skipped'  => count($hotels) - $added,
                'duration' => $duration,
            ], $country);
        }
    }

    // =========================================
    // MODE: list_facilities
    // =========================================
    elseif ($mode == 'list_facilities') {
        echo "Syncing facilities list from Novoton API...\n\n";
        
        $result = fn_novoton_sync_facilities_list();
        
        $fac_added = 0;
        $fac_updated = 0;
        $fac_errors = 0;

        if (is_array($result)) {
            if (!empty($result['success'])) {
                $fac_added = $result['added'] ?? 0;
                $fac_updated = $result['updated'] ?? 0;
                echo "Synced {$result['total']} facilities ({$fac_added} added, {$fac_updated} updated).\n";
            } else {
                $fac_errors = 1;
                echo "Error: " . ($result['error'] ?? 'Unknown error') . "\n";
            }
        } else {
            $fac_updated = intval($result);
            echo "Synced {$result} facilities.\n";
        }

        // Send email report
        $duration = round(microtime(true) - $cron_start_time, 1) . 's';
        fn_novoton_send_import_report_email([], 'facilities', [
            'added'    => $fac_added,
            'updated'  => $fac_updated,
            'errors'   => $fac_errors,
            'duration' => $duration,
        ]);
    }
    
    // =========================================
    // MODE: hotel_info (Hotel accommodation)
    // Fetches hotelinfo API per hotel to populate
    // rooms_data, board_data, packages_data, ages_data
    // On subsequent runs uses offers_update to only
    // refresh hotels that have changed.
    // =========================================
    elseif ($mode == 'hotel_info') {
        $force = !empty($_REQUEST['force']);
        $limit = intval($_REQUEST['limit'] ?? 500);
        $addon_settings = Registry::get('addons.novoton_holidays') ?? [];
        $countries = [];

        // Parse countries from settings
        if (!empty($addon_settings['selected_countries'])) {
            $sel = $addon_settings['selected_countries'];
            if (is_array($sel)) {
                foreach ($sel as $k => $v) {
                    if ($v === 'Y' || $v === '1' || $v === 1) {
                        $countries[] = strtoupper(trim($k));
                    } elseif (is_string($v) && strlen($v) > 2) {
                        $countries[] = strtoupper(trim($v));
                    }
                }
            } elseif (is_string($sel)) {
                $countries = array_map(function($c) { return strtoupper(trim($c)); }, explode(',', $sel));
            }
        }
        $countries = array_filter($countries);
        if (empty($countries)) {
            $countries = ['BULGARIA', 'GREECE', 'TURKEY', 'EGYPT', 'CYPRUS', 'SPAIN', 'ALBANIA', 'ITALY', 'MALDIVES', 'FRANCE', 'UNITED ARAB EMIRATES', 'UNITED KINGDOM'];
        }

        echo "Hotel Accommodation Sync (hotelinfo)\n";
        echo "Countries: " . implode(', ', $countries) . "\n";
        echo "Force full sync: " . ($force ? 'YES' : 'NO') . "\n";
        echo "Limit: {$limit}\n\n";

        // Determine which hotels to sync
        $hotel_ids_to_sync = [];

        if (!$force) {
            // Check if we have any previous hotelinfo sync
            $last_hotelinfo_sync = db_get_field(
                "SELECT MAX(hotelinfo_synced_at) FROM ?:novoton_hotels WHERE hotelinfo_synced_at IS NOT NULL"
            );

            if (!empty($last_hotelinfo_sync)) {
                // Incremental: use offers_update API to find changed hotels
                $datetime_param = date('Y-m-d\TH:i:s', strtotime($last_hotelinfo_sync));
                echo "Last hotelinfo sync: {$last_hotelinfo_sync}\n";
                echo "Calling offers_update since {$datetime_param}...\n\n";

                $changed_ids = [];
                foreach ($countries as $country) {
                    $response = $api->getOffersUpdate($datetime_param, $country);
                    if ($response && isset($response->Offer)) {
                        foreach ($response->Offer as $offer) {
                            $hid = (string)($offer->IdHotel ?? '');
                            if (!empty($hid)) {
                                $changed_ids[$hid] = true;
                            }
                        }
                    }
                }

                if (!empty($changed_ids)) {
                    $hotel_ids_to_sync = array_keys($changed_ids);
                    echo "offers_update returned " . count($hotel_ids_to_sync) . " changed hotel(s).\n\n";
                } else {
                    echo "offers_update returned 0 changes. Nothing to sync.\n";
                }

                // Also include hotels that have never had hotelinfo synced
                $unsyced = db_get_fields(
                    "SELECT hotel_id FROM ?:novoton_hotels WHERE hotelinfo_synced_at IS NULL AND country IN (?a) LIMIT ?i",
                    $countries, $limit
                );
                if (!empty($unsyced)) {
                    echo "Also syncing " . count($unsyced) . " hotel(s) that never had hotelinfo.\n\n";
                    $hotel_ids_to_sync = array_unique(array_merge($hotel_ids_to_sync, $unsyced));
                }
            } else {
                // First run ever — sync all hotels
                echo "First run: syncing all hotels...\n\n";
                $hotel_ids_to_sync = db_get_fields(
                    "SELECT hotel_id FROM ?:novoton_hotels WHERE country IN (?a) ORDER BY hotel_name LIMIT ?i",
                    $countries, $limit
                );
            }
        } else {
            // Force: re-sync all hotels
            echo "Forced full sync: fetching all hotels...\n\n";
            $hotel_ids_to_sync = db_get_fields(
                "SELECT hotel_id FROM ?:novoton_hotels WHERE country IN (?a) ORDER BY hotel_name LIMIT ?i",
                $countries, $limit
            );
        }

        if (empty($hotel_ids_to_sync)) {
            echo "No hotels to sync.\n";
        } else {
            echo "Processing " . count($hotel_ids_to_sync) . " hotel(s)...\n\n";

            // Apply limit
            if (count($hotel_ids_to_sync) > $limit) {
                $hotel_ids_to_sync = array_slice($hotel_ids_to_sync, 0, $limit);
                echo "(Limited to {$limit})\n\n";
            }

            $synced = 0;
            $errors = 0;
            $now = date('Y-m-d H:i:s');

            foreach ($hotel_ids_to_sync as $hotel_id) {
                $hotel_name = db_get_field("SELECT hotel_name FROM ?:novoton_hotels WHERE hotel_id = ?s", $hotel_id);
                echo "[{$hotel_id}] " . ($hotel_name ?: '?') . " ... ";

                try {
                    $hotel_info = $api->getHotelInfo($hotel_id);

                    if (!$hotel_info) {
                        echo "API returned empty\n";
                        $errors++;
                        continue;
                    }

                    $update = [
                        'hotelinfo_synced_at' => $now,
                    ];

                    // Extract package_name
                    $package_name = '';
                    if (isset($hotel_info->packages->PackageName)) {
                        $package_name = (string)$hotel_info->packages->PackageName;
                    } elseif (isset($hotel_info->packages->Package)) {
                        $package_name = (string)$hotel_info->packages->Package;
                    }
                    if (empty($package_name)) {
                        $pn = $hotel_info->xpath('//PackageName');
                        if (!empty($pn)) {
                            $package_name = (string)$pn[0];
                        }
                    }
                    if (!empty($package_name)) {
                        $update['package_name'] = $package_name;
                    }

                    // Extract rooms_data: IdRoom, Type, RB, EB, maxADT, maxCHD, minPAX
                    $rooms = [];
                    if (isset($hotel_info->rooms) && isset($hotel_info->rooms->room)) {
                        foreach ($hotel_info->rooms->room as $room) {
                            $rooms[] = [
                                'IdRoom' => (string)($room->IdRoom ?? ''),
                                'Type' => (string)($room->Type ?? $room->Room ?? ''),
                                'RB' => (string)($room->RB ?? ''),
                                'EB' => (string)($room->EB ?? ''),
                                'maxADT' => intval($room->maxADT ?? 0),
                                'maxCHD' => intval($room->maxCHD ?? 0),
                                'minPAX' => intval($room->minPAX ?? 0),
                            ];
                        }
                    }
                    if (!empty($rooms)) {
                        $update['rooms_data'] = json_encode($rooms, JSON_UNESCAPED_UNICODE);
                    }

                    // Extract board_data: IdBoard, Board name
                    $boards = [];
                    if (isset($hotel_info->boards) && isset($hotel_info->boards->board)) {
                        foreach ($hotel_info->boards->board as $board) {
                            $boards[] = [
                                'IdBoard' => (string)($board->IdBoard ?? ''),
                                'Board' => (string)($board->Board ?? ''),
                            ];
                        }
                    }
                    if (!empty($boards)) {
                        $update['board_data'] = json_encode($boards, JSON_UNESCAPED_UNICODE);
                    }

                    // Extract packages_data
                    $packages = [];
                    if (isset($hotel_info->packages)) {
                        if (isset($hotel_info->packages->PackageName)) {
                            // Single package
                            $packages[] = [
                                'PackageName' => (string)$hotel_info->packages->PackageName,
                            ];
                        }
                        // Multiple packages
                        if (isset($hotel_info->packages->package)) {
                            foreach ($hotel_info->packages->package as $pkg) {
                                $packages[] = [
                                    'PackageName' => (string)($pkg->PackageName ?? $pkg ?? ''),
                                ];
                            }
                        }
                    }
                    if (!empty($packages)) {
                        $update['packages_data'] = json_encode($packages, JSON_UNESCAPED_UNICODE);
                    }

                    // Extract ages_data
                    $ages = [];
                    if (isset($hotel_info->ages) && isset($hotel_info->ages->age)) {
                        foreach ($hotel_info->ages->age as $age) {
                            $ages[] = [
                                'IdAge' => (string)($age->IdAge ?? ''),
                                'Age' => (string)($age->Age ?? ''),
                                'FromYear' => (string)($age->FromYear ?? ''),
                                'ToYear' => (string)($age->ToYear ?? ''),
                            ];
                        }
                    }
                    if (!empty($ages)) {
                        $update['ages_data'] = json_encode($ages, JSON_UNESCAPED_UNICODE);
                    }

                    db_query("UPDATE ?:novoton_hotels SET ?u WHERE hotel_id = ?s", $update, $hotel_id);
                    $synced++;

                    $parts = [];
                    if (!empty($rooms)) $parts[] = count($rooms) . ' rooms';
                    if (!empty($boards)) $parts[] = count($boards) . ' boards';
                    if (!empty($package_name)) $parts[] = 'pkg: ' . $package_name;
                    if (!empty($ages)) $parts[] = count($ages) . ' ages';
                    echo "OK (" . (empty($parts) ? 'no detail data' : implode(', ', $parts)) . ")\n";

                } catch (Exception $e) {
                    echo "ERROR: " . $e->getMessage() . "\n";
                    $errors++;
                }

                usleep(100000); // 100ms delay between API calls

                if ($synced % 50 == 0 && $synced > 0) {
                    echo "--- Progress: {$synced} synced ---\n";
                    flush();
                }
            }

            echo "\nSummary:\n";
            echo "Synced: {$synced}\n";
            echo "Errors: {$errors}\n";
            echo "Total processed: " . count($hotel_ids_to_sync) . "\n";

            // Send email report
            $duration = round(microtime(true) - $cron_start_time, 1) . 's';
            fn_novoton_send_import_report_email([], 'hotel_info', [
                'updated'  => $synced,
                'errors'   => $errors,
                'skipped'  => count($hotel_ids_to_sync) - $synced - $errors,
                'duration' => $duration,
            ], implode(', ', $countries));
        }
    }

    // =========================================
    // MODE: check_packages
    // =========================================
    elseif ($mode == 'check_packages') {
        echo "Check Hotel Packages (hotelinfo API)\n\n";

        // Get countries from addon settings
        $countries = fn_novoton_parse_countries();

        echo "Countries: " . implode(', ', $countries) . "\n\n";

        $hotels = db_get_array(
            "SELECT hotel_id, hotel_name, country, package_name FROM ?:novoton_hotels WHERE country IN (?a) ORDER BY country, hotel_name",
            $countries
        );

        if (empty($hotels)) {
            echo "No hotels found in database.\n";
        } else {
            echo "Processing " . count($hotels) . " hotels...\n\n";

            $total = 0;
            $with_packages = 0;
            $updated = 0;
            $errors = 0;
            $current_country = '';

            foreach ($hotels as $hotel) {
                $total++;

                if ($hotel['country'] !== $current_country) {
                    $current_country = $hotel['country'];
                    echo "\n--- {$current_country} ---\n";
                }

                try {
                    $hotel_info = $api->getHotelInfo($hotel['hotel_id']);

                    $package_name = '';
                    if ($hotel_info) {
                        if (isset($hotel_info->packages->PackageName)) {
                            $package_name = (string)$hotel_info->packages->PackageName;
                        } elseif (isset($hotel_info->packages->Package)) {
                            $package_name = (string)$hotel_info->packages->Package;
                        }
                        if (empty($package_name)) {
                            $pn = $hotel_info->xpath('//PackageName');
                            if (!empty($pn)) {
                                $package_name = (string)$pn[0];
                            }
                        }
                    }

                    if (!empty($package_name)) {
                        $with_packages++;
                        if ($package_name !== ($hotel['package_name'] ?? '')) {
                            db_query("UPDATE ?:novoton_hotels SET package_name = ?s WHERE hotel_id = ?s", $package_name, $hotel['hotel_id']);
                            $updated++;
                            echo "[{$hotel['hotel_id']}] {$hotel['hotel_name']} -> {$package_name} (updated)\n";
                        } else {
                            echo "[{$hotel['hotel_id']}] {$hotel['hotel_name']} -> {$package_name}\n";
                        }
                    } else {
                        echo "[{$hotel['hotel_id']}] {$hotel['hotel_name']} - no package\n";
                    }
                } catch (Exception $e) {
                    $errors++;
                    echo "[{$hotel['hotel_id']}] {$hotel['hotel_name']} - ERROR: " . $e->getMessage() . "\n";
                }

                usleep(100000); // 100ms delay

                if ($total % 50 == 0) {
                    echo "\n--- Progress: {$total}/" . count($hotels) . " ({$with_packages} with packages) ---\n";
                }
            }

            echo "\n\nSummary:\n";
            echo "Total checked: {$total}\n";
            echo "With packages: {$with_packages}\n";
            echo "Updated: {$updated}\n";
            echo "Errors: {$errors}\n";

            // Send email report
            $duration = round(microtime(true) - $cron_start_time, 1) . 's';
            fn_novoton_send_import_report_email([], 'check_packages', [
                'added'    => $with_packages,
                'updated'  => $updated,
                'skipped'  => $total - $with_packages - $errors,
                'errors'   => $errors,
                'duration' => $duration,
            ], implode(', ', $countries));
        }
    }

    // =========================================
    // UNKNOWN MODE
    // =========================================
    else {
        $mode_found = false;
        echo "Unknown mode: {$mode}\n";
        echo "\nAvailable modes:\n";
        echo "- resinfo: Check ASK bookings status\n";
        echo "- hotel_list: Hotel list sync from API\n";
        echo "- update_prices: Update hotel prices (use admin panel)\n";
        echo "- room_price: Check which hotels have active prices (fast resort-based)\n";
        echo "- alternative_rs: Check alternative_RS for pending requests (24h+)\n";
        echo "- alternative_rs_bookings: Check alternatives for RQ status bookings\n";
        echo "- notify_alternatives: Send email notifications for found alternatives\n";
        echo "- expire_requests: Expire old alternative requests (&days=30)\n";
        echo "- offers_update: Check for new/updated offers (&country=BULGARIA)\n";
        echo "- add_hotels_as_products: Add hotels as products (&country=BULGARIA&limit=50&exclude_resorts=RESORT1,RESORT2)\n";
        echo "- list_facilities: Sync facilities list from API\n";
        echo "- hotel_info: Sync hotel accommodation (rooms, boards, packages, ages) (&force=1&limit=500)\n";
        echo "- check_packages: Check PackageName for all hotels across selected countries\n";
        echo "\nExamples:\n";
        echo "  &mode=add_hotels_as_products&country=BULGARIA&limit=100\n";
        echo "  &mode=add_hotels_as_products&country=BULGARIA&exclude_resorts=GOLDEN+SANDS,ALBENA\n";
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n===========================================\n";
echo "Completed at: " . date('Y-m-d H:i:s') . "\n";
echo "===========================================\n";

exit;

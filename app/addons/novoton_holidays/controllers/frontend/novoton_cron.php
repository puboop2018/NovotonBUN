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
    }
    
    // =========================================
    // MODE: hotel_list
    // =========================================
    elseif ($mode == 'hotel_list') {
        echo "Syncing hotels from API (with hotelinfo details)...\n\n";

        // Get countries from settings (or all if none selected)
        $countries = fn_novoton_parse_countries();

        echo "Countries: " . implode(', ', $countries) . "\n";
        if (count($countries) > 3) {
            echo "(All available countries - none specifically selected in settings)\n";
        }
        echo "\n";

        $total_hotels = 0;
        $synced_hotels = 0;
        $detail_fetched = 0;
        $detail_errors = 0;

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
                    $data = [
                        'hotel_id' => $hotel_id,
                        'hotel_name' => $hotel_name,
                        'city' => $city,
                        'country' => $country,
                        'resort' => (string)($hotel->Resort ?? $hotel->City ?? ''),
                        'stars' => intval($hotel->Stars ?? 0),
                        'hotel_type' => (string)($hotel->HotelType ?? ''),
                        'updated_at' => date('Y-m-d H:i:s')
                    ];

                    // Fetch hotelinfo details for Region, Lat, Lng
                    $hotel_info = $api->getHotelInfo($hotel_id);
                    if ($hotel_info && isset($hotel_info->hotels->hotel)) {
                        $h = $hotel_info->hotels->hotel;
                        $data['region'] = (string)($h->Region ?? '');
                        // HotelType from hotelinfo (may be more detailed)
                        $ht = (string)($h->HotelType ?? '');
                        if (!empty($ht)) {
                            $data['hotel_type'] = $ht;
                        }
                        // Coordinates
                        $lat = (string)($h->Lat ?? '');
                        $lng = (string)($h->Lng ?? '');
                        if ($lat !== '') {
                            $data['latitude'] = $lat;
                        }
                        if ($lng !== '') {
                            $data['longitude'] = $lng;
                        }
                        $detail_fetched++;
                        echo "  [{$hotel_id}] {$hotel_name} - details OK";
                        if (!empty($data['region'])) echo " | Region: {$data['region']}";
                        if ($lat !== '') echo " | Lat: {$lat}";
                        if ($lng !== '') echo " | Lng: {$lng}";
                        echo "\n";
                    } else {
                        $detail_errors++;
                        echo "  [{$hotel_id}] {$hotel_name} - hotelinfo failed\n";
                    }

                    if ($exists) {
                        db_query("UPDATE ?:novoton_hotels SET ?u WHERE hotel_id = ?s", $data, $hotel_id);
                    } else {
                        $data['created_at'] = date('Y-m-d H:i:s');
                        db_query("INSERT INTO ?:novoton_hotels ?e", $data);
                    }

                    $synced_hotels++;
                }
            } else {
                echo "0 hotels (or error)\n";
            }
        }

        echo "\nTotal hotels: {$total_hotels}\n";
        echo "Synced: {$synced_hotels}\n";
        echo "Hotelinfo details fetched: {$detail_fetched}\n";
        if ($detail_errors > 0) {
            echo "Hotelinfo errors: {$detail_errors}\n";
        }
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
                    if ($hotel_info && isset($hotel_info->hotels->hotel)) {
                        $h = $hotel_info->hotels->hotel;
                        $hotel_data = [
                            'hotel_id' => $hotel_id,
                            'hotel_name' => (string)($h->Hotel ?? $hotel_name),
                            'package_name' => (string)($h->PackageName ?? ''),
                            'city' => (string)($h->City ?? ''),
                            'region' => (string)($h->Region ?? ''),
                            'country' => (string)($h->Country ?? $country),
                            'stars' => (string)($h->Stars ?? ''),
                            'has_prices' => 'N',
                            'synced_at' => date('Y-m-d H:i:s')
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
        }
    }
    
    // =========================================
    // MODE: list_facilities
    // =========================================
    elseif ($mode == 'list_facilities') {
        echo "Syncing facilities list from Novoton API...\n\n";
        
        $result = fn_novoton_sync_facilities_list();
        
        if (is_array($result)) {
            if (!empty($result['success'])) {
                echo "Synced {$result['total']} facilities ({$result['added']} added, {$result['updated']} updated).\n";
            } else {
                echo "Error: " . ($result['error'] ?? 'Unknown error') . "\n";
            }
        } else {
            echo "Synced {$result} facilities.\n";
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

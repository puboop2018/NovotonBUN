<?php
/**
 * Novoton Holidays - Booking Functions
 * 
 * Functions for booking management, reservation status, alternatives.
 * 
 * @package NovotonHolidays
 * @since 2.8.0
 */

use Tygh\Registry;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

/**
 * Decrypt encrypted PII fields on an alternative request row.
 *
 * Since v2.9.0, contact_email, contact_phone and notes are stored
 * AES-256-CBC encrypted via SecurityService::encrypt(). This helper
 * transparently decrypts them so callers (admin views, cron, email
 * sending) can work with plaintext.
 *
 * Safe to call on rows that were stored before encryption was introduced:
 * decrypt() returns null on non-encrypted strings, and the helper falls
 * back to the original value in that case.
 *
 * @param array $request  Single row from novoton_alternative_requests
 * @return array          Same row with decrypted PII fields
 */
function fn_novoton_decrypt_request_pii(array $request): array
{
    // Lazy-load SecurityService (works in both controller and cron context)
    static $security = null;
    if ($security === null) {
        $loader = Registry::get('config.dir.addons') . 'novoton_holidays/Services/ServiceLoader.php';
        if (file_exists($loader)) {
            require_once $loader;
        }
        if (function_exists('_nvt_security_service')) {
            $security = _nvt_security_service();
        }
    }

    if ($security === null) {
        return $request;
    }

    foreach (['contact_email', 'contact_phone', 'notes'] as $field) {
        if (!empty($request[$field])) {
            $decrypted = $security->decrypt($request[$field]);
            if ($decrypted !== null) {
                $request[$field] = $decrypted;
            }
            // else: value was stored in plaintext (pre-encryption) — keep as-is
        }
    }

    return $request;
}

/**
 * Decrypt PII fields on an array of alternative request rows.
 *
 * @param array $requests  Array of rows from novoton_alternative_requests
 * @return array           Same rows with decrypted PII fields
 */
function fn_novoton_decrypt_requests_pii(array $requests): array
{
    foreach ($requests as &$request) {
        $request = fn_novoton_decrypt_request_pii($request);
    }
    return $requests;
}

/**
 * Check reservation status from Novoton API
 * 
 * @param int $booking_id Booking ID (0 = check all pending)
 * @return array Result
 */
function fn_novoton_check_reservation_status($booking_id = 0): array
{
    $api = fn_novoton_get_api();
    if (!$api) {
        return ['success' => false, 'error' => 'API not available'];
    }
    
    $condition = "novoton_reservation_id IS NOT NULL AND novoton_reservation_id != ''";
    
    if ($booking_id > 0) {
        $condition .= db_quote(" AND booking_id = ?i", $booking_id);
    } else {
        $condition .= " AND status = 'pending'";
    }
    
    $bookings = db_get_array(
        "SELECT booking_id, novoton_reservation_id, status 
         FROM ?:novoton_bookings 
         WHERE {$condition}"
    );
    
    $result = [
        'success' => true,
        'checked' => 0,
        'updated' => 0,
        'details' => []
    ];
    
    foreach ($bookings as $booking) {
        $result['checked']++;
        
        try {
            $status_response = $api->getReservationStatus($booking['novoton_reservation_id']);
            
            if (!empty($status_response)) {
                $new_status = (string)($status_response['Status'] ?? $status_response['status'] ?? '');
                
                // Map Novoton API status codes to internal status via centralized constant
                $internal_status = \Tygh\Addons\NovotonHolidays\Constants::NOVOTON_STATUS_TO_INTERNAL[$new_status]
                    ?? $booking['status'];
                
                if ($internal_status != $booking['status']) {
                    db_query(
                        "UPDATE ?:novoton_bookings SET status = ?s, novoton_status = ?s WHERE booking_id = ?i",
                        $internal_status, $new_status, $booking['booking_id']
                    );
                    $result['updated']++;
                    $result['details'][$booking['booking_id']] = [
                        'old' => $booking['status'],
                        'new' => $internal_status,
                        'novoton' => $new_status
                    ];
                }
            }
            
        } catch (\Exception $e) {
            $result['details'][$booking['booking_id']] = [
                'error' => $e->getMessage()
            ];
        }
    }
    
    return $result;
}

/**
 * Request alternatives for a booking
 * 
 * @param int $booking_id Booking ID
 * @return array Result
 */
function fn_novoton_request_alternatives($booking_id): array
{
    $booking = db_get_row("SELECT * FROM ?:novoton_bookings WHERE booking_id = ?i", $booking_id);
    
    if (empty($booking)) {
        return ['success' => false, 'error' => 'Booking not found'];
    }
    
    // Check if request already exists
    $existing = db_get_field(
        "SELECT request_id FROM ?:novoton_alternative_requests WHERE booking_id = ?i AND status = 'pending'",
        $booking_id
    );
    
    if ($existing) {
        return ['success' => false, 'error' => 'Alternative request already pending', 'request_id' => $existing];
    }
    
    // Create new request
    $request_data = [
        'booking_id' => $booking_id,
        'order_id' => $booking['order_id'],
        'status' => 'pending',
        'notes' => 'Requested by customer'
    ];
    
    $request_id = db_query("INSERT INTO ?:novoton_alternative_requests ?e", $request_data);
    
    return [
        'success' => true,
        'request_id' => $request_id,
        'message' => 'Alternative request created'
    ];
}

/**
 * Get alternatives for a booking
 * 
 * @param int $booking_id Booking ID
 * @return array Alternatives data
 */
function fn_novoton_get_alternatives($booking_id): array
{
    $request = db_get_row(
        "SELECT * FROM ?:novoton_alternative_requests WHERE booking_id = ?i ORDER BY created_at DESC LIMIT 1",
        $booking_id
    );
    
    if (empty($request)) {
        return [];
    }
    
    if (!empty($request['alternatives_data'])) {
        return json_decode($request['alternatives_data'], true) ?: [];
    }
    
    return [];
}

/**
 * Get bookings for an order
 * 
 * @param int $order_id Order ID
 * @return array Bookings
 */
function fn_novoton_get_order_bookings($order_id): array
{
    return db_get_array(
        "SELECT * FROM ?:novoton_bookings WHERE order_id = ?i ORDER BY booking_id",
        $order_id
    );
}

/**
 * Cron: Sync hotels from ResInfo API
 * 
 * @return array Result
 */
function fn_novoton_cron_resinfo(): array
{
    $api = fn_novoton_get_api();
    if (!$api) {
        return ['success' => false, 'error' => 'API not available'];
    }
    
    $countries = fn_novoton_parse_countries();
    
    $result = [
        'success' => true,
        'synced' => 0,
        'added' => 0,
        'updated' => 0,
        'errors' => 0,
        'countries' => []
    ];
    
    foreach ($countries as $country) {
        $country = trim($country);
        if (empty($country)) continue;
        
        try {
            $hotels = $api->getHotelList($country);
            
            if (!empty($hotels)) {
                $country_stats = ['synced' => 0, 'added' => 0, 'updated' => 0];
                
                foreach ($hotels as $hotel) {
                    $hotel_id = (string)($hotel['HotelId'] ?? $hotel['hotelId'] ?? '');
                    if (empty($hotel_id)) continue;
                    
                    $hotel_data = [
                        'hotel_name' => (string)($hotel['HotelName'] ?? $hotel['hotelName'] ?? ''),
                        'country' => $country,
                        'city' => (string)($hotel['City'] ?? $hotel['city'] ?? ''),
                        'hotel_type' => (string)($hotel['HotelType'] ?? $hotel['hotelType'] ?? ''),
                        'last_sync' => date('Y-m-d H:i:s')
                    ];
                    
                    $exists = db_get_field("SELECT hotel_id FROM ?:novoton_hotels WHERE hotel_id = ?s", $hotel_id);
                    
                    if ($exists) {
                        db_query("UPDATE ?:novoton_hotels SET ?u WHERE hotel_id = ?s", $hotel_data, $hotel_id);
                        $country_stats['updated']++;
                    } else {
                        $hotel_data['hotel_id'] = $hotel_id;
                        db_query("INSERT INTO ?:novoton_hotels ?e", $hotel_data);
                        $country_stats['added']++;
                    }
                    
                    $country_stats['synced']++;
                }
                
                $result['synced'] += $country_stats['synced'];
                $result['added'] += $country_stats['added'];
                $result['updated'] += $country_stats['updated'];
                $result['countries'][$country] = $country_stats;
            }
            
        } catch (\Exception $e) {
            $result['errors']++;
            $result['countries'][$country] = ['error' => $e->getMessage()];
        }
    }
    
    // Log sync
    db_query(
        "INSERT INTO ?:novoton_sync_log (sync_type, sync_date, hotels_synced, hotels_added, hotels_updated, errors, details)
         VALUES ('resinfo', NOW(), ?i, ?i, ?i, ?i, ?s)",
        $result['synced'], $result['added'], $result['updated'], $result['errors'], json_encode($result['countries'])
    );
    
    return $result;
}

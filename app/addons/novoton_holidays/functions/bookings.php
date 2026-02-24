<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Booking Functions
 * 
 * Functions for booking management, reservation status, alternatives.
 * 
 * @package NovotonHolidays
 * @since 2.8.0
 */

use Tygh\Registry;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

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
function fn_novoton_holidays_decrypt_request_pii(array $request): array
{
    // Lazy-load SecurityService (works in both controller and cron context)
    static $security = null;
    if ($security === null) {
        $loader = Registry::get('config.dir.addons') . 'novoton_holidays/src/Services/ServiceLoader.php';
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
function fn_novoton_holidays_decrypt_requests_pii(array $requests): array
{
    foreach ($requests as &$request) {
        $request = fn_novoton_holidays_decrypt_request_pii($request);
    }
    return $requests;
}

/**
 * Check reservation status from Novoton API
 * 
 * @param int $booking_id Booking ID (0 = check all pending)
 * @return array Result
 */
function fn_novoton_holidays_check_reservation_status($booking_id = 0): array
{
    $api = fn_novoton_holidays_get_api();
    if (!$api) {
        return ['success' => false, 'error' => 'API not available'];
    }

    $bookingRepo = new \Tygh\Addons\NovotonHolidays\Repository\BookingRepository();

    if ($booking_id > 0) {
        $booking = $bookingRepo->findById($booking_id);
        $bookings = $booking ? [$booking] : [];
    } else {
        $bookings = $bookingRepo->findWithReservationId();
        // Filter to only pending
        $bookings = array_filter($bookings, fn($b) => $b['status'] === 'pending');
    }

    $result = [
        'success' => true,
        'checked' => 0,
        'updated' => 0,
        'details' => []
    ];

    foreach ($bookings as $booking) {
        if (empty($booking['novoton_reservation_id'])) continue;
        $result['checked']++;

        try {
            $status_response = $api->getReservationInfo($booking['novoton_reservation_id']);

            if (!empty($status_response)) {
                // getReservationInfo returns an XML object — access via object properties
                $new_status = (string)($status_response->Status ?? $status_response->status ?? '');

                // Map Novoton API status codes to internal status via centralized constant
                $internal_status = \Tygh\Addons\NovotonHolidays\Constants::NOVOTON_STATUS_TO_INTERNAL[$new_status]
                    ?? $booking['status'];

                if ($internal_status != $booking['status']) {
                    $bookingRepo->updateStatus($booking['booking_id'], $internal_status, $new_status);
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
function fn_novoton_holidays_request_alternatives($booking_id): array
{
    $bookingRepo = new \Tygh\Addons\NovotonHolidays\Repository\BookingRepository();
    $booking = $bookingRepo->findById($booking_id);

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
function fn_novoton_holidays_get_alternatives($booking_id): array
{
    $request = db_get_row(
        "SELECT request_id, booking_id, order_id, status, alternatives_data, notes, created_at, updated_at FROM ?:novoton_alternative_requests WHERE booking_id = ?i ORDER BY created_at DESC LIMIT 1",
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
function fn_novoton_holidays_get_order_bookings($order_id): array
{
    $bookingRepo = new \Tygh\Addons\NovotonHolidays\Repository\BookingRepository();
    return $bookingRepo->findByOrderId($order_id);
}

/**
 * Cron: Sync hotels from ResInfo API
 * 
 * @return array Result
 */
function fn_novoton_holidays_cron_resinfo(): array
{
    $api = fn_novoton_holidays_get_api();
    if (!$api) {
        return ['success' => false, 'error' => 'API not available'];
    }
    
    $countries = fn_novoton_holidays_parse_countries();
    
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
                
                $hotelRepo = new \Tygh\Addons\NovotonHolidays\Repository\HotelRepository();
                foreach ($hotels as $hotel) {
                    $hotel_id = (string)($hotel['HotelId'] ?? $hotel['hotelId'] ?? '');
                    if (empty($hotel_id)) continue;

                    $hotel_data = [
                        'hotel_id' => $hotel_id,
                        'hotel_name' => (string)($hotel['HotelName'] ?? $hotel['hotelName'] ?? ''),
                        'country' => $country,
                        'city' => (string)($hotel['City'] ?? $hotel['city'] ?? ''),
                        'hotel_type' => (string)($hotel['HotelType'] ?? $hotel['hotelType'] ?? ''),
                        'last_sync' => date('Y-m-d H:i:s')
                    ];

                    $hotelRepo->upsert($hotel_data);
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
    
    // Log sync via repository (column names must match addon.xml schema)
    $syncRepo = new \Tygh\Addons\NovotonHolidays\Repository\SyncLogRepository();
    $syncRepo->create('resinfo', [
        'total'   => $result['synced'],
        'updated' => $result['updated'],
        'failed'  => $result['errors'],
        'status'  => 'completed',
        'details' => $result['countries'],
    ]);
    
    return $result;
}

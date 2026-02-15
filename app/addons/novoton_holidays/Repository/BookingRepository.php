<?php
/**
 * Novoton Holidays - Booking Repository
 * 
 * Centralized database access for booking data.
 * 
 * @package NovotonHolidays
 * @since 2.8.0
 */

namespace Tygh\Addons\NovotonHolidays\Repository;

class BookingRepository
{
    /**
     * Find booking by ID
     */
    public function findById(int $booking_id): ?array
    {
        $booking = db_get_row("SELECT * FROM ?:novoton_bookings WHERE booking_id = ?i", $booking_id);
        return $booking ?: null;
    }
    
    /**
     * Find bookings by order ID
     */
    public function findByOrderId(int $order_id): array
    {
        return db_get_array("SELECT * FROM ?:novoton_bookings WHERE order_id = ?i ORDER BY booking_id", $order_id);
    }
    
    /**
     * Find bookings by user ID
     */
    public function findByUserId(int $user_id, int $limit = 0): array
    {
        $limit_clause = $limit > 0 ? db_quote(" LIMIT ?i", $limit) : '';
        return db_get_array(
            "SELECT * FROM ?:novoton_bookings WHERE user_id = ?i ORDER BY created_at DESC {$limit_clause}",
            $user_id
        );
    }
    
    /**
     * Find bookings by session ID
     */
    public function findBySessionId(string $session_id): array
    {
        return db_get_array(
            "SELECT * FROM ?:novoton_bookings WHERE session_id = ?s AND order_id = 0 ORDER BY created_at DESC",
            $session_id
        );
    }
    
    /**
     * Find bookings by hotel ID
     */
    public function findByHotelId(string $hotel_id): array
    {
        return db_get_array("SELECT * FROM ?:novoton_bookings WHERE hotel_id = ?s ORDER BY check_in DESC", $hotel_id);
    }
    
    /**
     * Find pending bookings
     */
    public function findPending(int $limit = 0): array
    {
        $limit_clause = $limit > 0 ? db_quote(" LIMIT ?i", $limit) : '';
        return db_get_array(
            "SELECT * FROM ?:novoton_bookings WHERE status = 'pending' ORDER BY created_at DESC {$limit_clause}"
        );
    }
    
    /**
     * Find bookings with Novoton reservation ID
     */
    public function findWithReservationId(): array
    {
        return db_get_array(
            "SELECT * FROM ?:novoton_bookings 
             WHERE novoton_reservation_id IS NOT NULL AND novoton_reservation_id != ''
             ORDER BY created_at DESC"
        );
    }
    
    /**
     * Find existing booking (for duplicate prevention)
     */
    public function findExisting(string $hotel_id, string $check_in, string $check_out, string $holder_name, int $hours = 1): ?array
    {
        $booking = db_get_row(
            "SELECT * FROM ?:novoton_bookings 
             WHERE order_id = 0 
               AND hotel_id = ?s 
               AND check_in = ?s 
               AND check_out = ?s 
               AND holder_name = ?s
               AND created_at > DATE_SUB(NOW(), INTERVAL ?i HOUR)
             LIMIT 1",
            $hotel_id, $check_in, $check_out, $holder_name, $hours
        );
        
        return $booking ?: null;
    }
    
    /**
     * Count bookings with filters
     */
    public function count(array $filters = []): int
    {
        $where = $this->buildWhereClause($filters);
        return (int) db_get_field("SELECT COUNT(*) FROM ?:novoton_bookings {$where}");
    }
    
    /**
     * Create new booking
     */
    public function create(array $data): int
    {
        $booking_id = db_query("INSERT INTO ?:novoton_bookings ?e", $data);
        return (int) $booking_id;
    }
    
    /**
     * Update booking
     */
    public function update(int $booking_id, array $data): bool
    {
        return (bool) db_query("UPDATE ?:novoton_bookings SET ?u WHERE booking_id = ?i", $data, $booking_id);
    }
    
    /**
     * Update booking status
     */
    public function updateStatus(int $booking_id, string $status, string $novoton_status = ''): bool
    {
        $data = ['status' => $status];
        if (!empty($novoton_status)) {
            $data['novoton_status'] = $novoton_status;
        }
        return $this->update($booking_id, $data);
    }
    
    /**
     * Link booking to order
     */
    public function linkToOrder(int $booking_id, int $order_id): bool
    {
        return $this->update($booking_id, ['order_id' => $order_id]);
    }
    
    /**
     * Set Novoton reservation ID
     */
    public function setReservationId(int $booking_id, string $reservation_id, string $status = 'OK'): bool
    {
        // Map Novoton API status codes to internal status
        $status_map = [
            'OK' => 'confirmed',
            'Confirmed' => 'confirmed',
            'ASK' => 'ask',
            'ST' => 'cancelled',
            'WT' => 'waiting',
        ];
        $internal_status = $status_map[$status] ?? 'pending';

        return $this->update($booking_id, [
            'novoton_reservation_id' => $reservation_id,
            'novoton_status' => $status,
            'status' => $internal_status
        ]);
    }
    
    /**
     * Store API request/response
     */
    public function storeApiData(int $booking_id, $request, $response): bool
    {
        return $this->update($booking_id, [
            'api_request' => is_array($request) ? json_encode($request) : $request,
            'api_response' => is_array($response) ? json_encode($response) : $response
        ]);
    }
    
    /**
     * Delete booking
     */
    public function delete(int $booking_id): bool
    {
        return (bool) db_query("DELETE FROM ?:novoton_bookings WHERE booking_id = ?i", $booking_id);
    }
    
    /**
     * Delete orphan bookings (not linked to orders, older than X hours)
     */
    public function deleteOrphans(int $hours = 24): int
    {
        // In CS-Cart, db_query returns affected rows count for DELETE
        $affected = db_query(
            "DELETE FROM ?:novoton_bookings 
             WHERE order_id = 0 
               AND created_at < DATE_SUB(NOW(), INTERVAL ?i HOUR)",
            $hours
        );
        return (int) $affected;
    }
    
    /**
     * Get booking statistics
     */
    public function getStats(): array
    {
        return [
            'total' => $this->count(),
            'pending' => $this->count(['status' => 'pending']),
            'confirmed' => $this->count(['status' => 'confirmed']),
            'cancelled' => $this->count(['status' => 'cancelled']),
            'with_orders' => $this->count(['has_order' => true]),
            'orphans' => $this->count(['no_order' => true])
        ];
    }
    
    /**
     * Get unified booking list - uses novoton_bookings as single source of truth
     * Joins with orders table for order status information
     *
     * @param array $params Filter parameters
     * @return array Unified bookings list
     */
    public function getUnifiedBookings(array $params = []): array
    {
        // Build WHERE conditions for novoton_bookings
        $conditions = [];

        // By default, only show bookings linked to orders (exclude orphans)
        if (empty($params['show_orphans'])) {
            $conditions[] = "nb.order_id > 0";
        }

        if (!empty($params['order_id'])) {
            $conditions[] = db_quote("nb.order_id = ?i", $params['order_id']);
        }
        if (!empty($params['hotel_id'])) {
            $conditions[] = db_quote("nb.hotel_id = ?s", $params['hotel_id']);
        }
        if (!empty($params['novoton_status'])) {
            $conditions[] = db_quote("nb.novoton_status = ?s", $params['novoton_status']);
        }
        if (!empty($params['status'])) {
            $conditions[] = db_quote("nb.status = ?s", $params['status']);
        }
        if (!empty($params['check_in_from'])) {
            $conditions[] = db_quote("nb.check_in >= ?s", $params['check_in_from']);
        }
        if (!empty($params['check_in_to'])) {
            $conditions[] = db_quote("nb.check_in <= ?s", $params['check_in_to']);
        }

        $where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        // Query novoton_bookings as primary source, LEFT JOIN orders for order status
        // Also join order_details to get product price as fallback when booking prices are 0
        $bookings_raw = db_get_array(
            "SELECT nb.*,
                    nh.hotel_name, nh.city AS hotel_city, nh.region AS hotel_region, nh.country AS hotel_country,
                    o.status AS order_status, o.timestamp AS order_timestamp,
                    o.firstname AS order_firstname, o.lastname AS order_lastname,
                    o.email AS order_email, o.phone AS order_phone,
                    od.price AS order_product_price, od.base_price AS order_product_base_price
             FROM ?:novoton_bookings nb
             LEFT JOIN ?:novoton_hotels nh ON nb.hotel_id = nh.hotel_id
             LEFT JOIN ?:orders o ON nb.order_id = o.order_id
             LEFT JOIN ?:order_details od ON nb.order_id = od.order_id AND nb.product_id = od.product_id
             {$where_clause}
             ORDER BY nb.order_id DESC, nb.booking_id DESC"
        );

        $bookings = [];

        foreach ($bookings_raw as $nb) {
            // Build unified booking record from novoton_bookings
            $booking = [
                'booking_id' => $nb['booking_id'],
                'order_id' => $nb['order_id'],
                'product_id' => $nb['product_id'] ?? 0,
                'item_id' => $nb['item_id'] ?? '',
                'hotel_id' => $nb['hotel_id'],
                'hotel_name' => $nb['hotel_name'] ?? '',
                'city' => $nb['hotel_city'] ?? $nb['city'] ?? '',
                'hotel_city' => $nb['hotel_city'] ?? $nb['city'] ?? '',
                'region' => $nb['hotel_region'] ?? $nb['region'] ?? '',
                'hotel_region' => $nb['hotel_region'] ?? $nb['region'] ?? '',
                'country' => $nb['hotel_country'] ?? $nb['country'] ?? '',
                'package_id' => $nb['package_id'] ?? '',
                'package_name' => $nb['package_name'] ?? '',
                'room_id' => $nb['room_id'] ?? '',
                'room_type' => $nb['room_type'] ?? '',
                'board_id' => $nb['board_id'] ?? '',
                'board_name' => $nb['board_name'] ?? '',
                'check_in' => $nb['check_in'],
                'check_out' => $nb['check_out'],
                'nights' => $nb['nights'] ?? 0,
                'adults' => $nb['adults'] ?? 0,
                'children' => $nb['children'] ?? 0,
                'children_ages' => $nb['children_ages'] ?? '',
                'num_rooms' => $nb['num_rooms'] ?? 1,
                'room_number' => $nb['room_number'] ?? 1,
                'total_rooms' => $nb['total_rooms'] ?? 1,
                'rooms_data' => $nb['rooms_data'] ?? null,
                'guests_data' => $nb['guests_data'] ?? null,
                'base_price' => floatval($nb['base_price'] ?? 0) > 0
                    ? $nb['base_price']
                    : ($nb['order_product_base_price'] ?? $nb['order_product_price'] ?? 0),
                'api_price' => floatval($nb['api_price'] ?? 0) > 0
                    ? $nb['api_price']
                    : (floatval($nb['base_price'] ?? 0) > 0 ? $nb['base_price'] : ($nb['order_product_base_price'] ?? 0)),
                'total_price' => floatval($nb['total_price'] ?? 0) > 0
                    ? $nb['total_price']
                    : ($nb['order_product_price'] ?? $nb['order_product_base_price'] ?? 0),
                'currency' => $nb['currency'] ?? 'EUR',
                'holder_name' => $nb['holder_name'] ?? '',
                'guest_name' => $nb['guest_name'] ?? $nb['holder_name'] ?? '',
                'guest_email' => $nb['order_email'] ?? $nb['guest_email'] ?? '',
                'guest_phone' => $nb['order_phone'] ?? $nb['guest_phone'] ?? '',
                // Novoton API status
                'status' => $nb['status'] ?? 'pending',
                'novoton_status' => $nb['novoton_status'] ?? '',
                'novoton_invoice_id' => $nb['novoton_invoice_id'] ?? '',
                'novoton_confirm_id' => $nb['novoton_confirm_id'] ?? '',
                'novoton_reservation_id' => $nb['novoton_reservation_id'] ?? '',
                'api_request' => $nb['api_request'] ?? null,
                'api_response' => $nb['api_response'] ?? null,
                'alternatives_data' => $nb['alternatives_data'] ?? null,
                // Order info from joined orders table
                'order_status' => $nb['order_status'] ?? '',
                'created_at' => $nb['created_at'] ?? ($nb['order_timestamp'] ? date('Y-m-d H:i:s', $nb['order_timestamp']) : ''),
                // Source indicator
                '_source' => ($nb['order_id'] > 0) ? 'novoton_bookings' : 'orphan',
            ];

            // Format room types list for display
            $rooms_data = null;
            if (!empty($nb['rooms_data'])) {
                $rooms_data = is_string($nb['rooms_data']) ? json_decode($nb['rooms_data'], true) : $nb['rooms_data'];
            }

            if (!empty($rooms_data) && is_array($rooms_data)) {
                $room_types = [];
                $board_names = [];
                foreach ($rooms_data as $room) {
                    $room_display = $room['room_type_display'] ?? $room['room_name'] ?? $room['room_id'] ?? 'Room';
                    $room_display = str_replace(['%2b', '%2B'], '+', $room_display);
                    $room_types[] = $room_display;
                    if (!empty($room['board_name'])) {
                        $board_names[] = $room['board_name'];
                    }
                }
                $booking['room_types_list'] = implode(', ', $room_types);
                if (!empty($board_names)) {
                    $booking['board_display'] = $board_names[0];
                } else {
                    $booking['board_display'] = $booking['board_name'];
                }
            } else {
                $booking['room_types_list'] = $booking['room_type'] ?: fn_novoton_format_room_type($booking['room_id']);
                $booking['board_display'] = $booking['board_name'] ?: fn_novoton_format_board_name($booking['board_id']);
            }

            // Parse guests for display
            $guests_data = null;
            if (!empty($nb['guests_data'])) {
                $guests_data = is_string($nb['guests_data']) ? json_decode($nb['guests_data'], true) : $nb['guests_data'];
            }

            if (!empty($guests_data) && is_array($guests_data)) {
                $by_room = [];
                foreach ($guests_data as $guest) {
                    $room_num = $guest['room'] ?? 1;
                    if (!isset($by_room[$room_num])) {
                        $by_room[$room_num] = [];
                    }
                    $by_room[$room_num][] = $guest['name'] ?? 'Guest';
                }
                $booking['guests_by_room'] = $by_room;
            }

            $bookings[] = $booking;
        }

        return $bookings;
    }

    /**
     * Build WHERE clause from filters
     */
    private function buildWhereClause(array $filters): string
    {
        $conditions = [];
        
        if (!empty($filters['status'])) {
            $conditions[] = db_quote("status = ?s", $filters['status']);
        }
        if (!empty($filters['hotel_id'])) {
            $conditions[] = db_quote("hotel_id = ?s", $filters['hotel_id']);
        }
        if (!empty($filters['order_id'])) {
            $conditions[] = db_quote("order_id = ?i", $filters['order_id']);
        }
        if (!empty($filters['user_id'])) {
            $conditions[] = db_quote("user_id = ?i", $filters['user_id']);
        }
        if (!empty($filters['has_order'])) {
            $conditions[] = "order_id > 0";
        }
        if (!empty($filters['no_order'])) {
            $conditions[] = "order_id = 0";
        }
        if (!empty($filters['check_in_from'])) {
            $conditions[] = db_quote("check_in >= ?s", $filters['check_in_from']);
        }
        if (!empty($filters['check_in_to'])) {
            $conditions[] = db_quote("check_in <= ?s", $filters['check_in_to']);
        }
        
        return !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
    }
}

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
    public function setReservationId(int $booking_id, string $reservation_id, string $status = 'confirmed'): bool
    {
        return $this->update($booking_id, [
            'novoton_reservation_id' => $reservation_id,
            'novoton_status' => $status,
            'status' => ($status == 'Confirmed') ? 'confirmed' : 'pending'
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
     * Get unified booking list - combines order_details.extra with novoton_bookings
     * This ensures complete data by preferring order_details.extra when available
     *
     * @param array $params Filter parameters
     * @return array Unified bookings list
     */
    public function getUnifiedBookings(array $params = []): array
    {
        $bookings = [];
        $seen_keys = []; // Track unique bookings by order_id + hotel_id + check_in

        // Build conditions for orders
        $order_condition = '';
        if (!empty($params['order_id'])) {
            $order_condition .= db_quote(" AND o.order_id = ?i", $params['order_id']);
        }
        if (!empty($params['check_in_from'])) {
            $order_condition .= db_quote(" AND od.extra LIKE ?l", '%"check_in":"' . $params['check_in_from'] . '%');
        }

        // 1. Get bookings from order_details.extra (most complete source)
        $order_details = db_get_array(
            "SELECT od.order_id, od.product_id, od.item_id, od.extra, od.price, od.amount,
                    o.status AS order_status, o.timestamp, o.total AS order_total,
                    o.firstname, o.lastname, o.email, o.phone
             FROM ?:order_details od
             INNER JOIN ?:orders o ON od.order_id = o.order_id
             WHERE od.extra LIKE '%novoton_booking%' {$order_condition}
             ORDER BY o.order_id DESC"
        );

        foreach ($order_details as $od) {
            $extra = @unserialize($od['extra']);
            if (!$extra) {
                $extra = json_decode($od['extra'], true);
            }

            if (!$extra || !is_array($extra) || empty($extra['novoton_booking'])) {
                continue;
            }

            // Build unique key
            $hotel_id = $extra['hotel_id'] ?? '';
            $check_in = $extra['check_in'] ?? '';
            $unique_key = $od['order_id'] . '_' . $hotel_id . '_' . $check_in;

            if (isset($seen_keys[$unique_key])) {
                continue;
            }
            $seen_keys[$unique_key] = true;

            // Get matching novoton_bookings record for API status
            $nb_record = db_get_row(
                "SELECT booking_id, novoton_status, novoton_invoice_id, novoton_confirm_id,
                        api_request, api_response, status, alternatives_data
                 FROM ?:novoton_bookings
                 WHERE order_id = ?i AND hotel_id = ?s
                 ORDER BY booking_id DESC LIMIT 1",
                $od['order_id'],
                $hotel_id
            );

            // Build unified booking record from order_details.extra
            $booking = [
                'booking_id' => $nb_record['booking_id'] ?? 0,
                'order_id' => $od['order_id'],
                'product_id' => $od['product_id'],
                'item_id' => $od['item_id'],
                'hotel_id' => $hotel_id,
                'hotel_name' => $extra['hotel_name'] ?? '',
                'city' => $extra['hotel_city'] ?? '',
                'region' => $extra['hotel_region'] ?? '',
                'country' => $extra['hotel_country'] ?? '',
                'package_id' => $extra['package_id'] ?? '',
                'package_name' => $extra['package_name'] ?? '',
                'room_id' => $extra['room_id'] ?? '',
                'room_type' => $extra['room_type_display'] ?? $extra['room_name'] ?? '',
                'board_id' => $extra['board_id'] ?? '',
                'board_name' => $extra['board_name'] ?? '',
                'check_in' => $check_in,
                'check_out' => $extra['check_out'] ?? '',
                'nights' => $extra['nights'] ?? 0,
                'adults' => $extra['adults'] ?? 0,
                'children' => $extra['children'] ?? 0,
                'children_ages' => is_array($extra['children_ages'] ?? null)
                    ? implode(', ', $extra['children_ages'])
                    : ($extra['children_ages'] ?? ''),
                'num_rooms' => $extra['num_rooms'] ?? 1,
                'rooms_data' => isset($extra['rooms_data']) ? json_encode($extra['rooms_data']) : null,
                'guests_data' => isset($extra['guests_data']) ? json_encode($extra['guests_data']) : null,
                'base_price' => $extra['base_price'] ?? $od['price'] ?? 0,
                'api_price' => $extra['api_price'] ?? $extra['base_price'] ?? 0,
                'total_price' => $od['price'] ?? $extra['total_price'] ?? 0,
                'currency' => $extra['currency'] ?? 'EUR',
                'holder_name' => $extra['holder_name'] ?? ($od['firstname'] . ' ' . $od['lastname']),
                'guest_email' => $od['email'] ?? '',
                'guest_phone' => $od['phone'] ?? '',
                // From novoton_bookings record
                'status' => $nb_record['status'] ?? 'pending',
                'novoton_status' => $nb_record['novoton_status'] ?? '',
                'novoton_invoice_id' => $nb_record['novoton_invoice_id'] ?? '',
                'novoton_confirm_id' => $nb_record['novoton_confirm_id'] ?? '',
                'api_request' => $nb_record['api_request'] ?? null,
                'api_response' => $nb_record['api_response'] ?? null,
                'alternatives_data' => $nb_record['alternatives_data'] ?? null,
                // Order info
                'order_status' => $od['order_status'],
                'created_at' => date('Y-m-d H:i:s', $od['timestamp']),
                // Source indicator
                '_source' => 'order_details',
            ];

            // Apply filters
            if (!empty($params['novoton_status']) && $booking['novoton_status'] != $params['novoton_status']) {
                continue;
            }
            if (!empty($params['hotel_id']) && $booking['hotel_id'] != $params['hotel_id']) {
                continue;
            }

            // Format room types list for display
            if (!empty($extra['rooms_data']) && is_array($extra['rooms_data'])) {
                $room_types = [];
                $board_names = [];
                foreach ($extra['rooms_data'] as $room) {
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
                }
            } else {
                $booking['room_types_list'] = $booking['room_type'];
                $booking['board_display'] = $booking['board_name'];
            }

            // Parse guests for display
            if (!empty($extra['guests_data']) && is_array($extra['guests_data'])) {
                $by_room = [];
                foreach ($extra['guests_data'] as $guest) {
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

        // 2. Add orphan bookings (no order_id) if show_orphans is enabled
        if (!empty($params['show_orphans'])) {
            $orphans = db_get_array(
                "SELECT nb.*, nh.city AS hotel_city, nh.region AS hotel_region, nh.country AS hotel_country
                 FROM ?:novoton_bookings nb
                 LEFT JOIN ?:novoton_hotels nh ON nb.hotel_id = nh.hotel_id
                 WHERE nb.order_id = 0
                 ORDER BY nb.created_at DESC"
            );

            foreach ($orphans as $orphan) {
                $unique_key = '0_' . $orphan['hotel_id'] . '_' . $orphan['check_in'];
                if (isset($seen_keys[$unique_key])) {
                    continue;
                }

                $orphan['_source'] = 'orphan';
                $orphan['room_types_list'] = $orphan['room_type'] ?? fn_novoton_format_room_type($orphan['room_id'] ?? '');
                $orphan['board_display'] = fn_novoton_format_board_name($orphan['board_id'] ?? '');
                $orphan['city'] = $orphan['hotel_city'] ?? '';
                $orphan['region'] = $orphan['hotel_region'] ?? '';
                $orphan['country'] = $orphan['hotel_country'] ?? '';

                // Parse guests
                if (!empty($orphan['guests_data'])) {
                    $guests = json_decode($orphan['guests_data'], true);
                    if ($guests) {
                        $by_room = [];
                        foreach ($guests as $guest) {
                            $room_num = $guest['room'] ?? 1;
                            if (!isset($by_room[$room_num])) {
                                $by_room[$room_num] = [];
                            }
                            $by_room[$room_num][] = $guest['name'] ?? 'Guest';
                        }
                        $orphan['guests_by_room'] = $by_room;
                    }
                }

                $bookings[] = $orphan;
            }
        }

        // Sort by order_id DESC (most recent first)
        usort($bookings, function($a, $b) {
            return ($b['order_id'] ?? 0) - ($a['order_id'] ?? 0);
        });

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

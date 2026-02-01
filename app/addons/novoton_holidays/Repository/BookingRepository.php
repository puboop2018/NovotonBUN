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

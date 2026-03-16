<?php
declare(strict_types=1);
/**
 * Sphinx Holidays - Booking Repository
 *
 * Centralized database access for sphinx_bookings data.
 * All writes to sphinx_bookings MUST go through this repository
 * to ensure dual-write consistency with travel_bookings.
 *
 * @package SphinxHolidays
 * @since   1.1.0
 */

namespace Tygh\Addons\SphinxHolidays\Repository;

use Tygh\Addons\TravelCore\TravelConstants;

class SphinxBookingRepository
{
    /**
     * Find booking by ID (raw DB row).
     */
    public function findById(int $booking_id): ?array
    {
        $booking = db_get_row("SELECT * FROM ?:sphinx_bookings WHERE booking_id = ?i", $booking_id);
        return $booking ?: null;
    }

    /**
     * Find bookings by order ID.
     */
    public function findByOrderId(int $order_id): array
    {
        return db_get_array(
            "SELECT * FROM ?:sphinx_bookings WHERE order_id = ?i ORDER BY booking_id",
            $order_id
        );
    }

    /**
     * Find existing unassigned booking matching hotel + dates + holder (for dedup in add_to_cart).
     */
    public function findRecentUnassigned(string $hotel_id, string $check_in, string $check_out, string $holder_name): ?int
    {
        $id = db_get_field(
            "SELECT booking_id FROM ?:sphinx_bookings
             WHERE order_id = 0 AND hotel_id = ?s AND check_in = ?s AND check_out = ?s
               AND holder_name = ?s AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
             LIMIT 1",
            $hotel_id, $check_in, $check_out, $holder_name
        );
        return $id ? (int) $id : null;
    }

    /**
     * Create a new sphinx booking with dual-write to travel_bookings.
     */
    public function create(array $data): int
    {
        $data = self::filterNullValues($data);

        db_query("START TRANSACTION");
        try {
            $booking_id = (int) db_query("INSERT INTO ?:sphinx_bookings ?e", $data);

            if ($booking_id > 0) {
                $this->syncToTravelBookings($booking_id, $data);
            }

            db_query("COMMIT");
        } catch (\Throwable $e) {
            db_query("ROLLBACK");
            throw $e;
        }

        return $booking_id;
    }

    /**
     * Update an existing sphinx booking with dual-write to travel_bookings.
     */
    public function update(int $booking_id, array $data): bool
    {
        $data = self::filterNullValues($data);

        db_query("START TRANSACTION");
        try {
            $result = (bool) db_query(
                "UPDATE ?:sphinx_bookings SET ?u WHERE booking_id = ?i",
                $data, $booking_id
            );

            if ($result) {
                $this->syncUpdateToTravelBookings($booking_id, $data);
            }

            db_query("COMMIT");
        } catch (\Throwable $e) {
            db_query("ROLLBACK");
            throw $e;
        }

        return $result;
    }

    /**
     * Link booking to an order and update status.
     */
    public function linkToOrder(int $booking_id, int $order_id, string $status = ''): void
    {
        $data = ['order_id' => $order_id];
        if (!empty($status)) {
            $data['status'] = $status;
        }
        $this->update($booking_id, $data);
    }

    /**
     * Store API booking reference and response after successful API submission.
     */
    public function updateApiResponse(int $booking_id, string $ref, string $response): void
    {
        $this->update($booking_id, [
            'api_booking_ref' => $ref,
            'api_response' => $response,
        ]);
    }

    /**
     * Link unassigned bookings to a user by session ID.
     *
     * Used after login/registration to claim guest bookings.
     *
     * @return int Number of bookings linked
     */
    public function linkToUserBySession(int $user_id, string $session_id): int
    {
        $affected = (int) db_query(
            "UPDATE ?:sphinx_bookings SET user_id = ?i WHERE session_id = ?s AND user_id = 0 AND order_id = 0",
            $user_id, $session_id
        );

        if ($affected > 0) {
            $booking_ids = db_get_fields(
                "SELECT booking_id FROM ?:sphinx_bookings WHERE session_id = ?s AND user_id = ?i",
                $session_id, $user_id
            );
            if (!empty($booking_ids)) {
                $id_strings = array_map('strval', $booking_ids);
                db_query(
                    "UPDATE ?:travel_bookings SET user_id = ?i WHERE provider = 'sphinx' AND provider_booking_id IN (?a)",
                    $user_id, $id_strings
                );
            }
        }

        return $affected;
    }

    /**
     * Delete booking from both tables.
     */
    public function delete(int $booking_id): bool
    {
        db_query("START TRANSACTION");
        try {
            db_query(
                "DELETE FROM ?:travel_bookings WHERE provider = 'sphinx' AND provider_booking_id = ?s",
                (string) $booking_id
            );
            $result = (bool) db_query("DELETE FROM ?:sphinx_bookings WHERE booking_id = ?i", $booking_id);
            db_query("COMMIT");
        } catch (\Throwable $e) {
            db_query("ROLLBACK");
            throw $e;
        }

        return $result;
    }

    /**
     * Delete orphan bookings (not linked to orders, older than X hours).
     */
    public function deleteOrphans(int $hours = 24): int
    {
        db_query(
            "DELETE tb FROM ?:travel_bookings tb
             INNER JOIN ?:sphinx_bookings sb ON tb.provider_booking_id = CAST(sb.booking_id AS CHAR)
             WHERE tb.provider = 'sphinx' AND sb.order_id = 0
               AND sb.created_at < DATE_SUB(NOW(), INTERVAL ?i HOUR)",
            $hours
        );

        return (int) db_query(
            "DELETE FROM ?:sphinx_bookings
             WHERE order_id = 0
               AND created_at < DATE_SUB(NOW(), INTERVAL ?i HOUR)",
            $hours
        );
    }

    /**
     * Sync a sphinx booking to the shared travel_bookings table.
     *
     * Uses INSERT ... ON DUPLICATE KEY UPDATE for atomicity.
     * Relies on UNIQUE KEY uq_provider_booking(provider, provider_booking_id).
     */
    private function syncToTravelBookings(int $booking_id, array $data): void
    {
        $guests_json = $data['guests_data'] ?? '{}';
        if (!is_string($guests_json)) {
            $guests_json = json_encode($guests_json);
        }

        $travel_record = [
            'provider'            => 'sphinx',
            'provider_booking_id' => (string) $booking_id,
            'order_id'            => (int) ($data['order_id'] ?? 0),
            'user_id'             => (int) ($data['user_id'] ?? 0),
            'hotel_id'            => $data['hotel_id'] ?? '',
            'hotel_name'          => $data['hotel_name'] ?? '',
            'room_name'           => $data['room_type'] ?? '',
            'board_code'          => $data['board_id'] ?? '',
            'check_in'            => $data['check_in'] ?? '',
            'check_out'           => $data['check_out'] ?? '',
            'nights'              => (int) ($data['nights'] ?? 0),
            'adults'              => (int) ($data['adults'] ?? 2),
            'children'            => (int) ($data['children'] ?? 0),
            'children_ages'       => $data['children_ages'] ?? '',
            'total_price'         => (float) ($data['total_price'] ?? 0),
            'currency'            => $data['currency'] ?? 'EUR',
            'status'              => $data['status'] ?? TravelConstants::STATUS_PENDING,
            'guests_json'         => $guests_json,
        ];

        db_query(
            "INSERT INTO ?:travel_bookings ?e ON DUPLICATE KEY UPDATE ?u",
            $travel_record, $travel_record
        );
    }

    /**
     * Sync partial updates from sphinx_bookings to travel_bookings.
     *
     * Only syncs fields that travel_bookings actually stores.
     */
    private function syncUpdateToTravelBookings(int $booking_id, array $data): void
    {
        static $fieldMap = [
            'order_id'      => 'order_id',
            'user_id'       => 'user_id',
            'hotel_id'      => 'hotel_id',
            'hotel_name'    => 'hotel_name',
            'room_type'     => 'room_name',
            'board_id'      => 'board_code',
            'check_in'      => 'check_in',
            'check_out'     => 'check_out',
            'nights'        => 'nights',
            'adults'        => 'adults',
            'children'      => 'children',
            'children_ages' => 'children_ages',
            'total_price'   => 'total_price',
            'currency'      => 'currency',
            'status'        => 'status',
            'guests_data'   => 'guests_json',
        ];

        $travelUpdate = [];
        foreach ($fieldMap as $sphinxField => $travelField) {
            if (array_key_exists($sphinxField, $data)) {
                $travelUpdate[$travelField] = $data[$sphinxField];
            }
        }

        if (empty($travelUpdate)) {
            return;
        }

        db_query(
            "UPDATE ?:travel_bookings SET ?u WHERE provider = 'sphinx' AND provider_booking_id = ?s",
            $travelUpdate, (string) $booking_id
        );
    }

    /**
     * Filter null values to prevent PHP 8.1+ deprecation warnings.
     */
    private static function filterNullValues(array $data): array
    {
        return array_filter($data, static fn($v) => $v !== null);
    }
}

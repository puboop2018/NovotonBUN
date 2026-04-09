<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Booking Repository
 * 
 * Centralized database access for booking data.
 * 
 * @package NovotonHolidays
 * @since 2.8.0
 */

namespace Tygh\Addons\NovotonHolidays\Repository;

use Tygh\Addons\TravelCore\Services\GuestDataNormalizer;
use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\TravelCore\TravelConstants;
use Tygh\Addons\NovotonHolidays\Helpers\JsonDecoder;
use Tygh\Addons\TravelCore\ValueObjects\BoardType;
use Tygh\Addons\TravelCore\ValueObjects\RoomType;

class BookingRepository implements BookingRepositoryInterface
{
    /**
     * Columns selected for listing queries (excludes large JSON/text fields:
     * rooms_data, guests_data, api_request, api_response, alternatives_data,
     * notes, terms_of_payment_raw/formatted, terms_of_cancellation_raw/formatted).
     */
    private const LIST_COLUMNS = 'booking_id, order_id, product_id, user_id,
        session_id, novoton_confirm_id, novoton_invoice_id, novoton_res_num,
        novoton_status, hotel_id, hotel_name, package_id, package_name,
        room_id, room_type, board_id, board_name, item_id,
        check_in, check_out, nights, adults, children, children_ages,
        num_rooms, room_number, total_rooms, guest_name, guest_email, guest_phone,
        holder_name, base_price, extras_price, total_price, api_price,
        currency, status, alternatives_requested, last_status_check,
        created_at, updated_at';

    /**
     * Request-scoped memo cache for hydrated bookings.
     * Prevents the same booking's rooms_data/guests_data from being
     * decoded 2-3 times within a single request cycle.
     *
     * @var array<int, array>
     */
    private static array $hydratedCache = [];

    private readonly GuestDataNormalizer $guestDataNormalizer;

    public function __construct(?GuestDataNormalizer $guestDataNormalizer = null)
    {
        $this->guestDataNormalizer = $guestDataNormalizer ?? new GuestDataNormalizer();
    }

    /**
     * Find booking by ID (raw DB row, no JSON decoding).
     */
    public function findById(int $booking_id): ?array
    {
        $booking = db_get_row("SELECT * FROM ?:novoton_bookings WHERE booking_id = ?i", $booking_id);
        return $booking ?: null;
    }

    /**
     * Find booking by ID with JSON fields decoded and memo-cached.
     *
     * Decodes rooms_data and guests_data once per request. Subsequent
     * calls for the same booking_id return the cached result.
     *
     * @param int  $booking_id
     * @param bool $force  Bypass cache (e.g. after an update)
     * @return array|null Hydrated booking or null
     */
    public function findByIdHydrated(int $booking_id, bool $force = false): ?array
    {
        if (!$force && isset(self::$hydratedCache[$booking_id])) {
            return self::$hydratedCache[$booking_id];
        }

        $booking = $this->findById($booking_id);
        if ($booking === null) {
            return null;
        }

        $booking = self::hydrateJsonFields($booking);

        // Prevent unbounded cache growth in long-running processes (cron)
        if (count(self::$hydratedCache) > Constants::HYDRATED_CACHE_MAX) {
            self::$hydratedCache = array_slice(self::$hydratedCache, -Constants::HYDRATED_CACHE_TRIM, null, true);
        }
        self::$hydratedCache[$booking_id] = $booking;

        return $booking;
    }

    /**
     * Decode JSON fields on a raw booking row in-place.
     *
     * Call this instead of scattered json_decode() calls to ensure
     * each field is decoded exactly once.
     *
     * @param array $booking Raw DB row
     * @return array Booking with rooms_data_parsed, guests_data_parsed
     */
    public static function hydrateJsonFields(array $booking): array
    {
        // rooms_data
        $booking['rooms_data_parsed'] = JsonDecoder::decode($booking['rooms_data'] ?? '', 'rooms_data');

        // guests_data — always normalize to canonical keyed format
        if (!empty($booking['guests_data'])) {
            $booking['guests_data_parsed'] = (new GuestDataNormalizer())->normalize($booking['guests_data']);
        } else {
            $booking['guests_data_parsed'] = [];
        }

        return $booking;
    }

    /**
     * Invalidate the memo cache for a specific booking (e.g. after update).
     */
    public static function invalidateCache(int $booking_id = 0): void
    {
        if ($booking_id > 0) {
            unset(self::$hydratedCache[$booking_id]);
        } else {
            self::$hydratedCache = [];
        }
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
    public function findByUserId(int $user_id, int $limit = 100): array
    {
        return db_get_array(
            "SELECT " . self::LIST_COLUMNS . " FROM ?:novoton_bookings WHERE user_id = ?i ORDER BY created_at DESC LIMIT ?i",
            $user_id,
            $limit
        );
    }
    
    /**
     * Find bookings by session ID
     */
    public function findBySessionId(string $session_id): array
    {
        return db_get_array(
            "SELECT " . self::LIST_COLUMNS . " FROM ?:novoton_bookings WHERE session_id = ?s AND order_id = 0 ORDER BY created_at DESC LIMIT 50",
            $session_id
        );
    }
    
    /**
     * Find bookings by hotel ID
     */
    public function findByHotelId(string $hotel_id, int $limit = 100): array
    {
        return db_get_array(
            "SELECT " . self::LIST_COLUMNS . " FROM ?:novoton_bookings WHERE hotel_id = ?s ORDER BY check_in DESC LIMIT ?i",
            $hotel_id,
            $limit
        );
    }
    
    /**
     * Find pending bookings
     */
    public function findPending(int $limit = 500): array
    {
        return db_get_array(
            "SELECT " . self::LIST_COLUMNS . " FROM ?:novoton_bookings WHERE status = ?s ORDER BY created_at DESC LIMIT ?i",
            TravelConstants::STATUS_PENDING,
            $limit
        );
    }
    
    /**
     * Find bookings with Novoton reservation ID
     */
    public function findWithReservationId(int $limit = 1000): array
    {
        return db_get_array(
            "SELECT " . self::LIST_COLUMNS . ", novoton_reservation_id FROM ?:novoton_bookings
             WHERE novoton_reservation_id IS NOT NULL AND novoton_reservation_id != ''
             ORDER BY created_at DESC LIMIT ?i",
            $limit
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
        $data = self::filterNullValues($data);

        db_query("START TRANSACTION");
        try {
            $booking_id = (int) db_query("INSERT INTO ?:novoton_bookings ?e", $data);

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
     * Update booking
     */
    public function update(int $booking_id, array $data): bool
    {
        $data = self::filterNullValues($data);

        db_query("START TRANSACTION");
        try {
            // db_query() returns affected rows for UPDATE. A return of 0 means
            // "query succeeded but no rows changed" (data identical) — NOT failure.
            // We only fail if the booking doesn't exist at all.
            db_query("UPDATE ?:novoton_bookings SET ?u WHERE booking_id = ?i", $data, $booking_id);

            $this->syncUpdateToTravelBookings($booking_id, $data);

            db_query("COMMIT");
        } catch (\Throwable $e) {
            db_query("ROLLBACK");
            throw $e;
        }

        return true;
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
    public function setReservationId(int $booking_id, string $reservation_id, string $status = 'Good'): bool
    {
        $internal_status = Constants::NOVOTON_STATUS_TO_INTERNAL[$status] ?? TravelConstants::STATUS_PENDING;

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
        db_query("START TRANSACTION");
        try {
            db_query(
                "DELETE FROM ?:travel_bookings WHERE provider = 'novoton' AND provider_booking_id = ?s",
                (string) $booking_id
            );
            $result = (bool) db_query("DELETE FROM ?:novoton_bookings WHERE booking_id = ?i", $booking_id);
            db_query("COMMIT");
        } catch (\Throwable $e) {
            db_query("ROLLBACK");
            throw $e;
        }

        return $result;
    }
    
    /**
     * Delete orphan bookings (not linked to orders, older than X hours)
     */
    public function deleteOrphans(int $hours = 24): int
    {
        // Clean up matching travel_bookings first
        db_query(
            "DELETE tb FROM ?:travel_bookings tb
             INNER JOIN ?:novoton_bookings nb ON tb.provider_booking_id = CAST(nb.booking_id AS CHAR)
             WHERE tb.provider = 'novoton' AND nb.order_id = 0
               AND nb.created_at < DATE_SUB(NOW(), INTERVAL ?i HOUR)",
            $hours
        );

        $affected = db_query(
            "DELETE FROM ?:novoton_bookings
             WHERE order_id = 0
               AND created_at < DATE_SUB(NOW(), INTERVAL ?i HOUR)",
            $hours
        );
        return (int) $affected;
    }
    
    // ── Display queries delegated to BookingQueryService ──

    /**
     * Get booking statistics.
     * @deprecated Use BookingQueryService::getStats() directly
     */
    public function getStats(): array
    {
        $queryService = new \Tygh\Addons\NovotonHolidays\Services\BookingQueryService($this, $this->guestDataNormalizer);
        return $queryService->getStats();
    }

    /**
     * Get unified booking list.
     * @deprecated Use BookingQueryService::getUnifiedBookings() directly
     */
    public function getUnifiedBookings(array $params = []): array
    {
        $queryService = new \Tygh\Addons\NovotonHolidays\Services\BookingQueryService($this, $this->guestDataNormalizer);
        return $queryService->getUnifiedBookings($params);
    }

    /**
     * Link unassigned bookings to a user by session ID.
     *
     * Used after login to claim guest bookings from the current browser session.
     *
     * @return int Number of bookings linked
     */
    public function linkToUserBySession(int $user_id, string $session_id): int
    {
        $affected = (int) db_query(
            "UPDATE ?:novoton_bookings SET user_id = ?i WHERE session_id = ?s AND user_id = 0 AND order_id = 0",
            $user_id,
            $session_id
        );

        if ($affected > 0) {
            // Sync user_id to travel_bookings for these bookings
            $booking_ids = db_get_fields(
                "SELECT booking_id FROM ?:novoton_bookings WHERE session_id = ?s AND user_id = ?i",
                $session_id, $user_id
            );
            if (!empty($booking_ids)) {
                $id_strings = array_map('strval', $booking_ids);
                db_query(
                    "UPDATE ?:travel_bookings SET user_id = ?i WHERE provider = 'novoton' AND provider_booking_id IN (?a)",
                    $user_id, $id_strings
                );
            }
        }

        return $affected;
    }

    /**
     * Link unassigned bookings to a user by email.
     *
     * Used after login/registration to claim bookings made with the same email.
     *
     * @return int Number of bookings linked
     */
    public function linkToUserByEmail(int $user_id, string $email): int
    {
        // Get affected booking IDs before the update
        $booking_ids = db_get_fields(
            "SELECT booking_id FROM ?:novoton_bookings WHERE guest_email = ?s AND user_id = 0",
            $email
        );

        $affected = (int) db_query(
            "UPDATE ?:novoton_bookings SET user_id = ?i WHERE guest_email = ?s AND user_id = 0",
            $user_id,
            $email
        );

        if ($affected > 0 && !empty($booking_ids)) {
            $id_strings = array_map('strval', $booking_ids);
            db_query(
                "UPDATE ?:travel_bookings SET user_id = ?i WHERE provider = 'novoton' AND provider_booking_id IN (?a)",
                $user_id, $id_strings
            );
        }

        return $affected;
    }

    /**
     * Find bookings by multiple product IDs (batch query for cart).
     *
     * @param array  $product_ids Product IDs
     * @param array  $statuses    Optional status filter (default: pending + confirmed)
     * @return array Booking rows
     */
    public function findByProductIds(array $product_ids, array $statuses = [TravelConstants::STATUS_PENDING, TravelConstants::STATUS_CONFIRMED], string $session_id = '', int $user_id = 0): array
    {
        if (empty($product_ids)) {
            return [];
        }

        // Safety: if no ownership context is provided, return nothing rather than
        // leaking all users' bookings. Callers must provide session_id and/or user_id.
        if ($user_id <= 0 && empty($session_id)) {
            return [];
        }

        $select = "SELECT booking_id, product_id, hotel_id, hotel_name, room_id, room_type,
                    board_id, check_in, check_out, nights, adults, children, children_ages,
                    num_rooms, rooms_data, total_price, currency, status, guests_data,
                    package_name, session_id, holder_name, guest_name
             FROM ?:novoton_bookings
             WHERE product_id IN (?n) AND status IN (?a)";

        // Scope to current user/session to prevent cross-user booking leakage
        if ($user_id > 0 && !empty($session_id)) {
            return db_get_array(
                $select . " AND (session_id = ?s OR user_id = ?i) ORDER BY booking_id DESC",
                $product_ids, $statuses, $session_id, $user_id
            );
        } elseif ($user_id > 0) {
            return db_get_array(
                $select . " AND user_id = ?i ORDER BY booking_id DESC",
                $product_ids, $statuses, $user_id
            );
        }

        return db_get_array(
            $select . " AND session_id = ?s ORDER BY booking_id DESC",
            $product_ids, $statuses, $session_id
        );
    }

    /**
     * Delete all bookings for a product (used when product is deleted).
     *
     * @return int Number of bookings deleted
     */
    public function deleteByProductId(int $product_id): int
    {
        // Clean up travel_bookings for these bookings
        $booking_ids = db_get_fields(
            "SELECT booking_id FROM ?:novoton_bookings WHERE product_id = ?i", $product_id
        );
        if (!empty($booking_ids)) {
            $id_strings = array_map('strval', $booking_ids);
            db_query(
                "DELETE FROM ?:travel_bookings WHERE provider = 'novoton' AND provider_booking_id IN (?a)",
                $id_strings
            );
        }

        return (int) db_query("DELETE FROM ?:novoton_bookings WHERE product_id = ?i", $product_id);
    }

    /**
     * Get raw guests_data JSON for a booking.
     */
    public function getGuestsData(int $booking_id): ?string
    {
        $data = db_get_field("SELECT guests_data FROM ?:novoton_bookings WHERE booking_id = ?i", $booking_id);
        return $data ?: null;
    }

    /**
     * Find the most recent unassigned pending booking matching hotel + dates.
     *
     * Used as a fallback to recover guests_data when cart data is stale.
     */
    public function findUnassignedByHotelDates(string $hotel_id, string $check_in, string $check_out): ?array
    {
        $row = db_get_row(
            "SELECT guests_data, holder_name FROM ?:novoton_bookings
             WHERE hotel_id = ?s AND check_in = ?s AND check_out = ?s AND order_id = 0
             ORDER BY booking_id DESC LIMIT 1",
            $hotel_id,
            $check_in,
            $check_out
        );
        return $row ?: null;
    }

    /**
     * Find bookings for multiple order IDs in a single batch query.
     *
     * @param array $order_ids
     * @return array Booking summary rows
     */
    public function findByOrderIds(array $order_ids): array
    {
        if (empty($order_ids)) {
            return [];
        }
        return db_get_array(
            "SELECT booking_id, order_id, hotel_id, hotel_name, room_type, board_id,
                    check_in, check_out, nights, adults, children, total_price,
                    currency, status, novoton_status, novoton_confirm_id
             FROM ?:novoton_bookings
             WHERE order_id IN (?n)",
            $order_ids
        );
    }

    /**
     * Get booking terms (payment + cancellation) for order display.
     */
    public function getTerms(int $booking_id): ?array
    {
        $terms = db_get_row(
            "SELECT terms_of_payment_raw, terms_of_cancellation_raw,
                    terms_of_payment_formatted, terms_of_cancellation_formatted
             FROM ?:novoton_bookings WHERE booking_id = ?i",
            $booking_id
        );
        return $terms ?: null;
    }

    /**
     * Find existing booking ID by order + hotel + dates (for dedup).
     */
    public function findIdByOrderAndHotelDates(int $order_id, string $hotel_id, string $check_in, string $check_out): ?int
    {
        $id = db_get_field(
            "SELECT booking_id FROM ?:novoton_bookings
             WHERE order_id = ?i AND hotel_id = ?s AND check_in = ?s AND check_out = ?s
             LIMIT 1",
            $order_id,
            $hotel_id,
            $check_in,
            $check_out
        );
        return $id ? (int) $id : null;
    }

    /**
     * Find bookings by Novoton API status (e.g. ASK, RQ).
     *
     * @param string $novoton_status  API-level status (e.g. 'ASK')
     * @param array  $statuses        Internal statuses to match
     * @param int    $limit           Max rows
     */
    public function findByNovotonStatus(string $novoton_status, array $statuses, int $limit = 50): array
    {
        return db_get_array(
            "SELECT * FROM ?:novoton_bookings
             WHERE novoton_status = ?s AND status IN (?a)
             ORDER BY created_at DESC LIMIT ?i",
            $novoton_status,
            $statuses,
            $limit
        );
    }

    /**
     * Find RQ bookings that haven't had alternatives requested yet.
     */
    public function findRqWithoutAlternatives(int $limit = 50): array
    {
        return db_get_array(
            "SELECT * FROM ?:novoton_bookings
             WHERE novoton_status = ?s AND alternatives_requested = 0
             ORDER BY created_at ASC LIMIT ?i",
            Constants::NOVOTON_STATUS_ALTERNATIVES_PENDING,
            $limit
        );
    }

    /**
     * Count orphan bookings (no order, older than N hours).
     */
    public function countOrphans(int $hours = 48): int
    {
        return (int) db_get_field(
            "SELECT COUNT(*) FROM ?:novoton_bookings
             WHERE order_id = 0 AND created_at < DATE_SUB(NOW(), INTERVAL ?i HOUR)",
            $hours
        );
    }

    /**
     * Sync a novoton booking to the shared travel_bookings table.
     *
     * Maps novoton-specific fields to the provider-agnostic schema.
     * Uses INSERT ... ON DUPLICATE KEY UPDATE for idempotency.
     */
    private function syncToTravelBookings(int $booking_id, array $data): void
    {
        $travel_record = [
            'provider' => 'novoton',
            'provider_booking_id' => (string) $booking_id,
            'order_id' => (int) ($data['order_id'] ?? 0),
            'user_id' => (int) ($data['user_id'] ?? 0),
            'hotel_id' => $data['hotel_id'] ?? '',
            'hotel_name' => $data['hotel_name'] ?? '',
            'room_name' => $data['room_type'] ?? '',
            'board_code' => $data['board_id'] ?? '',
            'check_in' => $data['check_in'] ?? '',
            'check_out' => $data['check_out'] ?? '',
            'nights' => (int) ($data['nights'] ?? 0),
            'adults' => (int) ($data['adults'] ?? 2),
            'children' => (int) ($data['children'] ?? 0),
            'children_ages' => $data['children_ages'] ?? '',
            'total_price' => (float) ($data['total_price'] ?? 0),
            'currency' => $data['currency'] ?? 'EUR',
            'status' => $data['status'] ?? TravelConstants::STATUS_PENDING,
            'guests_json' => $data['guests_data'] ?? '{}',
        ];

        // Atomic upsert — relies on UNIQUE KEY uq_provider_booking(provider, provider_booking_id)
        // Prevents race conditions and eliminates the SELECT round-trip
        db_query(
            "INSERT INTO ?:travel_bookings ?e ON DUPLICATE KEY UPDATE ?u",
            $travel_record, $travel_record
        );
    }

    /**
     * Sync partial updates from novoton_bookings to travel_bookings.
     *
     * Only syncs fields that travel_bookings actually stores.
     * Skips the sync if no travel_bookings-relevant fields were changed.
     */
    private function syncUpdateToTravelBookings(int $booking_id, array $data): void
    {
        // Map novoton field names → travel_bookings field names
        static $fieldMap = [
            'order_id' => 'order_id',
            'user_id' => 'user_id',
            'hotel_id' => 'hotel_id',
            'hotel_name' => 'hotel_name',
            'room_type' => 'room_name',
            'board_id' => 'board_code',
            'check_in' => 'check_in',
            'check_out' => 'check_out',
            'nights' => 'nights',
            'adults' => 'adults',
            'children' => 'children',
            'children_ages' => 'children_ages',
            'total_price' => 'total_price',
            'currency' => 'currency',
            'status' => 'status',
            'guests_data' => 'guests_json',
        ];

        $travelUpdate = [];
        foreach ($fieldMap as $novotonField => $travelField) {
            if (array_key_exists($novotonField, $data)) {
                $travelUpdate[$travelField] = $data[$novotonField];
            }
        }

        if (empty($travelUpdate)) {
            return;
        }

        db_query(
            "UPDATE ?:travel_bookings SET ?u WHERE provider = 'novoton' AND provider_booking_id = ?s",
            $travelUpdate, (string) $booking_id
        );
    }

    /**
     * Find bookings for admin listing with order info joined.
     *
     * @param string $condition Extra WHERE conditions (must start with " AND ...")
     * @param int    $limit
     * @return array
     */
    public function findForAdminList(string $condition = '', int $limit = 500): array
    {
        return db_get_array(
            "SELECT b.booking_id, b.order_id, b.hotel_id, b.hotel_name, b.room_type,
                    b.check_in, b.check_out, b.nights, b.adults, b.children,
                    b.total_price, b.currency, b.status, b.novoton_status, b.created_at,
                    o.status as order_status, o.email
             FROM ?:novoton_bookings b
             LEFT JOIN ?:orders o ON b.order_id = o.order_id
             WHERE 1=1 {$condition}
             ORDER BY b.created_at DESC
             LIMIT ?i",
            $limit
        );
    }

    /**
     * Find a booking with full order and product info for admin detail view.
     */
    public function findWithOrderDetails(int $booking_id): ?array
    {
        $row = db_get_row(
            "SELECT b.*, o.*, p.product
             FROM ?:novoton_bookings b
             LEFT JOIN ?:orders o ON b.order_id = o.order_id
             LEFT JOIN ?:products p ON b.product_id = p.product_id
             WHERE b.booking_id = ?i",
            $booking_id
        );
        return $row ?: null;
    }

    /**
     * Find all bookings with order info for CSV export.
     */
    public function findAllForExport(): array
    {
        return db_get_array(
            "SELECT b.*, o.email, o.status as order_status
             FROM ?:novoton_bookings b
             LEFT JOIN ?:orders o ON b.order_id = o.order_id
             ORDER BY b.created_at DESC"
        );
    }

    /**
     * Find booking by ownership (user_id or session_id) — for frontend security checks.
     */
    public function findByIdWithOwnership(int $booking_id, int $user_id, string $session_id): ?array
    {
        $row = db_get_row(
            "SELECT * FROM ?:novoton_bookings WHERE booking_id = ?i AND (user_id = ?i OR session_id = ?s)",
            $booking_id, $user_id, $session_id
        );
        return $row ?: null;
    }

    /**
     * Check booking ownership (returns booking_id or null).
     */
    public function checkOwnership(int $booking_id, int $user_id, string $session_id): ?int
    {
        $id = db_get_field(
            "SELECT booking_id FROM ?:novoton_bookings WHERE booking_id = ?i AND (user_id = ?i OR session_id = ?s)",
            $booking_id, $user_id, $session_id
        );
        return $id ? (int) $id : null;
    }

    /**
     * Filter null values from data array to prevent PHP 8.1+
     * real_escape_string() deprecation when passed to ?e / ?u placeholders.
     */
    private static function filterNullValues(array $data): array
    {
        return array_filter($data, static fn($v) => $v !== null);
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

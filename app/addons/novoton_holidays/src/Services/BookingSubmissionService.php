<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Booking Submission Service
 *
 * Extracted from order_hooks.php — encapsulates the entire booking submission
 * pipeline that runs when CS-Cart fires the place_order hook.
 *
 * Orchestration:
 *   1. hydrateBookingFromDb()       — merge DB data into cart data
 *   2. resolveRoomsAndGuests()      — parse rooms_data + guests_data
 *   3. groupRoomsByPackage()        — group rooms for API batching
 *   4. buildGroupGuestsAndRooms()   — construct per-group guest+room payload
 *   5. buildApiBookingRequest()     — construct API request payload
 *   6. persistBookingRecord()       — upsert booking row (transaction)
 *   7. submitAndRecordBooking()     — call API + update status
 *
 * @package NovotonHolidays
 * @since 3.4.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\NovotonApi;
use Tygh\Addons\NovotonHolidays\NovotonApiInterface;
use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\Repository\BookingRepository;
use Tygh\Addons\NovotonHolidays\Repository\BookingRepositoryInterface;
use Tygh\Addons\NovotonHolidays\Exceptions\ApiException;
use Tygh\Addons\NovotonHolidays\Exceptions\NovotonException;
use Tygh\Registry;

class BookingSubmissionService implements BookingSubmissionServiceInterface
{
    private BookingRepositoryInterface $bookingRepo;
    private NovotonApiInterface $api;

    public function __construct(BookingRepositoryInterface $bookingRepo, NovotonApiInterface $api)
    {
        $this->bookingRepo = $bookingRepo;
        $this->api = $api;
    }

    /**
     * Submit all Novoton bookings in the cart to the API.
     *
     * Called from the fn_novoton_holidays_place_order_post hook after CS-Cart
     * creates the order record.
     *
     * For multi-room bookings:
     *   - Sends ALL rooms in SINGLE API request IF same hotel, package, and dates
     *   - Sends SEPARATE API calls if rooms have different packages or dates
     */
    public function submitOrder(int $orderId, array $cart): void
    {
        if (empty($orderId) || empty($cart['products'])) {
            return;
        }

        $commission    = ConfigProvider::getCommission();
        $disableApi    = ConfigProvider::isApiDisabled();
        $debugLogging  = ConfigProvider::isDebugLogging();

        foreach ($cart['products'] as $cartId => $product) {
            if (empty($product['extra']['novoton_booking'])) {
                continue;
            }

            $bookingData       = $product['extra'];
            $originalBookingId = (int) ($bookingData['novoton_booking_id'] ?? 0);

            // 1. Hydrate booking data from DB (single source of truth)
            $bookingData = $this->hydrateBookingFromDb($bookingData, $originalBookingId, $debugLogging);

            // Resolve final price
            $finalPrice = $this->resolveFinalPrice($bookingData, $product);
            $bookingData['final_price'] = $finalPrice;

            if ($debugLogging) {
                fn_log_event('general', 'runtime', [
                    'message'     => 'Novoton - Final price determined',
                    'booking_id'  => $originalBookingId,
                    'final_price' => $finalPrice,
                    'hotel_id'    => $bookingData['hotel_id'] ?? 'NOT SET',
                ]);
            }

            // 2. Resolve rooms and guests
            [$roomsData, $guestsData] = $this->resolveRoomsAndGuests(
                $bookingData, $orderId, $debugLogging
            );

            // 3. Group rooms by (package + dates)
            $roomGroups = $this->groupRoomsByPackage($roomsData, $bookingData);

            if ($debugLogging) {
                fn_log_event('general', 'runtime', [
                    'message'      => 'Novoton - Room grouping result',
                    'order_id'     => $orderId,
                    'total_rooms'  => count($roomsData),
                    'groups_count' => count($roomGroups),
                    'can_combine'  => count($roomGroups) === 1 ? 'YES - single API call' : 'NO - multiple API calls needed',
                ]);
            }

            // 4-6. Process each group inside a DB transaction
            db_query("START TRANSACTION");

            try {
                $groupNum = 0;

                foreach ($roomGroups as $group) {
                    $groupNum++;

                    // 4. Build per-group guest list + API payload
                    [$allGuests, $apiRooms, $totalApiPrice, $totalGroupPrice] =
                        $this->buildGroupGuestsAndRooms($group, $guestsData, $bookingData, $commission);

                    $apiData = $this->buildApiBookingRequest(
                        $group, $allGuests, $apiRooms, $bookingData, $orderId, $groupNum, count($roomGroups)
                    );

                    // 5. Persist booking record (upsert)
                    $bookingRecord = $this->buildBookingRecord(
                        $group, $allGuests, $bookingData, $product,
                        $orderId, $groupNum, count($roomGroups),
                        $totalApiPrice, $totalGroupPrice,
                        $apiData, $disableApi
                    );

                    $bookingId = $this->persistBookingRecord(
                        $bookingRecord, $originalBookingId, $groupNum, $orderId
                    );

                    if ($debugLogging) {
                        fn_log_event('general', 'runtime', [
                            'message'        => 'Novoton Booking - API Request Prepared',
                            'order_id'       => $orderId,
                            'booking_id'     => $bookingId,
                            'group'          => $groupNum . ' of ' . count($roomGroups),
                            'rooms_in_group' => count($group['rooms']),
                            'api_disabled'   => $disableApi ? 'YES' : 'NO',
                        ]);
                    }

                    // 6. Submit to API and record response
                    $this->submitAndRecordBooking(
                        $apiData, $bookingId, $orderId, $groupNum,
                        $totalApiPrice, $disableApi, $debugLogging
                    );
                }

                db_query("COMMIT");

            } catch (ApiException $e) {
                db_query("ROLLBACK");
                fn_log_event('general', 'runtime', [
                    'message'      => 'Novoton Booking transaction rolled back (API error)',
                    'order_id'     => $orderId,
                    'api_function' => $e->getApiFunction(),
                    'http_code'    => $e->getHttpCode(),
                    'error'        => $e->getMessage(),
                ]);
            } catch (NovotonException $e) {
                db_query("ROLLBACK");
                fn_log_event('general', 'runtime', [
                    'message'  => 'Novoton Booking transaction rolled back',
                    'order_id' => $orderId,
                    'context'  => $e->getContext(),
                    'error'    => $e->getMessage(),
                ]);
            } catch (\Throwable $e) {
                db_query("ROLLBACK");
                fn_log_event('general', 'runtime', [
                    'message'  => 'Novoton Booking transaction rolled back (unexpected)',
                    'order_id' => $orderId,
                    'error'    => $e->getMessage(),
                ]);
            }
        }
    }

    // =========================================================================
    // PRIVATE PIPELINE METHODS
    // =========================================================================

    /**
     * Hydrate booking data from the canonical DB record.
     *
     * The database is the Single Source of Truth. Cart session data may be
     * stale or incomplete (e.g. after browser refresh), so we always merge
     * the DB row back into $bookingData before processing.
     */
    private function hydrateBookingFromDb(array $bookingData, int $bookingId, bool $debug): array
    {
        if ($bookingId <= 0) {
            return $bookingData;
        }

        $dbBooking = $this->bookingRepo->findByIdHydrated($bookingId);

        if (empty($dbBooking)) {
            return $bookingData;
        }

        // DB takes priority for critical financial and identity fields
        $priorityFields = [
            'total_price', 'base_price', 'hotel_id', 'hotel_name', 'package_name',
            'room_id', 'room_type', 'board_id', 'check_in', 'check_out', 'nights',
            'adults', 'children', 'children_ages', 'num_rooms',
            'holder_name', 'guest_name', 'special_requests',
            'terms_of_payment_raw', 'terms_of_cancellation_raw',
            'terms_of_payment_formatted', 'terms_of_cancellation_formatted',
        ];

        foreach ($priorityFields as $field) {
            if (!empty($dbBooking[$field])) {
                $bookingData[$field] = $dbBooking[$field];
            }
        }

        // Numeric casts
        $bookingData['total_price'] = (float) $bookingData['total_price'];
        $bookingData['base_price']  = (float) ($bookingData['base_price'] ?? 0);

        // Structured JSON fields — prefer already-parsed arrays from hydrated cache
        if (!empty($dbBooking['rooms_data'])) {
            $bookingData['rooms_data'] = $dbBooking['rooms_data'];
        }
        if (!empty($dbBooking['guests_data'])) {
            $bookingData['guests_data'] = $dbBooking['guests_data'];
        }

        if ($debug) {
            fn_log_event('general', 'runtime', [
                'message'     => 'Novoton - Fetched booking data from database',
                'booking_id'  => $bookingId,
                'total_price' => $dbBooking['total_price'],
                'hotel_name'  => $dbBooking['hotel_name'],
            ]);
        }

        return $bookingData;
    }

    /**
     * Determine the authoritative price for a booking.
     *
     * Priority: DB total_price > cart price > cart base_price.
     */
    private function resolveFinalPrice(array $bookingData, array $product): float
    {
        $price = (float) ($bookingData['total_price'] ?? 0);
        if ($price > 0) {
            return $price;
        }

        $price = (float) ($product['price'] ?? 0);
        if ($price > 0) {
            return $price;
        }

        return (float) ($product['base_price'] ?? 0);
    }

    /**
     * Parse rooms_data and guests_data, with DB fallbacks.
     *
     * @return array{0: array, 1: array} [rooms_data[], guests_data[]]
     */
    private function resolveRoomsAndGuests(array $bookingData, int $orderId, bool $debug): array
    {
        // --- rooms_data ---
        $roomsData = [];
        if (!empty($bookingData['rooms_data'])) {
            $roomsData = is_string($bookingData['rooms_data'])
                ? json_decode($bookingData['rooms_data'], true)
                : $bookingData['rooms_data'];
        }

        // Synthesise a single-room entry when rooms_data is absent
        if (empty($roomsData)) {
            $childrenAges = [];
            if (!empty($bookingData['children_ages'])) {
                $childrenAges = is_string($bookingData['children_ages'])
                    ? array_map('intval', array_filter(explode(',', $bookingData['children_ages']), function ($v) { return $v !== ''; }))
                    : (array) $bookingData['children_ages'];
            }

            $roomsData = [[
                'room_id'           => $bookingData['room_id'],
                'room_name'         => $bookingData['room_type'] ?? $bookingData['room_name'] ?? $bookingData['room_id'],
                'room_type_display' => $bookingData['room_type'] ?? $bookingData['room_name'] ?? $bookingData['room_id'],
                'board_id'          => $bookingData['board_id'],
                'board_name'        => $bookingData['board_name'] ?? $bookingData['board_id'],
                'package_name'      => $bookingData['package_name'] ?? '',
                'check_in'          => $bookingData['check_in'],
                'check_out'         => $bookingData['check_out'],
                'adults'            => (int) ($bookingData['adults'] ?? 2),
                'children'          => (int) ($bookingData['children'] ?? 0),
                'childrenAges'      => $childrenAges,
                'price'             => $bookingData['final_price'],
            ]];
        }

        // --- guests_data ---
        $guestsData = $this->resolveGuestsData($bookingData, $orderId, $debug);

        return [$roomsData, $guestsData];
    }

    /**
     * Resolve guests_data with multiple fallback strategies.
     *
     * 1. From cart extra (already hydrated from DB if available)
     * 2. Re-fetch from DB by booking_id
     * 3. Match unassigned pending booking by hotel + dates
     */
    private function resolveGuestsData(array $bookingData, int $orderId, bool $debug): array
    {
        // Primary: normalize from cart/DB data (handles both keyed and legacy array formats)
        if (!empty($bookingData['guests_data'])) {
            $guestsData = GuestDataNormalizer::normalize($bookingData['guests_data']);
            if (!empty($guestsData)) {
                return $guestsData;
            }
        }

        // Fallback 1: re-fetch from DB by booking_id
        $bookingId = (int) ($bookingData['novoton_booking_id'] ?? 0);
        if ($bookingId > 0) {
            $dbGuests = $this->bookingRepo->getGuestsData($bookingId);
            if (!empty($dbGuests)) {
                $guestsData = GuestDataNormalizer::normalize($dbGuests);
                if ($debug) {
                    fn_log_event('general', 'runtime', [
                        'message'      => 'Novoton - Fetched guests_data from database (cart was empty)',
                        'booking_id'   => $bookingId,
                        'guests_count' => count($guestsData),
                    ]);
                }
                if (!empty($guestsData)) {
                    return $guestsData;
                }
            }
        }

        // Fallback 2: match unassigned pending booking by hotel + dates
        $existing = $this->bookingRepo->findUnassignedByHotelDates(
            $bookingData['hotel_id'] ?? '',
            $bookingData['check_in'] ?? '',
            $bookingData['check_out'] ?? ''
        );
        if (!empty($existing['guests_data'])) {
            $guestsData = GuestDataNormalizer::normalize($existing['guests_data']);
            if ($debug) {
                fn_log_event('general', 'runtime', [
                    'message'      => 'Novoton - Fetched guests_data from pending booking record',
                    'holder_name'  => $existing['holder_name'] ?? '',
                    'guests_count' => count($guestsData),
                ]);
            }
            return $guestsData;
        }

        return [];
    }

    /**
     * Group rooms by (package_name + check_in + check_out).
     *
     * Rooms with identical grouping keys can be sent in a single API call.
     *
     * @return array<string, array{package_name: string, check_in: string, check_out: string, rooms: array}>
     */
    private function groupRoomsByPackage(array $roomsData, array $bookingData): array
    {
        $groups          = [];
        $defaultPackage  = $bookingData['package_name'] ?? '';
        $defaultCheckIn  = $bookingData['check_in'];
        $defaultCheckOut = $bookingData['check_out'];

        foreach ($roomsData as $roomIdx => $room) {
            $package  = $room['package_name'] ?? $defaultPackage;
            $checkIn  = $room['check_in']     ?? $defaultCheckIn;
            $checkOut = $room['check_out']    ?? $defaultCheckOut;

            $groupKey = md5($package . '|' . $checkIn . '|' . $checkOut);

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'package_name' => $package,
                    'check_in'     => $checkIn,
                    'check_out'    => $checkOut,
                    'rooms'        => [],
                ];
            }

            $room['original_index'] = $roomIdx;
            $groups[$groupKey]['rooms'][] = $room;
        }

        return $groups;
    }

    /**
     * Build per-group guest list and API room payload.
     *
     * For each room in the group, extracts adult/child guest names from
     * guestsData (keyed "room{N}_adult_{I}" / "room{N}_child_{I}") and
     * calculates the API price (without commission).
     *
     * @return array{0: array, 1: array, 2: float, 3: float} [allGuests[], apiRooms[], totalApiPrice, totalGroupPrice]
     */
    private function buildGroupGuestsAndRooms(
        array $group,
        array $guestsData,
        array $bookingData,
        float $commission
    ): array {
        $allGuests       = [];
        $apiRooms        = [];
        $totalApiPrice   = 0.0;
        $totalGroupPrice = 0.0;

        foreach ($group['rooms'] as $room) {
            $roomIdx      = $room['original_index'];
            $roomNum      = $roomIdx + 1;
            $adultsCount  = (int) ($room['adults']   ?? 2);
            $childrenCount = (int) ($room['children'] ?? 0);
            $childrenAges = $room['childrenAges'] ?? [];
            $roomGuests   = [];

            // Adults
            for ($i = 1; $i <= $adultsCount; $i++) {
                $guestKey = "room{$roomNum}_adult_{$i}";
                $name = $this->extractGuestName($guestsData, $guestKey);

                if (empty($name)) {
                    $name = ($roomNum === 1 && $i === 1 && !empty($bookingData['holder_name']))
                        ? $bookingData['holder_name']
                        : "Adult {$i} Room {$roomNum}";
                }

                $guest = [
                    'name'     => $name,
                    'birthday' => $guestsData[$guestKey]['birthday'] ?? '',
                    'age'      => (int) ($guestsData[$guestKey]['age'] ?? 30),
                    'type'     => 'adult',
                    'room'     => $roomNum,
                ];
                $roomGuests[] = $guest;
                $allGuests[]  = $guest;
            }

            // Children
            for ($i = 1; $i <= $childrenCount; $i++) {
                $guestKey = "room{$roomNum}_child_{$i}";
                $name = $this->extractGuestName($guestsData, $guestKey);

                if (empty($name)) {
                    $name = "Child {$i} Room {$roomNum}";
                }

                $age = 0;
                if (isset($guestsData[$guestKey]['age'])) {
                    $age = (int) $guestsData[$guestKey]['age'];
                } elseif (isset($childrenAges[$i - 1])) {
                    $age = (int) $childrenAges[$i - 1];
                }

                if ($age <= 0) {
                    fn_log_event('general', 'runtime', [
                        'message' => 'Novoton - Child age missing, cannot determine correct pricing',
                        'guest_key' => $guestKey,
                        'room_num' => $roomNum,
                    ]);
                }

                $guest = [
                    'name'     => $name,
                    'birthday' => $guestsData[$guestKey]['birthday'] ?? '',
                    'age'      => $age,
                    'type'     => 'child',
                    'room'     => $roomNum,
                ];
                $roomGuests[] = $guest;
                $allGuests[]  = $guest;
            }

            // Price: reverse commission to get API (net) price
            $roomPriceWithCommission = (float) ($room['price'] ?? 0);
            $roomApiPrice            = $roomPriceWithCommission / (1 + ($commission / 100));
            $totalApiPrice   += $roomApiPrice;
            $totalGroupPrice += $roomPriceWithCommission;

            $apiRooms[] = [
                'room_id'  => $room['room_id']  ?? $bookingData['room_id'],
                'board_id' => $room['board_id'] ?? $bookingData['board_id'],
                'guests'   => $roomGuests,
            ];
        }

        return [$allGuests, $apiRooms, $totalApiPrice, $totalGroupPrice];
    }

    /**
     * Extract a guest name from the guestsData array.
     *
     * Prefers api_name (First Last format) over display_name/name.
     */
    private function extractGuestName(array $guestsData, string $guestKey): string
    {
        if (!isset($guestsData[$guestKey])) {
            return '';
        }

        $entry = $guestsData[$guestKey];

        if (!empty($entry['api_name'])) {
            return $entry['api_name'];
        }
        if (!empty($entry['name'])) {
            return $entry['name'];
        }

        return '';
    }

    /**
     * Construct the Novoton reservation API request payload.
     */
    private function buildApiBookingRequest(
        array $group,
        array $allGuests,
        array $apiRooms,
        array $bookingData,
        int   $orderId,
        int   $groupNum,
        int   $totalGroups
    ): array {
        $suffix = $totalGroups > 1 ? "-G{$groupNum}" : '';

        $apiData = [
            'hotel_id'     => $bookingData['hotel_id'],
            'package_name' => $group['package_name'],
            'check_in'     => $group['check_in'],
            'check_out'    => $group['check_out'],
            'holder'       => $allGuests[0]['name'] ?? $bookingData['holder_name'] ?? 'Guest',
            'guests'       => $allGuests,
            'rooms'        => $apiRooms,
            'order_num'    => $orderId . $suffix,
            'remark'       => $bookingData['special_requests'] ?? '',
            'comment'      => $bookingData['special_requests'] ?? '',
        ];

        // Single-room shortcut
        if (count($group['rooms']) === 1) {
            $apiData['room_id']  = $group['rooms'][0]['room_id']  ?? $bookingData['room_id'];
            $apiData['board_id'] = $group['rooms'][0]['board_id'] ?? $bookingData['board_id'];
        }

        return $apiData;
    }

    /**
     * Build the booking record array for DB persistence.
     *
     * @return array Column => value map for novoton_bookings
     */
    private function buildBookingRecord(
        array $group,
        array $allGuests,
        array $bookingData,
        array $product,
        int   $orderId,
        int   $groupNum,
        int   $totalGroups,
        float $totalApiPrice,
        float $totalGroupPrice,
        array $apiData,
        bool  $disableApi
    ): array {
        $groupRooms = $group['rooms'];

        // Calculate nights safely
        try {
            $checkInDate  = new \DateTime($group['check_in']);
            $checkOutDate = new \DateTime($group['check_out']);
            $nights       = $checkInDate->diff($checkOutDate)->days;
        } catch (\InvalidArgumentException $e) {
            fn_log_event('general', 'error', [
                'message'   => 'Novoton - Invalid date in booking group',
                'check_in'  => $group['check_in'] ?? '',
                'check_out' => $group['check_out'] ?? '',
                'error'     => $e->getMessage(),
            ]);
            $nights = (int) ($bookingData['nights'] ?? 7);
        }

        $orderInfo   = fn_get_order_info($orderId);
        $orderUserId = (int) ($orderInfo['user_id'] ?? 0);
        $orderEmail  = $orderInfo['email'] ?? '';

        return [
            'order_id'         => $orderId,
            'product_id'       => $product['product_id'],
            'item_id'          => $product['item_id'] ?? '',
            'hotel_id'         => $bookingData['hotel_id'],
            'hotel_name'       => $bookingData['hotel_name'] ?? '',
            'package_name'     => $group['package_name'],
            'room_id'          => implode(', ', array_column($groupRooms, 'room_id')),
            'room_type'        => $groupRooms[0]['room_type_display'] ?? $groupRooms[0]['room_name'] ?? '',
            'board_id'         => $groupRooms[0]['board_id'] ?? $bookingData['board_id'],
            'board_name'       => $groupRooms[0]['board_name'] ?? $bookingData['board_name'] ?? '',
            'check_in'         => $group['check_in'],
            'check_out'        => $group['check_out'],
            'nights'           => $nights,
            'adults'           => array_sum(array_column($groupRooms, 'adults')),
            'children'         => array_sum(array_column($groupRooms, 'children')),
            'children_ages'    => $bookingData['children_ages'] ?? '',
            'num_rooms'        => count($groupRooms),
            'room_number'      => $groupNum,
            'total_rooms'      => $totalGroups,
            'rooms_data'       => json_encode($groupRooms),
            'guest_name'       => implode(', ', array_column($allGuests, 'name')),
            'holder_name'      => $allGuests[0]['name'] ?? $bookingData['holder_name'] ?? '',
            'guests_data'      => json_encode($allGuests),
            'base_price'       => $totalApiPrice,
            'total_price'      => $totalGroupPrice,
            'currency'         => ConfigProvider::getApiCurrency(),
            'status'           => Constants::STATUS_PENDING,
            'special_requests' => $bookingData['special_requests'] ?? '',
            'api_request'      => json_encode($apiData),
            'notes'                          => $disableApi ? 'API submission disabled - test mode' : '',
            'user_id'                        => $orderUserId,
            'guest_email'                    => $orderEmail,
            'terms_of_payment_raw'           => $bookingData['terms_of_payment_raw'] ?? null,
            'terms_of_cancellation_raw'      => $bookingData['terms_of_cancellation_raw'] ?? null,
            'terms_of_payment_formatted'     => $bookingData['terms_of_payment'] ?? $bookingData['terms_of_payment_formatted'] ?? null,
            'terms_of_cancellation_formatted' => $bookingData['terms_of_cancellation'] ?? $bookingData['terms_of_cancellation_formatted'] ?? null,
        ];
    }

    /**
     * Upsert a booking record using the Single Source of Truth pattern.
     *
     * Priority:
     *   1. Update by originalBookingId (from cart) for group 1
     *   2. Update existing row matching (order + hotel + dates) to prevent duplicates
     *   3. Insert new row
     */
    private function persistBookingRecord(
        array $record,
        int   $originalBookingId,
        int   $groupNum,
        int   $orderId
    ): int {
        // Group 1: update the original booking from cart
        if ($groupNum === 1 && $originalBookingId > 0) {
            $this->bookingRepo->update($originalBookingId, $record);
            return $originalBookingId;
        }

        // Dedup: find existing booking for this order + hotel + dates
        $existingId = $this->bookingRepo->findIdByOrderAndHotelDates(
            $orderId, $record['hotel_id'], $record['check_in'], $record['check_out']
        );

        if ($existingId) {
            $this->bookingRepo->update($existingId, $record);
            return $existingId;
        }

        // New booking
        $record['session_id'] = session_id();
        return $this->bookingRepo->create($record);
    }

    /**
     * Submit booking to the Novoton reservation API and update the DB row.
     *
     * Uses Constants::NOVOTON_STATUS_TO_INTERNAL for status mapping.
     */
    private function submitAndRecordBooking(
        array $apiData,
        int   $bookingId,
        int   $orderId,
        int   $groupNum,
        float $totalApiPrice,
        bool  $disableApi,
        bool  $debug
    ): void {
        if ($disableApi) {
            $this->bookingRepo->update($bookingId, ['notes' => 'API submission disabled - booking saved locally only.']);
            return;
        }

        try {
            $response = $this->api->createReservation($apiData);

            if ($response) {
                $novotonId     = (string) ($response->IdNum   ?? '');
                $novotonStatus = (string) ($response->Status  ?? '');
                $novotonPrice  = (string) ($response->Price   ?? '');

                $update = [
                    'novoton_invoice_id' => $novotonId,
                    'novoton_status'     => $novotonStatus,
                    'api_price'          => !empty($novotonPrice) ? (float) $novotonPrice : $totalApiPrice,
                    'api_response'       => json_encode([
                        'IdNum'    => $novotonId,
                        'Price'    => $novotonPrice,
                        'Currency' => (string) ($response->Currency ?? 'EUR'),
                        'Quota'    => (string) ($response->Quota    ?? ''),
                        'Status'   => $novotonStatus,
                    ]),
                ];

                // Map API status to internal status via centralized constant
                $statusMap = Constants::NOVOTON_STATUS_TO_INTERNAL;
                if (isset($statusMap[$novotonStatus])) {
                    $update['status'] = $statusMap[$novotonStatus];
                }

                $this->bookingRepo->update($bookingId, $update);

                if ($debug) {
                    fn_log_event('general', 'runtime', [
                        'message'    => 'Novoton Booking - API Response',
                        'order_id'   => $orderId,
                        'booking_id' => $bookingId,
                        'novoton_id' => $novotonId,
                        'status'     => $novotonStatus,
                    ]);
                }
            }
        } catch (ApiException $e) {
            $this->bookingRepo->update($bookingId, [
                'status' => 'failed',
                'notes'  => 'API Error (' . $e->getApiFunction() . ', HTTP ' . $e->getHttpCode() . '): ' . $e->getMessage(),
            ]);

            fn_log_event('general', 'runtime', [
                'message'  => 'Novoton Booking API Error',
                'order_id' => $orderId,
                'group'    => $groupNum,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}

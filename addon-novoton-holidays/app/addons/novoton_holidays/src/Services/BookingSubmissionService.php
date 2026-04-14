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

use Tygh\Addons\NovotonHolidays\Api\Contracts\ReservationApiClientInterface;
use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\Exceptions\ApiException;
use Tygh\Addons\NovotonHolidays\Exceptions\NovotonException;
use Tygh\Addons\NovotonHolidays\Repository\BookingRepositoryInterface;
use Tygh\Addons\TravelCore\Services\GuestDataNormalizer;
use Tygh\Addons\TravelCore\TravelConstants;

class BookingSubmissionService implements BookingSubmissionServiceInterface
{
    private readonly BookingRepositoryInterface $bookingRepo;
    private readonly ReservationApiClientInterface $reservations;
    private readonly GuestDataNormalizer $guestDataNormalizer;

    public function __construct(
        BookingRepositoryInterface $bookingRepo,
        ReservationApiClientInterface $reservations,
        ?GuestDataNormalizer $guestDataNormalizer = null,
    ) {
        $this->bookingRepo = $bookingRepo;
        $this->reservations = $reservations;
        $this->guestDataNormalizer = $guestDataNormalizer ?? new GuestDataNormalizer();
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
     * @param array<string, mixed> $cart
     */
    public function submitOrder(int $orderId, array $cart): void
    {
        if (empty($orderId) || empty($cart['products'])) {
            return;
        }

        $commission = ConfigProvider::getCommission();
        $disableApi = ConfigProvider::isApiDisabled();
        $debugLogging = ConfigProvider::isDebugLogging();
        $orderComment = trim((string) ($cart['notes'] ?? ''));

        $cartProducts = is_array($cart['products'] ?? null) ? $cart['products'] : [];
        foreach ($cartProducts as $cartId => $product) {
            if (!is_array($product)) {
                continue;
            }
            /** @var array<string, mixed> $extra */
            $extra = is_array($product['extra'] ?? null) ? $product['extra'] : [];
            if (empty($extra['novoton_booking'])) {
                continue;
            }

            /** @var array<string, mixed> $bookingData */
            $bookingData = $extra;
            $originalBookingId = PriceInfoFormatter::toInt($bookingData['novoton_booking_id'] ?? 0);

            // 1. Hydrate booking data from DB (single source of truth)
            $bookingData = $this->hydrateBookingFromDb($bookingData, $originalBookingId, $debugLogging);

            // Resolve final price
            $finalPrice = $this->resolveFinalPrice($bookingData, $product);
            $bookingData['final_price'] = $finalPrice;

            if ($debugLogging) {
                fn_log_event('general', 'runtime', [
                    'message' => 'Novoton - Final price determined',
                    'booking_id' => $originalBookingId,
                    'final_price' => $finalPrice,
                    'hotel_id' => $bookingData['hotel_id'] ?? 'NOT SET',
                ]);
            }

            // 2. Resolve rooms and guests
            [$roomsData, $guestsData] = $this->resolveRoomsAndGuests(
                $bookingData,
                $orderId,
                $debugLogging,
            );

            // 3. Group rooms by (package + dates)
            $roomGroups = $this->groupRoomsByPackage($roomsData, $bookingData);

            if ($debugLogging) {
                fn_log_event('general', 'runtime', [
                    'message' => 'Novoton - Room grouping result',
                    'order_id' => $orderId,
                    'total_rooms' => count($roomsData),
                    'groups_count' => count($roomGroups),
                    'can_combine' => count($roomGroups) === 1 ? 'YES - single API call' : 'NO - multiple API calls needed',
                ]);
            }

            // 4-6. Process each group inside a DB transaction
            db_query('START TRANSACTION');

            try {
                $groupNum = 0;

                foreach ($roomGroups as $group) {
                    $groupNum++;

                    // 4. Build per-group guest list + API payload
                    [$allGuests, $apiRooms, $totalApiPrice, $totalGroupPrice] =
                        $this->buildGroupGuestsAndRooms($group, $guestsData, $bookingData, $commission);

                    $apiData = $this->buildApiBookingRequest(
                        $group,
                        $allGuests,
                        $apiRooms,
                        $bookingData,
                        $orderId,
                        $groupNum,
                        count($roomGroups),
                        $orderComment,
                    );

                    // 5. Persist booking record (upsert)
                    $bookingRecord = $this->buildBookingRecord(
                        $group,
                        $allGuests,
                        $bookingData,
                        $product,
                        $orderId,
                        $groupNum,
                        count($roomGroups),
                        $totalApiPrice,
                        $totalGroupPrice,
                        $apiData,
                        $disableApi,
                    );

                    $bookingId = $this->persistBookingRecord(
                        $bookingRecord,
                        $originalBookingId,
                        $groupNum,
                        $orderId,
                    );

                    if ($debugLogging) {
                        fn_log_event('general', 'runtime', [
                            'message' => 'Novoton Booking - API Request Prepared',
                            'order_id' => $orderId,
                            'booking_id' => $bookingId,
                            'group' => $groupNum . ' of ' . count($roomGroups),
                            'rooms_in_group' => count($group['rooms']),
                            'api_disabled' => $disableApi ? 'YES' : 'NO',
                        ]);
                    }

                    // 6. Submit to API and record response
                    $this->submitAndRecordBooking(
                        $apiData,
                        $bookingId,
                        $orderId,
                        $groupNum,
                        $totalApiPrice,
                        $disableApi,
                        $debugLogging,
                    );
                }

                db_query('COMMIT');
            } catch (ApiException $e) {
                db_query('ROLLBACK');

                // ROLLBACK undoes the failed status set inside submitAndRecordBooking().
                // Re-apply failed status OUTSIDE the transaction so it persists.
                if ($originalBookingId > 0) {
                    $this->bookingRepo->update($originalBookingId, [
                        'status' => TravelConstants::STATUS_FAILED,
                        'order_id' => $orderId,
                        'notes' => 'API Error (' . $e->getApiFunction() . ', HTTP ' . $e->getHttpCode() . '): ' . $e->getMessage(),
                    ]);
                }

                fn_log_event('general', 'runtime', [
                    'message' => 'Novoton Booking transaction rolled back (API error)',
                    'order_id' => $orderId,
                    'api_function' => $e->getApiFunction(),
                    'http_code' => $e->getHttpCode(),
                    'error' => $e->getMessage(),
                ]);
            } catch (NovotonException $e) {
                db_query('ROLLBACK');

                if ($originalBookingId > 0) {
                    $this->bookingRepo->update($originalBookingId, [
                        'status' => TravelConstants::STATUS_FAILED,
                        'order_id' => $orderId,
                        'notes' => 'Booking error (' . json_encode($e->getContext()) . '): ' . $e->getMessage(),
                    ]);
                }

                fn_log_event('general', 'runtime', [
                    'message' => 'Novoton Booking transaction rolled back',
                    'order_id' => $orderId,
                    'context' => $e->getContext(),
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable $e) {
                db_query('ROLLBACK');

                if ($originalBookingId > 0) {
                    $this->bookingRepo->update($originalBookingId, [
                        'status' => TravelConstants::STATUS_FAILED,
                        'order_id' => $orderId,
                        'notes' => 'Unexpected error: ' . $e->getMessage(),
                    ]);
                }

                fn_log_event('general', 'runtime', [
                    'message' => 'Novoton Booking transaction rolled back (unexpected)',
                    'order_id' => $orderId,
                    'error' => $e->getMessage(),
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
     * @param array<string, mixed> $bookingData
     * @return array<string, mixed>
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
            'holder_name', 'guest_name',
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
        $bookingData['base_price'] = (float) ($bookingData['base_price'] ?? 0);

        // Structured JSON fields — prefer already-parsed arrays from hydrated cache
        if (!empty($dbBooking['rooms_data'])) {
            $bookingData['rooms_data'] = $dbBooking['rooms_data'];
        }
        if (!empty($dbBooking['guests_data'])) {
            $bookingData['guests_data'] = $dbBooking['guests_data'];
        }

        if ($debug) {
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton - Fetched booking data from database',
                'booking_id' => $bookingId,
                'total_price' => $dbBooking['total_price'],
                'hotel_name' => $dbBooking['hotel_name'],
            ]);
        }

        return $bookingData;
    }

    /**
     * Determine the authoritative price for a booking.
     *
     * Priority: DB total_price > cart price > cart base_price.
     * @param array<string, mixed> $bookingData
     * @param array<string, mixed> $product
     */
    private function resolveFinalPrice(array $bookingData, array $product): float
    {
        $price = PriceInfoFormatter::toFloat($bookingData['total_price'] ?? 0);
        if ($price > 0) {
            return $price;
        }

        $price = PriceInfoFormatter::toFloat($product['price'] ?? 0);
        if ($price > 0) {
            return $price;
        }

        return PriceInfoFormatter::toFloat($product['base_price'] ?? 0);
    }

    /**
     * Parse rooms_data and guests_data, with DB fallbacks.
     *
     * @param array<string, mixed> $bookingData
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>} [rooms_data[], guests_data[]]
     */
    private function resolveRoomsAndGuests(array $bookingData, int $orderId, bool $debug): array
    {
        // --- rooms_data ---
        $roomsData = [];
        if (!empty($bookingData['rooms_data'])) {
            $decoded = is_string($bookingData['rooms_data'])
                ? json_decode($bookingData['rooms_data'], true)
                : $bookingData['rooms_data'];
            $roomsData = is_array($decoded) ? $decoded : [];
        }

        // Synthesise a single-room entry when rooms_data is absent
        if (empty($roomsData)) {
            $childrenAges = [];
            if (!empty($bookingData['children_ages'])) {
                $childrenAges = is_string($bookingData['children_ages'])
                    ? array_map('intval', array_filter(explode(',', $bookingData['children_ages']), fn ($v) => $v !== ''))
                    : (array) $bookingData['children_ages'];
            }

            $roomsData = [[
                'room_id' => PriceInfoFormatter::toScalar($bookingData['room_id'] ?? ''),
                'room_name' => PriceInfoFormatter::toScalar($bookingData['room_type'] ?? $bookingData['room_name'] ?? $bookingData['room_id'] ?? ''),
                'room_type_display' => PriceInfoFormatter::toScalar($bookingData['room_type'] ?? $bookingData['room_name'] ?? $bookingData['room_id'] ?? ''),
                'board_id' => PriceInfoFormatter::toScalar($bookingData['board_id'] ?? ''),
                'board_name' => PriceInfoFormatter::toScalar($bookingData['board_name'] ?? $bookingData['board_id'] ?? ''),
                'package_name' => PriceInfoFormatter::toScalar($bookingData['package_name'] ?? ''),
                'check_in' => PriceInfoFormatter::toScalar($bookingData['check_in'] ?? ''),
                'check_out' => PriceInfoFormatter::toScalar($bookingData['check_out'] ?? ''),
                'adults' => PriceInfoFormatter::toInt($bookingData['adults'] ?? 2),
                'children' => PriceInfoFormatter::toInt($bookingData['children'] ?? 0),
                'childrenAges' => $childrenAges,
                'price' => PriceInfoFormatter::toFloat($bookingData['final_price'] ?? 0),
            ]];
        }

        // --- guests_data ---
        $guestsData = $this->resolveGuestsData($bookingData, $orderId, $debug);

        /** @var list<array<string, mixed>> $roomsData */
        return [$roomsData, $guestsData];
    }

    /**
     * Resolve guests_data with multiple fallback strategies.
     *
     * 1. From cart extra (already hydrated from DB if available)
     * 2. Re-fetch from DB by booking_id
     * 3. Match unassigned pending booking by hotel + dates
     * @param array<string, mixed> $bookingData
     * @return array<string, mixed>
     */
    private function resolveGuestsData(array $bookingData, int $orderId, bool $debug): array
    {
        // Primary: normalize from cart/DB data (handles both keyed and legacy array formats)
        if (!empty($bookingData['guests_data'])) {
            $guestsData = $this->guestDataNormalizer->normalize($bookingData['guests_data']);
            if (!empty($guestsData)) {
                return $guestsData;
            }
        }

        // Fallback 1: re-fetch from DB by booking_id
        $bookingId = PriceInfoFormatter::toInt($bookingData['novoton_booking_id'] ?? 0);
        if ($bookingId > 0) {
            $dbGuests = $this->bookingRepo->getGuestsData($bookingId);
            if (!empty($dbGuests)) {
                $guestsData = $this->guestDataNormalizer->normalize($dbGuests);
                if ($debug) {
                    fn_log_event('general', 'runtime', [
                        'message' => 'Novoton - Fetched guests_data from database (cart was empty)',
                        'booking_id' => $bookingId,
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
            PriceInfoFormatter::toScalar($bookingData['hotel_id'] ?? ''),
            PriceInfoFormatter::toScalar($bookingData['check_in'] ?? ''),
            PriceInfoFormatter::toScalar($bookingData['check_out'] ?? ''),
        );
        if (!empty($existing['guests_data'])) {
            $guestsData = $this->guestDataNormalizer->normalize($existing['guests_data']);
            if ($debug) {
                fn_log_event('general', 'runtime', [
                    'message' => 'Novoton - Fetched guests_data from pending booking record',
                    'holder_name' => $existing['holder_name'] ?? '',
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
     * @param list<array<string, mixed>> $roomsData
     * @param array<string, mixed> $bookingData
     * @return array<string, array{package_name: string, check_in: string, check_out: string, rooms: list<array<string, mixed>>}>
     */
    private function groupRoomsByPackage(array $roomsData, array $bookingData): array
    {
        $groups = [];
        $defaultPackage = PriceInfoFormatter::toScalar($bookingData['package_name'] ?? '');
        $defaultCheckIn = PriceInfoFormatter::toScalar($bookingData['check_in'] ?? '');
        $defaultCheckOut = PriceInfoFormatter::toScalar($bookingData['check_out'] ?? '');

        foreach ($roomsData as $roomIdx => $room) {
            if (!is_array($room)) {
                continue;
            }
            $package = PriceInfoFormatter::toScalar($room['package_name'] ?? $defaultPackage);
            $checkIn = PriceInfoFormatter::toScalar($room['check_in'] ?? $defaultCheckIn);
            $checkOut = PriceInfoFormatter::toScalar($room['check_out'] ?? $defaultCheckOut);

            $groupKey = md5($package . '|' . $checkIn . '|' . $checkOut);

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'package_name' => $package,
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                    'rooms' => [],
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
     * @param array<string, mixed> $group
     * @param array<int|string, array<string, mixed>> $guestsData
     * @param array<string, mixed> $bookingData
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: float, 3: float} [allGuests[], apiRooms[], totalApiPrice, totalGroupPrice]
     */
    private function buildGroupGuestsAndRooms(
        array $group,
        array $guestsData,
        array $bookingData,
        float $commission,
    ): array {
        $allGuests = [];
        $apiRooms = [];
        $totalApiPrice = 0.0;
        $totalGroupPrice = 0.0;

        $groupRooms = is_array($group['rooms'] ?? null) ? $group['rooms'] : [];
        foreach ($groupRooms as $room) {
            if (!is_array($room)) {
                continue;
            }
            $roomIdx = PriceInfoFormatter::toInt($room['original_index'] ?? 0);
            $roomNum = $roomIdx + 1;
            $adultsCount = PriceInfoFormatter::toInt($room['adults'] ?? 2);
            $childrenCount = PriceInfoFormatter::toInt($room['children'] ?? 0);
            $childrenAges = is_array($room['childrenAges'] ?? null) ? $room['childrenAges'] : [];
            $roomGuests = [];

            // Adults
            for ($i = 1; $i <= $adultsCount; $i++) {
                $guestKey = "room{$roomNum}_adult_{$i}";
                $name = $this->extractGuestName($guestsData, $guestKey);

                if (empty($name)) {
                    $name = ($roomNum === 1 && $i === 1 && !empty($bookingData['holder_name']))
                        ? PriceInfoFormatter::toScalar($bookingData['holder_name'])
                        : "Adult {$i} Room {$roomNum}";
                }

                $guestEntry = is_array($guestsData[$guestKey] ?? null) ? $guestsData[$guestKey] : [];
                $guest = [
                    'name' => $name,
                    'birthday' => PriceInfoFormatter::toScalar($guestEntry['birthday'] ?? ''),
                    'age' => PriceInfoFormatter::toInt($guestEntry['age'] ?? 30),
                    'type' => 'adult',
                    'room' => $roomNum,
                ];
                $roomGuests[] = $guest;
                $allGuests[] = $guest;
            }

            // Children
            for ($i = 1; $i <= $childrenCount; $i++) {
                $guestKey = "room{$roomNum}_child_{$i}";
                $name = $this->extractGuestName($guestsData, $guestKey);

                if (empty($name)) {
                    $name = "Child {$i} Room {$roomNum}";
                }

                $childEntry = is_array($guestsData[$guestKey] ?? null) ? $guestsData[$guestKey] : [];
                $age = 0;
                if (isset($childEntry['age'])) {
                    $age = PriceInfoFormatter::toInt($childEntry['age']);
                } elseif (isset($childrenAges[$i - 1])) {
                    $age = PriceInfoFormatter::toInt($childrenAges[$i - 1]);
                }

                if ($age <= 0) {
                    fn_log_event('general', 'runtime', [
                        'message' => 'Novoton - Child age missing, cannot determine correct pricing',
                        'guest_key' => $guestKey,
                        'room_num' => $roomNum,
                    ]);
                }

                $guest = [
                    'name' => $name,
                    'birthday' => PriceInfoFormatter::toScalar($childEntry['birthday'] ?? ''),
                    'age' => $age,
                    'type' => 'child',
                    'room' => $roomNum,
                ];
                $roomGuests[] = $guest;
                $allGuests[] = $guest;
            }

            // Price: reverse commission to get API (net) price
            $roomPriceWithCommission = PriceInfoFormatter::toFloat($room['price'] ?? 0);
            $roomApiPrice = $roomPriceWithCommission / (1 + ($commission / 100));
            $totalApiPrice += $roomApiPrice;
            $totalGroupPrice += $roomPriceWithCommission;

            $apiRooms[] = [
                'room_id' => PriceInfoFormatter::toScalar($room['room_id'] ?? $bookingData['room_id'] ?? ''),
                'board_id' => PriceInfoFormatter::toScalar($room['board_id'] ?? $bookingData['board_id'] ?? ''),
                'guests' => $roomGuests,
            ];
        }

        return [$allGuests, $apiRooms, $totalApiPrice, $totalGroupPrice];
    }

    /**
     * Extract a guest name from the guestsData array.
     *
     * Prefers api_name (First Last format) over display_name/name.
     * @param array<int|string, array<string, mixed>> $guestsData
     */
    private function extractGuestName(array $guestsData, string $guestKey): string
    {
        if (!isset($guestsData[$guestKey])) {
            return '';
        }

        $entry = $guestsData[$guestKey];
        if (!is_array($entry)) {
            return '';
        }

        if (!empty($entry['api_name'])) {
            return PriceInfoFormatter::toScalar($entry['api_name']);
        }
        if (!empty($entry['name'])) {
            return PriceInfoFormatter::toScalar($entry['name']);
        }

        return '';
    }

    /**
     * Construct the Novoton reservation API request payload.
     * @param array<string, mixed> $group
     * @param list<array<string, mixed>> $allGuests
     * @param list<array<string, mixed>> $apiRooms
     * @param array<string, mixed> $bookingData
     * @return array<string, mixed>
     */
    private function buildApiBookingRequest(
        array $group,
        array $allGuests,
        array $apiRooms,
        array $bookingData,
        int $orderId,
        int $groupNum,
        int $totalGroups,
        string $orderComment = '',
    ): array {
        $suffix = $totalGroups > 1 ? "-G{$groupNum}" : '';

        $firstGuestName = (is_array($allGuests[0] ?? null) && !empty($allGuests[0]['name']))
            ? PriceInfoFormatter::toScalar($allGuests[0]['name'])
            : PriceInfoFormatter::toScalar($bookingData['holder_name'] ?? 'Guest');

        $apiData = [
            'hotel_id' => PriceInfoFormatter::toScalar($bookingData['hotel_id'] ?? ''),
            'package_name' => PriceInfoFormatter::toScalar($group['package_name'] ?? ''),
            'check_in' => PriceInfoFormatter::toScalar($group['check_in'] ?? ''),
            'check_out' => PriceInfoFormatter::toScalar($group['check_out'] ?? ''),
            'holder' => $firstGuestName,
            'guests' => $allGuests,
            'rooms' => $apiRooms,
            'order_num' => $orderId . $suffix,
            'remark' => '',
            'comment' => $orderComment,
        ];

        // Single-room shortcut
        $groupRoomsArr = is_array($group['rooms'] ?? null) ? $group['rooms'] : [];
        if (count($groupRoomsArr) === 1) {
            $firstGroupRoom = is_array($groupRoomsArr[0] ?? null) ? $groupRoomsArr[0] : [];
            $apiData['room_id'] = PriceInfoFormatter::toScalar($firstGroupRoom['room_id'] ?? $bookingData['room_id'] ?? '');
            $apiData['board_id'] = PriceInfoFormatter::toScalar($firstGroupRoom['board_id'] ?? $bookingData['board_id'] ?? '');
        }

        return $apiData;
    }

    /**
     * Build the booking record array for DB persistence.
     *
     * @return array<string, mixed> Column => value map for novoton_bookings
     * @param array<string, mixed> $group
     * @param list<array<string, mixed>> $allGuests
     * @param array<string, mixed> $bookingData
     * @param array<string, mixed> $product
     * @param array<string, mixed> $apiData
     */
    private function buildBookingRecord(
        array $group,
        array $allGuests,
        array $bookingData,
        array $product,
        int $orderId,
        int $groupNum,
        int $totalGroups,
        float $totalApiPrice,
        float $totalGroupPrice,
        array $apiData,
        bool $disableApi,
    ): array {
        $groupRooms = is_array($group['rooms'] ?? null) ? $group['rooms'] : [];

        // Calculate nights safely
        $groupCheckIn = PriceInfoFormatter::toScalar($group['check_in'] ?? '');
        $groupCheckOut = PriceInfoFormatter::toScalar($group['check_out'] ?? '');
        try {
            $checkInDate = new \DateTime($groupCheckIn);
            $checkOutDate = new \DateTime($groupCheckOut);
            $nights = $checkInDate->diff($checkOutDate)->days;
        } catch (\Exception $e) {
            fn_log_event('general', 'error', [
                'message' => 'Novoton - Invalid date in booking group',
                'check_in' => $group['check_in'] ?? '',
                'check_out' => $group['check_out'] ?? '',
                'error' => $e->getMessage(),
            ]);
            $nights = PriceInfoFormatter::toInt($bookingData['nights'] ?? 7);
        }

        $orderInfo = fn_get_order_info($orderId);
        /** @var array<string, mixed> $orderInfo */
        $orderInfo = is_array($orderInfo) ? $orderInfo : [];
        $orderUserId = PriceInfoFormatter::toInt($orderInfo['user_id'] ?? 0);
        $orderEmail = PriceInfoFormatter::toScalar($orderInfo['email'] ?? '');

        $firstGroupRoom = is_array($groupRooms[0] ?? null) ? $groupRooms[0] : [];
        $firstGuestName = (is_array($allGuests[0] ?? null) && !empty($allGuests[0]['name']))
            ? PriceInfoFormatter::toScalar($allGuests[0]['name'])
            : PriceInfoFormatter::toScalar($bookingData['holder_name'] ?? '');

        return [
            'order_id' => $orderId,
            'product_id' => PriceInfoFormatter::toScalar($product['product_id'] ?? ''),
            'item_id' => PriceInfoFormatter::toScalar($product['item_id'] ?? ''),
            'hotel_id' => PriceInfoFormatter::toScalar($bookingData['hotel_id'] ?? ''),
            'hotel_name' => PriceInfoFormatter::toScalar($bookingData['hotel_name'] ?? ''),
            'package_name' => PriceInfoFormatter::toScalar($group['package_name'] ?? ''),
            'room_id' => implode(', ', array_column($groupRooms, 'room_id')),
            'room_type' => PriceInfoFormatter::toScalar($firstGroupRoom['room_type_display'] ?? $firstGroupRoom['room_name'] ?? ''),
            'board_id' => PriceInfoFormatter::toScalar($firstGroupRoom['board_id'] ?? $bookingData['board_id'] ?? ''),
            'board_name' => PriceInfoFormatter::toScalar($firstGroupRoom['board_name'] ?? $bookingData['board_name'] ?? ''),
            'check_in' => $groupCheckIn,
            'check_out' => $groupCheckOut,
            'nights' => $nights,
            'adults' => array_sum(array_column($groupRooms, 'adults')),
            'children' => array_sum(array_column($groupRooms, 'children')),
            'children_ages' => PriceInfoFormatter::toScalar($bookingData['children_ages'] ?? ''),
            'num_rooms' => count($groupRooms),
            'room_number' => $groupNum,
            'total_rooms' => $totalGroups,
            'rooms_data' => json_encode($groupRooms),
            'guest_name' => implode(', ', array_column($allGuests, 'name')),
            'holder_name' => $firstGuestName,
            'guests_data' => json_encode($allGuests),
            'base_price' => $totalApiPrice,
            'total_price' => $totalGroupPrice,
            'currency' => ConfigProvider::getApiCurrency(),
            'status' => TravelConstants::STATUS_PENDING,
            'api_request' => json_encode($apiData),
            'notes' => $disableApi ? 'API submission disabled - test mode' : '',
            'user_id' => $orderUserId,
            'guest_email' => $orderEmail,
            'terms_of_payment_raw' => $bookingData['terms_of_payment_raw'] ?? null,
            'terms_of_cancellation_raw' => $bookingData['terms_of_cancellation_raw'] ?? null,
            'terms_of_payment_formatted' => $bookingData['terms_of_payment'] ?? $bookingData['terms_of_payment_formatted'] ?? null,
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
     * @param array<string, mixed> $record
     */
    private function persistBookingRecord(
        array $record,
        int $originalBookingId,
        int $groupNum,
        int $orderId,
    ): int {
        // Group 1: update the original booking from cart
        if ($groupNum === 1 && $originalBookingId > 0) {
            $this->bookingRepo->update($originalBookingId, $record);
            return $originalBookingId;
        }

        // Dedup: find existing booking for this order + hotel + dates
        $existingId = $this->bookingRepo->findIdByOrderAndHotelDates(
            $orderId,
            PriceInfoFormatter::toScalar($record['hotel_id'] ?? ''),
            PriceInfoFormatter::toScalar($record['check_in'] ?? ''),
            PriceInfoFormatter::toScalar($record['check_out'] ?? ''),
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
     * @param array<string, mixed> $apiData
     */
    private function submitAndRecordBooking(
        array $apiData,
        int $bookingId,
        int $orderId,
        int $groupNum,
        float $totalApiPrice,
        bool $disableApi,
        bool $debug,
    ): void {
        if ($disableApi) {
            $this->bookingRepo->update($bookingId, ['notes' => 'API submission disabled - booking saved locally only.']);
            return;
        }

        try {
            $response = $this->reservations->createReservation($apiData);

            if ($response) {
                $novotonId = (string) ($response->IdNum ?? '');
                $novotonStatus = Constants::normalizeApiStatus((string) ($response->Status ?? ''));
                $novotonPrice = (string) ($response->Price ?? '');

                $update = [
                    'novoton_invoice_id' => $novotonId,
                    'novoton_status' => $novotonStatus,
                    'api_price' => !empty($novotonPrice) ? (float) $novotonPrice : $totalApiPrice,
                    'api_response' => json_encode([
                        'IdNum' => $novotonId,
                        'Price' => $novotonPrice,
                        'Currency' => (string) ($response->Currency ?? 'EUR'),
                        'Quota' => (string) ($response->Quota ?? ''),
                        'Status' => $novotonStatus,
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
                        'message' => 'Novoton Booking - API Response',
                        'order_id' => $orderId,
                        'booking_id' => $bookingId,
                        'novoton_id' => $novotonId,
                        'status' => $novotonStatus,
                    ]);
                }
            }
        } catch (ApiException $e) {
            $this->bookingRepo->update($bookingId, [
                'status' => TravelConstants::STATUS_FAILED,
                'notes' => 'API Error (' . $e->getApiFunction() . ', HTTP ' . $e->getHttpCode() . '): ' . $e->getMessage(),
            ]);

            fn_log_event('general', 'runtime', [
                'message' => 'Novoton Booking API Error',
                'order_id' => $orderId,
                'group' => $groupNum,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

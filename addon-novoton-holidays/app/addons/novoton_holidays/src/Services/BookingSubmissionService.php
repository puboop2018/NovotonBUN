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
use Tygh\Addons\NovotonHolidays\Helpers\BookingRoomAssembler;
use Tygh\Addons\NovotonHolidays\Repository\BookingRepositoryInterface;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\GuestDataNormalizer;
use Tygh\Addons\TravelCore\TravelConstants;

class BookingSubmissionService implements BookingSubmissionServiceInterface
{
    private readonly BookingRepositoryInterface $bookingRepo;
    private readonly ReservationApiClientInterface $reservations;
    private readonly GuestDataNormalizer $guestDataNormalizer;
    private readonly BookingRoomAssembler $roomAssembler;
    private readonly ApiBookingRequestBuilder $apiRequestBuilder;
    private readonly BookingRoomsGuestsResolver $roomsGuestsResolver;

    public function __construct(
        BookingRepositoryInterface $bookingRepo,
        ReservationApiClientInterface $reservations,
        ?GuestDataNormalizer $guestDataNormalizer = null,
    ) {
        $this->bookingRepo = $bookingRepo;
        $this->reservations = $reservations;
        $this->guestDataNormalizer = $guestDataNormalizer ?? new GuestDataNormalizer();
        $this->roomAssembler = new BookingRoomAssembler();
        $this->apiRequestBuilder = new ApiBookingRequestBuilder();
        $this->roomsGuestsResolver = new BookingRoomsGuestsResolver($this->guestDataNormalizer, $this->bookingRepo);
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
        $orderComment = trim(PriceInfoFormatter::toScalar($cart['notes'] ?? ''));

        $cartProducts = TypeCoerce::toRowList($cart['products']);
        foreach ($cartProducts as $product) {
            $extra = TypeCoerce::toStringMap($product['extra'] ?? null);
            if (empty($extra['novoton_booking'])) {
                continue;
            }

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
            [$roomsData, $guestsData] = $this->roomsGuestsResolver->resolveRoomsAndGuests(
                $bookingData,
                $debugLogging,
            );

            // 3. Group rooms by (package + dates)
            $roomGroups = $this->roomAssembler->groupRoomsByPackage($roomsData, $bookingData);

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
                        $this->roomAssembler->buildGroupGuestsAndRooms($group, $guestsData, $bookingData, $commission);

                    $apiData = $this->apiRequestBuilder->build(
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
        $bookingData['total_price'] = PriceInfoFormatter::toFloat($bookingData['total_price']);
        $bookingData['base_price'] = PriceInfoFormatter::toFloat($bookingData['base_price'] ?? 0);

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
            'room_id' => implode(', ', TypeCoerce::toStringList(array_column($groupRooms, 'room_id'))),
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
            'guest_name' => implode(', ', TypeCoerce::toStringList(array_column($allGuests, 'name'))),
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

        if ($existingId !== null && $existingId !== 0) {
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

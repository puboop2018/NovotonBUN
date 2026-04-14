<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Repository;

use Tygh\Addons\TravelCore\TravelConstants;

/**
 * @phpstan-type BookingRow = array<string, mixed>
 */
interface BookingRepositoryInterface
{
    /** @return BookingRow|null */
    public function findById(int $booking_id): ?array;
    /** @return BookingRow|null */
    public function findByIdHydrated(int $booking_id, bool $force = false): ?array;
    /** @return list<BookingRow> */
    public function findByOrderId(int $order_id): array;
    /**
     * @param list<int> $order_ids
     * @return list<BookingRow>
     */
    public function findByOrderIds(array $order_ids): array;
    /** @return list<BookingRow> */
    public function findByUserId(int $user_id, int $limit = 100): array;
    /** @return list<BookingRow> */
    public function findBySessionId(string $session_id): array;
    /** @return list<BookingRow> */
    public function findByHotelId(string $hotel_id, int $limit = 100): array;
    /**
     * @param list<int> $product_ids
     * @param list<string> $statuses
     * @return list<BookingRow>
     */
    public function findByProductIds(array $product_ids, array $statuses = [TravelConstants::STATUS_PENDING, TravelConstants::STATUS_CONFIRMED], string $session_id = '', int $user_id = 0): array;
    /** @return list<BookingRow> */
    public function findPending(int $limit = 500): array;
    /** @return BookingRow|null */
    public function findExisting(string $hotel_id, string $check_in, string $check_out, string $holder_name, int $hours = 1): ?array;
    /**
     * @param list<string> $statuses
     * @return list<BookingRow>
     */
    public function findByNovotonStatus(string $novoton_status, array $statuses, int $limit = 50): array;
    /** @return list<BookingRow> */
    public function findRqWithoutAlternatives(int $limit = 50): array;
    /**
     * @param array<string, mixed> $filters
     */
    public function count(array $filters = []): int;
    /**
     * @param array<string, mixed> $data
     * @return int Booking ID
     */
    public function create(array $data): int;
    /**
     * @param array<string, mixed> $data
     */
    public function update(int $booking_id, array $data): bool;
    public function updateStatus(int $booking_id, string $status, string $novoton_status = ''): bool;
    public function linkToOrder(int $booking_id, int $order_id): bool;
    public function setReservationId(int $booking_id, string $reservation_id, string $status = 'Good'): bool;
    /**
     * @param mixed $request
     * @param mixed $response
     */
    public function storeApiData(int $booking_id, $request, $response): bool;
    public function delete(int $booking_id): bool;
    public function deleteOrphans(int $hours = 24): int;
    public function countOrphans(int $hours = 48): int;
    /** @return array<string, int> */
    public function getStats(): array;
    /**
     * @param array<string, mixed> $params
     * @return list<BookingRow>
     */
    public function getUnifiedBookings(array $params = []): array;
    public function linkToUserBySession(int $user_id, string $session_id): int;
    public function linkToUserByEmail(int $user_id, string $email): int;
    public function deleteByProductId(int $product_id): int;
    public function getGuestsData(int $booking_id): ?string;
    /** @return BookingRow|null */
    public function findUnassignedByHotelDates(string $hotel_id, string $check_in, string $check_out): ?array;
    /** @return array<string, mixed>|null */
    public function getTerms(int $booking_id): ?array;
    public function findIdByOrderAndHotelDates(int $order_id, string $hotel_id, string $check_in, string $check_out): ?int;
    /** @return list<BookingRow> */
    public function findWithReservationId(int $limit = 1000): array;

    /** @return list<BookingRow> */
    public function findForAdminList(string $condition = '', int $limit = 500): array;
    /** @return BookingRow|null */
    public function findWithOrderDetails(int $booking_id): ?array;
    /** @return list<BookingRow> */
    public function findAllForExport(): array;
    /** @return BookingRow|null */
    public function findByIdWithOwnership(int $booking_id, int $user_id, string $session_id): ?array;
    public function checkOwnership(int $booking_id, int $user_id, string $session_id): ?int;

    /**
     * Decode JSON fields on a raw booking row in-place.
     *
     * @param array<string, mixed> $booking Raw DB row
     * @return array<string, mixed> Booking with rooms_data_parsed, guests_data_parsed
     */
    public static function hydrateJsonFields(array $booking): array;

    /**
     * Invalidate the memo cache for a specific booking (e.g. after update).
     *
     * @param int $booking_id Specific booking ID, or 0 to clear all
     */
    public static function invalidateCache(int $booking_id = 0): void;
}

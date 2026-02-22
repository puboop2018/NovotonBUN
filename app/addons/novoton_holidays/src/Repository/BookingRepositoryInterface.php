<?php
declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Repository;

use Tygh\Addons\NovotonHolidays\Constants;

interface BookingRepositoryInterface
{
    public function findById(int $booking_id): ?array;
    public function findByIdHydrated(int $booking_id, bool $force = false): ?array;
    public function findByOrderId(int $order_id): array;
    public function findByOrderIds(array $order_ids): array;
    public function findByUserId(int $user_id, int $limit = 0): array;
    public function findBySessionId(string $session_id): array;
    public function findByHotelId(string $hotel_id): array;
    public function findByProductIds(array $product_ids, array $statuses = [Constants::STATUS_PENDING, Constants::STATUS_CONFIRMED]): array;
    public function findPending(int $limit = 0): array;
    public function findExisting(string $hotel_id, string $check_in, string $check_out, string $holder_name, int $hours = 1): ?array;
    public function findByNovotonStatus(string $novoton_status, array $statuses, int $limit = 50): array;
    public function findRqWithoutAlternatives(int $limit = 50): array;
    public function count(array $filters = []): int;
    public function create(array $data): int;
    public function update(int $booking_id, array $data): bool;
    public function updateStatus(int $booking_id, string $status, string $novoton_status = ''): bool;
    public function linkToOrder(int $booking_id, int $order_id): bool;
    public function setReservationId(int $booking_id, string $reservation_id, string $status = 'OK'): bool;
    public function storeApiData(int $booking_id, $request, $response): bool;
    public function delete(int $booking_id): bool;
    public function deleteOrphans(int $hours = 24): int;
    public function countOrphans(int $hours = 48): int;
    public function getStats(): array;
    public function getUnifiedBookings(array $params = []): array;
    public function linkToUserBySession(int $user_id, string $session_id): int;
    public function linkToUserByEmail(int $user_id, string $email): int;
    public function deleteByProductId(int $product_id): int;
    public function getGuestsData(int $booking_id): ?string;
    public function findUnassignedByHotelDates(string $hotel_id, string $check_in, string $check_out): ?array;
    public function getTerms(int $booking_id): ?array;
    public function findIdByOrderAndHotelDates(int $order_id, string $hotel_id, string $check_in, string $check_out): ?int;
    public function findWithReservationId(): array;
}

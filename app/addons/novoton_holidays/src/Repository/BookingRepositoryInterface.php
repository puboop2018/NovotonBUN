<?php
declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Repository;

interface BookingRepositoryInterface
{
    public function findById(int $booking_id): ?array;
    public function findByOrderId(int $order_id): array;
    public function findByUserId(int $user_id, int $limit = 0): array;
    public function findBySessionId(string $session_id): array;
    public function findByHotelId(string $hotel_id): array;
    public function findPending(int $limit = 0): array;
    public function findExisting(string $hotel_id, string $check_in, string $check_out, string $holder_name, int $hours = 1): ?array;
    public function count(array $filters = []): int;
    public function create(array $data): int;
    public function update(int $booking_id, array $data): bool;
    public function updateStatus(int $booking_id, string $status, string $novoton_status = ''): bool;
    public function linkToOrder(int $booking_id, int $order_id): bool;
    public function setReservationId(int $booking_id, string $reservation_id, string $status = 'OK'): bool;
    public function storeApiData(int $booking_id, $request, $response): bool;
    public function delete(int $booking_id): bool;
    public function deleteOrphans(int $hours = 24): int;
    public function getStats(): array;
    public function linkToUserBySession(int $user_id, string $session_id): int;
    public function deleteByProductId(int $product_id): int;
}

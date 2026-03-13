<?php
declare(strict_types=1);
/**
 * Novoton Booking Service Interface
 *
 * Contract for booking operations: creation, retrieval, cart, and order linking.
 *
 * @package NovotonHolidays
 * @since 3.1.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

interface BookingServiceInterface
{
    public function createBooking(array $bookingData, int $product_id): int;
    public function updateBooking(int $booking_id, array $data): bool;
    public function getBooking(int $booking_id): ?array;
    public function getBookingsForOrder(int $order_id): array;
    public function linkToOrder(int $booking_id, int $order_id): bool;
    public function addToCart(int $booking_id, int $product_id, array $bookingData): bool;
    public function parseRoomsData(array $bookingData): array;
    public function calculateNights(string $check_in, string $check_out): int;
    public function verifyPrice(array $params): array;
    public function assembleCartProduct(int $productId, int $bookingId, array $bookingData, array $hotelInfo, array $guestsData, array $priceResult, array $roomsData): array;
    public function enrichRoomsData(array $roomsData, array $guestsData): array;
    public function resolveProductId(string $hotelId, int $fallbackProductId = 0): int;
}

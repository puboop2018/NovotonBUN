<?php
declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Repository;

use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\TravelCore\TravelConstants;

interface BookingRepositoryInterface
{
    public function findById(int $booking_id): ?array;
    public function findByIdHydrated(int $booking_id, bool $force = false): ?array;
    public function findByOrderId(int $order_id): array;
    public function findByOrderIds(array $order_ids): array;
    public function findByUserId(int $user_id, int $limit = 100): array;
    public function findBySessionId(string $session_id): array;
    public function findByHotelId(string $hotel_id, int $limit = 100): array;
    public function findByProductIds(array $product_ids, array $statuses = [TravelConstants::STATUS_PENDING, TravelConstants::STATUS_CONFIRMED], string $session_id = '', int $user_id = 0): array;
    public function findPending(int $limit = 500): array;
    public function findExisting(string $hotel_id, string $check_in, string $check_out, string $holder_name, int $hours = 1): ?array;
    public function findByNovotonStatus(string $novoton_status, array $statuses, int $limit = 50): array;
    public function findRqWithoutAlternatives(int $limit = 50): array;
    public function count(array $filters = []): int;
    public function create(array $data): int;
    public function update(int $booking_id, array $data): bool;
    public function updateStatus(int $booking_id, string $status, string $novoton_status = ''): bool;
    public function linkToOrder(int $booking_id, int $order_id): bool;
    public function setReservationId(int $booking_id, string $reservation_id, string $status = 'Good'): bool;
    public function storeApiData(int $booking_id, $request, $response): bool;
    public function delete(int $booking_id): bool;
    public function deleteOrphans(int $hours = 24): int;
    public function countOrphans(int $hours = 48): int;
    public function linkToUserBySession(int $user_id, string $session_id): int;
    public function linkToUserByEmail(int $user_id, string $email): int;
    public function deleteByProductId(int $product_id): int;
    public function getGuestsData(int $booking_id): ?string;
    public function findUnassignedByHotelDates(string $hotel_id, string $check_in, string $check_out): ?array;
    public function getTerms(int $booking_id): ?array;
    public function findIdByOrderAndHotelDates(int $order_id, string $hotel_id, string $check_in, string $check_out): ?int;
    public function findWithReservationId(int $limit = 1000): array;

    public function findForAdminList(string $condition = '', int $limit = 500): array;
    public function findWithOrderDetails(int $booking_id): ?array;
    public function findAllForExport(): array;
    public function findByIdWithOwnership(int $booking_id, int $user_id, string $session_id): ?array;
    public function checkOwnership(int $booking_id, int $user_id, string $session_id): ?int;

    /**
     * Convert a booking's stored total_price (in its api-side `currency`)
     * into the target display currency (defaults to CART_PRIMARY_CURRENCY).
     *
     * The booking record stores prices in the API currency used at
     * add-to-cart time (see add_to_cart.php). The cart line item, on the
     * other hand, stores prices in the cart primary currency via
     * CurrencyService::convertFromApiCurrency(). Any display path that
     * reads the booking directly (edit form, order recap, email, etc.)
     * must apply the same conversion, otherwise an EUR value gets
     * rendered with a LEI label.
     *
     * @param array       $booking        Raw booking row (must contain
     *                                    `total_price` + `currency`)
     * @param string|null $targetCurrency Null = CART_PRIMARY_CURRENCY
     * @return float Converted price in the target currency
     */
    public function getDisplayPrice(array $booking, ?string $targetCurrency = null): float;

    /**
     * Decode JSON fields on a raw booking row in-place.
     *
     * @param array $booking Raw DB row
     * @return array Booking with rooms_data_parsed, guests_data_parsed
     */
    public static function hydrateJsonFields(array $booking): array;

    /**
     * Invalidate the memo cache for a specific booking (e.g. after update).
     *
     * @param int $booking_id Specific booking ID, or 0 to clear all
     */
    public static function invalidateCache(int $booking_id = 0): void;
}

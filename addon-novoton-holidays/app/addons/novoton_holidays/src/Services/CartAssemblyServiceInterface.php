<?php
declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

/**
 * Contract for assembling CS-Cart cart products from booking data.
 *
 * @package NovotonHolidays
 * @since   3.9.0
 */
interface CartAssemblyServiceInterface
{
    /**
     * @param array<string, mixed> $bookingData
     * @param array<string, mixed> $hotelInfo
     * @param array<string, mixed> $guestsData
     * @param array<string, mixed> $priceResult
     * @param array<string, mixed> $roomsData
     * @return array<string, mixed>
     */
    public function assembleCartProduct(
        int $productId,
        int $bookingId,
        array $bookingData,
        array $hotelInfo,
        array $guestsData,
        array $priceResult,
        array $roomsData
    ): array;

    /**
     * @param array<string, mixed> $roomsData
     * @param array<string, mixed> $guestsData
     * @return array<string, mixed>
     */
    public function enrichRoomsData(array $roomsData, array $guestsData): array;

    /**
     * @param array<string, mixed> $booking
     * @param array<string, mixed> $bookingData
     * @return array<string, mixed>
     */
    public function buildCartExtra(array $booking, array $bookingData): array;
}

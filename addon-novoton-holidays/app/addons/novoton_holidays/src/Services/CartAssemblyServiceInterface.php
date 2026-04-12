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
    public function assembleCartProduct(
        int $productId,
        int $bookingId,
        array $bookingData,
        array $hotelInfo,
        array $guestsData,
        array $priceResult,
        array $roomsData
    ): array;

    public function enrichRoomsData(array $roomsData, array $guestsData): array;

    public function buildCartExtra(array $booking, array $bookingData): array;
}

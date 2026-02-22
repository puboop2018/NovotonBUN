<?php
declare(strict_types=1);
/**
 * Product Factory Interface
 *
 * Contract for creating CS-Cart products from hotel data.
 *
 * @package NovotonHolidays
 * @since 3.5.0
 */

namespace Tygh\Addons\NovotonHolidays\Helpers;

use Tygh\Addons\NovotonHolidays\NovotonApiInterface;

interface ProductFactoryInterface
{
    /**
     * Create CS-Cart product from hotel data.
     *
     * @param array              $hotel      Hotel data array
     * @param NovotonApiInterface $api       API instance for fetching additional data
     * @param int                $categoryId Category to assign product to
     * @return int|null Product ID or null on failure
     */
    public function createFromHotel(array $hotel, NovotonApiInterface $api, int $categoryId): ?int;

    /**
     * Attach images to product from API.
     *
     * @param int                $productId
     * @param string             $hotelId
     * @param NovotonApiInterface $api
     * @return int Number of images attached
     */
    public function attachHotelImages(int $productId, string $hotelId, NovotonApiInterface $api): int;
}

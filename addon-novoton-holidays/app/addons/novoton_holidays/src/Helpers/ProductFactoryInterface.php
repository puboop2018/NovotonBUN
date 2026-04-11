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

use Tygh\Addons\NovotonHolidays\Api\Contracts\HotelApiClientInterface;

interface ProductFactoryInterface
{
    /**
     * Create CS-Cart product from hotel data.
     *
     * @param array                   $hotel      Hotel data array
     * @param HotelApiClientInterface $api        Narrow hotel sub-client
     * @param int                     $categoryId Category to assign product to
     * @return int|null Product ID or null on failure
     */
    public function createFromHotel(array $hotel, HotelApiClientInterface $api, int $categoryId): ?int;

    /**
     * Attach images to product from API.
     *
     * @param int                     $productId
     * @param string                  $hotelId
     * @param HotelApiClientInterface $api
     * @return int Number of images attached
     */
    public function attachHotelImages(int $productId, string $hotelId, HotelApiClientInterface $api): int;

    /**
     * Build hotel title for SEO.
     *
     * @param string $hotelName
     * @param string $city
     * @param string $country
     * @param string $year
     * @return string
     */
    public static function buildHotelTitle(string $hotelName, string $city, string $country, string $year): string;

    /**
     * Get or create category by path.
     *
     * @param string $categoryPath
     * @return int Category ID
     */
    public static function getOrCreateCategory(string $categoryPath): int;
}

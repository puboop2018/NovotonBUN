<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Product Factory
 *
 * Creates CS-Cart products from Novoton hotel data.
 * Single Responsibility: hotel-to-product conversion, image attachment, title building.
 *
 * @package NovotonHolidays
 * @since 3.3.0
 */

namespace Tygh\Addons\NovotonHolidays\Helpers;

use Tygh\Addons\NovotonHolidays\NovotonApiInterface;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Exceptions\ApiException;

class ProductFactory implements ProductFactoryInterface
{
    private DatabaseHelperInterface $dbHelper;

    public function __construct(DatabaseHelperInterface $dbHelper)
    {
        $this->dbHelper = $dbHelper;
    }

    /**
     * Create CS-Cart product from hotel data
     *
     * @param array $hotel Hotel data array
     * @param NovotonApiInterface $api API instance for fetching additional data
     * @param int $categoryId Category to assign product to
     * @return int|null Product ID or null on failure
     */
    public function createFromHotel(array $hotel, NovotonApiInterface $api, int $categoryId): ?int
    {
        $hotelId = $hotel['hotel_id'];
        $hotelName = $hotel['hotel_name'] ?? '';
        $city = $hotel['city'] ?? '';
        $country = $hotel['country'] ?? '';

        $productCode = $this->dbHelper->getProductCode($hotelId);

        // Check if product already exists
        $existingProductId = db_get_field(
            "SELECT product_id FROM ?:products WHERE product_code = ?s",
            $productCode
        );

        if ($existingProductId) {
            db_query(
                "UPDATE ?:novoton_hotels SET product_id = ?i WHERE hotel_id = ?s",
                $existingProductId,
                $hotelId
            );
            return (int)$existingProductId;
        }

        // Format display name (Title Case + append property type for short names)
        $displayName = function_exists('fn_novoton_holidays_format_hotel_display_name')
            ? fn_novoton_holidays_format_hotel_display_name($hotelName)
            : $hotelName;

        // Build page title
        $pageTitle = self::buildHotelTitle($displayName, $city, $country, date('Y'));

        // Fetch description
        $description = '';
        try {
            $descResponse = $api->getHotelDescription($hotelId, 'UK');
            if ($descResponse && isset($descResponse->Description)) {
                $description = (string)$descResponse->Description;
            }
        } catch (ApiException $e) {
            // Ignore description fetch errors
        }

        // Create product
        $productData = [
            'product' => $displayName,
            'product_code' => $productCode,
            'price' => 0,
            'status' => 'D',
            'company_id' => ConfigProvider::getCompanyId(),
            'main_category' => $categoryId,
            'category_ids' => [$categoryId],
            'full_description' => $description,
            'page_title' => $pageTitle,
            'meta_description' => $pageTitle,
        ];

        $productId = fn_update_product($productData, 0, CART_LANGUAGE);

        if (!$productId) {
            return null;
        }

        // Link product to hotel
        db_query(
            "UPDATE ?:novoton_hotels SET product_id = ?i WHERE hotel_id = ?s",
            $productId,
            $hotelId
        );

        // Attach images
        $this->attachHotelImages($productId, $hotelId, $api);

        // Sync facilities
        try {
            if (function_exists('fn_novoton_holidays_sync_hotel_facilities')) {
                fn_novoton_holidays_sync_hotel_facilities($hotelId);
            }
        } catch (\Exception $e) {
            // Ignore facility sync errors
        }

        return $productId;
    }

    /**
     * Attach images to product from API
     *
     * @return int Number of images attached
     */
    public function attachHotelImages(int $productId, string $hotelId, NovotonApiInterface $api): int
    {
        try {
            $imagesResponse = $api->getHotelImages($hotelId);

            if (!$imagesResponse || !isset($imagesResponse->url)) {
                return 0;
            }

            $imgCount = 0;
            $maxImages = ConfigProvider::MAX_IMAGES_PER_HOTEL;

            foreach ($imagesResponse->url as $url) {
                $imageUrl = ConfigProvider::IMAGE_BASE_URL . str_replace(' ', '%20', (string)$url);

                if (function_exists('fn_novoton_holidays_add_product_image')) {
                    fn_novoton_holidays_add_product_image($productId, $imageUrl, $imgCount === 0);
                }

                $imgCount++;
                if ($imgCount >= $maxImages) {
                    break;
                }
            }

            return $imgCount;
        } catch (ApiException $e) {
            return 0;
        }
    }

    /**
     * Build hotel title for SEO
     */
    public static function buildHotelTitle(string $hotelName, string $city, string $country, string $year): string
    {
        if (function_exists('fn_novoton_holidays_build_hotel_title')) {
            return fn_novoton_holidays_build_hotel_title($hotelName, $city, $country, $year);
        }

        $parts = array_filter([$hotelName, $city, $country, $year]);
        return implode(' - ', $parts);
    }

    /**
     * Get or create category by path
     */
    public static function getOrCreateCategory(string $categoryPath): int
    {
        if (function_exists('fn_novoton_holidays_get_or_create_category')) {
            return fn_novoton_holidays_get_or_create_category($categoryPath);
        }

        return 1;
    }
}

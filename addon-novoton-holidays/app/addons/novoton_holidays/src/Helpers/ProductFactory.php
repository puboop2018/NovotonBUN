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

use Tygh\Addons\NovotonHolidays\Constants;
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

        // Build placeholder map for SEO templates
        $placeholders = self::buildNovotonPlaceholders($hotel, $displayName, $description);

        // Resolve full description: use template if configured, otherwise raw API description
        $descTemplate = ConfigProvider::getSeoFullDescription();
        $fullDescription = $descTemplate !== ''
            ? fn_travel_core_render_seo_template($descTemplate, $placeholders)
            : $description;

        // Create product using SEO templates
        $productData = [
            'product'          => fn_travel_core_render_seo_template(ConfigProvider::getSeoProductName(), $placeholders) ?: $displayName,
            'product_code'     => $productCode,
            'price'            => 0,
            'status'           => 'D',
            'company_id'       => ConfigProvider::getCompanyId(),
            'main_category'    => $categoryId,
            'category_ids'     => [$categoryId],
            'full_description' => $fullDescription,
            'page_title'       => fn_travel_core_render_seo_template(ConfigProvider::getSeoPageTitle(), $placeholders),
            'meta_description' => fn_travel_core_render_seo_template(ConfigProvider::getSeoMetaDescription(), $placeholders),
            'meta_keywords'    => fn_travel_core_render_seo_template(ConfigProvider::getSeoMetaKeywords(), $placeholders),
            'seo_name'         => fn_travel_core_render_seo_slug(ConfigProvider::getSeoNameSlug(), $placeholders),
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
                $imageUrl = Constants::IMAGE_BASE_URL . str_replace(' ', '%20', (string)$url);

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

    /**
     * Build the placeholder map for SEO template rendering from Novoton hotel data.
     *
     * @param array  $hotel       Hotel row from novoton_hotels table
     * @param string $displayName Formatted display name (Title Case, with property type)
     * @param string $description API description text
     * @return array<string, string|array> Key => value map (keys without braces)
     */
    private static function buildNovotonPlaceholders(array $hotel, string $displayName, string $description = ''): array
    {
        // Load facility names from DB
        $facilities = [];
        if (!empty($hotel['hotel_id'])) {
            $facilities = db_get_fields(
                "SELECT f.facility_name_en FROM ?:novoton_hotel_facilities hf
                 JOIN ?:novoton_facilities f ON f.facility_id = hf.facility_id
                 WHERE hf.hotel_id = ?s AND f.facility_name_en != ''
                 LIMIT 5",
                $hotel['hotel_id']
            ) ?: [];
        }

        return [
            'name'          => $displayName,
            'raw_name'      => $hotel['hotel_name'] ?? '',
            'city'          => $hotel['city'] ?? '',
            'country'       => $hotel['country'] ?? '',
            'region'        => $hotel['region'] ?? '',
            'star_rating'   => $hotel['star_rating'] ?? '',
            'hotel_type'    => $hotel['hotel_type'] ?? '',
            'property_type' => $hotel['property_type'] ?? 'hotel',
            'year'          => date('Y'),
            'description'   => $description,
            'facilities'    => $facilities,
            'latitude'      => $hotel['latitude'] ?? '',
            'longitude'     => $hotel['longitude'] ?? '',
        ];
    }
}

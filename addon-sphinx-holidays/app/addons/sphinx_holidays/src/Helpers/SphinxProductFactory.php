<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Helpers;

use Tygh\Registry;
use Tygh\Addons\SphinxHolidays\Repository\HotelRepository;
use Tygh\Addons\SphinxHolidays\Services\SphinxFeatureAssigner;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;

/**
 * Creates CS-Cart products from Sphinx hotel data.
 *
 * Category strategy (2 levels):
 *   Level 1: Root category set in addon settings (e.g. "Hoteluri")
 *   Level 2: Country — dynamically created under root (e.g. "Turkey")
 *
 * Region and City are assigned as product features (filters), not categories.
 */
class SphinxProductFactory implements SphinxProductFactoryInterface
{
    private HotelRepository $hotelRepo;
    private SphinxFeatureAssigner $featureAssigner;

    /** @var array<string, int> Country name → category_id cache (under root) */
    private array $categoryCache = [];

    /** @var array<string, string> Valid CS-Cart country codes (loaded once) */
    private array $validCountryCodes = [];

    public function __construct(HotelRepository $hotelRepo, SphinxFeatureAssigner $featureAssigner)
    {
        $this->hotelRepo = $hotelRepo;
        $this->featureAssigner = $featureAssigner;
    }

    /**
     * Load valid CS-Cart country codes (call once before processing batches).
     */
    public function loadValidCountryCodes(): void
    {
        if (empty($this->validCountryCodes)) {
            $this->validCountryCodes = db_get_hash_single_array(
                "SELECT code, code FROM ?:countries WHERE status = 'A'",
                ['code', 'code']
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function createFromHotel(array $hotel, array $hierarchy): array
    {
        $hotelId = $hotel['hotel_id'];
        $productCode = 'SPX' . $hotelId;

        // Country code validation
        $cc = $hotel['country_code'] ?? '';
        if (!empty($cc) && !isset($this->validCountryCodes[$cc])) {
            $this->hotelRepo->markSkipped($hotelId, 'invalid_country');
            fn_log_event('general', 'runtime', [
                'message' => "Sphinx: country code '{$cc}' not found in CS-Cart countries. Hotel {$hotelId} skipped.",
            ]);
            return ['status' => 'skipped', 'product_id' => 0, 'reason' => "invalid country: {$cc}"];
        }

        // Check if product already exists (re-run after partial failure)
        $existingProductId = (int) db_get_field(
            "SELECT product_id FROM ?:products WHERE product_code = ?s",
            $productCode
        );
        if ($existingProductId > 0) {
            $this->hotelRepo->linkToProduct($hotelId, $existingProductId);
            return ['status' => 'linked', 'product_id' => $existingProductId, 'reason' => 'existing'];
        }

        // Resolve root category from addon settings
        $rootCategoryId = ConfigProvider::getHotelsCategoryId();
        if ($rootCategoryId <= 0) {
            $this->hotelRepo->markSkipped($hotelId, 'no_root_category');
            return ['status' => 'skipped', 'product_id' => 0, 'reason' => 'hotels_category_id not configured'];
        }

        // Resolve country name and create country sub-category
        $countryName = $this->resolveCountryName($hotel, $hierarchy);
        if ($countryName === '') {
            $this->hotelRepo->markSkipped($hotelId, 'no_destination');
            return ['status' => 'skipped', 'product_id' => 0, 'reason' => 'no country resolved'];
        }

        $cacheKey = $rootCategoryId . '/' . $countryName;
        if (!isset($this->categoryCache[$cacheKey])) {
            $this->categoryCache[$cacheKey] = fn_travel_core_get_or_create_child_category($rootCategoryId, $countryName);
        }
        $categoryId = $this->categoryCache[$cacheKey];

        if (!$categoryId) {
            $this->hotelRepo->markSkipped($hotelId, 'category_failed');
            return ['status' => 'failed', 'product_id' => 0, 'reason' => "category: {$countryName} under root {$rootCategoryId}"];
        }

        // Resolve city name for page title
        $cityName = ($hierarchy['city'] ?? '') ?: ($hotel['destination_name'] ?? '');

        // Create CS-Cart product
        $productData = [
            'product'           => $hotel['name'],
            'product_code'      => $productCode,
            'price'             => 0,
            'status'            => 'A',
            'company_id'        => Registry::get('runtime.company_id') ?: 1,
            'main_category'     => $categoryId,
            'category_ids'      => [$categoryId],
            'full_description'  => $hotel['description'] ?? '',
            'short_description' => $hotel['short_description'] ?? '',
            'page_title'        => $hotel['name'] . ($cityName ? ' - ' . $cityName : ''),
        ];

        $configuredLanguages = ConfigProvider::getProductLanguages();
        $primaryLang = !empty($configuredLanguages) ? $configuredLanguages[0] : CART_LANGUAGE;

        $productId = (int) fn_update_product($productData, 0, $primaryLang);
        if (!$productId) {
            $this->hotelRepo->markSkipped($hotelId, 'product_creation_failed');
            return ['status' => 'failed', 'product_id' => 0, 'reason' => 'product creation'];
        }

        // Replicate descriptions to other configured languages
        $otherLanguages = array_diff($configuredLanguages, [$primaryLang]);
        foreach ($otherLanguages as $lc) {
            db_query(
                "INSERT INTO ?:product_descriptions (product_id, lang_code, product, full_description, short_description, page_title)
                 VALUES (?i, ?s, ?s, ?s, ?s, ?s)
                 ON DUPLICATE KEY UPDATE product = ?s, full_description = ?s, short_description = ?s, page_title = ?s",
                $productId, $lc,
                $hotel['name'], $hotel['description'] ?? '', $hotel['short_description'] ?? '', $productData['page_title'],
                $hotel['name'], $hotel['description'] ?? '', $hotel['short_description'] ?? '', $productData['page_title']
            );
        }

        // Link hotel → product
        $this->hotelRepo->linkToProduct($hotelId, $productId);

        // Assign features (non-fatal) — includes region & city as product features
        try {
            $this->featureAssigner->assignAll($productId, $hotel);
        } catch (\Throwable $e) {
            fn_log_event('general', 'runtime', [
                'message' => "Sphinx: feature assignment failed for hotel {$hotelId}: " . $e->getMessage(),
            ]);
        }

        return ['status' => 'added', 'product_id' => $productId, 'reason' => ''];
    }

    /**
     * {@inheritdoc}
     */
    public function resolveCountryName(array $hotel, array $hierarchy): string
    {
        $countryName = ($hierarchy['country'] ?? '') ?: ($hotel['country_name'] ?? '');
        return $countryName ?: ($hotel['country_code'] ?? '');
    }
}

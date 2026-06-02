<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Helpers;

use Tygh\Addons\SphinxHolidays\Repository\HotelRepository;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\SphinxFeatureAssigner;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

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
    #[\Override]
    public function loadValidCountryCodes(): void
    {
        if (empty($this->validCountryCodes)) {
            $this->validCountryCodes = db_get_hash_single_array(
                "SELECT code, code FROM ?:countries WHERE status = 'A'",
                ['code', 'code'],
            );
        }
    }

    /**
     * {@inheritdoc}
     * @param array<string, mixed> $hotel
     * @param array<string, mixed> $hierarchy
     * @return array<string, mixed>
     */
    #[\Override]
    public function createFromHotel(array $hotel, array $hierarchy): array
    {
        $hotelId = TypeCoerce::toString($hotel['hotel_id'] ?? '');
        $cc = is_string($hotel['country_code']) ? strtoupper($hotel['country_code']) : '';

        // Country code validation
        if ($cc !== '' && !isset($this->validCountryCodes[$cc])) {
            $this->hotelRepo->markSkipped($hotelId, 'invalid_country');
            fn_log_event('general', 'runtime', [
                'message' => "Sphinx: country code '{$cc}' not found in CS-Cart countries. Hotel {$hotelId} skipped.",
            ]);
            return ['status' => 'skipped', 'product_id' => 0, 'reason' => "invalid country: {$cc}"];
        }

        // Product code: country-code prefix + hotel id (e.g. HR59843). In practice
        // every synced hotel has a country_code; in the rare case it's missing the
        // code is just the bare hotel id (no prefix).
        $productCode = $cc . $hotelId;

        // Check if product already exists (re-run after partial failure)
        $existingProductId = (int) db_get_field(
            'SELECT product_id FROM ?:products WHERE product_code = ?s',
            $productCode,
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
        if (empty($this->categoryCache[$cacheKey])) {
            $this->categoryCache[$cacheKey] = fn_travel_core_get_or_create_child_category($rootCategoryId, $countryName);
        }
        $categoryId = $this->categoryCache[$cacheKey];

        if (!$categoryId) {
            $this->hotelRepo->markSkipped($hotelId, 'category_failed');
            return ['status' => 'failed', 'product_id' => 0, 'reason' => "category: {$countryName} under root {$rootCategoryId}"];
        }

        // Skip hotels without description if setting is enabled
        if (ConfigProvider::shouldSkipNoDescription()) {
            $description = trim((string) ($hotel['description'] ?? ''));
            if ($description === '') {
                $this->hotelRepo->markSkipped($hotelId, 'no_description');
                return ['status' => 'skipped', 'product_id' => 0, 'reason' => 'no description'];
            }
        }

        // Skip hotels without star classification (0 stars) if setting is enabled
        if (ConfigProvider::shouldSkipUnratedHotels()) {
            $classification = (int) ($hotel['classification'] ?? 0);
            if ($classification < 1 || $classification > 5) {
                $this->hotelRepo->markSkipped($hotelId, 'unrated');
                return ['status' => 'skipped', 'product_id' => 0, 'reason' => 'no star classification'];
            }
        }

        // Deduplicate: same hotel can appear under different IDs from different suppliers.
        // Three-tier detection — each tier checks if a duplicate already has a CS-Cart product.
        $destId = (int) ($hotel['destination_id'] ?? 0);
        $lat = (float) ($hotel['latitude'] ?? 0);
        $lng = (float) ($hotel['longitude'] ?? 0);
        $regionId = (int) ($hotel['region_id'] ?? 0);
        $propType = (string) ($hotel['property_type'] ?? 'hotel');
        $classif = (int) ($hotel['classification'] ?? 0);

        $dupeProductId = 0;

        // Tier 1: name + property_type + classification + region_id + country_code
        if ($regionId > 0) {
            $dupeProductId = (int) db_get_field(
                'SELECT product_id FROM ?:sphinx_hotels
                 WHERE name = ?s AND property_type = ?s AND classification = ?i
                   AND region_id = ?i AND country_code = ?s
                   AND product_id IS NOT NULL AND product_id > 0 AND hotel_id != ?s
                 LIMIT 1',
                $hotel['name'],
                $propType,
                $classif,
                $regionId,
                $cc,
                $hotelId,
            );
        }

        // Tier 2: name + coordinates with ROUND(,3) tolerance (~110m)
        if ($dupeProductId === 0 && $lat !== 0.0 && $lng !== 0.0) {
            $dupeProductId = (int) db_get_field(
                'SELECT product_id FROM ?:sphinx_hotels
                 WHERE name = ?s
                   AND ROUND(latitude, 3) = ROUND(?d, 3) AND ROUND(longitude, 3) = ROUND(?d, 3)
                   AND product_id IS NOT NULL AND product_id > 0 AND hotel_id != ?s
                 LIMIT 1',
                $hotel['name'],
                $lat,
                $lng,
                $hotelId,
            );
        }

        // Tier 3: name + property_type + classification + destination_id (fallback)
        if ($dupeProductId === 0 && $destId > 0) {
            $dupeProductId = (int) db_get_field(
                'SELECT product_id FROM ?:sphinx_hotels
                 WHERE name = ?s AND property_type = ?s AND classification = ?i
                   AND destination_id = ?i
                   AND product_id IS NOT NULL AND product_id > 0 AND hotel_id != ?s
                 LIMIT 1',
                $hotel['name'],
                $propType,
                $classif,
                $destId,
                $hotelId,
            );
        }

        if ($dupeProductId > 0) {
            $this->hotelRepo->linkToProduct($hotelId, $dupeProductId);
            return ['status' => 'linked', 'product_id' => $dupeProductId, 'reason' => 'duplicate hotel'];
        }

        // Build placeholder map and apply SEO templates (respects overwrite mode + field toggles)
        $placeholders = self::buildPlaceholders($hotel, $hierarchy);
        $seoFields = fn_travel_core_apply_seo_fields('sphinx_holidays', $placeholders, 0, $hotelId);

        $productData = array_merge([
            'product_code' => $productCode,
            'price' => 0,
            'amount' => ConfigProvider::getDefaultProductQuantity(),
            'status' => 'A',
            'company_id' => ConfigProvider::getCompanyId(),
            'main_category' => $categoryId,
            'category_ids' => [$categoryId],
            'short_description' => $hotel['short_description'] ?? '',
        ], $seoFields);

        $configuredLanguages = ConfigProvider::getProductLanguages();
        $primaryLang = !empty($configuredLanguages) ? reset($configuredLanguages) : CART_LANGUAGE;

        $productName = $productData['product'] ?? '';
        if (!is_string($productName) || trim($productName) === '') {
            $productData['product'] = $hotel['name'];
        }

        $productId = (int) fn_update_product($productData, 0, $primaryLang);
        if (!$productId) {
            fn_log_event('general', 'runtime', [
                'message' => 'Sphinx: fn_update_product() returned 0 — product not created',
                'hotel_id' => $hotelId,
                'product_code' => $productData['product_code'],
                'product_name' => $productData['product'],
                'category_id' => $categoryId,
                'company_id' => $productData['company_id'],
                'lang' => $primaryLang,
            ]);
            $this->hotelRepo->markSkipped($hotelId, 'product_creation_failed');
            return ['status' => 'failed', 'product_id' => 0, 'reason' => 'product creation'];
        }

        // Replicate descriptions to other configured languages
        $otherLanguages = array_diff($configuredLanguages, [$primaryLang]);
        $fullDescription = $productData['full_description'] ?? '';
        $pageTitle = $productData['page_title'] ?? '';
        $metaDesc = $productData['meta_description'] ?? '';
        $metaKeywords = $productData['meta_keywords'] ?? '';
        foreach ($otherLanguages as $lc) {
            db_query(
                'INSERT INTO ?:product_descriptions (product_id, lang_code, product, full_description, short_description, page_title, meta_description, meta_keywords)
                 VALUES (?i, ?s, ?s, ?s, ?s, ?s, ?s, ?s)
                 ON DUPLICATE KEY UPDATE product = ?s, full_description = ?s, short_description = ?s, page_title = ?s, meta_description = ?s, meta_keywords = ?s',
                $productId,
                $lc,
                $productData['product'],
                $fullDescription,
                $hotel['short_description'] ?? '',
                $pageTitle,
                $metaDesc,
                $metaKeywords,
                $productData['product'],
                $fullDescription,
                $hotel['short_description'] ?? '',
                $pageTitle,
                $metaDesc,
                $metaKeywords,
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
     * @param array<string, mixed> $hotel
     * @param array<string, mixed> $hierarchy
     */
    #[\Override]
    public function resolveCountryName(array $hotel, array $hierarchy): string
    {
        $countryName = ($hierarchy['country'] ?? '') ?: ($hotel['country_name'] ?? '');
        return $countryName ?: ($hotel['country_code'] ?? '');
    }

    /**
     * Build the placeholder map for SEO template rendering from Sphinx hotel data.
     *
     * @param array<string, mixed> $hotel Hotel row from sphinx_hotels table
     * @param array<string, mixed> $hierarchy Destination hierarchy (country, region, city)
     * @return array<string, string|array<int, string>> Key => value map (keys without braces)
     */
    public static function buildPlaceholders(array $hotel, array $hierarchy = []): array
    {
        $cityName = ($hierarchy['city'] ?? '') ?: ($hotel['destination_name'] ?? '');
        $countryName = ($hierarchy['country'] ?? '') ?: ($hotel['country_name'] ?? '');

        // Extract facility names from JSON
        $facilities = [];
        if (!empty($hotel['facilities_json'])) {
            $facilitiesData = is_string($hotel['facilities_json']) ? json_decode($hotel['facilities_json'], true) : $hotel['facilities_json'];
            if (is_array($facilitiesData)) {
                foreach ($facilitiesData as $f) {
                    $name = is_array($f) ? ($f['name'] ?? $f['title'] ?? '') : (string) $f;
                    if ($name !== '') {
                        $facilities[] = $name;
                    }
                }
            }
        }

        // Extract board names from JSON
        $boards = [];
        if (!empty($hotel['boards_json'])) {
            $boardsData = is_string($hotel['boards_json']) ? json_decode($hotel['boards_json'], true) : $hotel['boards_json'];
            if (is_array($boardsData)) {
                $boards = array_map('strval', $boardsData);
            }
        }

        return [
            'name' => $hotel['name'] ?? '',
            'classification' => $hotel['classification'] ?? '',
            'stars_emoji' => fn_travel_core_build_star_emoji((int) ($hotel['classification'] ?? 0)),
            'city' => $cityName,
            'country' => $countryName,
            'region' => $hotel['region_name'] ?? '',
            'property_type' => $hotel['property_type'] ?? 'hotel',
            'description' => $hotel['description'] ?? '',
            'rating' => $hotel['rating'] ?? '',
            'facilities' => $facilities,
            'boards' => $boards,
            'board_types' => $boards,
            'latitude' => $hotel['latitude'] ?? '',
            'longitude' => $hotel['longitude'] ?? '',
            'image_url' => $hotel['image_url'] ?? '',
            'address' => $hotel['address'] ?? '',
            'phone' => $hotel['phone'] ?? '',
            'email' => $hotel['email'] ?? '',
            'website' => $hotel['website'] ?? '',
            'year' => date('Y'),
        ];
    }
}

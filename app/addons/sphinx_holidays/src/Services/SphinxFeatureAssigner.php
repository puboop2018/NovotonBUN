<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Registry;
use Tygh\Addons\SphinxHolidays\Api\SphinxNormalizer;
use Tygh\Addons\TravelCore\Services\FeatureMapper;

/**
 * Assigns CS-Cart product features to Sphinx hotel products.
 *
 * Uses travel_core/FeatureMapper for alias resolution and direct DB writes
 * for CS-Cart product_features_values assignment.
 *
 * Much simpler than Novoton's FeatureMapper — no separate mapping table,
 * no strict/dynamic modes, no FeatureMappingRepositoryInterface.
 */
class SphinxFeatureAssigner
{
    private const API_SOURCE = 'sphinx';

    private SphinxNormalizer $normalizer;
    private ?array $activeLanguages = null;

    /** Feature type → travel_core addon setting key for cscart_feature_id */
    private const FEATURE_SETTINGS = [
        'stars'         => 'feature_id_property_rating',
        'property_type' => 'feature_id_property_type',
        'board'         => 'feature_id_meals',
        'resort'        => 'feature_id_location',
    ];

    public function __construct(SphinxNormalizer $normalizer)
    {
        $this->normalizer = $normalizer;
    }

    /**
     * Assign all features from a sphinx_hotels row to a CS-Cart product.
     */
    public function assignAll(int $productId, array $hotel): void
    {
        $this->assignStarRating($productId, $hotel);
        $this->assignPropertyType($productId, $hotel);
        $this->assignResort($productId, $hotel);
        $this->assignBoardTypes($productId, $hotel);
    }

    private function assignStarRating(int $productId, array $hotel): void
    {
        $code = $this->normalizer->normalizeStarRating($hotel['classification'] ?? null);
        if ($code === null) {
            return;
        }

        $featureId = $this->getFeatureId('stars');
        if (!$featureId) {
            return;
        }

        $mapping = FeatureMapper::resolve(self::API_SOURCE, 'stars', $code);
        $variantId = $mapping ? (int) ($mapping['cscart_variant_id'] ?? 0) : 0;

        if ($variantId <= 0 && $mapping) {
            $variantId = $this->autoCreateVariant($featureId, $mapping);
        }

        if ($variantId > 0) {
            $this->assignSelectBoxValue($productId, $featureId, $variantId);
        }
    }

    private function assignPropertyType(int $productId, array $hotel): void
    {
        $code = $this->normalizer->normalizePropertyType($hotel['property_type'] ?? null);
        if ($code === null) {
            return;
        }

        $featureId = $this->getFeatureId('property_type');
        if (!$featureId) {
            return;
        }

        $mapping = FeatureMapper::resolve(self::API_SOURCE, 'property_type', $code);
        $variantId = $mapping ? (int) ($mapping['cscart_variant_id'] ?? 0) : 0;

        if ($variantId <= 0 && $mapping) {
            $variantId = $this->autoCreateVariant($featureId, $mapping);
        }

        if ($variantId > 0) {
            $this->assignSelectBoxValue($productId, $featureId, $variantId);
        }
    }

    private function assignResort(int $productId, array $hotel): void
    {
        $code = $this->normalizer->normalizeResort($hotel['destination_name'] ?? null);
        if ($code === null) {
            return;
        }

        $featureId = $this->getFeatureId('resort');
        if (!$featureId) {
            return;
        }

        // Resort names are dynamic — may not have aliases seeded
        $mapping = FeatureMapper::resolve(self::API_SOURCE, 'resort', $code);
        $variantId = $mapping ? (int) ($mapping['cscart_variant_id'] ?? 0) : 0;

        if ($variantId > 0) {
            $this->assignSelectBoxValue($productId, $featureId, $variantId);
        }
        // If no mapping, skip — resort feature is optional
    }

    private function assignBoardTypes(int $productId, array $hotel): void
    {
        $amenitiesJson = $hotel['amenities_json'] ?? null;
        if (empty($amenitiesJson)) {
            return;
        }

        $amenities = is_string($amenitiesJson) ? json_decode($amenitiesJson, true) : $amenitiesJson;
        if (!is_array($amenities)) {
            return;
        }

        $featureId = $this->getFeatureId('board');
        if (!$featureId) {
            return;
        }

        foreach ($amenities as $amenity) {
            $name = is_array($amenity) ? ($amenity['name'] ?? '') : (string) $amenity;
            $boardCode = $this->normalizer->normalizeBoardCode($name);
            if ($boardCode === null) {
                continue;
            }

            $mapping = FeatureMapper::resolve(self::API_SOURCE, 'board', $name);
            $variantId = $mapping ? (int) ($mapping['cscart_variant_id'] ?? 0) : 0;

            if ($variantId <= 0 && $mapping) {
                $variantId = $this->autoCreateVariant($featureId, $mapping);
            }
            if ($variantId > 0) {
                $this->assignCheckboxValue($productId, $featureId, $variantId);
            }
        }
    }

    // --- Private helpers ---

    private function getFeatureId(string $featureType): int
    {
        $settingKey = self::FEATURE_SETTINGS[$featureType] ?? null;
        if (!$settingKey) {
            return 0;
        }
        return (int) Registry::get('addons.travel_core.' . $settingKey);
    }

    /**
     * Auto-create a CS-Cart feature variant from mapping display names.
     */
    private function autoCreateVariant(int $featureId, array $mapping): int
    {
        $nameEn = $mapping['display_name_en'] ?? $mapping['canonical_code'] ?? '';
        $nameRo = $mapping['display_name_ro'] ?? $nameEn;

        $variantId = (int) db_query(
            "INSERT INTO ?:product_feature_variants ?e",
            ['feature_id' => $featureId, 'position' => 0]
        );
        if ($variantId <= 0) {
            return 0;
        }

        foreach ($this->getActiveLanguages() as $langCode) {
            $variantName = ($langCode === 'ro') ? $nameRo : $nameEn;
            db_query(
                "INSERT INTO ?:product_feature_variant_descriptions (variant_id, lang_code, variant)
                 VALUES (?i, ?s, ?s) ON DUPLICATE KEY UPDATE variant = ?s",
                $variantId, $langCode, $variantName, $variantName
            );
        }

        // Update travel_feature_map with the new variant_id for future lookups
        if (!empty($mapping['map_id'])) {
            db_query(
                "UPDATE ?:travel_feature_map SET cscart_variant_id = ?i WHERE map_id = ?i",
                $variantId, (int) $mapping['map_id']
            );
            FeatureMapper::clearCache();
        }

        return $variantId;
    }

    /**
     * Assign a Select Box feature value (overwrite: DELETE + INSERT per language).
     */
    private function assignSelectBoxValue(int $productId, int $featureId, int $variantId): void
    {
        db_query(
            "DELETE FROM ?:product_features_values WHERE feature_id = ?i AND product_id = ?i",
            $featureId, $productId
        );
        foreach ($this->getActiveLanguages() as $langCode) {
            db_query(
                "INSERT INTO ?:product_features_values ?e ON DUPLICATE KEY UPDATE variant_id = ?i, value_int = ?i",
                [
                    'feature_id'  => $featureId,
                    'product_id'  => $productId,
                    'variant_id'  => $variantId,
                    'value'       => '',
                    'value_int'   => $variantId,
                    'lang_code'   => $langCode,
                ],
                $variantId, $variantId
            );
        }
    }

    /**
     * Assign a Multiple Checkbox feature value (merge: INSERT if not present).
     */
    private function assignCheckboxValue(int $productId, int $featureId, int $variantId): void
    {
        $exists = db_get_field(
            "SELECT 1 FROM ?:product_features_values
             WHERE feature_id = ?i AND product_id = ?i AND variant_id = ?i AND lang_code = 'en'",
            $featureId, $productId, $variantId
        );
        if ($exists) {
            return;
        }

        foreach ($this->getActiveLanguages() as $langCode) {
            db_query(
                "INSERT INTO ?:product_features_values ?e ON DUPLICATE KEY UPDATE variant_id = ?i",
                [
                    'feature_id'  => $featureId,
                    'product_id'  => $productId,
                    'variant_id'  => $variantId,
                    'value'       => '',
                    'value_int'   => $variantId,
                    'lang_code'   => $langCode,
                ],
                $variantId
            );
        }
    }

    private function getActiveLanguages(): array
    {
        if ($this->activeLanguages === null) {
            $this->activeLanguages = db_get_fields("SELECT lang_code FROM ?:languages WHERE status = 'A'");
            if (empty($this->activeLanguages)) {
                $this->activeLanguages = ['en'];
            }
        }
        return $this->activeLanguages;
    }
}

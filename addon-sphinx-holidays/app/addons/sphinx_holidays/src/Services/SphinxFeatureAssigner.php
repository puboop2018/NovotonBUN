<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Registry;
use Tygh\Addons\SphinxHolidays\Api\SphinxNormalizer;
use Tygh\Addons\TravelCore\Services\FeatureMapper;
use Tygh\Addons\TravelCore\Traits\CsCartFeatureAssignment;

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
    use CsCartFeatureAssignment;

    private const API_SOURCE = 'sphinx';

    private SphinxNormalizer $normalizer;

    /** Feature type → travel_core addon setting key for cscart_feature_id */
    private const FEATURE_SETTINGS = [
        'stars'         => 'feature_id_property_rating',
        'property_type' => 'feature_id_property_type',
        'resort'        => 'feature_id_location',
        'board'         => 'feature_id_meals',
        'region'        => 'feature_id_region',
        'city'          => 'feature_id_city',
    ];

    /** @var array<string, int> featureId:variantName → variant_id cache */
    private array $locationVariantCache = [];

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
        $this->assignFacilities($productId, $hotel);
        $this->assignBoards($productId, $hotel);
        $this->assignRegion($productId, $hotel);
        $this->assignCity($productId, $hotel);
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

    /**
     * Assign facility features from facilities_json (diff-based sync).
     *
     * Each facility canonical code in travel_feature_map carries its own cscart_feature_id,
     * so different facilities can map to different CS-Cart features (e.g., "Hotel Facilities",
     * "Room Amenities", "Accessibility"). The admin controls this via the travel_feature_mappings UI.
     *
     * Groups wanted variants by cscart_feature_id, then for each feature:
     * adds new variants and removes stale ones — preventing accumulation
     * of facilities that are no longer present in the API data.
     */
    private function assignFacilities(int $productId, array $hotel): void
    {
        $facilitiesJson = $hotel['facilities_json'] ?? null;
        if (empty($facilitiesJson)) {
            return;
        }

        $facilities = is_string($facilitiesJson) ? json_decode($facilitiesJson, true) : $facilitiesJson;
        if (!is_array($facilities)) {
            return;
        }

        // Resolve all facilities and group wanted variant_ids by feature_id
        $wantedByFeature = [];
        foreach ($facilities as $facility) {
            $facilityId = (string) ($facility['id'] ?? '');
            if ($facilityId === '') {
                continue;
            }

            $mapping = FeatureMapper::resolve(self::API_SOURCE, 'facility', $facilityId);
            if (!$mapping) {
                continue;
            }

            $featureId = (int) ($mapping['cscart_feature_id'] ?? 0);
            $variantId = (int) ($mapping['cscart_variant_id'] ?? 0);

            if ($featureId <= 0 || $variantId <= 0) {
                continue;
            }

            $wantedByFeature[$featureId][] = $variantId;
        }

        // Diff-based sync per feature_id
        foreach ($wantedByFeature as $featureId => $wantedVariants) {
            $wantedVariants = array_unique($wantedVariants);

            $currentVariants = array_map('intval', db_get_fields(
                "SELECT variant_id FROM ?:product_features_values
                 WHERE feature_id = ?i AND product_id = ?i AND lang_code = 'en'",
                $featureId, $productId
            ));

            // Add new variants
            foreach ($wantedVariants as $vid) {
                if (!in_array($vid, $currentVariants, true)) {
                    $this->assignCheckboxValue($productId, $featureId, $vid);
                }
            }

            // Remove stale variants
            $stale = array_diff($currentVariants, $wantedVariants);
            foreach ($stale as $vid) {
                db_query(
                    "DELETE FROM ?:product_features_values
                     WHERE feature_id = ?i AND product_id = ?i AND variant_id = ?i",
                    $featureId, $productId, (int) $vid
                );
            }
        }
    }

    /**
     * Assign board/meal features from boards_json (diff-based sync).
     *
     * boards_json contains canonical codes (e.g. ["AI", "HB", "BB"]) discovered
     * by the discover_boards cron command from the cache API.
     *
     * Uses M-type (multiple checkboxes) assignment: a hotel can offer
     * multiple board options simultaneously (e.g. All Inclusive + Half Board).
     * Diff-based: adds new variants, removes stale ones.
     */
    private function assignBoards(int $productId, array $hotel): void
    {
        $boardsJson = $hotel['boards_json'] ?? null;
        if (empty($boardsJson)) {
            return;
        }

        $boards = is_string($boardsJson) ? json_decode($boardsJson, true) : $boardsJson;
        if (!is_array($boards) || empty($boards)) {
            return;
        }

        $featureId = $this->getFeatureId('board');
        if (!$featureId) {
            return;
        }

        // Get current variant_ids for diff
        $currentVariants = array_map('intval', db_get_fields(
            "SELECT variant_id FROM ?:product_features_values
             WHERE feature_id = ?i AND product_id = ?i AND lang_code = 'en'",
            $featureId, $productId
        ));

        // Resolve wanted variant_ids from canonical codes
        $wantedVariants = [];
        foreach ($boards as $code) {
            $mapping = FeatureMapper::resolve(self::API_SOURCE, 'board', $code);
            if (!$mapping) {
                continue;
            }

            $variantId = (int) ($mapping['cscart_variant_id'] ?? 0);
            if ($variantId <= 0) {
                $variantId = $this->autoCreateVariant($featureId, $mapping);
            }
            if ($variantId > 0) {
                $wantedVariants[] = $variantId;
            }
        }

        // Add new variants
        foreach ($wantedVariants as $vid) {
            if (!in_array($vid, $currentVariants, true)) {
                $this->assignCheckboxValue($productId, $featureId, $vid);
            }
        }

        // Remove stale variants
        $stale = array_diff($currentVariants, $wantedVariants);
        foreach ($stale as $vid) {
            db_query(
                "DELETE FROM ?:product_features_values
                 WHERE feature_id = ?i AND product_id = ?i AND variant_id = ?i",
                $featureId, $productId, (int) $vid
            );
        }
    }

    /**
     * Assign region as a select-box product feature.
     * Variants are created dynamically from region names.
     */
    private function assignRegion(int $productId, array $hotel): void
    {
        $regionName = trim((string) ($hotel['region_name'] ?? ''));
        if ($regionName === '') {
            return;
        }

        $featureId = $this->getFeatureId('region');
        if (!$featureId) {
            return;
        }

        $variantId = $this->getOrCreateLocationVariant($featureId, $regionName);
        if ($variantId > 0) {
            $this->assignSelectBoxValue($productId, $featureId, $variantId);
        }
    }

    /**
     * Assign city (destination) as a select-box product feature.
     * Variants are created dynamically from city/destination names.
     */
    private function assignCity(int $productId, array $hotel): void
    {
        $cityName = trim((string) ($hotel['destination_name'] ?? ''));
        if ($cityName === '') {
            return;
        }

        $featureId = $this->getFeatureId('city');
        if (!$featureId) {
            return;
        }

        $variantId = $this->getOrCreateLocationVariant($featureId, $cityName);
        if ($variantId > 0) {
            $this->assignSelectBoxValue($productId, $featureId, $variantId);
        }
    }

    /**
     * Get or create a feature variant by name for location-type features.
     * Caches lookups to avoid repeated DB queries within a batch.
     */
    private function getOrCreateLocationVariant(int $featureId, string $name): int
    {
        $cacheKey = $featureId . ':' . $name;
        if (isset($this->locationVariantCache[$cacheKey])) {
            return $this->locationVariantCache[$cacheKey];
        }

        // Look for existing variant by name
        $variantId = (int) db_get_field(
            "SELECT pf.variant_id FROM ?:product_feature_variant_descriptions pf
             WHERE pf.variant = ?s AND pf.lang_code = ?s
             AND pf.variant_id IN (SELECT variant_id FROM ?:product_feature_variants WHERE feature_id = ?i)
             LIMIT 1",
            $name, CART_LANGUAGE, $featureId
        );

        if ($variantId <= 0) {
            // Create new variant — same name for both EN and RO (geographic names are the same)
            $variantId = $this->createVariant($featureId, $name, $name);
        }

        if ($variantId > 0) {
            $this->locationVariantCache[$cacheKey] = $variantId;
        }

        return $variantId;
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
     * Delegates to the shared trait for variant creation, then updates
     * travel_feature_map with the new variant_id for future lookups.
     */
    private function autoCreateVariant(int $featureId, array $mapping): int
    {
        $nameEn = $mapping['display_name_en'] ?? $mapping['canonical_code'] ?? '';
        $nameRo = $mapping['display_name_ro'] ?? $nameEn;

        $variantId = $this->createVariant($featureId, $nameEn, $nameRo);

        // Update travel_feature_map with the new variant_id for future lookups
        if ($variantId > 0 && !empty($mapping['map_id'])) {
            db_query(
                "UPDATE ?:travel_feature_map SET cscart_variant_id = ?i WHERE map_id = ?i",
                $variantId, (int) $mapping['map_id']
            );
            FeatureMapper::clearCache();
        }

        return $variantId;
    }

    // assignSelectBoxValue(), assignCheckboxValue(), getActiveLanguages(),
    // and createVariant() are provided by CsCartFeatureAssignment trait.
}

<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

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

    private readonly SphinxNormalizer $normalizer;

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
        $this->assignTravelGroup($productId, $hotel);
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
                $facilityName = $facility['name'] ?? '';
                FeatureMapper::handleUnmapped(self::API_SOURCE, 'facility', $facilityId, $facilityName);
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
            $this->syncCheckboxValues($productId, $featureId, $wantedVariants);
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

        $this->syncCheckboxValues($productId, $featureId, $wantedVariants);
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

    /** Facility canonical codes that indicate a family-friendly hotel */
    private const FAMILY_FACILITY_CODES = ['family_rooms', 'kids_menu', 'babysitting', 'kids_club', 'kids_pool', 'playground'];

    /** Facility canonical codes that indicate a pet-friendly hotel */
    private const PETS_FACILITY_CODES = ['pets_allowed'];

    /**
     * Assign travel group features (M-type: multiple checkboxes).
     *
     * A hotel can have multiple travel groups simultaneously:
     * - adults_only: from explicit is_adults_only flag
     * - family_friendly: inferred from family facilities
     * - pets_friendly: inferred from pets_allowed facility
     *
     * Uses diff-based sync: adds new groups, removes stale ones.
     */
    private function assignTravelGroup(int $productId, array $hotel): void
    {
        $featureId = $this->getFeatureId('travel_group');
        if (!$featureId) {
            return;
        }

        $groupCodes = $this->detectTravelGroups($hotel);
        if (empty($groupCodes)) {
            // No groups — clear any existing assignments
            $this->syncCheckboxValues($productId, $featureId, []);
            return;
        }

        $wantedVariants = [];
        foreach ($groupCodes as $code) {
            $mapping = FeatureMapper::resolve(self::API_SOURCE, 'travel_group', $code);
            $variantId = $mapping ? (int) ($mapping['cscart_variant_id'] ?? 0) : 0;

            if ($variantId <= 0 && $mapping) {
                $variantId = $this->autoCreateVariant($featureId, $mapping);
            }

            if ($variantId > 0) {
                $wantedVariants[] = $variantId;
            }
        }

        if (!empty($wantedVariants)) {
            $this->syncCheckboxValues($productId, $featureId, $wantedVariants);
        }
    }

    /**
     * Detect all applicable travel groups from hotel data.
     *
     * @return string[] Alias keys for FeatureMapper ('Y' = adults_only, 'family' = family_friendly, 'pets' = pets_friendly)
     */
    private function detectTravelGroups(array $hotel): array
    {
        $groups = [];

        // Adults-only (explicit flag)
        if (($hotel['is_adults_only'] ?? 'N') === 'Y') {
            $groups[] = 'Y';
        }

        // Infer from facilities
        $facilityCodesPresent = $this->getHotelFacilityCodes($hotel);

        if (!empty(array_intersect($facilityCodesPresent, self::FAMILY_FACILITY_CODES))) {
            $groups[] = 'family';
        }

        if (!empty(array_intersect($facilityCodesPresent, self::PETS_FACILITY_CODES))) {
            $groups[] = 'pets';
        }

        return $groups;
    }

    /**
     * Get all canonical facility codes present in a hotel's facilities_json.
     *
     * @return string[] Canonical codes (e.g. ['pool', 'pets_allowed', 'family_rooms'])
     */
    private function getHotelFacilityCodes(array $hotel): array
    {
        $facilitiesJson = $hotel['facilities_json'] ?? null;
        if (empty($facilitiesJson)) {
            return [];
        }

        $facilities = is_string($facilitiesJson) ? json_decode($facilitiesJson, true) : $facilitiesJson;
        if (!is_array($facilities)) {
            return [];
        }

        $codes = [];
        foreach ($facilities as $facility) {
            $facilityId = (string) ($facility['id'] ?? '');
            if ($facilityId === '') {
                continue;
            }

            $mapping = FeatureMapper::resolve(self::API_SOURCE, 'facility', $facilityId);
            if ($mapping && !empty($mapping['canonical_code'])) {
                $codes[] = $mapping['canonical_code'];
            }
        }

        return $codes;
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
        return FeatureMapper::getFeatureId($featureType);
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

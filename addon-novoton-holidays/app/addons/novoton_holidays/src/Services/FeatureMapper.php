<?php

declare(strict_types=1);

/**
 * Feature Mapper Service (Novoton)
 *
 * Thin wrapper around travel_core's FeatureMapper for Novoton-specific
 * product feature assignment. All feature resolution goes through
 * the shared travel_feature_map + travel_api_alias canonical system.
 *
 * @package NovotonHolidays
 * @since 4.0.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\TravelCore\Services\FeatureMapper as CoreFeatureMapper;
use Tygh\Addons\TravelCore\Traits\CsCartFeatureAssignment;

class FeatureMapper
{
    use CsCartFeatureAssignment {
        assignSelectBoxValue as private assignSelectBox;
        assignCheckboxValue as private assignCheckbox;
        syncCheckboxValues as private syncCheckboxes;
    }

    /**
     * Assign a single feature value to a product via travel_core.
     *
     * Resolves the provider code via travel_core's canonical mapping,
     * ensures a CS-Cart variant exists (3-pass matching + auto-create),
     * and assigns to the product.
     *
     * @param string $coreFeatureType travel_core feature type (e.g. 'stars', 'property_type', 'travel_group', 'resort')
     */
    public function assignFeatureViaCore(
        int $productId,
        string $coreFeatureType,
        string $providerCode,
        string $apiSource = 'novoton',
    ): bool {
        $mapping = CoreFeatureMapper::resolveWithVariant($apiSource, $coreFeatureType, $providerCode);
        if (!$mapping) {
            CoreFeatureMapper::handleUnmapped($apiSource, $coreFeatureType, $providerCode);
            return false;
        }

        $featureId = (int) ($mapping['cscart_feature_id'] ?? 0);
        $variantId = (int) ($mapping['cscart_variant_id'] ?? 0);

        if ($featureId <= 0 || $variantId <= 0) {
            return false;
        }

        $csFeatureType = db_get_field(
            'SELECT feature_type FROM ?:product_features WHERE feature_id = ?i',
            $featureId,
        );

        return match ($csFeatureType) {
            'S' => $this->assignSelectBox($productId, $featureId, $variantId),
            'M' => $this->assignCheckbox($productId, $featureId, $variantId),
            default => false,
        };
    }

    /**
     * Assign multiple feature values via travel_core (diff-based sync).
     *
     * Resolves all codes, groups by cscart_feature_id, then syncs each group.
     * Handles codes mapping to different CS-Cart features (e.g. pool → Hotel Amenities, wifi → Room Amenities).
     *
     * @param string $coreFeatureType travel_core feature type (e.g. 'board', 'hotel_facility')
     * @param string[] $providerCodes Normalized provider codes
     * @return int Number of successfully assigned features
     */
    public function assignMultipleViaCore(
        int $productId,
        string $coreFeatureType,
        array $providerCodes,
        string $apiSource = 'novoton',
    ): int {
        $providerCodes = array_unique(array_filter(array_map('trim', $providerCodes)));
        if (empty($providerCodes)) {
            return 0;
        }

        $wantedByFeature = [];
        foreach ($providerCodes as $code) {
            $mapping = CoreFeatureMapper::resolveWithVariant($apiSource, $coreFeatureType, $code);
            if (!$mapping) {
                CoreFeatureMapper::handleUnmapped($apiSource, $coreFeatureType, $code);
                continue;
            }

            $featureId = (int) ($mapping['cscart_feature_id'] ?? 0);
            $variantId = (int) ($mapping['cscart_variant_id'] ?? 0);

            if ($featureId > 0 && $variantId > 0) {
                $wantedByFeature[$featureId][] = $variantId;
            }
        }

        if (empty($wantedByFeature)) {
            return 0;
        }

        $totalAssigned = 0;
        foreach ($wantedByFeature as $featureId => $variantIds) {
            $totalAssigned += $this->syncCheckboxes($productId, $featureId, array_unique($variantIds));
        }

        return $totalAssigned;
    }

    /**
     * Assign facility codes across all facility sub-types (hotel_facility, room_facility, beach_access).
     *
     * Facility codes may belong to different sub-types, so this uses the
     * cross-type resolver to find the correct mapping for each code.
     *
     * @param string[] $providerCodes Normalized provider codes (e.g. 'pool', 'free_wifi')
     * @return int Number of successfully assigned features
     */
    public function assignFacilitiesViaCore(int $productId, array $providerCodes, string $apiSource = 'novoton'): int
    {
        $providerCodes = array_unique(array_filter(array_map('trim', $providerCodes)));
        if (empty($providerCodes)) {
            return 0;
        }

        $wantedByFeature = [];
        foreach ($providerCodes as $code) {
            $mapping = CoreFeatureMapper::resolveWithVariantFacility($apiSource, $code);
            if (!$mapping) {
                CoreFeatureMapper::handleUnmapped($apiSource, 'hotel_facility', $code);
                continue;
            }

            $featureId = (int) ($mapping['cscart_feature_id'] ?? 0);
            $variantId = (int) ($mapping['cscart_variant_id'] ?? 0);

            if ($featureId > 0 && $variantId > 0) {
                $wantedByFeature[$featureId][] = $variantId;
            }
        }

        if (empty($wantedByFeature)) {
            return 0;
        }

        $totalAssigned = 0;
        foreach ($wantedByFeature as $featureId => $variantIds) {
            $totalAssigned += $this->syncCheckboxes($productId, $featureId, array_unique($variantIds));
        }

        return $totalAssigned;
    }

    /**
     * Get the CS-Cart feature name for a feature type.
     *
     * Uses travel_core's getFeatureId() to resolve the CS-Cart feature,
     * then fetches the description from product_features_descriptions.
     */
    public function getFeatureName(string $featureType, string $langCode = 'en'): ?string
    {
        // Map Novoton feature types to travel_core setting keys
        $coreTypeMap = [
            'property_rating' => 'stars',
            'meals' => 'board',
            'property_type' => 'property_type',
            'hotel_facility' => 'hotel_facility',
            'room_facility' => 'room_facility',
            'travel_group' => 'travel_group',
            'beach_access' => 'beach_access',
            'resort' => 'resort',
        ];

        $coreType = $coreTypeMap[$featureType] ?? $featureType;
        $featureId = CoreFeatureMapper::getFeatureId($coreType);

        if ($featureId <= 0) {
            return null;
        }

        $name = db_get_field(
            'SELECT description FROM ?:product_features_descriptions WHERE feature_id = ?i AND lang_code = ?s',
            $featureId,
            $langCode,
        );

        return $name ?: null;
    }
}

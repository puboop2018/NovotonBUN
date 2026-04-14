<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Traits;

/**
 * Shared CS-Cart product feature assignment methods.
 *
 * Provides the low-level DB operations for assigning feature values to products:
 * - Select Box (S-type): overwrite with skip-if-same optimization
 * - Multiple Checkbox (M-type): merge with existence check
 * - Variant creation with per-language descriptions
 * - Active language caching
 *
 * Used by both Novoton FeatureMapper and Sphinx SphinxFeatureAssigner
 * to eliminate code duplication (DUP-1 through DUP-4).
 */
trait CsCartFeatureAssignment
{
    /** @var string[]|null Cached active language codes */
    private ?array $activeLanguages = null;

    /**
     * Get all active language codes from CS-Cart.
     *
     * @return string[]
     */
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

    /**
     * Assign a Select Box feature value (overwrite: DELETE + INSERT per language).
     * Includes skip-if-same optimization to avoid unnecessary DB writes.
     */
    private function assignSelectBoxValue(int $productId, int $featureId, int $variantId): bool
    {
        // Skip if already correct
        $existing = db_get_field(
            "SELECT variant_id FROM ?:product_features_values WHERE feature_id = ?i AND product_id = ?i AND lang_code = 'en'",
            $featureId,
            $productId,
        );

        if ((int) $existing === $variantId) {
            return true;
        }

        // Overwrite: delete all then insert for each language
        db_query(
            'DELETE FROM ?:product_features_values WHERE feature_id = ?i AND product_id = ?i',
            $featureId,
            $productId,
        );

        foreach ($this->getActiveLanguages() as $langCode) {
            db_query(
                'INSERT INTO ?:product_features_values ?e ON DUPLICATE KEY UPDATE variant_id = ?i, value_int = ?i',
                [
                    'feature_id' => $featureId,
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'value' => '',
                    'value_int' => $variantId,
                    'lang_code' => $langCode,
                ],
                $variantId,
                $variantId,
            );
        }

        return true;
    }

    /**
     * Assign a Multiple Checkbox feature value (merge: INSERT if not present).
     * Includes existence check to avoid duplicate inserts.
     */
    private function assignCheckboxValue(int $productId, int $featureId, int $variantId): bool
    {
        $exists = db_get_field(
            "SELECT 1 FROM ?:product_features_values
             WHERE feature_id = ?i AND product_id = ?i AND variant_id = ?i AND lang_code = 'en'",
            $featureId,
            $productId,
            $variantId,
        );

        if ($exists) {
            return true;
        }

        foreach ($this->getActiveLanguages() as $langCode) {
            db_query(
                'INSERT INTO ?:product_features_values ?e ON DUPLICATE KEY UPDATE variant_id = ?i',
                [
                    'feature_id' => $featureId,
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'value' => '',
                    'value_int' => $variantId,
                    'lang_code' => $langCode,
                ],
                $variantId,
            );
        }

        return true;
    }

    /**
     * Diff-based sync for M-type (multiple checkbox) features.
     *
     * Compares wanted variant_ids against current DB state:
     * adds new variants, removes stale ones.
     *
     * @param int[] $wantedVariantIds The full set of variant_ids that should be assigned
     * @return int Count of variants now assigned (added + kept)
     */
    private function syncCheckboxValues(int $productId, int $featureId, array $wantedVariantIds): int
    {
        $wantedVariantIds = array_unique(array_filter($wantedVariantIds));

        $currentVariants = array_map('intval', db_get_fields(
            "SELECT variant_id FROM ?:product_features_values
             WHERE feature_id = ?i AND product_id = ?i AND lang_code = 'en'",
            $featureId,
            $productId,
        ));

        $toAdd = array_diff($wantedVariantIds, $currentVariants);
        $toRemove = array_diff($currentVariants, $wantedVariantIds);

        foreach ($toRemove as $vid) {
            db_query(
                'DELETE FROM ?:product_features_values
                 WHERE feature_id = ?i AND product_id = ?i AND variant_id = ?i',
                $featureId,
                $productId,
                (int) $vid,
            );
        }

        foreach ($toAdd as $vid) {
            $this->assignCheckboxValue($productId, $featureId, (int) $vid);
        }

        return count($toAdd) + count(array_intersect($wantedVariantIds, $currentVariants));
    }

    /**
     * Create a CS-Cart product feature variant + descriptions for all active languages.
     *
     * @param int $featureId CS-Cart feature_id
     * @param string $nameEn English display name
     * @param string $nameRo Romanian display name (falls back to EN if empty)
     * @param int $position Sort position (default 0)
     * @return int New variant_id or 0 on failure
     */
    private function createVariant(int $featureId, string $nameEn, string $nameRo = '', int $position = 0): int
    {
        if ($nameRo === '') {
            $nameRo = $nameEn;
        }

        $variantId = (int) db_query(
            'INSERT INTO ?:product_feature_variants ?e',
            ['feature_id' => $featureId, 'position' => $position],
        );

        if ($variantId <= 0) {
            return 0;
        }

        foreach ($this->getActiveLanguages() as $langCode) {
            $variantName = ($langCode === 'ro') ? $nameRo : $nameEn;
            db_query(
                'INSERT INTO ?:product_feature_variant_descriptions (variant_id, lang_code, variant)
                 VALUES (?i, ?s, ?s) ON DUPLICATE KEY UPDATE variant = ?s',
                $variantId,
                $langCode,
                $variantName,
                $variantName,
            );
        }

        return $variantId;
    }
}

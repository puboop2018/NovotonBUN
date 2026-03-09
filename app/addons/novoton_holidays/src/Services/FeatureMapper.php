<?php
declare(strict_types=1);
/**
 * Feature Mapper Service
 *
 * Central service for assigning provider feature values to CS-Cart products.
 * Handles overwrite (Select Box) and merge (Multiple Checkboxes) strategies,
 * auto-creates CS-Cart variants from mapping table data, and supports
 * strict/dynamic mode for unknown value handling.
 *
 * @package NovotonHolidays
 * @since 3.3.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\Repository\FeatureMappingRepositoryInterface;

class FeatureMapper
{
    private FeatureMappingRepositoryInterface $mappingRepo;

    /** @var string[]|null Cached active language codes */
    private ?array $activeLanguages = null;

    public function __construct(FeatureMappingRepositoryInterface $mappingRepo)
    {
        $this->mappingRepo = $mappingRepo;
    }

    /**
     * Assign a single feature value to a product.
     *
     * S features: DELETE old + INSERT new (overwrite).
     * M features: INSERT if not already present (merge).
     */
    public function assignFeatureToProduct(int $productId, string $featureType, string $providerCode, string $provider = 'novoton'): bool
    {
        $mapping = $this->mappingRepo->findMapping($provider, $featureType, $providerCode);

        if ($mapping === null) {
            return $this->handleUnmapped($provider, $featureType, $providerCode);
        }

        $this->mappingRepo->updateLastSynced((int) $mapping['mapping_id']);

        $featureId = (int) $mapping['cs_cart_feature_id'];
        if ($featureId <= 0) {
            return false;
        }

        $variantId = $this->ensureVariantExists($mapping);
        if ($variantId <= 0) {
            return false;
        }

        $csFeatureType = $mapping['cs_cart_feature_type'];

        if ($csFeatureType === 'S') {
            return $this->assignSelectBox($productId, $featureId, $variantId);
        }

        if ($csFeatureType === 'M') {
            return $this->assignCheckbox($productId, $featureId, $variantId);
        }

        return false;
    }

    /**
     * Assign multiple feature values to a product (batch version).
     *
     * For M features: performs a diff — adds new, removes stale variants.
     * For S features: uses the last code in the array.
     *
     * @param string[] $providerCodes
     * @return int Number of successfully assigned features
     */
    public function assignMultipleToProduct(int $productId, string $featureType, array $providerCodes, string $provider = 'novoton'): int
    {
        $providerCodes = array_unique(array_filter(array_map('trim', $providerCodes)));

        if (empty($providerCodes)) {
            return 0;
        }

        // Determine feature type (S or M) from first valid mapping
        $csFeatureType = $this->mappingRepo->getCsCartFeatureType($featureType, $provider);
        $featureId = $this->mappingRepo->getFeatureId($featureType, $provider);

        if ($featureId === null || $featureId <= 0) {
            return 0;
        }

        if ($csFeatureType === 'S') {
            // Select Box: only the last code wins
            $lastCode = end($providerCodes);
            return $this->assignFeatureToProduct($productId, $featureType, $lastCode, $provider) ? 1 : 0;
        }

        if ($csFeatureType === 'M') {
            return $this->assignMultipleCheckboxes($productId, $featureType, $featureId, $providerCodes, $provider);
        }

        return 0;
    }

    /**
     * Get display name for a feature type + provider code.
     * Falls back to EN if the requested language is empty.
     */
    public function getDisplayName(string $featureType, string $providerCode, string $langCode = 'en', string $provider = 'novoton'): ?string
    {
        $mapping = $this->mappingRepo->findMapping($provider, $featureType, $providerCode);

        if ($mapping === null) {
            return null;
        }

        $langCol = ($langCode === 'ro') ? 'display_name_ro' : 'display_name_en';
        $name = $mapping[$langCol] ?? '';

        // Language fallback: if RO is empty, use EN
        if ($name === '' && $langCode === 'ro') {
            $name = $mapping['display_name_en'] ?? '';
        }

        return $name !== '' ? $name : null;
    }

    /**
     * Get the CS-Cart feature name from product_features_descriptions.
     */
    public function getFeatureName(string $featureType, string $langCode = 'en', string $provider = 'novoton'): ?string
    {
        $featureId = $this->mappingRepo->getFeatureId($featureType, $provider);

        if ($featureId === null) {
            return null;
        }

        $name = db_get_field(
            "SELECT description FROM ?:product_features_descriptions WHERE feature_id = ?i AND lang_code = ?s",
            $featureId,
            $langCode
        );

        return $name ?: null;
    }

    /**
     * Verify that cached cs_cart_feature_type matches the actual CS-Cart feature.
     * Call once per sync run to detect admin changes.
     */
    public function verifyCsCartFeatureType(string $featureType, string $provider = 'novoton'): void
    {
        $featureId = $this->mappingRepo->getFeatureId($featureType, $provider);

        if ($featureId === null) {
            return;
        }

        $actualType = db_get_field(
            "SELECT feature_type FROM ?:product_features WHERE feature_id = ?i",
            $featureId
        );

        if (!$actualType) {
            return;
        }

        $cachedType = $this->mappingRepo->getCsCartFeatureType($featureType, $provider);

        if ($cachedType !== null && $cachedType !== $actualType) {
            fn_log_event('general', 'runtime', [
                'message' => "FeatureMapper: CS-Cart feature type mismatch for '{$featureType}'. " .
                    "Cached: '{$cachedType}', Actual: '{$actualType}'. Updating cache.",
            ]);

            $this->mappingRepo->updateCachedFeatureType($featureType, $actualType, $provider);
        }
    }

    // =========================================================================
    // Private methods
    // =========================================================================

    /**
     * Ensure the CS-Cart variant exists.
     *
     * Resolution order:
     * 1. If variant_id is set and exists in CS-Cart → use it (no-op)
     * 2. If variant_source='manual' → don't auto-resolve (admin locked it)
     * 3. Try name-match against existing CS-Cart variants by display name
     * 4. Auto-create if no match found
     *
     * @return int variant_id or 0 on failure
     */
    private function ensureVariantExists(array $mapping): int
    {
        $variantId = (int) ($mapping['cs_cart_variant_id'] ?? 0);
        $featureId = (int) $mapping['cs_cart_feature_id'];
        $mappingId = (int) $mapping['mapping_id'];
        $variantSource = $mapping['variant_source'] ?? null;

        // Check if stored variant still exists in CS-Cart
        if ($variantId > 0) {
            $exists = db_get_field(
                "SELECT variant_id FROM ?:product_feature_variants WHERE variant_id = ?i AND feature_id = ?i",
                $variantId,
                $featureId
            );
            if ($exists) {
                return $variantId;
            }
            // Variant was deleted from CS-Cart — reset so we can re-resolve
            // But if manually set, don't auto-resolve (admin must fix)
            if ($variantSource === 'manual') {
                return 0;
            }
        }

        // Never auto-resolve manually locked mappings
        if ($variantSource === 'manual') {
            return $variantId;
        }

        // Try name-match against existing CS-Cart variants
        $matched = $this->findVariantByName($mapping);
        if ($matched > 0) {
            $this->mappingRepo->updateVariantId($mappingId, $matched, 'auto');
            return $matched;
        }

        // Auto-create the variant
        $variantId = $this->createCsCartVariant($mapping);

        if ($variantId > 0) {
            $this->mappingRepo->updateVariantId($mappingId, $variantId, 'auto');
        }

        return $variantId;
    }

    /**
     * Try to find an existing CS-Cart variant by display name match.
     *
     * Uses a 3-pass strategy per language (EN first, then RO fallback):
     *   1. Exact match (fastest, relies on DB collation for case)
     *   2. Case-insensitive match via LOWER()
     *   3. Normalized match — strips non-alphanumeric chars and collapses
     *      whitespace so "All-Inclusive" matches "All Inclusive", etc.
     *
     * @return int Matched variant_id or 0
     */
    private function findVariantByName(array $mapping): int
    {
        $featureId = (int) $mapping['cs_cart_feature_id'];
        $nameEn = trim($mapping['display_name_en'] ?? '');
        $nameRo = trim($mapping['display_name_ro'] ?? '');

        if ($featureId <= 0 || $nameEn === '') {
            return 0;
        }

        // Try EN first, then RO fallback
        $candidates = [['en', $nameEn]];
        if ($nameRo !== '' && $nameRo !== $nameEn) {
            $candidates[] = ['ro', $nameRo];
        }

        foreach ($candidates as [$lang, $name]) {
            // Pass 1: Exact match
            $variantId = db_get_field(
                "SELECT v.variant_id
                 FROM ?:product_feature_variants v
                 JOIN ?:product_feature_variant_descriptions vd ON v.variant_id = vd.variant_id
                 WHERE v.feature_id = ?i AND vd.lang_code = ?s AND vd.variant = ?s
                 LIMIT 1",
                $featureId,
                $lang,
                $name
            );
            if ($variantId) {
                return (int) $variantId;
            }

            // Pass 2: Case-insensitive match
            $variantId = db_get_field(
                "SELECT v.variant_id
                 FROM ?:product_feature_variants v
                 JOIN ?:product_feature_variant_descriptions vd ON v.variant_id = vd.variant_id
                 WHERE v.feature_id = ?i AND vd.lang_code = ?s AND LOWER(vd.variant) = LOWER(?s)
                 LIMIT 1",
                $featureId,
                $lang,
                $name
            );
            if ($variantId) {
                return (int) $variantId;
            }

            // Pass 3: Normalized match — fetch all variants for this feature/lang
            // and compare after stripping special chars and collapsing whitespace
            $normalized = $this->normalizeName($name);
            $rows = db_get_array(
                "SELECT v.variant_id, vd.variant
                 FROM ?:product_feature_variants v
                 JOIN ?:product_feature_variant_descriptions vd ON v.variant_id = vd.variant_id
                 WHERE v.feature_id = ?i AND vd.lang_code = ?s",
                $featureId,
                $lang
            );
            foreach ($rows as $row) {
                if ($this->normalizeName($row['variant']) === $normalized) {
                    return (int) $row['variant_id'];
                }
            }
        }

        return 0;
    }

    /**
     * Normalize a variant name for fuzzy comparison.
     *
     * Lowercases, strips non-alphanumeric/non-space chars, and collapses whitespace.
     * e.g. "All-Inclusive (Premium)" => "all inclusive premium"
     */
    private function normalizeName(string $name): string
    {
        $name = mb_strtolower($name, 'UTF-8');
        // Remove everything except letters, digits, and spaces
        $name = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $name);
        // Collapse multiple spaces into one and trim
        return trim(preg_replace('/\s+/', ' ', $name));
    }

    /**
     * Public wrapper for creating a CS-Cart variant from a mapping row.
     * Used by the controller for bulk auto-resolve.
     *
     * @return int New variant_id or 0 on failure
     */
    public function createVariantFromMapping(array $mapping): int
    {
        return $this->createCsCartVariant($mapping);
    }

    /**
     * Create a CS-Cart product feature variant + descriptions.
     *
     * @return int New variant_id or 0 on failure
     */
    private function createCsCartVariant(array $mapping): int
    {
        $featureId = (int) $mapping['cs_cart_feature_id'];
        $position = (int) ($mapping['position'] ?? 0);
        $nameEn = $mapping['display_name_en'] ?? $mapping['provider_code'];
        $nameRo = $mapping['display_name_ro'] ?? '';

        // Language fallback
        if ($nameRo === '') {
            $nameRo = $nameEn;
        }

        $variantId = (int) db_query(
            "INSERT INTO ?:product_feature_variants ?e",
            ['feature_id' => $featureId, 'position' => $position]
        );

        if ($variantId <= 0) {
            return 0;
        }

        foreach ($this->getActiveLanguages() as $langCode) {
            $variantName = ($langCode === 'ro') ? $nameRo : $nameEn;
            db_query(
                "INSERT INTO ?:product_feature_variant_descriptions (variant_id, lang_code, variant) " .
                "VALUES (?i, ?s, ?s) ON DUPLICATE KEY UPDATE variant = ?s",
                $variantId,
                $langCode,
                $variantName,
                $variantName
            );
        }

        return $variantId;
    }

    /**
     * Assign a Select Box feature value (overwrite: delete old + insert new).
     */
    private function assignSelectBox(int $productId, int $featureId, int $variantId): bool
    {
        // Atomic check: if already correct, skip
        $existing = db_get_field(
            "SELECT variant_id FROM ?:product_features_values WHERE feature_id = ?i AND product_id = ?i AND lang_code = 'en'",
            $featureId,
            $productId
        );

        if ((int) $existing === $variantId) {
            return true;
        }

        // Overwrite: delete all then insert for each language
        db_query(
            "DELETE FROM ?:product_features_values WHERE feature_id = ?i AND product_id = ?i",
            $featureId,
            $productId
        );

        foreach ($this->getActiveLanguages() as $langCode) {
            db_query(
                "INSERT INTO ?:product_features_values ?e ON DUPLICATE KEY UPDATE variant_id = ?i, value_int = ?i",
                [
                    'feature_id' => $featureId,
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'value' => '',
                    'value_int' => $variantId,
                    'lang_code' => $langCode,
                ],
                $variantId,
                $variantId
            );
        }

        return true;
    }

    /**
     * Assign a single checkbox variant (merge: add if not present).
     */
    private function assignCheckbox(int $productId, int $featureId, int $variantId): bool
    {
        // Check if already assigned
        $exists = db_get_field(
            "SELECT 1 FROM ?:product_features_values WHERE feature_id = ?i AND product_id = ?i AND variant_id = ?i AND lang_code = 'en'",
            $featureId,
            $productId,
            $variantId
        );

        if ($exists) {
            return true;
        }

        foreach ($this->getActiveLanguages() as $langCode) {
            db_query(
                "INSERT INTO ?:product_features_values ?e ON DUPLICATE KEY UPDATE variant_id = ?i",
                [
                    'feature_id' => $featureId,
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'value' => '',
                    'value_int' => $variantId,
                    'lang_code' => $langCode,
                ],
                $variantId
            );
        }

        return true;
    }

    /**
     * Assign multiple checkboxes with diff-based sync (add new, remove stale).
     *
     * @param string[] $providerCodes
     * @return int Count of successfully assigned
     */
    private function assignMultipleCheckboxes(int $productId, string $featureType, int $featureId, array $providerCodes, string $provider): int
    {
        $count = 0;
        $newVariantIds = [];

        // Resolve all provider codes to variant IDs
        foreach ($providerCodes as $code) {
            $mapping = $this->mappingRepo->findMapping($provider, $featureType, $code);

            if ($mapping === null) {
                $this->handleUnmapped($provider, $featureType, $code);
                continue;
            }

            $this->mappingRepo->updateLastSynced((int) $mapping['mapping_id']);

            $variantId = $this->ensureVariantExists($mapping);
            if ($variantId > 0) {
                $newVariantIds[] = $variantId;
            }
        }

        // Get current variant IDs on the product for this feature
        $currentVariantIds = db_get_fields(
            "SELECT DISTINCT variant_id FROM ?:product_features_values WHERE feature_id = ?i AND product_id = ?i AND variant_id > 0",
            $featureId,
            $productId
        );
        $currentVariantIds = array_map('intval', $currentVariantIds);

        // Calculate diff
        $toAdd = array_diff($newVariantIds, $currentVariantIds);
        $toRemove = array_diff($currentVariantIds, $newVariantIds);

        // Remove stale variants
        foreach ($toRemove as $staleVariantId) {
            db_query(
                "DELETE FROM ?:product_features_values WHERE feature_id = ?i AND product_id = ?i AND variant_id = ?i",
                $featureId,
                $productId,
                $staleVariantId
            );
        }

        // Add new variants
        foreach ($toAdd as $variantId) {
            foreach ($this->getActiveLanguages() as $langCode) {
                db_query(
                    "INSERT INTO ?:product_features_values ?e ON DUPLICATE KEY UPDATE variant_id = ?i",
                    [
                        'feature_id' => $featureId,
                        'product_id' => $productId,
                        'variant_id' => $variantId,
                        'value' => '',
                        'value_int' => $variantId,
                        'lang_code' => $langCode,
                    ],
                    $variantId
                );
            }
            $count++;
        }

        // Count existing that are still valid
        $count += count(array_intersect($newVariantIds, $currentVariantIds));

        return $count;
    }

    /**
     * Handle an unmapped provider code based on strict/dynamic mode.
     *
     * @return bool Always false (unmapped = not assigned)
     */
    private function handleUnmapped(string $provider, string $featureType, string $providerCode): bool
    {
        if (in_array($featureType, Constants::STRICT_FEATURE_TYPES, true)) {
            fn_log_event('general', 'runtime', [
                'message' => "FeatureMapper: Unmapped strict value skipped",
                'provider' => $provider,
                'feature_type' => $featureType,
                'provider_code' => $providerCode,
            ]);
            return false;
        }

        if (in_array($featureType, Constants::DYNAMIC_FEATURE_TYPES, true)) {
            $this->mappingRepo->registerUnmapped($provider, $featureType, $providerCode);
            return false;
        }

        return false;
    }

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
}

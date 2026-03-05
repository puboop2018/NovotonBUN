<?php
declare(strict_types=1);
/**
 * Provider Normalizer Interface
 *
 * Decouples provider-specific API data from the feature mapping engine.
 * Each provider (Novoton, Sphinx) implements this interface to sanitize
 * and normalize raw API values to canonical codes before mapping.
 *
 * @package NovotonHolidays
 * @since 3.3.0
 */

namespace Tygh\Addons\NovotonHolidays\Api;

interface ProviderNormalizerInterface
{
    /**
     * Get the provider name used in the mapping table.
     *
     * @return string e.g. 'novoton', 'sphinx'
     */
    public function getProviderName(): string;

    /**
     * Normalize a raw star rating value to a canonical code (1-5).
     *
     * @param string $rawValue Raw value from API (e.g. "4*", "3 Sup", "5")
     * @return string|null Canonical code (e.g. "4") or null if invalid
     */
    public function normalizeStarRating(string $rawValue): ?string;

    /**
     * Normalize a raw board/meal type value to a canonical code.
     *
     * @param string $rawValue Raw value from API (e.g. "ALL INCL", "AI", "Mic Dejun")
     * @return string|null Canonical code (e.g. "AI") or null if unrecognized
     */
    public function normalizeBoardCode(string $rawValue): ?string;

    /**
     * Normalize a facility identifier to a string code for mapping lookup.
     *
     * @param int|string $facilityId Facility ID or code from API
     * @return string|null String code for mapping table lookup
     */
    public function normalizeFacilityCode(int|string $facilityId): ?string;

    /**
     * Normalize a resort/city/destination value to a canonical string.
     *
     * @param string $rawValue Raw resort name or destination from API
     * @return string|null Normalized resort name or null if invalid
     */
    public function normalizeResort(string $rawValue): ?string;

    /**
     * Normalize a property type value to a canonical code.
     *
     * @param string $rawValue Raw value from API (e.g. "hotel", "villa", "Apart")
     * @return string|null Canonical code or null if unrecognized
     */
    public function normalizePropertyType(string $rawValue): ?string;
}

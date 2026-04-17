<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Contracts;

/**
 * Provider normalizer contract.
 *
 * Each travel provider implements this to normalize its API-specific
 * data formats into canonical codes used by the feature mapping system.
 */
interface ProviderNormalizerInterface
{
    /**
     * Get the provider identifier (e.g., 'novoton', 'sphinx').
     */
    public function getProviderName(): string;

    /**
     * Normalize a star/classification rating to a canonical string code.
     *
     * @param mixed $rawValue API-specific value (e.g., "4*", 5, "4 Stars")
     * @return string|null Canonical star code ("1"-"5") or null if unknown
     */
    public function normalizeStarRating(mixed $rawValue): ?string;

    /**
     * Normalize a board/meal plan to a canonical code.
     *
     * @param mixed $rawValue API-specific value (e.g., "AI", "Mic dejun", "ALL INCLUSIVE PLUS")
     * @return string|null Canonical code (AI, BB, HB, FB, RO) or null if unknown
     */
    public function normalizeBoardCode(mixed $rawValue): ?string;

    /**
     * Normalize a room type to a canonical code.
     *
     * @param mixed $rawValue API-specific value (e.g., "DBL", "Twin Room with Sea View")
     * @return string|null Canonical code (DBL, SGL, TWIN, TRP, QUAD, SUITE) or null
     */
    public function normalizeRoomTypeCode(mixed $rawValue): ?string;

    /**
     * Normalize a property type.
     *
     * @param mixed $rawValue API-specific value (e.g., "hotel", "4* Hotel", "apartment")
     * @return string|null Canonical type (hotel, villa, apartment, resort, hostel, etc.) or null
     */
    public function normalizePropertyType(mixed $rawValue): ?string;

    /**
     * Normalize a facility/amenity code.
     *
     * @param mixed $rawValue API-specific facility identifier
     * @return string|null Canonical facility code or null
     */
    public function normalizeFacilityCode(mixed $rawValue): ?string;

    /**
     * Normalize a resort/city/destination value.
     *
     * @param mixed $rawValue Raw resort name or destination from API
     * @return string|null Normalized resort name or null if invalid
     */
    public function normalizeResort(mixed $rawValue): ?string;
}

<?php
declare(strict_types=1);
/**
 * Novoton Provider Normalizer
 *
 * Sanitizes and normalizes Novoton API values to canonical codes
 * for the feature mapping engine.
 *
 * @package NovotonHolidays
 * @since 3.3.0
 */

namespace Tygh\Addons\NovotonHolidays\Api;

use Tygh\Addons\NovotonHolidays\ValueObjects\BoardType;

class NovotonNormalizer implements ProviderNormalizerInterface
{
    public function getProviderName(): string
    {
        return 'novoton';
    }

    public function normalizeStarRating(string $rawValue): ?string
    {
        $numeric = preg_replace('/[^0-9]/', '', trim($rawValue));

        if ($numeric === '' || $numeric === null) {
            return null;
        }

        $stars = (int) $numeric;

        if ($stars < 1 || $stars > 5) {
            return null;
        }

        return (string) $stars;
    }

    public function normalizeBoardCode(string $rawValue): ?string
    {
        $trimmed = trim($rawValue);

        if ($trimmed === '') {
            return null;
        }

        $canonical = BoardType::toCanonicalCode($trimmed);

        return BoardType::isValid($canonical) ? $canonical : null;
    }

    public function normalizeFacilityCode(int|string $facilityId): ?string
    {
        $id = (int) $facilityId;

        return $id > 0 ? (string) $id : null;
    }

    public function normalizeResort(string $rawValue): ?string
    {
        $trimmed = trim($rawValue);

        return $trimmed !== '' ? $trimmed : null;
    }

    public function normalizePropertyType(string $rawValue): ?string
    {
        // Novoton API does not currently provide property type.
        // Returns null to indicate unsupported.
        return null;
    }
}

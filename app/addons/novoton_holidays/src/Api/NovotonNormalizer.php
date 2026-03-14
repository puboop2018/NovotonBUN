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

use Tygh\Addons\TravelCore\Contracts\ProviderNormalizerInterface;
use Tygh\Addons\NovotonHolidays\ValueObjects\BoardType;

class NovotonNormalizer implements ProviderNormalizerInterface
{
    public function getProviderName(): string
    {
        return 'novoton';
    }

    public function normalizeStarRating(mixed $rawValue): ?string
    {
        $numeric = preg_replace('/[^0-9]/', '', trim((string) $rawValue));

        if ($numeric === '' || $numeric === null) {
            return null;
        }

        $stars = (int) $numeric;

        if ($stars < 1 || $stars > 5) {
            return null;
        }

        return (string) $stars;
    }

    public function normalizeBoardCode(mixed $rawValue): ?string
    {
        $trimmed = trim((string) $rawValue);

        if ($trimmed === '') {
            return null;
        }

        $canonical = BoardType::toCanonicalCode($trimmed);

        return BoardType::isValid($canonical) ? $canonical : null;
    }

    public function normalizeRoomTypeCode(mixed $rawValue): ?string
    {
        if (empty($rawValue)) {
            return null;
        }

        $value = trim((string) $rawValue);
        if ($value === '') {
            return null;
        }

        $lower = mb_strtolower($value);

        $prefixMap = [
            'single'    => 'SGL',
            'double'    => 'DBL',
            'twin'      => 'TWIN',
            'triple'    => 'TRP',
            'quad'      => 'QUAD',
            'suite'     => 'SUITE',
            'apartment' => 'APT',
            'studio'    => 'STUDIO',
            'family'    => 'DBL',
        ];

        foreach ($prefixMap as $prefix => $code) {
            if (str_starts_with($lower, $prefix)) {
                return $code;
            }
        }

        // Pass through short codes (DBL, SGL, etc.) as-is if uppercase
        if (preg_match('/^[A-Z]{2,6}$/', $value)) {
            return $value;
        }

        return null;
    }

    public function normalizeFacilityCode(mixed $rawValue): ?string
    {
        $id = (int) $rawValue;

        return $id > 0 ? (string) $id : null;
    }

    public function normalizeResort(mixed $rawValue): ?string
    {
        $trimmed = trim((string) $rawValue);

        return $trimmed !== '' ? mb_convert_case($trimmed, MB_CASE_TITLE, 'UTF-8') : null;
    }

    public function normalizePropertyType(mixed $rawValue): ?string
    {
        $trimmed = trim((string) $rawValue);

        if ($trimmed === '') {
            return null;
        }

        return (new PropertyTypeDetector())->detectFromName($trimmed);
    }
}

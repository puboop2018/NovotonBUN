<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Api;

use Tygh\Addons\TravelCore\Contracts\ProviderNormalizerInterface;

/**
 * Sphinx API data normalizer.
 *
 * Translates Sphinx-specific free-text values into canonical codes
 * used by the shared feature mapping system.
 */
class SphinxNormalizer implements ProviderNormalizerInterface
{
    /** Board code mapping: Sphinx meal names → canonical codes */
    private const BOARD_MAP = [
        // Romanian
        'all inclusive'         => 'AI',
        'all inclusive plus'    => 'AI',
        'ultra all inclusive'   => 'UAI',
        'pensiune completa'    => 'FB',
        'pensiune completă'    => 'FB',
        'demipensiune'         => 'HB',
        'mic dejun'            => 'BB',
        'fara masa'            => 'RO',
        'fără masă'            => 'RO',
        'self catering'        => 'SC',
        // English
        'full board'           => 'FB',
        'half board'           => 'HB',
        'bed and breakfast'    => 'BB',
        'bed & breakfast'      => 'BB',
        'room only'            => 'RO',
        'b&b'                  => 'BB',
    ];

    /** Room type prefix mapping */
    private const ROOM_TYPE_PREFIXES = [
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

    public function getProviderName(): string
    {
        return 'sphinx';
    }

    public function normalizeStarRating(mixed $rawValue): ?int
    {
        if ($rawValue === null || $rawValue === '') {
            return null;
        }

        $stars = (int) $rawValue;
        return ($stars >= 1 && $stars <= 5) ? $stars : null;
    }

    public function normalizeBoardCode(mixed $rawValue): ?string
    {
        if (empty($rawValue) || !is_string($rawValue)) {
            return null;
        }

        $lower = mb_strtolower(trim($rawValue));

        // Direct match
        if (isset(self::BOARD_MAP[$lower])) {
            return self::BOARD_MAP[$lower];
        }

        // Partial match
        foreach (self::BOARD_MAP as $pattern => $code) {
            if (str_contains($lower, $pattern)) {
                return $code;
            }
        }

        return null;
    }

    public function normalizeRoomTypeCode(mixed $rawValue): ?string
    {
        if (empty($rawValue) || !is_string($rawValue)) {
            return null;
        }

        $lower = mb_strtolower(trim($rawValue));

        foreach (self::ROOM_TYPE_PREFIXES as $prefix => $code) {
            if (str_starts_with($lower, $prefix)) {
                return $code;
            }
        }

        return null;
    }

    public function normalizePropertyType(mixed $rawValue): string
    {
        if (empty($rawValue) || !is_string($rawValue)) {
            return 'hotel';
        }

        $lower = mb_strtolower(trim($rawValue));

        $typeMap = [
            'hotel'       => 'hotel',
            'villa'       => 'villa',
            'apartment'   => 'apartment',
            'resort'      => 'resort',
            'hostel'      => 'hostel',
            'guest_house' => 'guest_house',
            'guesthouse'  => 'guest_house',
            'pension'     => 'guest_house',
            'pensiune'    => 'guest_house',
            'chalet'      => 'chalet',
            'cabana'      => 'chalet',
            'motel'       => 'motel',
        ];

        return $typeMap[$lower] ?? 'hotel';
    }

    public function normalizeFacilityCode(mixed $rawValue): ?string
    {
        // Sphinx facilities come with numeric IDs — pass through as-is
        if (is_int($rawValue) || is_numeric($rawValue)) {
            return (string) $rawValue;
        }

        return is_string($rawValue) ? $rawValue : null;
    }

    /**
     * Normalize Sphinx booking status to internal status.
     *
     * @param string $sphinxStatus Sphinx status (confirmed, pending, cancelled, etc.)
     * @return string Internal status (confirmed, pending, cancelled, failed)
     */
    public function normalizeBookingStatus(string $sphinxStatus): string
    {
        return match (strtolower($sphinxStatus)) {
            'confirmed'           => 'confirmed',
            'pending', 'on_hold'  => 'pending',
            'cancelled', 'canceled' => 'cancelled',
            'rejected', 'failed'  => 'failed',
            default               => 'pending',
        };
    }
}

<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Api;

use Tygh\Addons\TravelCore\Contracts\ProviderNormalizerInterface;
use Tygh\Addons\TravelCore\TravelConstants;

/**
 * Sphinx API data normalizer.
 *
 * Translates Sphinx-specific free-text values into canonical codes
 * used by the shared feature mapping system.
 */
class SphinxNormalizer implements ProviderNormalizerInterface
{
    /**
     * Collects unrecognized board names during normalization.
     * Callers can retrieve and report these via getUnknownBoards().
     * @var array<string, int> raw_value => occurrence count
     */
    private static array $unknownBoards = [];

    /** Board code mapping: Sphinx meal names → canonical codes.
     *  Order matters for partial matching — more specific entries must come first. */
    private const BOARD_MAP = [
        // All Inclusive variants (specific before generic for partial match)
        'ultra all inclusive' => 'UAI',
        'all inclusive light' => 'AIL',
        'all inclusive soft' => 'AIL',
        'all inclusive plus' => 'AI',
        'platinum all inclusive' => 'AI',
        'all inclusive' => 'AI',
        // Romanian
        'pensiune completa' => 'FB',
        'pensiune completă' => 'FB',
        'demipensiune' => 'HB',
        'mic dejun' => 'BB',
        'fara masa' => 'RO',
        'fără masă' => 'RO',
        'self catering' => 'SC',
        // English
        'full board' => 'FB',
        'half board' => 'HB',
        'bed and breakfast' => 'BB',
        'bed & breakfast' => 'BB',
        'room only' => 'RO',
        'b&b' => 'BB',
        'buffet breakfast' => 'BB',
        'ro' => 'RO',
    ];

    /** Room type prefix mapping */
    private const ROOM_TYPE_PREFIXES = [
        'single' => 'SGL',
        'double' => 'DBL',
        'twin' => 'TWIN',
        'triple' => 'TRP',
        'quad' => 'QUAD',
        'suite' => 'SUITE',
        'apartment' => 'APT',
        'studio' => 'STUDIO',
        'family' => 'DBL',
    ];

    #[\Override]
    public function getProviderName(): string
    {
        return 'sphinx';
    }

    #[\Override]
    public function normalizeStarRating(mixed $rawValue): ?string
    {
        if ($rawValue === null || $rawValue === '') {
            return null;
        }

        $stars = (int) $rawValue;
        return ($stars >= 1 && $stars <= 5) ? (string) $stars : null;
    }

    #[\Override]
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

        // Track unrecognized board names for reporting
        if ($lower !== '') {
            self::$unknownBoards[$lower] = (self::$unknownBoards[$lower] ?? 0) + 1;
        }

        return null;
    }

    /**
     * Get unrecognized board names collected during normalization.
     * @return array<string, int> raw_value => occurrence count
     */
    public static function getUnknownBoards(): array
    {
        return self::$unknownBoards;
    }

    /**
     * Clear the unknown boards collector.
     */
    public static function clearUnknownBoards(): void
    {
        self::$unknownBoards = [];
    }

    #[\Override]
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

    #[\Override]
    public function normalizePropertyType(mixed $rawValue): ?string
    {
        if (empty($rawValue) || !is_string($rawValue)) {
            return null;
        }

        $lower = mb_strtolower(trim($rawValue));

        $typeMap = [
            'hotel' => 'hotel',
            'villa' => 'villa',
            'apartment' => 'apartment',
            'resort' => 'resort',
            'hostel' => 'hostel',
            'guest_house' => 'guest_house',
            'guesthouse' => 'guest_house',
            'pension' => 'guest_house',
            'pensiune' => 'guest_house',
            'chalet' => 'chalet',
            'cabana' => 'chalet',
            'motel' => 'motel',
        ];

        return $typeMap[$lower] ?? null;
    }

    #[\Override]
    public function normalizeFacilityCode(mixed $rawValue): ?string
    {
        // Sphinx facilities come with numeric IDs — pass through as-is
        if (is_int($rawValue) || is_numeric($rawValue)) {
            return (string) $rawValue;
        }

        return is_string($rawValue) ? $rawValue : null;
    }

    #[\Override]
    public function normalizeResort(mixed $rawValue): ?string
    {
        if (empty($rawValue) || !is_string($rawValue)) {
            return null;
        }

        $trimmed = trim($rawValue);
        return $trimmed !== '' ? mb_convert_case($trimmed, MB_CASE_TITLE, 'UTF-8') : null;
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
            'confirmed' => TravelConstants::STATUS_CONFIRMED,
            'pending', 'on_hold' => TravelConstants::STATUS_PENDING,
            'cancelled', 'canceled' => TravelConstants::STATUS_CANCELLED,
            'rejected', 'failed' => TravelConstants::STATUS_FAILED,
            default => TravelConstants::STATUS_PENDING,
        };
    }
}

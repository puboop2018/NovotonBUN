<?php
declare(strict_types=1);
/**
 * RoomType Value Object
 *
 * Centralizes all room-type mapping logic.
 * Single source of truth for converting API room codes to display names.
 *
 * @package TravelCore
 * @since   1.0.0
 */

namespace Tygh\Addons\TravelCore\ValueObjects;

final class RoomType
{
    public const SINGLE       = 'SGL';
    public const DOUBLE       = 'DBL';
    public const TWIN         = 'TWIN';
    public const TRIPLE       = 'TRP';
    public const QUAD         = 'QUA';
    public const FAMILY       = 'FAM';
    public const STUDIO       = 'STUDIO';
    public const APARTMENT    = 'APP';
    public const SUITE        = 'SUITE';
    public const JUNIOR_SUITE = 'JST';
    public const VILLA        = 'VILLA';
    public const BUNGALOW     = 'BUNGALOW';
    public const MAISONETTE   = 'MAISONETTE';
    public const PENTHOUSE    = 'PENTHOUSE';
    public const DELUXE       = 'DLX';
    public const SUPERIOR     = 'SUP';

    private const DISPLAY_NAMES = [
        self::SINGLE       => 'Camera Single',
        self::DOUBLE       => 'Camera Dubla',
        self::TWIN         => 'Camera Twin',
        self::TRIPLE       => 'Camera Tripla',
        self::QUAD         => 'Camera Cvadrupla',
        self::FAMILY       => 'Camera Familie',
        self::STUDIO       => 'Studio',
        self::APARTMENT    => 'Apartament',
        self::SUITE        => 'Suita',
        self::JUNIOR_SUITE => 'Junior Suita',
        self::VILLA        => 'Vila',
        self::BUNGALOW     => 'Bungalou',
        self::MAISONETTE   => 'Maisoneta',
        self::PENTHOUSE    => 'Penthouse',
        self::DELUXE       => 'Camera Deluxe',
        self::SUPERIOR     => 'Camera Superior',
    ];

    private const ALIASES = [
        'TWN'       => self::TWIN,
        'TRPL'      => self::TRIPLE,
        'TRIPLE'    => self::TRIPLE,
        'QUAD'      => self::QUAD,
        'FAMILY'    => self::FAMILY,
        'STD'       => self::STUDIO,
        'APT'       => self::APARTMENT,
        'APARTMENT' => self::APARTMENT,
        'STE'       => self::SUITE,
        'JRSUITE'   => self::JUNIOR_SUITE,
        'JUNIOR'    => self::JUNIOR_SUITE,
        'VLA'       => self::VILLA,
        'BNG'       => self::BUNGALOW,
        'MAI'       => self::MAISONETTE,
        'PH'        => self::PENTHOUSE,
        'DELUXE'    => self::DELUXE,
        'SUPERIOR'  => self::SUPERIOR,
    ];

    private const BEDROOM_PREFIX_TEMPLATE = 'Apartament %d Dormitoare';
    private const BEDROOM_PREFIX_SINGULAR = 'Apartament 1 Dormitor';

    private string $code;

    private function __construct(string $code)
    {
        $this->code = $code;
    }

    public static function fromApiCode(string $apiCode): ?self
    {
        $normalized = strtoupper(trim($apiCode));

        if (isset(self::DISPLAY_NAMES[$normalized])) {
            return new self($normalized);
        }

        if (isset(self::ALIASES[$normalized])) {
            return new self(self::ALIASES[$normalized]);
        }

        return null;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function displayName(): string
    {
        return self::DISPLAY_NAMES[$this->code] ?? $this->code;
    }

    public static function toDisplayName(string $roomTypeCode): string
    {
        $instance = self::fromApiCode($roomTypeCode);
        if ($instance !== null) {
            return $instance->displayName();
        }

        if (preg_match('/^(\d+)-BR$/i', strtoupper(trim($roomTypeCode)), $m)) {
            $bedrooms = (int)$m[1];
            return $bedrooms === 1
                ? self::BEDROOM_PREFIX_SINGULAR
                : sprintf(self::BEDROOM_PREFIX_TEMPLATE, $bedrooms);
        }

        return $roomTypeCode;
    }

    public static function normalizeRoomCode(string $roomCode): string
    {
        $roomCode = str_replace(['%2b', '%2B'], '+', $roomCode);
        $roomCode = rawurldecode($roomCode);
        $roomCode = trim($roomCode);
        $roomCode = preg_replace('/(\d)\s+(\d)/', '$1+$2', $roomCode);
        return $roomCode;
    }

    public static function formatRoomLabel(string $roomId, string $roomType = ''): string
    {
        $roomId = self::normalizeRoomCode($roomId);

        $formatted_pattern = '/^(Camera|Apartament|Studio|Suita|Vila|Bungalou|Maisoneta|Penthouse|Junior Suita)\s.*\(.+\)$/i';
        if (!empty($roomType) && preg_match($formatted_pattern, $roomType)) {
            return $roomType;
        }
        if (preg_match($formatted_pattern, $roomId)) {
            return $roomId;
        }

        if (!empty($roomType)) {
            $typeName = self::toDisplayName($roomType);
            return $typeName . ' (' . $roomId . ')';
        }

        $parts = preg_split('/[\s]+/', $roomId, 2);
        $baseCode = strtoupper($parts[0] ?? '');
        $displayName = self::toDisplayName($baseCode);

        if ($displayName === $baseCode) {
            return $roomId;
        }

        return $displayName . ' (' . $roomId . ')';
    }

    public static function isValid(string $roomTypeCode): bool
    {
        if (self::fromApiCode($roomTypeCode) !== null) {
            return true;
        }
        return (bool)preg_match('/^\d+-BR$/i', strtoupper(trim($roomTypeCode)));
    }

    /** @return array<int, string> */
    public static function allCodes(): array
    {
        return array_keys(self::DISPLAY_NAMES);
    }

    /** @return array<string, string> */
    public static function allDisplayNames(): array
    {
        return self::DISPLAY_NAMES;
    }

    /** @return array<string, string> */
    public static function allWithAliases(): array
    {
        $map = self::DISPLAY_NAMES;
        foreach (self::ALIASES as $alias => $canonical) {
            $map[$alias] = self::DISPLAY_NAMES[$canonical];
        }
        return $map;
    }

    private function __clone() {}
}

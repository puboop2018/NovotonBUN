<?php
declare(strict_types=1);
/**
 * RoomType Value Object
 *
 * Centralizes all room-type mapping logic.
 * Single source of truth for converting API room codes to display names.
 *
 * Aligned with Novoton API: hotelinfo returns <Type> per room,
 * room_price returns <IdRoom> codes like "DBL 2+1", "1-BR APP 2+2".
 *
 * Usage:
 *   $name  = RoomType::toDisplayName('DBL');                    // "Camera Dubla"
 *   $label = RoomType::formatRoomLabel('DBL 2+1');              // "Camera Dubla (DBL 2+1)"
 *   $label = RoomType::formatRoomLabel('DBL 2+1', 'DBL');       // "Camera Dubla (DBL 2+1)"
 *   $norm  = RoomType::normalizeRoomCode('DBL 2 1');            // "DBL 2+1"
 *   $valid = RoomType::isValid('DBL');                          // true
 *
 * @package NovotonHolidays
 * @since 3.0.0
 */

namespace Tygh\Addons\NovotonHolidays\ValueObjects;

final class RoomType
{
    // ========== Canonical Room Type Codes ==========
    // These match the <Type> values from the Novoton hotelinfo API

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

    /**
     * Canonical code => Romanian display name.
     * Room names are in Romanian because the primary market is Romania.
     */
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

    /**
     * Alias => canonical code.
     * The Novoton API uses several alternative codes for the same room type.
     */
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

    /**
     * Bedroom-count prefix => Romanian display template.
     * Handles "1-BR", "2-BR", "3-BR" etc. from the API.
     */
    private const BEDROOM_PREFIX_TEMPLATE = 'Apartament %d Dormitoare';
    private const BEDROOM_PREFIX_SINGULAR = 'Apartament 1 Dormitor';

    /** @var string The canonical room code */
    private $code;

    private function __construct(string $code)
    {
        $this->code = $code;
    }

    /**
     * Create a RoomType from an API room type code.
     *
     * @param string $apiCode Room type code (e.g. "DBL", "APP", "TWN")
     * @return self|null Null if unrecognized
     */
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

    /**
     * Get the canonical room code.
     */
    public function code(): string
    {
        return $this->code;
    }

    /**
     * Get the Romanian display name.
     */
    public function displayName(): string
    {
        return self::DISPLAY_NAMES[$this->code] ?? $this->code;
    }

    // ========== Static Helpers ==========

    /**
     * Convert a room type code to its display name.
     * Returns the original code if not recognized.
     *
     * @param string $roomTypeCode Room type code from API
     * @return string Display name
     */
    public static function toDisplayName(string $roomTypeCode): string
    {
        $instance = self::fromApiCode($roomTypeCode);
        if ($instance !== null) {
            return $instance->displayName();
        }

        // Handle N-BR pattern dynamically (e.g. "1-BR", "2-BR", "4-BR")
        if (preg_match('/^(\d+)-BR$/i', strtoupper(trim($roomTypeCode)), $m)) {
            $bedrooms = (int)$m[1];
            return $bedrooms === 1
                ? self::BEDROOM_PREFIX_SINGULAR
                : sprintf(self::BEDROOM_PREFIX_TEMPLATE, $bedrooms);
        }

        return $roomTypeCode;
    }

    /**
     * Normalize a room code: fix URL-encoded plus signs, ensure "+" between digits.
     * E.g. "DBL 2 1" -> "DBL 2+1", "DBL%2B2+1" -> "DBL 2+1"
     *
     * @param string $roomCode Raw room code from API
     * @return string Normalized room code
     */
    public static function normalizeRoomCode(string $roomCode): string
    {
        $roomCode = str_replace(['%2b', '%2B'], '+', $roomCode);
        $roomCode = rawurldecode($roomCode);
        $roomCode = trim($roomCode);
        $roomCode = preg_replace('/(\d)\s+(\d)/', '$1+$2', $roomCode) ?? $roomCode;
        return $roomCode;
    }

    /**
     * Format a full room label for display.
     *
     * When $roomType (from hotelinfo <Type>) is provided:
     *   "{Type display name} ({IdRoom})" e.g. "Camera Dubla (DBL 2+1)"
     *
     * When $roomType is empty, parses the $roomId code:
     *   "Camera Dubla (DBL 2+1)"
     *
     * Prevents double-formatting if the input is already formatted.
     *
     * @param string $roomId Room code from room_price API (e.g. "DBL 2+1")
     * @param string $roomType Room type from hotelinfo API (e.g. "DBL")
     * @return string Formatted room label
     */
    public static function formatRoomLabel(string $roomId, string $roomType = ''): string
    {
        // Normalize the room code
        $roomId = self::normalizeRoomCode($roomId);

        // Detect already-formatted strings (prevent double formatting)
        $formatted_pattern = '/^(Camera|Apartament|Studio|Suita|Vila|Bungalou|Maisoneta|Penthouse|Junior Suita)\s.*\(.+\)$/i';
        if (!empty($roomType) && preg_match($formatted_pattern, $roomType)) {
            return $roomType;
        }
        if (preg_match($formatted_pattern, $roomId)) {
            return $roomId;
        }

        // If hotelinfo Type is provided, use it: "{Type display name} ({IdRoom})"
        if (!empty($roomType)) {
            $typeName = self::toDisplayName($roomType);
            return $typeName . ' (' . $roomId . ')';
        }

        // Fallback: parse the IdRoom code
        $parts = preg_split('/[\s]+/', $roomId, 2);
        $baseCode = strtoupper($parts[0] ?? '');

        $displayName = self::toDisplayName($baseCode);

        // If toDisplayName returned the code itself (unrecognized), return raw
        if ($displayName === $baseCode) {
            return $roomId;
        }

        return $displayName . ' (' . $roomId . ')';
    }

    /**
     * Check if a room type code is recognized.
     *
     * @param string $roomTypeCode Room type code
     * @return bool
     */
    public static function isValid(string $roomTypeCode): bool
    {
        if (self::fromApiCode($roomTypeCode) !== null) {
            return true;
        }

        // Also accept N-BR patterns
        return (bool)preg_match('/^\d+-BR$/i', strtoupper(trim($roomTypeCode)));
    }

    /**
     * Get all canonical codes.
     *
     * @return string[]
     */
    public static function allCodes(): array
    {
        return array_keys(self::DISPLAY_NAMES);
    }

    /**
     * Get the full canonical-code => display-name map.
     *
     * @return array<string, string>
     */
    public static function allDisplayNames(): array
    {
        return self::DISPLAY_NAMES;
    }

    /**
     * Get the full lookup map including aliases.
     *
     * @return array<string, string> code/alias => display name
     */
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

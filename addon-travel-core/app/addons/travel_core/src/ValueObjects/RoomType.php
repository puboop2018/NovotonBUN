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
    public const string SINGLE = 'SGL';
    public const string DOUBLE = 'DBL';
    public const string TWIN = 'TWIN';
    public const string TRIPLE = 'TRP';
    public const string QUAD = 'QUA';
    public const string FAMILY = 'FAM';
    public const string STUDIO = 'STUDIO';
    public const string APARTMENT = 'APP';
    public const string SUITE = 'SUITE';
    public const string JUNIOR_SUITE = 'JST';
    public const string VILLA = 'VILLA';
    public const string BUNGALOW = 'BUNGALOW';
    public const string MAISONETTE = 'MAISONETTE';
    public const string PENTHOUSE = 'PENTHOUSE';
    public const string DELUXE = 'DLX';
    public const string SUPERIOR = 'SUP';

    // Romanian display names WITH proper diacritics ("Cameră Dublă", not
    // "Camera Dubla") — matches the booking form's .po labels. Keys are API
    // codes; matching everywhere is by code, so these are display-only.
    private const array DISPLAY_NAMES = [
        self::SINGLE => 'Cameră Single',
        self::DOUBLE => 'Cameră Dublă',
        self::TWIN => 'Cameră Twin',
        self::TRIPLE => 'Cameră Triplă',
        self::QUAD => 'Cameră Cvadruplă',
        self::FAMILY => 'Cameră Familială',
        self::STUDIO => 'Studio',
        self::APARTMENT => 'Apartament',
        self::SUITE => 'Suită',
        self::JUNIOR_SUITE => 'Suită Junior',
        self::VILLA => 'Vilă',
        self::BUNGALOW => 'Bungalou',
        // No Romanian form exists for maisonette — keep the international spelling.
        self::MAISONETTE => 'Maisonette',
        self::PENTHOUSE => 'Penthouse',
        self::DELUXE => 'Cameră Deluxe',
        self::SUPERIOR => 'Cameră Superioară',
    ];

    // English display names, used when the storefront language is English.
    private const array DISPLAY_NAMES_EN = [
        self::SINGLE => 'Single Room',
        self::DOUBLE => 'Double Room',
        self::TWIN => 'Twin Room',
        self::TRIPLE => 'Triple Room',
        self::QUAD => 'Quadruple Room',
        self::FAMILY => 'Family Room',
        self::STUDIO => 'Studio',
        self::APARTMENT => 'Apartment',
        self::SUITE => 'Suite',
        self::JUNIOR_SUITE => 'Junior Suite',
        self::VILLA => 'Villa',
        self::BUNGALOW => 'Bungalow',
        self::MAISONETTE => 'Maisonette',
        self::PENTHOUSE => 'Penthouse',
        self::DELUXE => 'Deluxe Room',
        self::SUPERIOR => 'Superior Room',
    ];

    // Keys are normalized with mb_strtoupper + inner-whitespace collapse.
    // Besides short code variants, this maps PROVIDER-SUPPLIED type names —
    // the hotelinfo API sends free-text Type strings ("QUATRIPLE" is the
    // provider's own spelling, "Camera Dubla" arrives without diacritics) —
    // so they resolve to canonical codes instead of leaking verbatim into
    // the storefront.
    private const array ALIASES = [
        'TWN' => self::TWIN,
        'TRPL' => self::TRIPLE,
        'TRIPLE' => self::TRIPLE,
        'QUAD' => self::QUAD,
        'QUAT' => self::QUAD,
        'FAMILY' => self::FAMILY,
        'STD' => self::STUDIO,
        'APT' => self::APARTMENT,
        'APARTMENT' => self::APARTMENT,
        'APARTAMENT' => self::APARTMENT,
        'STE' => self::SUITE,
        'JRSUITE' => self::JUNIOR_SUITE,
        'JUNIOR' => self::JUNIOR_SUITE,
        'VLA' => self::VILLA,
        'BNG' => self::BUNGALOW,
        'MAI' => self::MAISONETTE,
        'PH' => self::PENTHOUSE,
        'DELUXE' => self::DELUXE,
        'SUPERIOR' => self::SUPERIOR,
        // Provider type names (RO, no diacritics — as the API sends them)
        'CAMERA SINGLE' => self::SINGLE,
        'CAMERA DUBLA' => self::DOUBLE,
        'CAMERA TWIN' => self::TWIN,
        'CAMERA TRIPLA' => self::TRIPLE,
        'CAMERA CVADRUPLA' => self::QUAD,
        'CAMERA QUADRUPLA' => self::QUAD,
        'CAMERA FAMILIE' => self::FAMILY,
        'CAMERA FAMILIALA' => self::FAMILY,
        'CAMERA DELUXE' => self::DELUXE,
        'CAMERA SUPERIOR' => self::SUPERIOR,
        'CAMERA SUPERIOARA' => self::SUPERIOR,
        'QUATRIPLE' => self::QUAD,
        'QUADRUPLE' => self::QUAD,
        'QUADRUPLA' => self::QUAD,
        'CVADRUPLA' => self::QUAD,
        'SUITA' => self::SUITE,
        'JUNIOR SUITA' => self::JUNIOR_SUITE,
        'JUNIOR SUITE' => self::JUNIOR_SUITE,
        'SUITA JUNIOR' => self::JUNIOR_SUITE,
        'VILA' => self::VILLA,
        'BUNGALOU' => self::BUNGALOW,
        'MAISONETA' => self::MAISONETTE,
        // Diacritic forms (stored labels / RO input; mb_strtoupper-normalized)
        'CAMERĂ DUBLĂ' => self::DOUBLE,
        'CAMERĂ TRIPLĂ' => self::TRIPLE,
        'CAMERĂ CVADRUPLĂ' => self::QUAD,
        'SUITĂ' => self::SUITE,
        'SUITĂ JUNIOR' => self::JUNIOR_SUITE,
        'VILĂ' => self::VILLA,
        // English long forms
        'SINGLE' => self::SINGLE,
        'SINGLE ROOM' => self::SINGLE,
        'DOUBLE' => self::DOUBLE,
        'DOUBLE ROOM' => self::DOUBLE,
        'TWIN ROOM' => self::TWIN,
        'TRIPLE ROOM' => self::TRIPLE,
        'QUADRUPLE ROOM' => self::QUAD,
        'FAMILY ROOM' => self::FAMILY,
        'DELUXE ROOM' => self::DELUXE,
        'SUPERIOR ROOM' => self::SUPERIOR,
    ];

    private const string BEDROOM_PREFIX_TEMPLATE = 'Apartament %d Dormitoare';
    private const string BEDROOM_PREFIX_SINGULAR = 'Apartament 1 Dormitor';
    private const string BEDROOM_PREFIX_TEMPLATE_EN = 'Apartment %d Bedrooms';
    private const string BEDROOM_PREFIX_SINGULAR_EN = 'Apartment 1 Bedroom';

    private string $code;

    private function __construct(string $code)
    {
        $this->code = $code;
    }

    public static function fromApiCode(string $apiCode): ?self
    {
        // mb_strtoupper so diacritics fold too (ă→Ă); collapse inner whitespace
        // so multi-word provider names ("Camera  Dubla") match their alias.
        $normalized = mb_strtoupper(trim($apiCode), 'UTF-8');
        $normalized = (string) preg_replace('/\s+/', ' ', $normalized);

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

    public function displayName(?string $lang = null): string
    {
        if (self::resolveLang($lang) === 'en') {
            return self::DISPLAY_NAMES_EN[$this->code] ?? $this->code;
        }

        return self::DISPLAY_NAMES[$this->code] ?? $this->code;
    }

    public static function toDisplayName(string $roomTypeCode, ?string $lang = null): string
    {
        $instance = self::fromApiCode($roomTypeCode);
        if ($instance !== null) {
            return $instance->displayName($lang);
        }

        if (preg_match('/^(\d+)-BR$/i', strtoupper(trim($roomTypeCode)), $m) === 1) {
            $bedrooms = (int)$m[1];
            if (self::resolveLang($lang) === 'en') {
                return $bedrooms === 1
                    ? self::BEDROOM_PREFIX_SINGULAR_EN
                    : sprintf(self::BEDROOM_PREFIX_TEMPLATE_EN, $bedrooms);
            }
            return $bedrooms === 1
                ? self::BEDROOM_PREFIX_SINGULAR
                : sprintf(self::BEDROOM_PREFIX_TEMPLATE, $bedrooms);
        }

        return $roomTypeCode;
    }

    /**
     * Display language: explicit arg wins, else the storefront language
     * (CART_LANGUAGE), else Romanian — the store's primary language.
     */
    private static function resolveLang(?string $lang): string
    {
        if ($lang !== null && $lang !== '') {
            return strtolower($lang);
        }
        if (defined('CART_LANGUAGE')) {
            $cartLang = \Tygh\Addons\TravelCore\Helpers\TypeCoerce::toString(CART_LANGUAGE);
            if ($cartLang !== '') {
                return strtolower($cartLang);
            }
        }

        return 'ro';
    }

    public static function normalizeRoomCode(string $roomCode): string
    {
        $roomCode = str_replace(['%2b', '%2B'], '+', $roomCode);
        $roomCode = rawurldecode($roomCode);
        $roomCode = trim($roomCode);
        $roomCode = (string) preg_replace('/(\d)\s+(\d)/', '$1+$2', $roomCode);
        return $roomCode;
    }

    public static function formatRoomLabel(string $roomId, string $roomType = '', ?string $lang = null): string
    {
        $roomId = self::normalizeRoomCode($roomId);

        // Idempotency guard: an already-formatted label passes through untouched.
        // Both spellings must match — orders/bookings created BEFORE the
        // diacritics change persist labels like "Camera Dubla (DBL 2+1)" and
        // re-enter this function; dropping the legacy prefixes would double-wrap
        // them ("... (DBL 2+1) (DBL 2+1)"). /u so /i also case-folds ă/Ă.
        $formatted_pattern = '/^(Camera|Cameră|Apartament|Apartment|Studio|Suita|Suită|Suite|Single|Double|Twin|Triple|Quadruple|Family|Deluxe|Superior|Vila|Vilă|Villa|Bungalou|Bungalow|Maisoneta|Maisonetă|Maisonette|Penthouse|Junior Suita|Junior Suită|Junior Suite)\s.*\(.+\)$/iu';
        if (!empty($roomType) && preg_match($formatted_pattern, $roomType) === 1) {
            return $roomType;
        }
        if (preg_match($formatted_pattern, $roomId) === 1) {
            return $roomId;
        }

        if (!empty($roomType)) {
            $typeName = self::toDisplayName($roomType, $lang);
            return $typeName . ' (' . $roomId . ')';
        }

        $parts = preg_split('/\s+/', $roomId, 2);
        $baseCode = strtoupper($parts[0] ?? '');
        $displayName = self::toDisplayName($baseCode, $lang);

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

    private function __clone()
    {
    }
}

<?php
declare(strict_types=1);
/**
 * BoardType Value Object
 *
 * Centralizes all board-type (meal plan) mapping logic.
 * Single source of truth for converting API board codes to display names.
 *
 * @package TravelCore
 * @since   1.0.0
 */

namespace Tygh\Addons\TravelCore\ValueObjects;

final class BoardType
{
    public const ALL_INCLUSIVE       = 'AI';
    public const ULTRA_ALL_INCLUSIVE = 'UAI';
    public const FULL_BOARD          = 'FB';
    public const FULL_BOARD_PLUS     = 'FB+';
    public const HALF_BOARD          = 'HB';
    public const HALF_BOARD_PLUS     = 'HB+';
    public const BED_AND_BREAKFAST   = 'BB';
    public const ROOM_ONLY           = 'RO';
    public const SELF_CATERING       = 'SC';

    private const DISPLAY_NAMES = [
        self::ALL_INCLUSIVE       => 'All Inclusive',
        self::ULTRA_ALL_INCLUSIVE => 'Ultra All Inclusive',
        self::FULL_BOARD          => 'Full Board',
        self::FULL_BOARD_PLUS     => 'Full Board Plus',
        self::HALF_BOARD          => 'Half Board',
        self::HALF_BOARD_PLUS     => 'Half Board Plus',
        self::BED_AND_BREAKFAST   => 'Bed & Breakfast',
        self::ROOM_ONLY           => 'Room Only',
        self::SELF_CATERING       => 'Self Catering',
    ];

    private const ALIASES = [
        'ALL INCL'              => self::ALL_INCLUSIVE,
        'ALL INCLUSIVE'         => self::ALL_INCLUSIVE,
        'ALLINC'               => self::ALL_INCLUSIVE,
        'ULTRA ALL INCL'       => self::ULTRA_ALL_INCLUSIVE,
        'ULTRA ALL INCLUSIVE'   => self::ULTRA_ALL_INCLUSIVE,
        'FULL BOARD'           => self::FULL_BOARD,
        'HALF BOARD'           => self::HALF_BOARD,
        'BED AND BREAKFAST'    => self::BED_AND_BREAKFAST,
        'B&B'                  => self::BED_AND_BREAKFAST,
        'ROOM ONLY'            => self::ROOM_ONLY,
        'SELF CATERING'        => self::SELF_CATERING,
    ];

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

    public static function toDisplayName(string $boardCode): string
    {
        $instance = self::fromApiCode($boardCode);
        return $instance !== null ? $instance->displayName() : $boardCode;
    }

    public static function toCanonicalCode(string $boardCode): string
    {
        $instance = self::fromApiCode($boardCode);
        return $instance !== null ? $instance->code() : strtoupper(trim($boardCode));
    }

    public static function isValid(string $boardCode): bool
    {
        return self::fromApiCode($boardCode) !== null;
    }

    public static function allCodes(): array
    {
        return array_keys(self::DISPLAY_NAMES);
    }

    public static function allDisplayNames(): array
    {
        return self::DISPLAY_NAMES;
    }

    public static function allWithAliases(): array
    {
        $map = self::DISPLAY_NAMES;
        foreach (self::ALIASES as $alias => $canonical) {
            $map[$alias] = self::DISPLAY_NAMES[$canonical];
        }
        return $map;
    }

    public static function matchesMealPlan(string $boardId, string $mealPlan): bool
    {
        $canonical = self::toCanonicalCode($boardId);
        $mealPlan = strtoupper(trim($mealPlan));

        if ($canonical === $mealPlan) {
            return true;
        }

        if ($canonical === $mealPlan . '+') {
            return true;
        }

        return false;
    }

    private function __clone() {}
}

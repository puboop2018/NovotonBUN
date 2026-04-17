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
    public const string ALL_INCLUSIVE = 'AI';
    public const string ALL_INCLUSIVE_LIGHT = 'AIL';
    public const string ULTRA_ALL_INCLUSIVE = 'UAI';
    public const string FULL_BOARD = 'FB';
    public const string FULL_BOARD_PLUS = 'FB+';
    public const string HALF_BOARD = 'HB';
    public const string HALF_BOARD_PLUS = 'HB+';
    public const string BED_AND_BREAKFAST = 'BB';
    public const string ROOM_ONLY = 'RO';
    public const string SELF_CATERING = 'SC';

    private const array DISPLAY_NAMES = [
        self::ALL_INCLUSIVE => 'All Inclusive',
        self::ALL_INCLUSIVE_LIGHT => 'All Inclusive Light',
        self::ULTRA_ALL_INCLUSIVE => 'Ultra All Inclusive',
        self::FULL_BOARD => 'Full Board',
        self::FULL_BOARD_PLUS => 'Full Board Plus',
        self::HALF_BOARD => 'Half Board',
        self::HALF_BOARD_PLUS => 'Half Board Plus',
        self::BED_AND_BREAKFAST => 'Bed & Breakfast',
        self::ROOM_ONLY => 'Room Only',
        self::SELF_CATERING => 'Self Catering',
    ];

    private const array ALIASES = [
        'ALL INCL' => self::ALL_INCLUSIVE,
        'ALL INCLUSIVE' => self::ALL_INCLUSIVE,
        'ALL INCLUSIVE LIGHT' => self::ALL_INCLUSIVE_LIGHT,
        'ALL INCLUSIVE SOFT' => self::ALL_INCLUSIVE_LIGHT,
        'ALLINC' => self::ALL_INCLUSIVE,
        'ULTRA ALL INCL' => self::ULTRA_ALL_INCLUSIVE,
        'ULTRA ALL INCLUSIVE' => self::ULTRA_ALL_INCLUSIVE,
        'FULL BOARD' => self::FULL_BOARD,
        'HALF BOARD' => self::HALF_BOARD,
        'BED AND BREAKFAST' => self::BED_AND_BREAKFAST,
        'B&B' => self::BED_AND_BREAKFAST,
        'ROOM ONLY' => self::ROOM_ONLY,
        'SELF CATERING' => self::SELF_CATERING,
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

    private function __clone()
    {
    }
}

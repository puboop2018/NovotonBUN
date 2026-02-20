<?php
/**
 * BoardType Value Object
 *
 * Centralizes all board-type (meal plan) mapping logic.
 * Single source of truth for converting API board codes to display names.
 *
 * Aligned with Novoton API function naming: room_price returns <IdBoard>,
 * hotelinfo returns board codes, priceinfo references board types.
 *
 * Usage:
 *   $name = BoardType::toDisplayName('AI');        // "All Inclusive"
 *   $name = BoardType::toDisplayName('FB+');       // "Full Board Plus"
 *   $valid = BoardType::isValid('AI');             // true
 *   $all = BoardType::allDisplayNames();           // ['AI' => 'All Inclusive', ...]
 *   $codes = BoardType::allCodes();                // ['AI', 'UAI', 'FB', ...]
 *   $canonical = BoardType::toCanonicalCode('ALL INCL'); // 'AI'
 *
 * @package NovotonHolidays
 * @since 3.0.0
 */

namespace Tygh\Addons\NovotonHolidays\ValueObjects;

final class BoardType
{
    // ========== Canonical Board Codes ==========
    // These match the <IdBoard> values returned by the Novoton room_price API

    public const ALL_INCLUSIVE       = 'AI';
    public const ULTRA_ALL_INCLUSIVE = 'UAI';
    public const FULL_BOARD          = 'FB';
    public const FULL_BOARD_PLUS     = 'FB+';
    public const HALF_BOARD          = 'HB';
    public const HALF_BOARD_PLUS     = 'HB+';
    public const BED_AND_BREAKFAST   = 'BB';
    public const ROOM_ONLY           = 'RO';
    public const SELF_CATERING       = 'SC';

    /**
     * Canonical code => human-readable display name.
     * These are the primary board types returned by the Novoton API.
     */
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

    /**
     * Alias => canonical code.
     * The Novoton API occasionally returns alternative spellings for board types.
     * This map normalizes them to canonical codes for consistent lookups.
     */
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

    /** @var string The canonical board code */
    private $code;

    /**
     * @param string $code Canonical board code (AI, FB, HB, etc.)
     */
    private function __construct(string $code)
    {
        $this->code = $code;
    }

    /**
     * Create a BoardType from any API board code or alias.
     *
     * @param string $apiCode Board code from API (e.g. "AI", "ALL INCL", "FB+")
     * @return self|null Null if unrecognized code
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
     * Get the canonical board code.
     *
     * @return string e.g. "AI", "FB+", "HB"
     */
    public function code(): string
    {
        return $this->code;
    }

    /**
     * Get the human-readable display name.
     *
     * @return string e.g. "All Inclusive", "Full Board Plus"
     */
    public function displayName(): string
    {
        return self::DISPLAY_NAMES[$this->code] ?? $this->code;
    }

    // ========== Static Helpers ==========

    /**
     * Convert a board code directly to a display name.
     * Returns the original code if not recognized.
     *
     * @param string $boardCode Board code from API
     * @return string Display name
     */
    public static function toDisplayName(string $boardCode): string
    {
        $instance = self::fromApiCode($boardCode);
        return $instance !== null ? $instance->displayName() : $boardCode;
    }

    /**
     * Resolve any alias to its canonical board code.
     * Returns the original code if not recognized.
     *
     * @param string $boardCode Board code or alias
     * @return string Canonical code (e.g. "ALL INCL" -> "AI")
     */
    public static function toCanonicalCode(string $boardCode): string
    {
        $instance = self::fromApiCode($boardCode);
        return $instance !== null ? $instance->code() : strtoupper(trim($boardCode));
    }

    /**
     * Check if a board code (or alias) is recognized.
     *
     * @param string $boardCode Board code to validate
     * @return bool
     */
    public static function isValid(string $boardCode): bool
    {
        return self::fromApiCode($boardCode) !== null;
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
     * Suitable for dropdowns, export headers, etc.
     *
     * @return array<string, string>
     */
    public static function allDisplayNames(): array
    {
        return self::DISPLAY_NAMES;
    }

    /**
     * Get the full lookup map including aliases.
     * Useful for template comparisons and CSS class assignment.
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

    /**
     * Check if an API board code matches a user-selected meal plan.
     *
     * Normalizes both codes to canonical form, then matches exactly
     * or as a "plus" variant (e.g. mealPlan "FB" matches board "FB+").
     *
     * @param string $boardId  Board code from API (e.g. "ALL INCL", "FB+", "HB")
     * @param string $mealPlan Canonical meal plan code the user selected (e.g. "AI", "FB")
     * @return bool
     */
    public static function matchesMealPlan(string $boardId, string $mealPlan): bool
    {
        $canonical = self::toCanonicalCode($boardId);
        $mealPlan = strtoupper(trim($mealPlan));

        // Exact match (e.g. "AI" == "AI", "FB" == "FB")
        if ($canonical === $mealPlan) {
            return true;
        }

        // Plus-variant match (e.g. mealPlan "FB" matches canonical "FB+")
        if ($canonical === $mealPlan . '+') {
            return true;
        }

        return false;
    }

    /**
     * Prevent cloning.
     */
    private function __clone() {}
}

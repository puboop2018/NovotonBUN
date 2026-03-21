<?php
declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Helpers;

/**
 * Shared validation and sanitization helpers.
 *
 * Centralizes common validation logic used by multiple provider addons
 * (Sphinx SecurityService, Novoton SecurityService) to eliminate duplication.
 */
class ValidationHelpers
{
    /**
     * Validate a date string in YYYY-MM-DD format.
     *
     * Checks both the format (regex) and the calendar validity (checkdate).
     */
    public static function isValidDate(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }

        [$year, $month, $day] = explode('-', $date);
        return checkdate((int) $month, (int) $day, (int) $year);
    }

    /**
     * Sanitize a person name.
     *
     * Removes non-letter/space/apostrophe/hyphen characters and trims to max length.
     * Supports Unicode (accented characters, Cyrillic, etc.).
     */
    public static function sanitizeName(string $name, int $maxLength = 100): string
    {
        $name = preg_replace('/[^\p{L}\s\'-]/u', '', $name);
        return mb_substr(trim($name), 0, $maxLength);
    }

    /**
     * Validate a hotel/entity ID format.
     *
     * Allows alphanumeric characters, hyphens, and underscores.
     */
    public static function isValidEntityId(string $id, int $maxLength = 50): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9_-]{1,' . $maxLength . '}$/', $id);
    }

    /**
     * Validate a person name contains only valid characters.
     *
     * Allows letters (including accented), spaces, hyphens, and apostrophes.
     */
    public static function isValidName(string $name, int $maxLength = 100): bool
    {
        return (bool) preg_match('/^[\p{L}\s\'-]{1,' . $maxLength . '}$/u', $name);
    }
}

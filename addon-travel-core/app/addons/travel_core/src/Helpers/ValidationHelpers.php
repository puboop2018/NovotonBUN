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
        $name = (string) preg_replace('/[^\p{L}\s\'-]/u', '', $name);
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
     * Safely extract a scalar string from a mixed value.
     *
     * Arrays and objects become ''; scalars are cast to string and trimmed.
     */
    public static function toString(mixed $value): string
    {
        if (is_array($value) || is_object($value)) {
            return '';
        }
        return trim((string) $value);
    }

    /**
     * Safely extract a float from a mixed value.
     */
    public static function toFloat(mixed $value): float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }
        return 0.0;
    }

    /**
     * Safely extract an int from a mixed value.
     */
    public static function toInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }
        return 0;
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

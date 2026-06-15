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
     * Keeps letters, digits, spaces, apostrophes, hyphens, and dots.
     * Strips any other character (control chars, angle brackets,
     * SQL-dangerous symbols, etc.).
     *
     * Digits are allowed to support Roman-numeral suffixes (Louis XIV,
     * Queen Elizabeth II) and to prevent silent data loss on inputs
     * with numeric content. Dots are allowed for suffixes like "Jr.", "Sr."
     * Supports Unicode (accented characters, Cyrillic, etc.).
     */
    public static function sanitizeName(string $name, int $maxLength = 100): string
    {
        $name = (string) preg_replace('/[^\p{L}\p{N}\s\'\-.]/u', '', $name);
        return mb_substr(trim($name), 0, $maxLength);
    }

    /**
     * Strip HTML tags from a free-text string and truncate it.
     *
     * Input-time sanitisation only — HTML-encoding for output is the caller's
     * responsibility (escapeHtml() / the template engine). Use sanitizeName()
     * for person names, which preserves Unicode letters and name punctuation.
     */
    public static function sanitizeString(string $string, int $maxLength = 255): string
    {
        return mb_substr(strip_tags($string), 0, $maxLength);
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
     *
     * @deprecated 3.3.0 Use {@see TypeCoerce::toString()} directly.
     */
    public static function toString(mixed $value): string
    {
        return TypeCoerce::toString($value);
    }

    /**
     * Safely extract a float from a mixed value.
     *
     * @deprecated 3.3.0 Use {@see TypeCoerce::toFloat()} directly.
     */
    public static function toFloat(mixed $value): float
    {
        return TypeCoerce::toFloat($value);
    }

    /**
     * Safely extract an int from a mixed value.
     *
     * @deprecated 3.3.0 Use {@see TypeCoerce::toInt()} directly.
     */
    public static function toInt(mixed $value): int
    {
        return TypeCoerce::toInt($value);
    }

    /**
     * Validate a person name contains only valid characters.
     *
     * Allows letters (including accented), digits, spaces, hyphens,
     * apostrophes, and dots.
     */
    public static function isValidName(string $name, int $maxLength = 100): bool
    {
        return (bool) preg_match('/^[\p{L}\p{N}\s\'\-.]{1,' . $maxLength . '}$/u', $name);
    }
}

<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Helpers;

/**
 * Canonical scalar/array coercion helpers.
 *
 * Boundary helpers for narrowing `mixed` inputs (db_* results, json_decode,
 * $_REQUEST, Registry::get, SimpleXMLElement casts) into concrete types at
 * the edge of the system so the rest of the code can operate on typed
 * values.
 *
 * This is the single source of truth. The addon-local wrappers
 * (`PriceInfoFormatter::toScalar/toFloat/toInt`,
 *  `ValidationHelpers::toString/toFloat/toInt`) delegate here so both
 * addons share identical semantics.
 */
final class TypeCoerce
{
    /**
     * Safely extract a trimmed scalar string from a mixed value.
     *
     * - Arrays and objects → `''` (SimpleXMLElement empty-tag quirks)
     * - null → `''`
     * - true → `'1'`, false → `''` (matches PHP (string) cast)
     * - int/float/string → trim((string) $value)
     */
    public static function toString(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? '1' : '';
        }
        // null, array, object, resource → empty string
        return '';
    }

    /**
     * Safely extract a float from a mixed value.
     *
     * int/float stay as-is (as float); numeric strings are parsed;
     * everything else (including empty string, non-numeric string,
     * bool, null, array, object) returns 0.0.
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
     *
     * int stays as-is; float is truncated; numeric strings are parsed;
     * everything else returns 0.
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
     * Safely extract a bool from a mixed value.
     *
     * CS-Cart represents booleans as 'Y'/'N' strings in many places.
     * This helper normalises:
     * - true/'Y'/'y'/'1'/1/1.0 → true
     * - false/'N'/'n'/'0'/0/0.0/''/null/[] → false
     * - any other array/object → false
     * - any other string → false (strict: we don't treat 'true'/'yes' as true)
     */
    public static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return $value !== 0 && $value !== 0.0;
        }
        if (is_string($value)) {
            return $value === 'Y' || $value === 'y' || $value === '1';
        }
        return false;
    }

    /**
     * Coerce a mixed value into a list of mixed items.
     *
     * Non-arrays return `[]`. Associative arrays are reindexed via
     * array_values so the result is a true `list<mixed>` (PHPStan shape).
     *
     * @return list<mixed>
     */
    public static function toList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        return array_values($value);
    }

    /**
     * Coerce a mixed value into an `array<string, mixed>`.
     *
     * Non-arrays return `[]`. Keys that aren't strings are dropped
     * (so callers can rely on `is_string($key)` downstream).
     *
     * @return array<string, mixed>
     */
    public static function toStringMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $k => $v) {
            if (is_string($k)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /**
     * Coerce a mixed value into a list of strings (e.g. `db_get_fields()` output).
     *
     * Non-arrays return `[]`. Each entry is passed through {@see self::toString()}.
     *
     * @return list<string>
     */
    public static function toStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            $out[] = self::toString($item);
        }
        return $out;
    }

    /**
     * Coerce a mixed value into a list of ints (e.g. `db_get_fields()` on an id column).
     *
     * Non-arrays return `[]`. Each entry is passed through {@see self::toInt()}.
     *
     * @return list<int>
     */
    public static function toIntList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            $out[] = self::toInt($item);
        }
        return $out;
    }

    /**
     * Coerce a mixed value into an `array<int, array<string, mixed>>` —
     * the shape of `db_get_array()` with a row-per-entry.
     *
     * Non-arrays return `[]`. Entries that are not themselves arrays
     * are filtered out. String keys are preserved on the inner rows
     * but the outer array is reindexed.
     *
     * @return list<array<string, mixed>>
     */
    public static function toRowList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $rows = [];
        foreach ($value as $row) {
            if (is_array($row)) {
                $rows[] = self::toStringMap($row);
            }
        }
        return $rows;
    }
}

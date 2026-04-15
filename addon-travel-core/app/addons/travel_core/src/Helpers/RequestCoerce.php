<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Helpers;

/**
 * Typed accessors for HTTP request arrays (`$_GET`, `$_POST`, `$_REQUEST`,
 * and CS-Cart's legacy `$_REQUEST` equivalents).
 *
 * PHPStan sees `$_REQUEST` as `array<mixed, mixed>`; every `$_REQUEST['foo']`
 * access therefore emits `mixed` downstream. Controllers that use these
 * accessors get clean narrowing in one call:
 *
 * ```php
 * $hotelId = RequestCoerce::string($_REQUEST, 'hotel_id');
 * $adults  = RequestCoerce::int($_REQUEST, 'adults', 2);
 * $guests  = RequestCoerce::stringMap($_REQUEST, 'guests');
 * $roomIds = RequestCoerce::list($_REQUEST, 'room_ids');
 * ```
 *
 * Missing keys return the default; present-but-wrong-type values are
 * coerced via {@see TypeCoerce} rules.
 */
final class RequestCoerce
{
    /**
     * @param array<mixed, mixed> $source
     */
    public static function string(array $source, string $key, string $default = ''): string
    {
        if (!array_key_exists($key, $source)) {
            return $default;
        }
        $coerced = TypeCoerce::toString($source[$key]);
        // Distinguish "present but empty/invalid" from "missing": the caller
        // asked for a string — if the key was there at all, return the coerced
        // result. If they want "missing OR invalid → default", they should
        // check themselves before calling.
        return $coerced;
    }

    /**
     * @param array<mixed, mixed> $source
     */
    public static function int(array $source, string $key, int $default = 0): int
    {
        if (!array_key_exists($key, $source)) {
            return $default;
        }
        // If the raw value can't be coerced to a number, TypeCoerce::toInt
        // returns 0. That's ambiguous with "key present but value was '0'",
        // which is fine for most controller use cases.
        return TypeCoerce::toInt($source[$key]);
    }

    /**
     * @param array<mixed, mixed> $source
     */
    public static function float(array $source, string $key, float $default = 0.0): float
    {
        if (!array_key_exists($key, $source)) {
            return $default;
        }
        return TypeCoerce::toFloat($source[$key]);
    }

    /**
     * @param array<mixed, mixed> $source
     */
    public static function bool(array $source, string $key, bool $default = false): bool
    {
        if (!array_key_exists($key, $source)) {
            return $default;
        }
        return TypeCoerce::toBool($source[$key]);
    }

    /**
     * Extract a string→mixed associative array (e.g. a nested form field).
     *
     * Missing / non-array values return `[]`. String keys are preserved,
     * non-string keys are dropped.
     *
     * @param array<mixed, mixed> $source
     * @return array<string, mixed>
     */
    public static function stringMap(array $source, string $key): array
    {
        if (!array_key_exists($key, $source)) {
            return [];
        }
        return TypeCoerce::toStringMap($source[$key]);
    }

    /**
     * Extract a list of items (e.g. `room_ids[]=1&room_ids[]=2`).
     *
     * Missing / non-array values return `[]`. Associative arrays are
     * reindexed via array_values.
     *
     * @param array<mixed, mixed> $source
     * @return list<mixed>
     */
    public static function list(array $source, string $key): array
    {
        if (!array_key_exists($key, $source)) {
            return [];
        }
        return TypeCoerce::toList($source[$key]);
    }

    /**
     * Extract a list of strings (e.g. multi-select form field).
     *
     * Non-string entries are coerced via {@see TypeCoerce::toString()}.
     * Missing → `[]`.
     *
     * @param array<mixed, mixed> $source
     * @return list<string>
     */
    public static function stringList(array $source, string $key): array
    {
        $raw = self::list($source, $key);
        $out = [];
        foreach ($raw as $item) {
            $out[] = TypeCoerce::toString($item);
        }
        return $out;
    }

    /**
     * Extract a list of ints (e.g. multi-select of IDs).
     *
     * Non-numeric entries become 0; callers should `array_filter` if they
     * want to drop zeros.
     *
     * @param array<mixed, mixed> $source
     * @return list<int>
     */
    public static function intList(array $source, string $key): array
    {
        $raw = self::list($source, $key);
        $out = [];
        foreach ($raw as $item) {
            $out[] = TypeCoerce::toInt($item);
        }
        return $out;
    }
}

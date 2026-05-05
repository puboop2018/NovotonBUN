<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Helpers;

/**
 * Typed coercion of `mixed` values, used at the boundary with CS-Cart's
 * loosely-typed structures (Registry, $_REQUEST, $order_info, db rows).
 *
 * Intentionally a copy-paste of the equivalent TravelCore helper rather
 * than a cross-addon dependency — the FGO addon is independent of
 * travel_core by design.
 */
final class TypeCoerce
{
    public static function toString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        return '';
    }

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
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        return 0;
    }

    public static function toFloat(mixed $value): float
    {
        if (is_float($value)) {
            return $value;
        }
        if (is_int($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }
        return 0.0;
    }

    public static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return $value === 'Y' || $value === '1' || strtolower($value) === 'true';
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public static function toAssocArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[(string) $k] = $v;
        }
        return $out;
    }
}

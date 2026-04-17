<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Helpers;

use Tygh\Registry;

/**
 * Typed accessors for `Tygh\Registry::get()`.
 *
 * CS-Cart's Registry returns `mixed` for every key — config values, cached
 * data, loaded schemas, etc. This wrapper narrows the return type at the
 * call site so downstream code is fully typed:
 *
 * ```php
 * $apiUrl     = RegistryCoerce::string('addons.novoton_holidays.api_url');
 * $batchSize  = RegistryCoerce::int('addons.novoton_holidays.batch_size', 100);
 * $currencies = RegistryCoerce::stringMap('currencies');
 * ```
 *
 * For addon settings, prefer the addon's typed {@see ConfigProvider} class
 * which already wraps these with domain-specific names. Use this helper
 * for ad-hoc Registry access in controllers / hooks / scripts.
 */
final class RegistryCoerce
{
    public static function string(string $key, string $default = ''): string
    {
        $raw = Registry::get($key);
        if ($raw === null) {
            return $default;
        }
        return TypeCoerce::toString($raw);
    }

    public static function int(string $key, int $default = 0): int
    {
        $raw = Registry::get($key);
        if ($raw === null) {
            return $default;
        }
        return TypeCoerce::toInt($raw);
    }

    public static function float(string $key, float $default = 0.0): float
    {
        $raw = Registry::get($key);
        if ($raw === null) {
            return $default;
        }
        return TypeCoerce::toFloat($raw);
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $raw = Registry::get($key);
        if ($raw === null) {
            return $default;
        }
        return TypeCoerce::toBool($raw);
    }

    /**
     * @return array<string, mixed>
     */
    public static function stringMap(string $key): array
    {
        return TypeCoerce::toStringMap(Registry::get($key));
    }

    /**
     * @return list<mixed>
     */
    public static function list(string $key): array
    {
        return TypeCoerce::toList(Registry::get($key));
    }
}

<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Registry;

/**
 * Shared base for per-addon ConfigProvider classes.
 *
 * Every provider addon exposes its CS-Cart settings through a static
 * ConfigProvider with typed getters. The Registry plumbing underneath —
 * load `addons.<id>` once per request, serve individual keys, coerce
 * types — was forked per addon; this base centralizes it. Subclasses
 * supply {@see addonId()} and keep only their domain-specific getters.
 *
 * The settings cache is keyed by concrete class, so two providers never
 * share (or clobber) each other's cached settings despite living on a
 * single static property.
 *
 * For ad-hoc Registry access outside addon settings, use
 * {@see \Tygh\Addons\TravelCore\Helpers\RegistryCoerce} instead.
 */
abstract class AbstractConfigProvider
{
    /** @var array<class-string, array<string, mixed>> Per-provider cached settings. */
    private static array $settingsCache = [];

    /**
     * CS-Cart addon id whose Registry settings this provider serves
     * (e.g. 'sphinx_holidays', 'novoton_holidays').
     */
    abstract protected static function addonId(): string;

    /**
     * Full settings array for the addon, loaded from the Registry once per
     * request and cached per provider class.
     *
     * @return array<string, mixed>
     */
    protected static function loadSettings(): array
    {
        return self::$settingsCache[static::class] ??= TypeCoerce::toStringMap(
            Registry::get('addons.' . static::addonId()),
        );
    }

    /**
     * Raw setting value, or $default when the key is unset/null.
     */
    protected static function getSetting(string $key, mixed $default = null): mixed
    {
        return static::loadSettings()[$key] ?? $default;
    }

    protected static function getString(string $key, string $default = ''): string
    {
        $value = static::getSetting($key);

        return $value === null ? $default : TypeCoerce::toString($value);
    }

    protected static function getInt(string $key, int $default = 0): int
    {
        $value = static::getSetting($key);

        return $value === null ? $default : TypeCoerce::toInt($value);
    }

    protected static function getFloat(string $key, float $default = 0.0): float
    {
        $value = static::getSetting($key);

        return $value === null ? $default : TypeCoerce::toFloat($value);
    }

    /**
     * CS-Cart checkbox semantics: only the literal 'Y' is true.
     */
    protected static function getBool(string $key, bool $default = false): bool
    {
        $value = static::getSetting($key);

        return $value === null ? $default : $value === 'Y';
    }

    /**
     * Drop this provider's cached settings so the next read reloads from the
     * Registry (tests, or after runtime settings changes).
     */
    public static function resetSettingsCache(): void
    {
        unset(self::$settingsCache[static::class]);
    }
}

<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Registry;

/**
 * Designated settings-reader boundary for travel_core's own config +
 * the CS-Cart globals it legitimately needs (currencies, date format).
 *
 * Plays the same role the addon-level `ConfigProvider` classes play for
 * novoton_holidays and sphinx_holidays — the single place inside
 * travel_core where `\Tygh\Registry::get(...)` is legal. Allowlisted in
 * `phpstan-disallowed-calls.neon` at the repo root.
 *
 * All methods are typed + narrow. New travel_core services should read
 * settings through this class instead of reaching into Registry directly.
 */
final class TravelCoreConfig
{
    /**
     * CS-Cart's admin-configured display date format (e.g. `'%d %b %Y'`).
     * Falls back to a numeric European default when the admin setting is
     * missing.
     */
    public static function getDateFormat(): string
    {
        $fmt = Registry::get('settings.Appearance.date_format');
        return is_string($fmt) && $fmt !== '' ? $fmt : '%d.%m.%Y';
    }

    /**
     * CS-Cart currency catalogue (code => per-currency metadata). The
     * inner shape is left as `array<string, mixed>`; individual callers
     * coerce `coefficient`/`symbol` per-field with explicit guards.
     *
     * @return array<string, mixed>
     */
    public static function getCurrencies(): array
    {
        $currencies = Registry::get('currencies');
        if (!is_array($currencies)) {
            return [];
        }
        $out = [];
        foreach ($currencies as $k => $v) {
            if (is_string($k)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /**
     * Read an arbitrary `addons.travel_core.{key}` setting. Returns the
     * raw mixed value; callers are responsible for coercing to the
     * expected type (use TypeCoerce / ValidationHelpers).
     */
    public static function getSetting(string $key): mixed
    {
        return Registry::get('addons.travel_core.' . $key);
    }

    /**
     * Convenience accessor for the `addons.travel_core.feature_id_*`
     * product-feature IDs shared across provider addons
     * (region / city / meals). Returns 0 when the setting is missing
     * or non-numeric so callers can treat "no mapping" uniformly.
     */
    public static function getFeatureId(string $key): int
    {
        $value = Registry::get('addons.travel_core.feature_id_' . $key);
        return is_numeric($value) ? (int) $value : 0;
    }
}

<?php
declare(strict_types=1);
/**
 * Travel Core - Self-heal stamp helpers
 *
 * The addons' self-heals (schema migrations, index backfills) are
 * idempotent but not free — catalog queries against INFORMATION_SCHEMA,
 * SHOW COLUMNS, even a CREATE TABLE IF NOT EXISTS — and they used to
 * re-run on EVERY admin request. These helpers persist a per-heal
 * fingerprint in ?:storage_data so the steady state costs one indexed
 * row read; any change to the fingerprint (new migration code deployed)
 * re-arms the heal exactly once.
 *
 * Shared by travel_core and the provider addons (they depend on
 * travel_core, so these are always loaded first).
 *
 * @package TravelCore
 */

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

/**
 * TRUE when the self-heal identified by $key must run for $fingerprint.
 *
 * Fail-open: without a storage backend (partial bootstrap) the heal runs,
 * matching the pre-stamp behavior.
 */
function fn_travel_core_self_heal_due(string $key, string $fingerprint): bool
{
    if (!function_exists('fn_get_storage_data')) {
        return true;
    }

    $stored = fn_get_storage_data('travel_heal_' . $key);

    return !is_string($stored) || $stored !== $fingerprint;
}

/**
 * Persist the fingerprint after a successful heal run, so the next admin
 * request skips it. Callers stamp only AFTER the heal returned — a heal
 * that dies mid-run stays armed and retries on the next request.
 */
function fn_travel_core_self_heal_stamp(string $key, string $fingerprint): void
{
    if (function_exists('fn_set_storage_data')) {
        fn_set_storage_data('travel_heal_' . $key, $fingerprint);
    }
}

/**
 * Create settings an addon.xml declares that the database never received.
 *
 * CS-Cart imports <settings> only at install/upgrade, so every setting added
 * after a store was installed is invisible there — the field is missing from
 * the settings page with no way to set it. Language keys and the schema
 * already self-heal for the same reason; this closes the third gap.
 *
 * Safe for any addon: only creates what `isExists()` says is absent, and
 * returns the names it created so callers can log or report them.
 *
 * @return list<string> settings created (empty when already in sync)
 */
function fn_travel_core_ensure_settings(string $addon, string $addonDir, string $langsDir): array
{
    if (!class_exists(\Tygh\Settings::class)) {
        return [];
    }

    return \Tygh\Addons\TravelCore\Install\SettingsMigrator::ensure($addon, $addonDir, $langsDir);
}

/**
 * Heal the settings of every travel addon that is installed and deployed.
 *
 * Each addon ships its own addon.xml + .po pair, so the paths are derived
 * from the addon name; addons whose directory is absent are skipped.
 *
 * @return array<string, list<string>> addon => created setting names
 */
function fn_travel_core_ensure_all_settings(): array
{
    $addonsRoot = dirname(__DIR__, 2);          // …/app/addons
    $varLangs = dirname($addonsRoot, 2) . '/var/langs';

    $created = [];
    foreach (['travel_core', 'novoton_holidays', 'sphinx_holidays'] as $addon) {
        $dir = $addonsRoot . '/' . $addon;
        if (!is_file($dir . '/addon.xml')) {
            continue;
        }
        $names = fn_travel_core_ensure_settings($addon, $dir, $varLangs);
        if ($names !== []) {
            $created[$addon] = $names;
        }
    }

    return $created;
}

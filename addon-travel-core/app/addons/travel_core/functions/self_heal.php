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
 * Persist the fingerprint after a heal run, so the next admin request skips it.
 */
function fn_travel_core_self_heal_stamp(string $key, string $fingerprint): void
{
    if (function_exists('fn_set_storage_data')) {
        fn_set_storage_data('travel_heal_' . $key, $fingerprint);
    }
}

/**
 * Run a self-heal so that it CANNOT take the store down.
 *
 * These heals run from init.php on every admin page load. CS-Cart turns an
 * uncaught throwable into the 503 "Service unavailable" page, so a heal that
 * throws locks the admin out — including the admin pages you would need to
 * fix it. The heals touch CS-Cart's own APIs and schema, which are licensed
 * kit code that is NOT in this repository and therefore cannot be pinned by
 * a test: "it works on the deployed kit" is a hope, not a guarantee. So the
 * failure mode has to be "not healed", never "no store".
 *
 * The caller stamps the fingerprint either way, deliberately: a heal that
 * throws on this store would otherwise throw on every subsequent request
 * forever. The stamp re-arms on the next change to the healer or to any
 * addon.xml — precisely when a fix ships — so a genuine repair is not lost.
 */
function fn_travel_core_self_heal_guard(string $key, callable $heal): void
{
    try {
        $heal();
    } catch (\Throwable $e) {
        error_log(
            'travel_core: self-heal "' . $key . '" failed and was skipped — '
            . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(),
        );
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

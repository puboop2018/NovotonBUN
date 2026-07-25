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

<?php

declare(strict_types=1);

/**
 * Eurosite Touring — addon functions.
 *
 * MVP keeps this thin: the API/booking logic lives in src/ (Api\* + Services\*).
 * This file holds only the CS-Cart procedural boundary the platform calls by
 * name. As the addon grows, hook bodies (place_order_post, etc.) belong in
 * src/Hooks and delegate from here — the pattern the sphinx addon uses.
 *
 * Location: app/addons/eurosite/func.php
 */

use Tygh\Registry;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/**
 * The ?: table prefix, read here at the boundary — Registry access is
 * banned inside src/ class bodies (phpstan-disallowed-calls.neon).
 */
function fn_eurosite_table_prefix(): string
{
    $prefixRaw = Registry::get('config.table_prefix');

    return is_scalar($prefixRaw) ? (string) $prefixRaw : 'cscart_';
}

/**
 * Idempotent schema self-heal (missing tables/columns on installed stores);
 * body in Install\SchemaMigrator. Safe on every request — one catalog read
 * after the first call.
 */
function fn_eurosite_ensure_schema(): void
{
    \Tygh\Addons\Eurosite\Install\SchemaMigrator::ensure(fn_eurosite_table_prefix());
}

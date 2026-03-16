<?php
declare(strict_types=1);

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

/**
 * Get or create a nested CS-Cart category tree from a path string.
 *
 * E.g. "Hotels/Greece/Crete/Heraklion" creates:
 *   Hotels → Greece → Crete → Heraklion (each as a child of the previous)
 *
 * Idempotent — existing categories are reused by name match at each level.
 *
 * @param string $path Forward-slash separated category path
 * @return int The leaf category_id, or 0 on failure
 */
function fn_sphinx_holidays_get_or_create_category(string $path): int
{
    return fn_travel_core_get_or_create_category($path);
}

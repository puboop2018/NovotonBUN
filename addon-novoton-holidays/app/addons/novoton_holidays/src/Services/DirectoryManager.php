<?php

declare(strict_types=1);

/**
 * Novoton Holidays - Directory Manager
 *
 * Creates and validates addon directories (cache, reports).
 * Single Responsibility: directory creation/existence checks only.
 *
 * @package NovotonHolidays
 * @since 3.3.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

class DirectoryManager
{
    /**
     * Ensure cache directory exists.
     */
    public static function ensureCacheDir(): bool
    {
        $cache_dir = PathResolver::getPath('cache');

        if (!is_dir($cache_dir)) {
            return mkdir($cache_dir, 0o755, true);
        }

        return true;
    }

    /**
     * Ensure reports directory exists.
     */
    public static function ensureReportsDir(): bool
    {
        $reports_dir = PathResolver::getPath('reports');

        if (!is_dir($reports_dir)) {
            return function_exists('fn_mkdir')
                ? (bool) fn_mkdir($reports_dir)
                : mkdir($reports_dir, 0o755, true);
        }

        return true;
    }
}

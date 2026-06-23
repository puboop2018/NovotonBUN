<?php

declare(strict_types=1);

/**
 * Novoton Holidays - Path Resolver
 *
 * Resolves addon directory paths (addon root, src, helpers, functions, cache, reports).
 * Single Responsibility: filesystem path resolution only.
 *
 * @package NovotonHolidays
 * @since 3.3.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

class PathResolver
{
    /** @var array<string, string>|null Cached paths array. */
    private static ?array $paths = null;

    /**
     * Get all addon paths (cached).
     *
     * @return array<string, string>
     */
    public static function getPaths(): array
    {
        if (self::$paths === null) {
            $addon_dir = ConfigProvider::getAddonsDir() . ConfigProvider::ADDON_ID . '/';
            $cache_root = ConfigProvider::getCacheMiscDir();
            if ($cache_root === '') {
                $cache_root = defined('DIR_ROOT') ? TypeCoerce::toString(DIR_ROOT) . '/var/cache/' : '/tmp/';
            }

            $reports = function_exists('fn_get_files_dir_path')
                ? TypeCoerce::toString(fn_get_files_dir_path()) . 'novoton_reports/'
                : $addon_dir . 'reports/';

            $paths = [
                'addon' => $addon_dir,
                'src' => $addon_dir . 'src/',
                'helpers' => $addon_dir . 'Helpers/',
                'functions' => $addon_dir . 'functions/',
                'cache' => $cache_root . 'novoton/',
                'reports' => $reports,
            ];
            self::$paths = $paths;
            return $paths;
        }
        return self::$paths;
    }

    /**
     * Get a specific addon path.
     *
     * @param string $key Path key (addon, src, helpers, functions, cache, reports)
     */
    public static function getPath(string $key): string
    {
        $paths = self::getPaths();
        return $paths[$key] ?? '';
    }

    /**
     * Reset cached paths (e.g. during testing).
     */
    public static function reset(): void
    {
        self::$paths = null;
    }
}

<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron;

use Tygh\Addons\SphinxHolidays\Cron\Commands\AddProductsCommand;
use Tygh\Addons\SphinxHolidays\Cron\Commands\AssignBoardsCommand;
use Tygh\Addons\SphinxHolidays\Cron\Commands\CacheRefreshCommand;
use Tygh\Addons\SphinxHolidays\Cron\Commands\CircuitSyncCommand;
use Tygh\Addons\SphinxHolidays\Cron\Commands\CleanupCommand;
use Tygh\Addons\SphinxHolidays\Cron\Commands\DestinationSyncCommand;
use Tygh\Addons\SphinxHolidays\Cron\Commands\DiscoverBoardsCommand;
use Tygh\Addons\SphinxHolidays\Cron\Commands\ExperienceSyncCommand;
use Tygh\Addons\SphinxHolidays\Cron\Commands\FullSyncCommand;
use Tygh\Addons\SphinxHolidays\Cron\Commands\HotelSyncCommand;
use Tygh\Addons\SphinxHolidays\Cron\Commands\OrderStatusSyncCommand;
use Tygh\Addons\SphinxHolidays\Cron\Commands\PackageRouteSyncCommand;
use Tygh\Addons\SphinxHolidays\Cron\Commands\SyncImagesCommand;
use Tygh\Addons\SphinxHolidays\Cron\Commands\UpdateProductsCommand;

/**
 * Dispatches cron jobs by mode name.
 *
 * Each mode maps to a Command class that implements execute().
 * Concurrency protection via file locks prevents two instances of the same mode from running simultaneously.
 */
class CronDispatcher
{
    /**
     * Map of mode => command class.
     */
    private static array $modes = [
        'destinations'    => DestinationSyncCommand::class,
        'hotels'          => HotelSyncCommand::class,
        'package_routes'  => PackageRouteSyncCommand::class,
        'circuits'        => CircuitSyncCommand::class,
        'experiences'     => ExperienceSyncCommand::class,
        'order_status'    => OrderStatusSyncCommand::class,
        'cache_refresh'   => CacheRefreshCommand::class,
        'add_products'    => AddProductsCommand::class,
        'discover_boards' => DiscoverBoardsCommand::class,
        'assign_boards'   => AssignBoardsCommand::class,
        'update_products' => UpdateProductsCommand::class,
        'sync_images'     => SyncImagesCommand::class,
        'cleanup'         => CleanupCommand::class,
        'full'            => FullSyncCommand::class,
    ];

    /**
     * Get all available modes with descriptions.
     *
     * @return array<string, string>
     */
    public static function getAvailableModes(): array
    {
        $result = [];
        foreach (self::$modes as $mode => $class) {
            $result[$mode] = $class::getDescription();
        }
        return $result;
    }

    /**
     * Check if a mode exists.
     */
    public function hasMode(string $mode): bool
    {
        return isset(self::$modes[$mode]);
    }

    /**
     * Dispatch a cron job by mode.
     *
     * Uses file-based locking to prevent two instances of the same mode
     * from running concurrently. The 'full' mode acquires a single lock
     * for the composite run (individual modes dispatched by FullSyncCommand
     * will also acquire their own locks).
     *
     * @param string $mode The cron mode to execute
     * @param array $params Additional parameters
     * @return array Result from the command
     */
    public function dispatch(string $mode, array $params = []): array
    {
        if (!$this->hasMode($mode)) {
            return [
                'success' => false,
                'error' => "Unknown mode: {$mode}",
            ];
        }

        // Status/reset are non-destructive read-only ops — skip the lock
        $isReadOnly = !empty($params['status']) || !empty($params['reset']);

        // Acquire file lock to prevent concurrent execution of the same mode
        $lockFp = null;
        if (!$isReadOnly) {
            $lockFile = $this->getLockPath($mode);
            $lockFp = fopen($lockFile, 'w');

            if ($lockFp && !flock($lockFp, LOCK_EX | LOCK_NB)) {
                fclose($lockFp);
                return [
                    'success' => false,
                    'error' => "Mode '{$mode}' is already running. Try again later. Use &status=1 to check progress or &reset=1 to clear stale state.",
                ];
            }
        }

        try {
            // Remove PHP execution time limit for long-running sync jobs
            // (destination sync: 200k+ items, hotel sync: 100k+ items)
            set_time_limit(0);

            $class = self::$modes[$mode];
            $command = new $class();

            // Set output callback to echo progress
            $command->setOutputCallback(function (string $message) {
                echo $message . "\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            });

            $result = $command->execute($params);
        } finally {
            // Release lock
            if ($lockFp) {
                flock($lockFp, LOCK_UN);
                fclose($lockFp);
                @unlink($lockFile);
            }
        }

        return $result;
    }

    /**
     * Get the lock file path for a given mode.
     */
    private function getLockPath(string $mode): string
    {
        $cacheDir = defined('DIR_CACHE') ? DIR_CACHE : sys_get_temp_dir();
        return rtrim($cacheDir, '/') . "/sphinx_cron_{$mode}.lock";
    }
}

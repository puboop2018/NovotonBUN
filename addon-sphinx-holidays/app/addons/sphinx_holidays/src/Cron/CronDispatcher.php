<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron;

use Tygh\Addons\SphinxHolidays\Cron\Commands\AddProductsCommand;
use Tygh\Addons\SphinxHolidays\Cron\Commands\AssignBoardsCommand;
use Tygh\Addons\SphinxHolidays\Cron\Commands\AuditFacilitiesCommand;
use Tygh\Addons\SphinxHolidays\Cron\Commands\CacheRefreshCommand;
use Tygh\Addons\SphinxHolidays\Cron\Commands\CircuitSyncCommand;
use Tygh\Addons\SphinxHolidays\Cron\Commands\CleanupCommand;
use Tygh\Addons\SphinxHolidays\Cron\Commands\DeduplicateCommand;
use Tygh\Addons\SphinxHolidays\Cron\Commands\DestinationSyncCommand;
use Tygh\Addons\SphinxHolidays\Cron\Commands\DiagnoseImagesCommand;
use Tygh\Addons\SphinxHolidays\Cron\Commands\DiagnoseSeoCommand;
use Tygh\Addons\SphinxHolidays\Cron\Commands\DiscoverBoardsCommand;
use Tygh\Addons\SphinxHolidays\Cron\Commands\ExperienceSyncCommand;
use Tygh\Addons\SphinxHolidays\Cron\Commands\FullSyncCommand;
use Tygh\Addons\SphinxHolidays\Cron\Commands\HotelSyncCommand;
use Tygh\Addons\SphinxHolidays\Cron\Commands\OrderStatusSyncCommand;
use Tygh\Addons\SphinxHolidays\Cron\Commands\PackageRouteSyncCommand;
use Tygh\Addons\SphinxHolidays\Cron\Commands\SyncImagesCommand;
use Tygh\Addons\SphinxHolidays\Cron\Commands\UpdateProductsCommand;
use Tygh\Addons\TravelCore\Contracts\CronDispatcherInterface;

/**
 * Dispatches cron jobs by mode name.
 *
 * Each mode maps to a Command class that implements execute().
 * Concurrency protection via file locks prevents two instances of the same mode from running simultaneously.
 */
class CronDispatcher implements CronDispatcherInterface
{
    /**
     * Map of mode => command class.
     * @var array<string, class-string>
     */
    private static array $modes = [
        'destinations' => DestinationSyncCommand::class,
        'hotels' => HotelSyncCommand::class,
        'package_routes' => PackageRouteSyncCommand::class,
        'circuits' => CircuitSyncCommand::class,
        'experiences' => ExperienceSyncCommand::class,
        'order_status' => OrderStatusSyncCommand::class,
        'cache_refresh' => CacheRefreshCommand::class,
        'add_products' => AddProductsCommand::class,
        'discover_boards' => DiscoverBoardsCommand::class,
        'assign_boards' => AssignBoardsCommand::class,
        'update_products' => UpdateProductsCommand::class,
        'sync_images' => SyncImagesCommand::class,
        'diagnose_images' => DiagnoseImagesCommand::class,
        'diagnose_seo' => DiagnoseSeoCommand::class,
        'cleanup' => CleanupCommand::class,
        'deduplicate' => DeduplicateCommand::class,
        'audit_facilities' => AuditFacilitiesCommand::class,
        'full' => FullSyncCommand::class,
    ];

    /**
     * Get all available modes with descriptions.
     *
     * @return array<string, string>
     */
    #[\Override]
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
    #[\Override]
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
     * @param array<string, mixed> $params Additional parameters
     * @return array<string, mixed> Result from the command
     */
    #[\Override]
    public function dispatch(string $mode, array $params = []): array
    {
        if (!$this->hasMode($mode)) {
            return [
                'success' => false,
                'error' => "Unknown mode: {$mode}",
            ];
        }

        // Status/reset/debug are non-destructive read-only ops — skip the lock
        $isReadOnly = !empty($params['status']) || !empty($params['reset']) || !empty($params['debug']);

        // Acquire file lock to prevent concurrent execution of the same mode
        $lockFile = null;
        $lockFp = null;
        if (!$isReadOnly) {
            $lockFile = $this->getLockPath($mode);
            $lockFp = $this->acquireLock($lockFile);

            if ($lockFp === false) {
                return [
                    'success' => false,
                    'busy' => true,
                    'message' => "Mode '{$mode}' is already running. Try again later. Use &status=1 to check progress or &reset=1 to clear stale state.",
                ];
            }
        }

        try {
            // Remove PHP execution time limit for long-running sync jobs
            // (destination sync: 200k+ items, hotel sync: 100k+ items)
            set_time_limit(0);

            $class = self::$modes[$mode];
            /** @var \Tygh\Addons\SphinxHolidays\Cron\Commands\AbstractSyncCommand $command */
            $command = new $class();

            // Set output callback to echo progress and keep lock file fresh
            $command->setOutputCallback(function (string $message, bool $addNewline = true) use ($lockFile): void {
                echo $message . ($addNewline ? "\n" : '');
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
                // Touch lock file so stale detection doesn't kill active sync
                if ($lockFile !== null && file_exists($lockFile)) {
                    touch($lockFile);
                }
            });

            $result = $command->execute($params);
        } finally {
            // Release lock
            if ($lockFp) {
                flock($lockFp, LOCK_UN);
                fclose($lockFp);
                if (file_exists($lockFile)) {
                    unlink($lockFile);
                }
            }
        }

        return $result;
    }

    /** Maximum lock age before it's considered stale (seconds). */
    private const int STALE_LOCK_THRESHOLD = 1800; // 30 minutes

    /**
     * Acquire an exclusive file lock with stale lock detection.
     *
     * If the lock is held by another process but the lock file hasn't been
     * touched in 30+ minutes, assume the holder died and force-acquire.
     *
     * Uses a single fopen+flock cycle to avoid TOCTOU race conditions:
     * instead of unlink+reopen (where two processes could both delete and
     * re-create), we open the existing file and retry the lock after
     * checking staleness via the file handle we already hold.
     *
     * @return resource|false File handle on success, false if lock is held
     */
    private function acquireLock(string $lockFile)
    {
        $fp = fopen($lockFile, 'c'); // 'c' = create if missing, don't truncate
        if (!$fp) {
            return false;
        }

        // Try non-blocking exclusive lock
        if (flock($fp, LOCK_EX | LOCK_NB)) {
            // Got the lock — truncate and write PID for debugging
            ftruncate($fp, 0);
            fwrite($fp, (string) getmypid());
            fflush($fp);
            return $fp;
        }

        // Lock is held — check if stale via the file we already opened
        $stat = fstat($fp);
        fclose($fp);

        if ($stat === false) {
            return false;
        }

        $lockAge = time() - $stat['mtime'];
        if ($lockAge <= self::STALE_LOCK_THRESHOLD) {
            return false; // Lock is fresh, another process is active
        }

        // Stale lock — force-acquire by reopening and retrying
        // The unlink+reopen is acceptable here because only stale-lock
        // recovery reaches this path, and concurrent stale recovery is
        // harmless (both processes would try to acquire, only one wins flock)
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }
        $fp = fopen($lockFile, 'c');
        if (!$fp) {
            return false;
        }

        if (flock($fp, LOCK_EX | LOCK_NB)) {
            ftruncate($fp, 0);
            fwrite($fp, (string) getmypid());
            fflush($fp);
            return $fp;
        }

        fclose($fp);
        return false;
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

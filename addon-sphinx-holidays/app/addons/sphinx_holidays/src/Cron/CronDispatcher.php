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
    /** Maximum seconds a lock can be held before it's considered stale. */
    private const LOCK_STALE_TIMEOUT = 3600; // 1 hour

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
     * @param array $params Additional parameters (pass force=1 to clear stale locks)
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

        // Ensure long-running sync completes even if the browser is closed.
        // Without this, PHP aborts the script on the next output after connection drop,
        // leaving partial data and a held lock.
        ignore_user_abort(true);

        // Acquire file lock to prevent concurrent execution of the same mode
        $lockFile = $this->getLockPath($mode);
        $force = !empty($params['force']);

        // If force requested and lock file exists, check for stale lock
        if ($force && file_exists($lockFile)) {
            $this->clearStaleLock($lockFile, $mode);
        }

        $lockFp = fopen($lockFile, 'w');

        if ($lockFp && !flock($lockFp, LOCK_EX | LOCK_NB)) {
            // Lock is held — check if it's stale (process died without releasing)
            $lockInfo = $this->readLockInfo($lockFile);
            $staleCleaned = false;

            if ($lockInfo && $this->isLockStale($lockInfo)) {
                // The process that held the lock is gone — force acquire
                fclose($lockFp);
                $this->clearStaleLock($lockFile, $mode);
                $lockFp = fopen($lockFile, 'w');
                if ($lockFp && flock($lockFp, LOCK_EX | LOCK_NB)) {
                    $staleCleaned = true;
                }
            }

            if (!$staleCleaned) {
                if ($lockFp) {
                    fclose($lockFp);
                }
                $msg = "Mode '{$mode}' is already running.";
                if ($lockInfo) {
                    $msg .= " Started at {$lockInfo['started_at']} (PID {$lockInfo['pid']}).";
                    $age = time() - (int) $lockInfo['time'];
                    $msg .= " Running for " . $this->formatDuration($age) . ".";
                }
                $msg .= " Add &force=1 to clear a stale lock.";
                return [
                    'success' => false,
                    'error' => $msg,
                ];
            }
        }

        // Write lock metadata (PID + timestamp) so other processes can detect stale locks
        if ($lockFp) {
            ftruncate($lockFp, 0);
            rewind($lockFp);
            fwrite($lockFp, json_encode([
                'pid' => getmypid(),
                'time' => time(),
                'started_at' => date('Y-m-d H:i:s'),
                'mode' => $mode,
            ]));
            fflush($lockFp);
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

    /**
     * Read lock metadata from lock file.
     *
     * @return array{pid: int, time: int, started_at: string, mode: string}|null
     */
    private function readLockInfo(string $lockFile): ?array
    {
        $content = @file_get_contents($lockFile);
        if ($content === false || $content === '') {
            return null;
        }
        $data = json_decode($content, true);
        if (!is_array($data) || empty($data['pid'])) {
            return null;
        }
        return $data;
    }

    /**
     * Check if a lock is stale (owning process is dead or lock is too old).
     */
    private function isLockStale(array $lockInfo): bool
    {
        $pid = (int) $lockInfo['pid'];
        $lockTime = (int) ($lockInfo['time'] ?? 0);

        // If the process is no longer running, the lock is stale
        if ($pid > 0 && !$this->isProcessRunning($pid)) {
            return true;
        }

        // If the lock is older than the stale timeout, consider it stale
        if ($lockTime > 0 && (time() - $lockTime) > self::LOCK_STALE_TIMEOUT) {
            return true;
        }

        return false;
    }

    /**
     * Check if a process is still running.
     */
    private function isProcessRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        // posix_kill(pid, 0) checks if process exists without sending a signal
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }

        // Fallback: check /proc on Linux
        if (is_dir("/proc/{$pid}")) {
            return true;
        }

        return false;
    }

    /**
     * Remove a stale lock file so a new lock can be acquired.
     */
    private function clearStaleLock(string $lockFile, string $mode): void
    {
        @unlink($lockFile);
        fn_log_event('general', 'runtime', [
            'message' => "Sphinx cron: cleared stale lock for mode '{$mode}'",
        ]);
    }

    /**
     * Format seconds into human-readable duration.
     */
    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }
        $min = (int) floor($seconds / 60);
        $sec = $seconds % 60;
        if ($min < 60) {
            return "{$min}m {$sec}s";
        }
        $hr = (int) floor($min / 60);
        $min = $min % 60;
        return "{$hr}h {$min}m";
    }
}

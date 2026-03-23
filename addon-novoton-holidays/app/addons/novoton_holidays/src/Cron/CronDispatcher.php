<?php
declare(strict_types=1);
namespace Tygh\Addons\NovotonHolidays\Cron;

/**
 * Cron Command Dispatcher
 *
 * Auto-discovers command classes from the Cron/Commands/ directory.
 * Any class extending AbstractCronCommand is automatically registered
 * via its getModes() method. Adding a new command requires only creating
 * a new file in Commands/ — no modification to this class needed (OCP).
 *
 * Includes file-based locking with stale-lock detection (PID + timestamp)
 * and ignore_user_abort(true) so sync completes even if the browser is closed.
 *
 * @package NovotonHolidays
 * @since 3.3.0
 */
class CronDispatcher
{
    /** Maximum seconds a lock can be held before it's considered stale. */
    private const LOCK_STALE_TIMEOUT = 3600; // 1 hour

    /** @var array<string, class-string<AbstractCronCommand>> mode => command class */
    private static array $commandMap = [];
    private static bool $registered = false;
    private \Tygh\Addons\NovotonHolidays\NovotonApi $api;
    private ?\Tygh\Addons\NovotonHolidays\Helpers\SyncLogger $logger;

    public function __construct(\Tygh\Addons\NovotonHolidays\NovotonApi $api, ?\Tygh\Addons\NovotonHolidays\Helpers\SyncLogger $logger)
    {
        $this->api = $api;
        $this->logger = $logger;
        self::registerCommands();
    }

    /**
     * Auto-discover and register all command classes from the Commands/ directory.
     */
    private static function registerCommands(): void
    {
        if (self::$registered) {
            return;
        }

        $commandsDir = __DIR__ . '/Commands/';
        if (!is_dir($commandsDir)) {
            self::$registered = true;
            return;
        }

        $namespace = 'Tygh\\Addons\\NovotonHolidays\\Cron\\Commands\\';

        foreach (glob($commandsDir . '*Command.php') as $file) {
            $className = $namespace . basename($file, '.php');

            if (!class_exists($className)) {
                require_once $file;
            }

            if (!class_exists($className) || !is_subclass_of($className, AbstractCronCommand::class)) {
                continue;
            }

            foreach ($className::getModes() as $mode) {
                self::$commandMap[$mode] = $className;
            }
        }

        self::$registered = true;
    }

    /**
     * Dispatch a cron job by mode.
     *
     * Uses file-based locking to prevent concurrent execution of the same mode.
     * Pass force=1 in $params to clear stale locks.
     */
    public function dispatch(string $mode, array $params = []): array
    {
        if (!isset(self::$commandMap[$mode])) {
            return ['success' => false, 'error' => "Unknown mode: {$mode}"];
        }

        // Ensure long-running sync completes even if the browser is closed
        ignore_user_abort(true);
        set_time_limit(0);

        // Acquire file lock
        $lockFile = $this->getLockPath($mode);
        $force = !empty($params['force']);

        if ($force && file_exists($lockFile)) {
            $this->clearStaleLock($lockFile, $mode);
        }

        $lockFp = @fopen($lockFile, 'w');

        if ($lockFp && !flock($lockFp, LOCK_EX | LOCK_NB)) {
            $lockInfo = $this->readLockInfo($lockFile);
            $staleCleaned = false;

            if ($lockInfo && $this->isLockStale($lockInfo)) {
                fclose($lockFp);
                $this->clearStaleLock($lockFile, $mode);
                $lockFp = @fopen($lockFile, 'w');
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
                return ['success' => false, 'error' => $msg];
            }
        }

        // Write lock metadata
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
            $class = self::$commandMap[$mode];
            $command = new $class($this->api, $this->logger, array_merge($params, ['_mode' => $mode]));
            return $command->execute();
        } finally {
            if ($lockFp) {
                flock($lockFp, LOCK_UN);
                fclose($lockFp);
                @unlink($lockFile);
            }
        }
    }

    public function hasMode(string $mode): bool
    {
        return isset(self::$commandMap[$mode]);
    }

    /**
     * @return array<string, string> mode => description
     */
    public static function getAvailableModes(): array
    {
        self::registerCommands();

        $modes = [];
        foreach (self::$commandMap as $mode => $class) {
            $modes[$mode] = $class::getDescription();
        }
        return $modes;
    }

    /**
     * Reset for testing — forces re-discovery on next use.
     */
    public static function reset(): void
    {
        self::$commandMap = [];
        self::$registered = false;
    }

    private function getLockPath(string $mode): string
    {
        $cacheDir = defined('DIR_CACHE') ? DIR_CACHE : sys_get_temp_dir();
        return rtrim($cacheDir, '/') . "/novoton_cron_{$mode}.lock";
    }

    /**
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

    private function isLockStale(array $lockInfo): bool
    {
        $pid = (int) $lockInfo['pid'];
        $lockTime = (int) ($lockInfo['time'] ?? 0);

        if ($pid > 0 && !$this->isProcessRunning($pid)) {
            return true;
        }

        if ($lockTime > 0 && (time() - $lockTime) > self::LOCK_STALE_TIMEOUT) {
            return true;
        }

        return false;
    }

    private function isProcessRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }
        if (is_dir("/proc/{$pid}")) {
            return true;
        }
        return false;
    }

    private function clearStaleLock(string $lockFile, string $mode): void
    {
        @unlink($lockFile);
        fn_log_event('general', 'runtime', [
            'message' => "Novoton cron: cleared stale lock for mode '{$mode}'",
        ]);
    }

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

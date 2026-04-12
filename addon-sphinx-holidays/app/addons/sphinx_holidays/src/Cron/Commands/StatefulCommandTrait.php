<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

/**
 * Provides JSON-based state file persistence for resumable cron commands.
 *
 * State files are stored in DIR_CACHE with atomic writes (tmp + rename)
 * and checksum-based corruption detection.
 *
 * Using classes MUST define:
 *   - private const STATE_FILE_NAME  (unique filename per command)
 *   - private const STALE_HOURS      (hours before state is considered abandoned)
 *   - private const DEFAULT_STATE    (array with default structure including 'status' key)
 */
trait StatefulCommandTrait
{
    private function getStatePath(): string
    {
        $cacheDir = defined('DIR_CACHE') ? DIR_CACHE : sys_get_temp_dir() . '/';
        return $cacheDir . self::STATE_FILE_NAME;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadState(): array
    {
        $path = $this->getStatePath();
        if (!file_exists($path)) {
            return self::DEFAULT_STATE;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return self::DEFAULT_STATE;
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return self::DEFAULT_STATE;
        }

        if (isset($decoded['_checksum'], $decoded['_data'])) {
            $expected = $decoded['_checksum'];
            $actual = md5((string) json_encode($decoded['_data']));
            if ($expected !== $actual) {
                return self::DEFAULT_STATE;
            }
            $state = $decoded['_data'];
        } else {
            $state = $decoded;
        }

        return is_array($state) ? array_merge(self::DEFAULT_STATE, $state) : self::DEFAULT_STATE;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function saveState(array $state): void
    {
        $path = $this->getStatePath();

        $data = json_encode($state);
        if ($data === false) {
            return;
        }

        $wrapped = json_encode([
            '_checksum' => md5($data),
            '_data'     => $state,
        ]);

        $tmpPath = $path . '.tmp';
        if (file_put_contents($tmpPath, $wrapped, LOCK_EX) === false) {
            return;
        }

        rename($tmpPath, $path);
    }

    private function clearState(): void
    {
        $path = $this->getStatePath();
        foreach (['', '.tmp'] as $suffix) {
            $file = $path . $suffix;
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    /**
     * @param array<string, mixed> $state
     */
    private function isStale(array $state): bool
    {
        if ($state['status'] !== 'in_progress') {
            return false;
        }

        $lastRun = $state['last_run_at'] ?? $state['started_at'] ?? null;
        if ($lastRun === null) {
            return true;
        }

        $ageHours = (time() - strtotime($lastRun)) / 3600;
        return $ageHours > self::STALE_HOURS;
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }
        if ($seconds < 3600) {
            $m = (int) floor($seconds / 60);
            $s = $seconds % 60;
            return "{$m}m {$s}s";
        }
        $h = (int) floor($seconds / 3600);
        $m = (int) floor(($seconds % 3600) / 60);
        return "{$h}h {$m}m";
    }
}

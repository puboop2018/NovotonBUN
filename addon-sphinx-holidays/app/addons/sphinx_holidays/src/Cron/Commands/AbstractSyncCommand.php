<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

/**
 * Base class for Sphinx sync cron commands.
 *
 * Provides shared output callback management and rate limit summary formatting.
 */
abstract class AbstractSyncCommand
{
    protected ?\Closure $outputCallback = null;

    abstract public static function getDescription(): string;

    public function setOutputCallback(\Closure $callback): void
    {
        $this->outputCallback = $callback;
    }

    protected function outputRateLimitSummary(array $stats): void
    {
        if ($this->outputCallback === null) {
            return;
        }

        $rlHits = $stats['rate_limit_hits'] ?? 0;
        if ($rlHits > 0) {
            ($this->outputCallback)("Rate limit: {$rlHits} request(s) were throttled (HTTP 429).");
        }

        $rl = $stats['rate_limit'] ?? [];
        if (isset($rl['remaining'], $rl['limit'])) {
            $msg = "Rate limit: {$rl['remaining']}/{$rl['limit']} requests remaining.";
            $resetIn = $rl['reset_in'] ?? null;
            if ($resetIn !== null && $resetIn > 0) {
                $msg .= ' Resets in ' . $this->formatResetTime($resetIn) . '.';
            }
            ($this->outputCallback)($msg);
        }
    }

    private function formatResetTime(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }
        $m = (int) floor($seconds / 60);
        $s = $seconds % 60;
        if ($m < 60) {
            return $s > 0 ? "{$m}m {$s}s" : "{$m}m";
        }
        $h = (int) floor($m / 60);
        $m = $m % 60;
        return $m > 0 ? "{$h}h {$m}m" : "{$h}h";
    }

    protected function wrapResult(array $stats): array
    {
        return [
            'success' => $stats['success'],
            'stats'   => $stats,
        ];
    }
}

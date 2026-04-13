<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\TravelCore\Cron\AbstractCronCommand as BaseCommand;

/**
 * Sphinx-specific cron command base.
 *
 * Extends travel_core's shared base with rate-limit summary formatting
 * specific to the Sphinx API's HTTP 429 / X-RateLimit headers.
 */
abstract class AbstractSyncCommand extends BaseCommand
{
    /**
     * Execute the command with optional parameters.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    abstract public function execute(array $params = []): array;

    /**
     * @param array<string, mixed> $stats
     */
    protected function outputRateLimitSummary(array $stats): void
    {
        if ($this->outputCallback === null) {
            return;
        }

        $rlHits = $stats['rate_limit_hits'] ?? 0;
        if ($rlHits > 0) {
            $this->output("Rate limit: {$rlHits} request(s) were throttled (HTTP 429).");
        }

        $rl = $stats['rate_limit'] ?? [];
        if (isset($rl['remaining'], $rl['limit'])) {
            $msg = "Rate limit: {$rl['remaining']}/{$rl['limit']} requests remaining.";
            $resetIn = $rl['reset_in'] ?? null;
            if ($resetIn !== null && $resetIn > 0) {
                $msg .= ' Resets in ' . $this->formatResetTime($resetIn) . '.';
            }
            $this->output($msg);
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
}

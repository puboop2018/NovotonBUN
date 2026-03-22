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
    /** @var callable|null */
    protected $outputCallback = null;

    abstract public static function getDescription(): string;

    public function setOutputCallback(callable $callback): void
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
            ($this->outputCallback)("Rate limit: {$rl['remaining']}/{$rl['limit']} requests remaining.");
        }
    }

    protected function wrapResult(array $stats): array
    {
        return [
            'success' => $stats['success'],
            'stats'   => $stats,
        ];
    }
}

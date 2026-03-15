<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\DestinationSyncService;
use Tygh\Addons\SphinxHolidays\Repository\DestinationRepository;

/**
 * Cron command: sync destinations from Sphinx API.
 *
 * Usage:
 *   php cron.php access_key=KEY mode=destinations
 */
class DestinationSyncCommand
{
    /** @var callable|null */
    private $outputCallback = null;

    public static function getDescription(): string
    {
        return 'Sync destinations (countries, regions, cities) from Sphinx API';
    }

    /**
     * Set output callback for progress messages.
     */
    public function setOutputCallback(callable $callback): void
    {
        $this->outputCallback = $callback;
    }

    private function outputRateLimitSummary(array $stats): void
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

    /**
     * Execute the destination sync.
     *
     * @param array $params CLI parameters (unused for now)
     * @return array{success: bool, stats: array}
     */
    public function execute(array $params = []): array
    {
        $api = Container::getApi();
        $repository = new DestinationRepository();
        $service = new DestinationSyncService($api, $repository);

        if ($this->outputCallback !== null) {
            $service->setOutputCallback($this->outputCallback);
        }

        $stats = $service->sync();

        // Output rate limit summary
        $this->outputRateLimitSummary($stats);

        return [
            'success' => $stats['success'],
            'stats' => $stats,
        ];
    }
}

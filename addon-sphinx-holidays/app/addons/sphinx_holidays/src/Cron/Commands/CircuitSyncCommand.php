<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Services\CircuitSyncService;
use Tygh\Addons\SphinxHolidays\Services\Container;

/**
 * Cron command: sync circuits from Sphinx static API.
 *
 * Usage: php cron.php access_key=KEY mode=circuits [full=1]
 *
 * Default is incremental (only circuits updated since the last successful run,
 * via updated_since); pass full=1 to force a full re-fetch of the catalog.
 */
class CircuitSyncCommand extends AbstractSyncCommand
{
    #[\Override]
    public static function getDescription(): string
    {
        return 'Sync circuit catalog from Sphinx static API (incremental; full=1 for a full re-fetch)';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    #[\Override]
    public function execute(array $params = []): array
    {
        $api = Container::getApi();
        $service = new CircuitSyncService($api);

        if ($this->outputCallback !== null) {
            $service->setOutputCallback($this->outputCallback);
        }

        $fullSync = !empty($params['full']);
        $stats = $service->sync($fullSync);

        $this->outputRateLimitSummary($stats);

        return $this->wrapResult($stats);
    }
}

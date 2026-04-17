<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Services\CircuitSyncService;
use Tygh\Addons\SphinxHolidays\Services\Container;

/**
 * Cron command: sync circuits from Sphinx static API.
 *
 * Usage: php cron.php access_key=KEY mode=circuits
 */
class CircuitSyncCommand extends AbstractSyncCommand
{
    #[\Override]
    public static function getDescription(): string
    {
        return 'Sync circuit catalog from Sphinx static API';
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

        $stats = $service->sync();

        $this->outputRateLimitSummary($stats);

        return $this->wrapResult($stats);
    }
}

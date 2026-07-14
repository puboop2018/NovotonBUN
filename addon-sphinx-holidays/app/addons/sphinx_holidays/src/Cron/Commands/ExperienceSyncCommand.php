<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\ExperienceSyncService;

/**
 * Cron command: sync experiences from Sphinx static API.
 *
 * Usage: php cron.php access_key=KEY mode=experiences
 */
class ExperienceSyncCommand extends AbstractSyncCommand
{
    /**
     * @return list<string>
     */
    #[\Override]
    public static function getModes(): array
    {
        return ['experiences'];
    }

    #[\Override]
    public static function getDescription(): string
    {
        return 'Sync experience catalog from Sphinx static API';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    #[\Override]
    public function execute(array $params = []): array
    {
        $api = Container::getApi();
        $service = new ExperienceSyncService($api);

        if ($this->outputCallback !== null) {
            $service->setOutputCallback($this->outputCallback);
        }

        $stats = $service->sync();

        $this->outputRateLimitSummary($stats);

        return $this->wrapResult($stats);
    }
}

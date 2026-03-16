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
class ExperienceSyncCommand
{
    /** @var callable|null */
    private $outputCallback = null;

    public static function getDescription(): string
    {
        return 'Sync experience catalog from Sphinx static API';
    }

    public function setOutputCallback(callable $callback): void
    {
        $this->outputCallback = $callback;
    }

    public function execute(array $params = []): array
    {
        $api = Container::getApi();
        $service = new ExperienceSyncService($api);

        if ($this->outputCallback !== null) {
            $service->setOutputCallback($this->outputCallback);
        }

        return $service->sync();
    }
}

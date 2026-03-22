<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\DestinationSyncService;

/**
 * Cron command: sync destinations from Sphinx API.
 *
 * Usage:
 *   php cron.php access_key=KEY mode=destinations
 *   php cron.php access_key=KEY mode=destinations full=1
 */
class DestinationSyncCommand extends AbstractSyncCommand
{
    public static function getDescription(): string
    {
        return 'Sync destinations (countries, regions, cities) from Sphinx API';
    }

    /**
     * Execute the destination sync.
     *
     * @param array $params CLI parameters. 'full' => 1 forces full re-sync.
     * @return array{success: bool, stats: array}
     */
    public function execute(array $params = []): array
    {
        $api = Container::getApi();
        $repository = Container::getDestinationRepository();
        $service = new DestinationSyncService($api, $repository);

        if ($this->outputCallback !== null) {
            $service->setOutputCallback($this->outputCallback);
        }

        $fullSync = !empty($params['full']);
        $stats = $service->sync($fullSync);

        $this->outputRateLimitSummary($stats);

        return $this->wrapResult($stats);
    }
}

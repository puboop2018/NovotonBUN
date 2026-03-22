<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\PackageRouteSyncService;

/**
 * Cron command: sync package routes (flight/bus connections) from Sphinx API.
 *
 * Usage:
 *   php cron.php access_key=KEY mode=package_routes
 *   php cron.php access_key=KEY mode=package_routes departure_ids=197128,197775
 *   php cron.php access_key=KEY mode=package_routes destination_ids=87819,3713
 */
class PackageRouteSyncCommand extends AbstractSyncCommand
{
    public static function getDescription(): string
    {
        return 'Sync package routes (flight/bus) from Sphinx static API';
    }

    public function execute(array $params = []): array
    {
        $api = Container::getApi();
        $service = new PackageRouteSyncService($api);

        if ($this->outputCallback !== null) {
            $service->setOutputCallback($this->outputCallback);
        }

        $departureIds = [];
        if (!empty($params['departure_ids'])) {
            $departureIds = array_map('intval', array_filter(explode(',', $params['departure_ids'])));
        }

        $destinationIds = [];
        if (!empty($params['destination_ids'])) {
            $destinationIds = array_map('intval', array_filter(explode(',', $params['destination_ids'])));
        }

        $stats = $service->sync($departureIds, $destinationIds);

        $this->outputRateLimitSummary($stats);

        return $this->wrapResult($stats);
    }
}

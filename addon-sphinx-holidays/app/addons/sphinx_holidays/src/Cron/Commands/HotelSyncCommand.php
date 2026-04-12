<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\HotelSyncService;

/**
 * Cron command: sync hotels from Sphinx API.
 *
 * Filters by selected country codes from addon settings.
 * Supports CLI override: country=GR to sync a single country.
 *
 * Usage:
 *   php cron.php access_key=KEY mode=hotels
 *   php cron.php access_key=KEY mode=hotels country=GR
 *   php cron.php access_key=KEY mode=hotels country=GR,BG
 *   php cron.php access_key=KEY mode=hotels destination_ids=1234,5678
 *   php cron.php access_key=KEY mode=hotels full=1
 */
class HotelSyncCommand extends AbstractSyncCommand
{
    #[\Override]
    public static function getDescription(): string
    {
        return 'Sync hotels from Sphinx API (filtered by selected destinations)';
    }

    /**
     * Execute the hotel sync.
     *
     * @param array<string, mixed> $params CLI parameters. 'country' overrides settings.
     * @return array<string, mixed>
     */
    #[\Override]
    public function execute(array $params = []): array
    {
        $api = Container::getApi();
        $hotelRepo = Container::getHotelRepository();
        $destRepo = Container::getDestinationRepository();
        $service = new HotelSyncService($api, $hotelRepo, $destRepo);

        if ($this->outputCallback !== null) {
            $service->setOutputCallback($this->outputCallback);
        }

        // Allow CLI override: country=GR or country=GR,BG
        $countryCodes = [];
        if (!empty($params['country'])) {
            $countryCodes = array_filter(array_map(function ($c) {
                return strtoupper(trim($c));
            }, explode(',', $params['country'])));
        }

        // Allow CLI override: destination_ids=1234,5678
        $destinationIds = [];
        if (!empty($params['destination_ids'])) {
            $destinationIds = array_map('intval', array_filter(explode(',', $params['destination_ids'])));
        }

        if (empty($countryCodes) && empty($destinationIds)) {
            $countryCodes = ConfigProvider::getSelectedCountryCodes();
            $destinationIds = ConfigProvider::getAllowedDestinationIds();
        }

        $fullSync = !empty($params['full']);
        $stats = $service->sync($countryCodes, $destinationIds, $fullSync);

        $this->outputRateLimitSummary($stats);

        return $this->wrapResult($stats);
    }
}

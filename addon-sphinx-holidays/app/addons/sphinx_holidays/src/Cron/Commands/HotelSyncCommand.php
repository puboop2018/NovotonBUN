<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\HotelSyncService;
use Tygh\Addons\SphinxHolidays\Repository\HotelRepository;
use Tygh\Addons\SphinxHolidays\Repository\DestinationRepository;

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
 */
class HotelSyncCommand
{
    /** @var callable|null */
    private $outputCallback = null;

    public static function getDescription(): string
    {
        return 'Sync hotels from Sphinx API (filtered by selected destinations)';
    }

    public function setOutputCallback(callable $callback): void
    {
        $this->outputCallback = $callback;
    }

    /**
     * Execute the hotel sync.
     *
     * @param array $params CLI parameters. 'country' overrides settings.
     */
    public function execute(array $params = []): array
    {
        $api = Container::getApi();
        $hotelRepo = new HotelRepository();
        $destRepo = new DestinationRepository();
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

        if (empty($countryCodes)) {
            $countryCodes = ConfigProvider::getSelectedCountryCodes();
        }

        $stats = $service->sync($countryCodes);

        return [
            'success' => $stats['success'],
            'stats'   => $stats,
        ];
    }
}

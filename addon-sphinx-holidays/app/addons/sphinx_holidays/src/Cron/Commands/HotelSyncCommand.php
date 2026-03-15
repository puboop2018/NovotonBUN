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
 *   php cron.php access_key=KEY mode=hotels destination_ids=1234,5678
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

        // Allow CLI override: destination_ids=1234,5678
        $destinationIds = [];
        if (!empty($params['destination_ids'])) {
            $destinationIds = array_map('intval', array_filter(explode(',', $params['destination_ids'])));
        }

        if (empty($countryCodes) && empty($destinationIds)) {
            $targets = ConfigProvider::getSelectedSyncTargets();
            $countryCodes = $targets['country_codes'];
            $destinationIds = $targets['destination_ids'];
        }

        $stats = $service->sync($countryCodes, $destinationIds);

        // Output rate limit summary
        $this->outputRateLimitSummary($stats);

        return [
            'success' => $stats['success'],
            'stats'   => $stats,
        ];
    }
}

<?php
declare(strict_types=1);
namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
use Tygh\Addons\NovotonHolidays\HotelSync;

class HotelSyncCommand extends AbstractCronCommand
{
    public static function getModes(): array
    {
        return ['sync_hotels', 'sync_hotellist', 'sync_hotelinfo', 'sync_priceinfo'];
    }

    public static function getDescription(): string
    {
        return 'Hotel sync (hotel_list, hotelinfo, priceinfo)';
    }

    public function execute(): array
    {
        $mode = $this->params['_mode'] ?? 'sync_hotels';
        $country = $this->getParam('country');
        $limit = (int)$this->getParam('limit', 0);

        $sync = new HotelSync();
        $stats = [];

        switch ($mode) {
            case 'sync_hotels':
                $this->output("=== FULL HOTEL SYNC ===");
                $stats = $sync->fullSync($country, $limit);
                $this->output("");
                $this->output("Hotels processed: " . $stats['hotels_processed']);
                $this->output("Hotels updated: " . $stats['hotels_updated']);
                $this->output("Hotels failed: " . $stats['hotels_failed']);
                $this->output("Packages processed: " . $stats['packages_processed']);
                $this->output("Packages updated: " . $stats['packages_updated']);
                break;

            case 'sync_hotellist':
                $this->output("=== HOTEL LIST SYNC ===");
                $stats = $sync->syncHotelList($country);
                $this->output("");
                $this->output("Hotels processed: " . $stats['hotels_processed']);
                $this->output("Hotels updated: " . $stats['hotels_updated']);
                break;

            case 'sync_hotelinfo':
                $limit = $limit ?: 50;
                $this->output("=== HOTEL INFO SYNC ===");
                $stats = $sync->syncHotelInfo(null, $limit);
                $this->output("");
                $this->output("Hotels processed: " . $stats['hotels_processed']);
                $this->output("Hotels updated: " . $stats['hotels_updated']);
                $this->output("Packages updated: " . $stats['packages_updated']);
                break;

            case 'sync_priceinfo':
                $limit = $limit ?: 100;
                $this->output("=== PRICE INFO SYNC ===");
                $stats = $sync->syncPriceInfoOnly($limit);
                $this->output("");
                $this->output("Packages processed: " . $stats['packages_processed']);
                $this->output("Packages updated: " . $stats['packages_updated']);
                $this->output("Duration: " . ($stats['duration'] ?? 0) . " seconds");
                break;
        }

        if (!empty($stats['errors'])) {
            $this->output("Errors:");
            foreach (array_slice($stats['errors'], 0, 10) as $error) {
                $this->output("  - {$error}");
            }
        }

        $this->logComplete($mode, $stats ?? []);
        return ['success' => true, 'stats' => $stats ?? []];
    }
}

<?php
namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Registry;
use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
use Tygh\Addons\NovotonHolidays\HotelSync;

class V3SyncCommand extends AbstractCronCommand
{
    public static function getModes(): array
    {
        return ['sync_hotels', 'sync_hotellist', 'sync_hotelinfo', 'sync_priceinfo'];
    }

    public static function getDescription(): string
    {
        return 'V3 hotel sync (hotel_list, hotelinfo, priceinfo)';
    }

    public function execute(): array
    {
        $mode = $this->params['_mode'] ?? 'sync_hotels';
        $country = $this->getParam('country');
        $limit = (int)$this->getParam('limit', 0);

        // Load HotelSync class
        $src_dir = Registry::get('config.dir.addons') . 'novoton_holidays/src/';
        if (file_exists($src_dir . 'HotelSync.php')) {
            require_once($src_dir . 'HotelSync.php');
        }

        $sync = new HotelSync();

        switch ($mode) {
            case 'sync_hotels':
                $this->output("=== V3 FULL HOTEL SYNC ===");
                $stats = $sync->fullSync($country, $limit);
                $this->output("");
                $this->output("Hotels processed: " . $stats['hotels_processed']);
                $this->output("Hotels updated: " . $stats['hotels_updated']);
                $this->output("Hotels failed: " . $stats['hotels_failed']);
                $this->output("Packages processed: " . $stats['packages_processed']);
                $this->output("Packages updated: " . $stats['packages_updated']);
                break;

            case 'sync_hotellist':
                $this->output("=== V3 HOTEL LIST SYNC ===");
                $stats = $sync->syncHotelList($country);
                $this->output("");
                $this->output("Hotels processed: " . $stats['hotels_processed']);
                $this->output("Hotels updated: " . $stats['hotels_updated']);
                break;

            case 'sync_hotelinfo':
                $limit = $limit ?: 50;
                $this->output("=== V3 HOTEL INFO SYNC ===");
                $stats = $sync->syncHotelInfo(null, $limit);
                $this->output("");
                $this->output("Hotels processed: " . $stats['hotels_processed']);
                $this->output("Hotels updated: " . $stats['hotels_updated']);
                $this->output("Packages updated: " . $stats['packages_updated']);
                break;

            case 'sync_priceinfo':
                $limit = $limit ?: 100;
                $this->output("=== V3 PRICE INFO SYNC ===");
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

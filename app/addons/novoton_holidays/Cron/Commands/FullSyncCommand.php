<?php
namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
use Tygh\Addons\NovotonHolidays\Cron\Commands\ResInfoCommand;
use Tygh\Addons\NovotonHolidays\Cron\Commands\CleanupCommand;
use Tygh\Addons\NovotonHolidays\PriceInfoSync;

class FullSyncCommand extends AbstractCronCommand
{
    public static function getModes(): array
    {
        return ['full', 'update_prices'];
    }

    public static function getDescription(): string
    {
        return 'Full sync: prices + booking status + cleanup';
    }

    public function execute(): array
    {
        $mode = $this->params['_mode'] ?? 'full';

        if ($mode === 'update_prices') {
            $this->output("Updating hotel prices...");
            $this->output("This mode is resource-intensive. Use admin panel for full price update.");
            $this->output("URL: admin.php?dispatch=novoton_holidays.update_prices");
            return ['success' => true, 'stats' => []];
        }

        // 1. Sync prices
        $this->output("Syncing prices...");
        $sync = new PriceInfoSync();
        $stats = $sync->syncAllProducts();

        $this->output("");
        $this->output("=== SYNC COMPLETED ===");
        $this->output("Total products: " . $stats['total']);
        $this->output("Updated: " . count($stats['updated']));
        $this->output("Failed: " . count($stats['failed']));
        $this->output("No data: " . count($stats['no_data']));
        $this->output("======================");
        $this->output("");

        // 2. Check booking statuses
        $this->output("Checking booking statuses...");
        $resinfo = new ResInfoCommand($this->api, $this->logger);
        $resinfo->execute();
        $this->output("");

        // 3. Cleanup
        $this->output("Running cleanup tasks...");
        $cleanup = new CleanupCommand($this->api, $this->logger);
        $cleanup->execute();

        $this->logComplete('full', $stats);
        return ['success' => true, 'stats' => $stats];
    }
}

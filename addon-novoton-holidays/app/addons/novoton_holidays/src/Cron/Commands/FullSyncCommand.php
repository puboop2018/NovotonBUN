<?php
declare(strict_types=1);
namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
use Tygh\Addons\NovotonHolidays\Cron\Commands\ResInfoCommand;
use Tygh\Addons\NovotonHolidays\Cron\Commands\CleanupCommand;
use Tygh\Addons\NovotonHolidays\PriceInfoSync;

class FullSyncCommand extends AbstractCronCommand
{
    private ?PriceInfoSync $priceSync;
    private ?ResInfoCommand $resInfoCmd;
    private ?CleanupCommand $cleanupCmd;

    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        \Tygh\Addons\NovotonHolidays\Api\Contracts\NovotonApiKitInterface $api,
        ?\Tygh\Addons\NovotonHolidays\Helpers\SyncLogger $logger,
        array $params = [],
        ?PriceInfoSync $priceSync = null,
        ?ResInfoCommand $resInfoCmd = null,
        ?CleanupCommand $cleanupCmd = null,
    ) {
        parent::__construct($api, $logger, $params);
        $this->priceSync  = $priceSync;
        $this->resInfoCmd = $resInfoCmd;
        $this->cleanupCmd = $cleanupCmd;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public static function getModes(): array
    {
        return ['full', 'update_prices'];
    }

    #[\Override]
    public static function getDescription(): string
    {
        return 'Full sync: prices + booking status + cleanup';
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
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
        $sync = $this->priceSync ?? new PriceInfoSync();
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
        $resinfo = $this->resInfoCmd ?? new ResInfoCommand($this->api, $this->logger);
        $resinfo->execute();
        $this->output("");

        // 3. Cleanup
        $this->output("Running cleanup tasks...");
        $cleanup = $this->cleanupCmd ?? new CleanupCommand($this->api, $this->logger);
        $cleanup->execute();

        $this->logComplete('full', $stats);
        return ['success' => true, 'stats' => $stats];
    }
}

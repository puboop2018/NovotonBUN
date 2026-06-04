<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
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
        $this->priceSync = $priceSync;
        $this->resInfoCmd = $resInfoCmd;
        $this->cleanupCmd = $cleanupCmd;
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public static function getModes(): array
    {
        return ['full'];
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
        // 1. Sync prices
        $this->output('Syncing prices...');
        $sync = $this->priceSync ?? new PriceInfoSync();
        $stats = $sync->syncAllProducts();

        $this->output('');
        $this->output('=== SYNC COMPLETED ===');
        $this->output('Total products: ' . $stats['total']);
        $this->output('Updated: ' . count($stats['updated']));
        $this->output('Failed: ' . count($stats['failed']));
        $this->output('No data: ' . count($stats['no_data']));
        $this->output('======================');
        $this->output('');

        // 2. Check booking statuses
        $this->output('Checking booking statuses...');
        $resinfo = $this->resInfoCmd ?? new ResInfoCommand($this->api, $this->logger);
        $resinfo->execute();
        $this->output('');

        // 3. Cleanup
        $this->output('Running cleanup tasks...');
        $cleanup = $this->cleanupCmd ?? new CleanupCommand($this->api, $this->logger);
        $cleanup->execute();

        $this->logComplete('full', $stats);
        return ['success' => true, 'stats' => $stats];
    }
}

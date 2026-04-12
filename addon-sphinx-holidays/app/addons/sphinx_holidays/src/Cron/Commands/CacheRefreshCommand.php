<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\CacheEndpointService;

/**
 * Cron command: refresh cached hotel/package deals from Sphinx cache endpoints.
 *
 * Fetches fresh data from the Sphinx cache API and stores it locally
 * so frontend widgets (best_deals block) have up-to-date prices.
 *
 * Usage:
 *   php cron.php access_key=KEY mode=cache_refresh
 */
class CacheRefreshCommand extends AbstractSyncCommand
{
    #[\Override]
    public static function getDescription(): string
    {
        return 'Refresh cached hotel & package deals from Sphinx cache endpoints';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    #[\Override]
    public function execute(array $params = []): array
    {
        $this->output('Starting cache refresh...');

        try {
            $api = Container::getApi();
            $commission = ConfigProvider::getCommission();

            $service = new CacheEndpointService($api, $commission);
            $stats = $service->refreshAll();

            $this->output("Cache refresh complete: {$stats['hotels_count']} hotels, {$stats['packages_count']} packages, {$stats['errors']} errors");

            return [
                'success' => $stats['errors'] === 0,
                'stats'   => $stats,
            ];
        } catch (\Throwable $e) {
            $this->output("Cache refresh FAILED: " . $e->getMessage());
            fn_log_event('general', 'runtime', [
                'message' => 'Sphinx cache refresh failed: ' . $e->getMessage(),
            ]);

            return [
                'success' => false,
                'stats'   => ['errors' => 1, 'error_message' => $e->getMessage()],
            ];
        }
    }

}

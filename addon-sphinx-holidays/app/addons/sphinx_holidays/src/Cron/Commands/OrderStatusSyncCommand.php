<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\OrderStatusSyncService;

/**
 * Cron command: sync booking statuses from Sphinx Orders API.
 *
 * Polls GET /api/v1/orders for status changes on active bookings
 * and updates local records accordingly.
 *
 * Usage:
 *   php cron.php access_key=KEY mode=order_status
 */
class OrderStatusSyncCommand extends AbstractSyncCommand
{
    #[\Override]
    public static function getDescription(): string
    {
        return 'Check Sphinx booking statuses via Orders API';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    #[\Override]
    public function execute(array $params = []): array
    {
        $api = Container::getApi();
        $repo = Container::getBookingRepository();
        $service = new OrderStatusSyncService($api, $repo);

        if ($this->outputCallback !== null) {
            $service->setOutputCallback($this->outputCallback);
        }

        $stats = $service->syncAll();

        return [
            'success' => $stats['errors'] === 0,
            'stats'   => $stats,
        ];
    }
}

<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\DestinationSyncService;
use Tygh\Addons\SphinxHolidays\Repository\DestinationRepository;

/**
 * Cron command: sync destinations from Sphinx API.
 *
 * Usage:
 *   php cron.php access_key=KEY mode=destinations
 */
class DestinationSyncCommand
{
    /** @var callable|null */
    private $outputCallback = null;

    public static function getDescription(): string
    {
        return 'Sync destinations (countries, regions, cities) from Sphinx API';
    }

    /**
     * Set output callback for progress messages.
     */
    public function setOutputCallback(callable $callback): void
    {
        $this->outputCallback = $callback;
    }

    /**
     * Execute the destination sync.
     *
     * @param array $params CLI parameters (unused for now)
     * @return array{success: bool, stats: array}
     */
    public function execute(array $params = []): array
    {
        $api = Container::getApi();
        $repository = new DestinationRepository();
        $service = new DestinationSyncService($api, $repository);

        if ($this->outputCallback !== null) {
            $service->setOutputCallback($this->outputCallback);
        }

        $stats = $service->sync();

        return [
            'success' => $stats['success'],
            'stats' => $stats,
        ];
    }
}

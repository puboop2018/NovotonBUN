<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron;

use Tygh\Addons\SphinxHolidays\Cron\Commands\AddProductsCommand;
use Tygh\Addons\SphinxHolidays\Cron\Commands\DestinationSyncCommand;
use Tygh\Addons\SphinxHolidays\Cron\Commands\HotelSyncCommand;

/**
 * Dispatches cron jobs by mode name.
 *
 * Each mode maps to a Command class that implements execute().
 */
class CronDispatcher
{
    /**
     * Map of mode => command class.
     */
    private static array $modes = [
        'destinations'  => DestinationSyncCommand::class,
        'hotels'        => HotelSyncCommand::class,
        'add_products'  => AddProductsCommand::class,
    ];

    /**
     * Get all available modes with descriptions.
     *
     * @return array<string, string>
     */
    public static function getAvailableModes(): array
    {
        $result = [];
        foreach (self::$modes as $mode => $class) {
            $result[$mode] = $class::getDescription();
        }
        return $result;
    }

    /**
     * Check if a mode exists.
     */
    public function hasMode(string $mode): bool
    {
        return isset(self::$modes[$mode]);
    }

    /**
     * Dispatch a cron job by mode.
     *
     * @param string $mode The cron mode to execute
     * @param array $params Additional parameters
     * @return array Result from the command
     */
    public function dispatch(string $mode, array $params = []): array
    {
        if (!$this->hasMode($mode)) {
            return [
                'success' => false,
                'error' => "Unknown mode: {$mode}",
            ];
        }

        $class = self::$modes[$mode];
        $command = new $class();

        // Set output callback to echo progress
        $command->setOutputCallback(function (string $message) {
            echo $message . "\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        });

        return $command->execute($params);
    }
}

<?php
namespace Tygh\Addons\NovotonHolidays\Cron;

use Tygh\Addons\NovotonHolidays\Cron\Commands\ResInfoCommand;
use Tygh\Addons\NovotonHolidays\Cron\Commands\CleanupCommand;
use Tygh\Addons\NovotonHolidays\Cron\Commands\HotelListSyncCommand;
use Tygh\Addons\NovotonHolidays\Cron\Commands\V3SyncCommand;
use Tygh\Addons\NovotonHolidays\Cron\Commands\FullSyncCommand;
use Tygh\Addons\NovotonHolidays\Cron\Commands\RoomPriceCheckCommand;
use Tygh\Addons\NovotonHolidays\Cron\Commands\AlternativesCommand;
use Tygh\Addons\NovotonHolidays\Cron\Commands\OffersUpdateCommand;
use Tygh\Addons\NovotonHolidays\Cron\Commands\AddProductsCommand;
use Tygh\Addons\NovotonHolidays\Cron\Commands\DataSyncCommand;
use Tygh\Addons\NovotonHolidays\Cron\Commands\BatchedSyncCommand;

class CronDispatcher
{
    private static $commandMap = [];
    private static $registered = false;
    private $api;
    private $logger;

    public function __construct($api, $logger)
    {
        $this->api = $api;
        $this->logger = $logger;
        self::registerCommands();
    }

    private static function registerCommands(): void
    {
        if (self::$registered) {
            return;
        }

        $commands = [
            ResInfoCommand::class,
            CleanupCommand::class,
            HotelListSyncCommand::class,
            V3SyncCommand::class,
            FullSyncCommand::class,
            RoomPriceCheckCommand::class,
            AlternativesCommand::class,
            OffersUpdateCommand::class,
            AddProductsCommand::class,
            DataSyncCommand::class,
            BatchedSyncCommand::class,
        ];

        foreach ($commands as $class) {
            foreach ($class::getModes() as $mode) {
                self::$commandMap[$mode] = $class;
            }
        }

        self::$registered = true;
    }

    public function dispatch(string $mode, array $params = []): array
    {
        if (!isset(self::$commandMap[$mode])) {
            return ['success' => false, 'error' => "Unknown mode: {$mode}"];
        }

        $class = self::$commandMap[$mode];
        $command = new $class($this->api, $this->logger, array_merge($params, ['_mode' => $mode]));
        return $command->execute();
    }

    public function hasMode(string $mode): bool
    {
        return isset(self::$commandMap[$mode]);
    }

    public static function getAvailableModes(): array
    {
        self::registerCommands();

        $modes = [];
        foreach (self::$commandMap as $mode => $class) {
            $modes[$mode] = $class::getDescription();
        }
        return $modes;
    }
}

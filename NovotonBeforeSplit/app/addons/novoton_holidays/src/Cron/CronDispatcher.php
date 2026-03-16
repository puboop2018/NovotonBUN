<?php
declare(strict_types=1);
namespace Tygh\Addons\NovotonHolidays\Cron;

/**
 * Cron Command Dispatcher
 *
 * Auto-discovers command classes from the Cron/Commands/ directory.
 * Any class extending AbstractCronCommand is automatically registered
 * via its getModes() method. Adding a new command requires only creating
 * a new file in Commands/ — no modification to this class needed (OCP).
 *
 * @package NovotonHolidays
 * @since 3.3.0
 */
class CronDispatcher
{
    /** @var array<string, class-string<AbstractCronCommand>> mode => command class */
    private static array $commandMap = [];
    private static bool $registered = false;
    private \Tygh\Addons\NovotonHolidays\NovotonApi $api;
    private ?\Tygh\Addons\NovotonHolidays\Helpers\SyncLogger $logger;

    public function __construct(\Tygh\Addons\NovotonHolidays\NovotonApi $api, ?\Tygh\Addons\NovotonHolidays\Helpers\SyncLogger $logger)
    {
        $this->api = $api;
        $this->logger = $logger;
        self::registerCommands();
    }

    /**
     * Auto-discover and register all command classes from the Commands/ directory.
     */
    private static function registerCommands(): void
    {
        if (self::$registered) {
            return;
        }

        $commandsDir = __DIR__ . '/Commands/';
        if (!is_dir($commandsDir)) {
            self::$registered = true;
            return;
        }

        $namespace = 'Tygh\\Addons\\NovotonHolidays\\Cron\\Commands\\';

        foreach (glob($commandsDir . '*Command.php') as $file) {
            $className = $namespace . basename($file, '.php');

            if (!class_exists($className)) {
                require_once $file;
            }

            if (!class_exists($className) || !is_subclass_of($className, AbstractCronCommand::class)) {
                continue;
            }

            foreach ($className::getModes() as $mode) {
                self::$commandMap[$mode] = $className;
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

    /**
     * @return array<string, string> mode => description
     */
    public static function getAvailableModes(): array
    {
        self::registerCommands();

        $modes = [];
        foreach (self::$commandMap as $mode => $class) {
            $modes[$mode] = $class::getDescription();
        }
        return $modes;
    }

    /**
     * Reset for testing — forces re-discovery on next use.
     */
    public static function reset(): void
    {
        self::$commandMap = [];
        self::$registered = false;
    }
}

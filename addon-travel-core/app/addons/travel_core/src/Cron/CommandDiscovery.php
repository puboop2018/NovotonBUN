<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Cron;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Auto-discovery of cron command classes — the OCP registration both
 * provider dispatchers used to carry as identical private copies: glob the
 * Commands/ directory, load each *Command.php, and register every subclass
 * of the shared AbstractCronCommand base under the modes it declares.
 *
 * Dispatchers stay the owners of everything else (locking, execution,
 * result normalization, per-addon constructor wiring).
 */
final class CommandDiscovery
{
    /**
     * @template T of AbstractCronCommand
     *
     * @param string $commandsDir Absolute path of the addon's Cron/Commands dir
     * @param string $namespace Fully-qualified namespace of that dir, with trailing backslashes
     * @param class-string<T> $baseClass Addon-level abstract the commands extend
     *
     * @return array<string, class-string<T>> mode => command class
     */
    public static function map(string $commandsDir, string $namespace, string $baseClass): array
    {
        $map = [];

        if (!is_dir($commandsDir)) {
            return $map;
        }

        foreach (glob(rtrim($commandsDir, '/') . '/*Command.php') ?: [] as $file) {
            /** @var class-string<T> $className */
            $className = $namespace . basename($file, '.php');

            if (!class_exists($className)) {
                require_once $file;
            }

            if (!class_exists($className) || !is_subclass_of($className, $baseClass)) {
                continue;
            }

            foreach ($className::getModes() as $mode) {
                $map[TypeCoerce::toString($mode)] = $className;
            }
        }

        return $map;
    }
}

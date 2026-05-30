<?php

declare(strict_types=1);

/**
 * Travel Core Debug Logger
 *
 * Centralized debug-logging helper. Provider addons can set the debug
 * constant (e.g. TRAVEL_DEBUG) or pass a callable to check debug state.
 *
 * @package TravelCore
 * @since   1.0.0
 */

namespace Tygh\Addons\TravelCore\Helpers;

class DebugLogger
{
    /** @var bool|null Cached result */
    private static ?bool $enabled = null;

    /** @var callable|null Custom checker */
    private static $checker = null;

    /** @var string Last error captured while attaching an image to a product. */
    public static string $lastImageAttachError = '';

    /**
     * Set a custom debug-enabled checker.
     *
     * @param callable $checker Returns bool
     */
    public static function setChecker(callable $checker): void
    {
        self::$checker = $checker;
        self::$enabled = null;
    }

    /**
     * Whether debug logging is active.
     */
    public static function isEnabled(): bool
    {
        if (self::$enabled === null) {
            if (self::$checker !== null) {
                self::$enabled = (bool)(self::$checker)();
            } else {
                self::$enabled = defined('TRAVEL_DEBUG') || defined('NOVOTON_DEBUG');
            }
        }
        return self::$enabled;
    }

    /**
     * Log a debug message via fn_log_event when debug is enabled.
     *
     * @param array<string, mixed> $context
     */
    public static function log(string $message, array $context = []): void
    {
        if (!self::isEnabled()) {
            return;
        }

        fn_log_event('general', 'runtime', array_merge(
            ['message' => $message],
            $context,
        ));
    }

    /**
     * Reset the cached state.
     */
    public static function reset(): void
    {
        self::$enabled = null;
    }
}

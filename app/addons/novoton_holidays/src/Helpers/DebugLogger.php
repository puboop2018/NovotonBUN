<?php
declare(strict_types=1);
/**
 * Centralized debug-logging helper.
 *
 * Replaces the repeated pattern:
 *   if (defined('NOVOTON_DEBUG') || ConfigProvider::isDebugLogging()) { ... }
 *
 * Usage:
 *   DebugLogger::log('Novoton frmsearch Request', ['xml' => $xml]);
 *   if (DebugLogger::isEnabled()) { ... }
 *
 * @package NovotonHolidays
 * @since   3.6.0
 */

namespace Tygh\Addons\NovotonHolidays\Helpers;

use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;

class DebugLogger
{
    /** @var bool|null Cached result so we don't re-evaluate per call */
    private static ?bool $enabled = null;

    /**
     * Whether debug logging is active (NOVOTON_DEBUG constant OR addon setting).
     */
    public static function isEnabled(): bool
    {
        if (self::$enabled === null) {
            self::$enabled = defined('NOVOTON_DEBUG') || ConfigProvider::isDebugLogging();
        }
        return self::$enabled;
    }

    /**
     * Log a debug message via fn_log_event when debug is enabled.
     *
     * @param string $message  Human-readable summary
     * @param array  $context  Arbitrary key-value context (will appear in log)
     */
    public static function log(string $message, array $context = []): void
    {
        if (!self::isEnabled()) {
            return;
        }

        fn_log_event('general', 'runtime', array_merge(
            ['message' => $message],
            $context
        ));
    }

    /**
     * Reset the cached state (useful in tests).
     */
    public static function reset(): void
    {
        self::$enabled = null;
    }
}

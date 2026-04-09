<?php
declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Cron;

/**
 * Shared base class for cron commands across all travel provider addons.
 *
 * Provides common infrastructure: output handling, duration tracking,
 * error-safe item processing, and result wrapping. Provider addons
 * extend this with addon-specific methods (logging, reporting, etc.).
 */
abstract class AbstractCronCommand
{
    protected ?\Closure $outputCallback = null;
    protected float $startTime;

    public function __construct()
    {
        $this->startTime = microtime(true);
    }

    /**
     * Execute the cron command.
     *
     * @return array Command result with at minimum a 'success' key
     */
    abstract public function execute(): array;

    /**
     * Human-readable description for CLI help output.
     */
    abstract public static function getDescription(): string;

    /**
     * Set a callback for output messages (used in web/CLI contexts).
     */
    public function setOutputCallback(\Closure $callback): void
    {
        $this->outputCallback = $callback;
    }

    /**
     * Emit an output message via the callback, or fall back to fn_log_event.
     */
    protected function output(string $message): void
    {
        if ($this->outputCallback !== null) {
            ($this->outputCallback)($message);
            return;
        }

        if (function_exists('fn_log_event')) {
            fn_log_event('general', 'runtime', [
                'message' => 'CronCommand: ' . $message,
            ]);
        }
    }

    /**
     * Elapsed seconds since command construction, rounded to 1 decimal.
     */
    protected function getDuration(): float
    {
        return round(microtime(true) - $this->startTime, 1);
    }

    /**
     * Execute a sync operation with standardized error handling.
     *
     * Catches all Throwable, logs consistently. Returns true on success.
     *
     * @param callable $work    The operation to execute
     * @param string   $context Human-readable context for error messages
     * @param array    &$errors Array to collect error messages
     */
    protected function trySyncItem(callable $work, string $context, array &$errors): bool
    {
        try {
            $work();
            return true;
        } catch (\Throwable $e) {
            $errors[] = "Error for {$context}: " . $e->getMessage();
            return false;
        }
    }

    /**
     * Wrap command stats into a standardized result array.
     */
    protected function wrapResult(array $stats): array
    {
        return [
            'success' => $stats['success'] ?? true,
            'stats'   => $stats,
        ];
    }
}

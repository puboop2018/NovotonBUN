<?php
declare(strict_types=1);
/**
 * Sync Logger Interface
 *
 * Contract for sync operation logging, progress output, and reporting.
 *
 * @package NovotonHolidays
 * @since 3.5.0
 */

namespace Tygh\Addons\NovotonHolidays\Helpers;

interface SyncLoggerInterface
{
    /**
     * Set output callback for custom output handling.
     *
     * @param callable $callback
     */
    public function setOutputCallback(callable $callback): void;

    /**
     * Output a message to console/callback.
     *
     * @param string $message
     * @param bool   $newline
     */
    public function output(string $message, bool $newline = true): void;

    /**
     * Output header for cron job start.
     *
     * @param string|null $mode Mode name (uses syncType if not provided)
     */
    public function outputHeader(?string $mode = null): void;

    /**
     * Output footer for cron job end.
     */
    public function outputFooter(): void;

    /**
     * Output progress indicator.
     *
     * @param int    $current
     * @param int    $total
     * @param string $prefix
     */
    public function outputProgress(int $current, int $total, string $prefix = ''): void;

    /**
     * Output summary statistics.
     *
     * @param array|null $stats Custom stats (uses internal stats if not provided)
     */
    public function outputSummary(?array $stats = null): void;

    /**
     * Increment a statistic counter.
     *
     * @param string $key
     * @param int    $amount
     */
    public function increment(string $key, int $amount = 1): void;

    /**
     * Set a statistic value.
     *
     * @param string $key
     * @param mixed  $value
     */
    public function setStat(string $key, $value): void;

    /**
     * Get current statistics.
     *
     * @return array
     */
    public function getStats(): array;

    /**
     * Get elapsed time in seconds.
     *
     * @return float
     */
    public function getElapsedTime(): float;

    /**
     * Get formatted duration string.
     *
     * @return string
     */
    public function getFormattedDuration(): string;

    /**
     * Get formatted current datetime.
     *
     * @return string
     */
    public function getFormattedDateTime(): string;

    /**
     * Log to database sync_log table.
     *
     * @param string|null $status Status: 'completed', 'failed', 'in_progress'
     * @param array       $extra  Additional data
     * @return int|false Log ID or false on failure
     */
    public function logToDatabase(?string $status = 'completed', array $extra = []): int|false;

    /**
     * Send email report.
     *
     * @param array  $results Detailed results for CSV attachment
     * @param string $country
     * @return bool
     */
    public function sendEmailReport(array $results = [], string $country = ''): bool;

    /**
     * Log event using CS-Cart's fn_log_event.
     *
     * @param string $action
     * @param array  $context
     */
    public function logEvent(string $action, array $context = []): void;

    /**
     * Complete sync: log to database and optionally send email.
     *
     * @param bool   $sendEmail
     * @param string $country
     * @param array  $extra
     * @return array Result with log_id and email_sent
     */
    public function complete(bool $sendEmail = true, string $country = '', array $extra = []): array;

    /**
     * Get all collected messages.
     *
     * @return array
     */
    public function getMessages(): array;

    /**
     * Clear collected messages.
     */
    public function clearMessages(): void;

    /**
     * Get type label for display.
     *
     * @param string|null $type Sync type (uses current if not provided)
     * @return string
     */
    public static function getTypeLabel(?string $type = null): string;
}

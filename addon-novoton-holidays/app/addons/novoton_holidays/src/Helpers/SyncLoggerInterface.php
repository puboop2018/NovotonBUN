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
     */
    public function setOutputCallback(callable $callback): void;

    /**
     * Output a message to console/callback.
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
     */
    public function outputProgress(int $current, int $total, string $prefix = ''): void;

    /**
     * Output summary statistics.
     *
     * @param array<string, mixed>|null $stats Custom stats (uses internal stats if not provided)
     */
    public function outputSummary(?array $stats = null): void;

    /**
     * Increment a statistic counter.
     */
    public function increment(string $key, int $amount = 1): void;

    /**
     * Set a statistic value.
     *
     * @param mixed $value
     */
    public function setStat(string $key, $value): void;

    /**
     * Get current statistics.
     *
     * @return array<string, mixed>
     */
    public function getStats(): array;

    /**
     * Get elapsed time in seconds.
     */
    public function getElapsedTime(): float;

    /**
     * Get formatted duration string.
     */
    public function getFormattedDuration(): string;

    /**
     * Get formatted current datetime.
     */
    public function getFormattedDateTime(): string;

    /**
     * Log to database sync_log table.
     *
     * @param string|null $status Status: 'completed', 'failed', 'in_progress'
     * @param array<string, mixed> $extra Additional data
     * @return int|false Log ID or false on failure
     */
    public function logToDatabase(?string $status = 'completed', array $extra = []): int|false;

    /**
     * Send email report.
     *
     * @param array<string, mixed> $results Detailed results for CSV attachment
     */
    public function sendEmailReport(array $results = [], string $country = ''): bool;

    /**
     * Log event using CS-Cart's fn_log_event.
     *
     * @param array<string, mixed> $context
     */
    public function logEvent(string $action, array $context = []): void;

    /**
     * Complete sync: log to database and optionally send email.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed> Result with log_id and email_sent
     */
    public function complete(bool $sendEmail = true, string $country = '', array $extra = []): array;

    /**
     * Get all collected messages.
     *
     * @return list<string>
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
     */
    public static function getTypeLabel(?string $type = null): string;
}

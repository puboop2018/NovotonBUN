<?php

declare(strict_types=1);

/**
 * Novoton Holidays - Sync Logger
 *
 * Unified logging for all sync operations. Consolidates:
 * - Console output (echo)
 * - Database sync_log entries
 * - Email reports
 * - Event logging (fn_log_event)
 *
 * @package NovotonHolidays
 * @since 3.1.0
 */

namespace Tygh\Addons\NovotonHolidays\Helpers;

use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

class SyncLogger implements SyncLoggerInterface
{
    /**
     * Sync type labels for email subjects
     */
    public const array TYPE_LABELS = [
        'hotel_list' => 'Hotel List Sync',
        'hotellist' => 'Hotel List Sync',
        'hotel_info' => 'Hotel Accommodation',
        'hotelinfo' => 'Hotel Accommodation',
        'hotel_info_batched' => 'Batched Hotel Info',
        'room_price' => 'Price Check',
        'prices' => 'Price Check',
        'priceinfo' => 'Price Info Sync',
        'sync_priceinfo_batched' => 'Batched Price Info',
        'add_products' => 'Add Hotels as Products',
        'product_import' => 'Product Import',
        'offers_update' => 'Offers Update',
        'facilities' => 'Facilities Sync',
        'resinfo' => 'Booking Status Check',
        'exchange_rates' => 'Exchange Rates Update',
        'manual' => 'Manual Import',
        'cron' => 'Cron Sync',
    ];

    /**
     * Current sync type
     */
    private string $syncType;

    /**
     * Start time for duration calculation
     */
    private float $startTime;

    /**
     * Timezone for output
     */
    private string $timezone;

    /**
     * Output callback (optional, for custom output handling)
     * @var callable|null
     */
    private $outputCallback = null;

    /**
     * Collected messages for later retrieval
     * @var list<string>
     */
    private array $messages = [];

    /**
     * Statistics for the current sync
     * @var array<string, mixed>
     */
    private array $stats = [
        'total' => 0,
        'added' => 0,
        'updated' => 0,
        'synced' => 0,
        'skipped' => 0,
        'errors' => 0,
        'failed' => 0,
    ];

    /**
     * Constructor
     *
     * @param string $syncType Type of sync operation
     */
    public function __construct(string $syncType = 'cron')
    {
        $this->syncType = $syncType;
        $this->startTime = microtime(true);
        $this->timezone = ConfigProvider::getTimezone();
    }

    /**
     * Set output callback for custom output handling
     */
    public function setOutputCallback(callable $callback): void
    {
        $this->outputCallback = $callback;
    }

    /**
     * Output a message to console/callback
     *
     * @param bool $newline Add newline at end
     */
    public function output(string $message, bool $newline = true): void
    {
        $formatted = $message . ($newline ? "\n" : '');

        $this->messages[] = $message;

        if ($this->outputCallback !== null) {
            call_user_func($this->outputCallback, $formatted);
        } else {
            echo $formatted;
            flush();
        }
    }

    /**
     * Output header for cron job start
     *
     * @param string|null $mode Mode name (uses syncType if not provided)
     */
    public function outputHeader(?string $mode = null): void
    {
        $mode ??= $this->syncType;
        $datetime = $this->getFormattedDateTime();

        $this->output('===========================================');
        $this->output('NOVOTON HOLIDAYS CRON - ' . strtoupper($mode));
        $this->output('===========================================');
        $this->output("Time: {$datetime} ({$this->timezone})");
        $this->output('');
    }

    /**
     * Output footer for cron job end
     */
    public function outputFooter(): void
    {
        $datetime = $this->getFormattedDateTime();

        $this->output('');
        $this->output('===========================================');
        $this->output("Completed at: {$datetime} ({$this->timezone})");
        $this->output('===========================================');
    }

    /**
     * Output progress indicator
     *
     * @param int $current Current item number
     * @param int $total Total items
     * @param string $prefix Optional prefix text
     */
    public function outputProgress(int $current, int $total, string $prefix = ''): void
    {
        $percent = $total > 0 ? round($current / $total * 100, 1) : 0;
        $message = $prefix !== '' ? "{$prefix}: " : '';
        $message .= "--- Progress: {$current}/{$total} ({$percent}%) ---";
        $this->output($message);
    }

    /**
     * Output summary statistics
     *
     * @param array<string, mixed>|null $stats Custom stats (uses internal stats if not provided)
     */
    public function outputSummary(?array $stats = null): void
    {
        $s = $stats ?? $this->stats;

        $this->output('');
        $this->output('========================================');
        $this->output('SYNC COMPLETED');
        $this->output('========================================');

        $total = isset($s['total']) ? TypeCoerce::toInt($s['total']) : 0;
        if ($total > 0) {
            $this->output("Total: {$total}");
        }
        $synced = isset($s['synced']) ? TypeCoerce::toInt($s['synced']) : 0;
        if ($synced > 0) {
            $this->output("Synced: {$synced}");
        }
        $added = isset($s['added']) ? TypeCoerce::toInt($s['added']) : 0;
        if ($added > 0) {
            $this->output("Added: {$added}");
        }
        $updated = isset($s['updated']) ? TypeCoerce::toInt($s['updated']) : 0;
        if ($updated > 0) {
            $this->output("Updated: {$updated}");
        }
        $skipped = isset($s['skipped']) ? TypeCoerce::toInt($s['skipped']) : 0;
        if ($skipped > 0) {
            $this->output("Skipped: {$skipped}");
        }
        $errors = isset($s['errors']) ? TypeCoerce::toInt($s['errors']) : 0;
        if ($errors > 0) {
            $this->output("Errors: {$errors}");
        }

        $this->output('Duration: ' . $this->getFormattedDuration());
        $this->output('========================================');
    }

    /**
     * Increment a statistic counter
     *
     * @param string $key Stat key
     * @param int $amount Amount to increment
     */
    public function increment(string $key, int $amount = 1): void
    {
        if (!isset($this->stats[$key])) {
            $this->stats[$key] = 0;
        }
        $this->stats[$key] = TypeCoerce::toInt($this->stats[$key]) + $amount;
    }

    /**
     * Set a statistic value
     *
     * @param string $key Stat key
     * @param mixed $value Value
     */
    public function setStat(string $key, $value): void
    {
        $this->stats[$key] = $value;
    }

    /**
     * Get current statistics
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * Get elapsed time in seconds
     */
    public function getElapsedTime(): float
    {
        return microtime(true) - $this->startTime;
    }

    /**
     * Get formatted duration string
     */
    public function getFormattedDuration(): string
    {
        $seconds = (int)$this->getElapsedTime();

        if ($seconds < 60) {
            return "{$seconds}s";
        }
        if ($seconds < 3600) {
            $m = floor($seconds / 60);
            $s = $seconds % 60;
            return "{$m}m {$s}s";
        }

        $h = floor($seconds / 3600);
        $m = floor(($seconds % 3600) / 60);
        return "{$h}h {$m}m";
    }

    /**
     * Get formatted current datetime
     */
    public function getFormattedDateTime(): string
    {
        return (new \DateTime('now', new \DateTimeZone($this->timezone)))
            ->format('Y-m-d H:i:s');
    }

    /**
     * Log to database sync_log table
     *
     * @param string|null $status Status: 'completed', 'failed', 'in_progress'
     * @param array<string, mixed> $extra Extra data to store in notes
     * @return int|false Log ID or false on failure
     */
    public function logToDatabase(?string $status = 'completed', array $extra = []): int|false
    {
        $duration = round($this->getElapsedTime(), 1);
        $stats = $this->stats;

        $notes = !empty($extra) ? json_encode($extra) : '';

        $total = TypeCoerce::toInt($stats['total'] ?? 0);
        $updated = TypeCoerce::toInt($stats['synced'] ?? 0)
            + TypeCoerce::toInt($stats['added'] ?? 0)
            + TypeCoerce::toInt($stats['updated'] ?? 0);
        $failed = TypeCoerce::toInt($stats['errors'] ?? 0)
            + TypeCoerce::toInt($stats['failed'] ?? 0);

        $result = db_query(
            'INSERT INTO ?:novoton_sync_log SET
             sync_type = ?s,
             sync_date = NOW(),
             products_total = ?i,
             products_updated = ?i,
             products_failed = ?i,
             duration_seconds = ?i,
             status = ?s,
             notes = ?s',
            $this->syncType,
            $total,
            $updated,
            $failed,
            $duration,
            $status,
            $notes,
        );

        return is_int($result) ? $result : false;
    }

    /**
     * Send email report
     *
     * @param array<string, mixed> $results Detailed results for CSV attachment (optional)
     * @param string $country Country or countries
     */
    public function sendEmailReport(array $results = [], string $country = ''): bool
    {
        $summary = array_merge($this->stats, [
            'duration' => $this->getFormattedDuration(),
        ]);

        return fn_novoton_holidays_send_import_report_email($results, $this->syncType, $summary, $country);
    }

    /**
     * Log event using CS-Cart's fn_log_event
     *
     * @param string $action Action name
     * @param array<string, mixed> $context Additional context
     */
    public function logEvent(string $action, array $context = []): void
    {
        fn_log_event('novoton_holidays', $action, array_merge([
            'sync_type' => $this->syncType,
            'timestamp' => time(),
        ], $context));
    }

    /**
     * Complete sync: log to database and optionally send email
     *
     * @param bool $sendEmail Whether to send email report
     * @param string $country Country for email
     * @param array<string, mixed> $extra Extra data for database log
     * @return array<string, mixed> Result with log_id and email_sent
     */
    public function complete(bool $sendEmail = true, string $country = '', array $extra = []): array
    {
        $status = ($this->stats['errors'] ?? 0) > 0 ? 'completed_with_errors' : 'completed';

        $logId = $this->logToDatabase($status, $extra);

        $emailSent = false;
        if ($sendEmail) {
            $emailSent = $this->sendEmailReport([], $country);
        }

        return [
            'log_id' => $logId,
            'email_sent' => $emailSent,
            'duration' => $this->getFormattedDuration(),
            'stats' => $this->stats,
        ];
    }

    /**
     * Get type label for display
     *
     * @param string|null $type Sync type (uses current if not provided)
     */
    public static function getTypeLabel(?string $type = null): string
    {
        $type ??= 'cron';
        return self::TYPE_LABELS[$type] ?? ucfirst(str_replace('_', ' ', $type));
    }

    /**
     * Get all collected messages
     *
     * @return list<string>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * Clear collected messages
     */
    public function clearMessages(): void
    {
        $this->messages = [];
    }
}

<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

/**
 * Cron command: clean up orphan data, old logs, and expired cache.
 *
 * Usage: php cron.php access_key=KEY mode=cleanup
 */
class CleanupCommand
{
    /** @var callable|null */
    private $outputCallback = null;

    public static function getDescription(): string
    {
        return 'Clean up orphan bookings, old sync logs, and expired cache entries';
    }

    public function setOutputCallback(callable $callback): void
    {
        $this->outputCallback = $callback;
    }

    public function execute(array $params = []): array
    {
        $startMs = (int)(microtime(true) * 1000);

        $this->output('Starting cleanup...');

        $cleaned = [
            'orphan_bookings' => 0,
            'old_logs' => 0,
            'expired_cache' => 0,
        ];
        $errors = 0;

        // 1. Remove orphan bookings (order_id = 0, created more than 48h ago)
        try {
            db_query(
                "DELETE FROM ?:sphinx_bookings WHERE order_id = 0 AND created_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)"
            );
            $cleaned['orphan_bookings'] = (int)db_affected_rows();
            $this->output("Orphan bookings removed: {$cleaned['orphan_bookings']}");
        } catch (\Throwable $e) {
            $errors++;
            $this->output("Orphan bookings cleanup failed: " . $e->getMessage());
            fn_log_event('general', 'runtime', ['message' => 'Sphinx cleanup: orphan bookings failed: ' . $e->getMessage()]);
        }

        // 2. Trim sync log — keep latest 200 entries, delete older ones
        try {
            $cutoffId = (int)db_get_field(
                "SELECT log_id FROM ?:sphinx_sync_log ORDER BY log_id DESC LIMIT 1 OFFSET 200"
            );
            if ($cutoffId > 0) {
                db_query("DELETE FROM ?:sphinx_sync_log WHERE log_id <= ?i", $cutoffId);
                $cleaned['old_logs'] = (int)db_affected_rows();
            }
            $this->output("Old sync log entries removed: {$cleaned['old_logs']}");
        } catch (\Throwable $e) {
            $errors++;
            $this->output("Sync log cleanup failed: " . $e->getMessage());
            fn_log_event('general', 'runtime', ['message' => 'Sphinx cleanup: sync log failed: ' . $e->getMessage()]);
        }

        // 3. Delete expired cache entries
        try {
            db_query(
                "DELETE FROM ?:sphinx_cache WHERE expires_at < ?i",
                time()
            );
            $cleaned['expired_cache'] = (int)db_affected_rows();
            $this->output("Expired cache entries removed: {$cleaned['expired_cache']}");
        } catch (\Throwable $e) {
            $errors++;
            $this->output("Cache cleanup failed: " . $e->getMessage());
            fn_log_event('general', 'runtime', ['message' => 'Sphinx cleanup: cache cleanup failed: ' . $e->getMessage()]);
        }

        $durationMs = (int)(microtime(true) * 1000) - $startMs;
        $total = array_sum($cleaned);
        $this->output("Cleanup complete: {$total} items removed in " . round($durationMs / 1000, 1) . "s");

        return [
            'success' => $errors === 0,
            'stats'   => [
                'total' => $total,
                'synced' => $total,
                'failed' => $errors,
                'duration_ms' => $durationMs,
                'details' => $cleaned,
            ],
        ];
    }

    private function output(string $message): void
    {
        if ($this->outputCallback !== null) {
            ($this->outputCallback)($message);
        }
    }
}

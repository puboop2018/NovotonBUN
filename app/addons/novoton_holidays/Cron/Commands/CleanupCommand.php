<?php
namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;

class CleanupCommand extends AbstractCronCommand
{
    public static function getModes(): array
    {
        return ['cleanup'];
    }

    public static function getDescription(): string
    {
        return 'Clean orphan bookings, old logs, and expired cache';
    }

    public function execute(): array
    {
        $this->output("=== NOVOTON CLEANUP ===");
        $this->output("");

        // 1. Clean orphan bookings (no order_id, older than 48h)
        $this->output("1. Cleaning orphan bookings...");
        $orphan_count = (int)db_get_field(
            "SELECT COUNT(*) FROM ?:novoton_bookings
             WHERE order_id = 0
             AND created_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)"
        );
        db_query(
            "DELETE FROM ?:novoton_bookings
             WHERE order_id = 0
             AND created_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)"
        );
        $this->output("   Orphan bookings deleted: {$orphan_count}");
        $this->output("");

        // 2. Clean old sync logs (keep last 100)
        $this->output("2. Cleaning old sync logs...");
        $total_logs = (int)db_get_field("SELECT COUNT(*) FROM ?:novoton_sync_log");
        $logs_to_keep = 100;
        $logs_deleted = 0;

        if ($total_logs > $logs_to_keep) {
            $threshold_id = db_get_field(
                "SELECT log_id FROM ?:novoton_sync_log
                 ORDER BY sync_date DESC
                 LIMIT 1 OFFSET ?i",
                $logs_to_keep - 1
            );
            if ($threshold_id) {
                $logs_deleted = (int)db_query(
                    "DELETE FROM ?:novoton_sync_log WHERE log_id < ?i",
                    $threshold_id
                );
            }
        }
        $this->output("   Total: {$total_logs}, Kept: {$logs_to_keep}, Deleted: {$logs_deleted}");
        $this->output("");

        // 3. Clean expired cache entries
        $this->output("3. Cleaning expired cache...");
        $expired_count = (int)db_get_field(
            "SELECT COUNT(*) FROM ?:novoton_cache WHERE expires_at < NOW()"
        );
        db_query("DELETE FROM ?:novoton_cache WHERE expires_at < NOW()");
        $this->output("   Expired cache entries deleted: {$expired_count}");
        $this->output("");

        $stats = [
            'orphans_deleted' => $orphan_count,
            'logs_deleted' => $logs_deleted,
            'cache_deleted' => $expired_count,
        ];
        $this->logComplete('cleanup', $stats);

        return ['success' => true, 'stats' => $stats];
    }
}

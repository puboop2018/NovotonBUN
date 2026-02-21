<?php
declare(strict_types=1);
namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
use Tygh\Addons\NovotonHolidays\Repository\BookingRepository;
use Tygh\Addons\NovotonHolidays\Repository\SyncLogRepository;

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
        $bookingRepo = new BookingRepository();
        $orphan_count = $bookingRepo->countOrphans(48);
        $bookingRepo->deleteOrphans(48);
        $this->output("   Orphan bookings deleted: {$orphan_count}");
        $this->output("");

        // 2. Clean old sync logs (keep last 100)
        $this->output("2. Cleaning old sync logs...");
        $syncRepo = new SyncLogRepository();
        $total_logs = $syncRepo->count();
        $logs_to_keep = 100;
        $logs_deleted = $syncRepo->trimToLatest($logs_to_keep);
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

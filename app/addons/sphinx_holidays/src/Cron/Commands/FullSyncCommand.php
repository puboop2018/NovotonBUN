<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Cron\CronDispatcher;

/**
 * Cron command: run all sync modes in sequence.
 *
 * Executes the complete sync pipeline:
 *   destinations → hotels → package_routes → circuits → experiences → order_status → cache_refresh → exchange_rates → cleanup
 *
 * Usage: php cron.php access_key=KEY mode=full
 */
class FullSyncCommand
{
    /** @var callable|null */
    private $outputCallback = null;

    /**
     * Ordered list of modes to execute in sequence.
     * 'full' itself is excluded to prevent recursion.
     * 'add_products' is excluded as it's a manual-only action.
     */
    private const SYNC_SEQUENCE = [
        'destinations',
        'hotels',
        'package_routes',
        'circuits',
        'experiences',
        'order_status',
        'cache_refresh',
        'exchange_rates',
        'cleanup',
    ];

    public static function getDescription(): string
    {
        return 'Run all sync modes in sequence (full pipeline)';
    }

    public function setOutputCallback(callable $callback): void
    {
        $this->outputCallback = $callback;
    }

    public function execute(array $params = []): array
    {
        $startMs = (int)(microtime(true) * 1000);

        $this->output('=== FULL SYNC PIPELINE ===');
        $this->output('Modes: ' . implode(' → ', self::SYNC_SEQUENCE));
        $this->output('');

        $dispatcher = new CronDispatcher();
        $results = [];
        $totalSuccess = 0;
        $totalFailed = 0;

        foreach (self::SYNC_SEQUENCE as $mode) {
            if (!$dispatcher->hasMode($mode)) {
                $this->output("[SKIP] {$mode} — mode not registered");
                continue;
            }

            $this->output("──── {$mode} ────");
            $result = $dispatcher->dispatch($mode, $params);

            $success = $result['success'] ?? false;
            $results[$mode] = $success;

            if ($success) {
                $totalSuccess++;
            } else {
                $totalFailed++;
                $error = $result['error'] ?? $result['stats']['error'] ?? 'unknown error';
                $this->output("[WARN] {$mode} finished with errors: {$error}");
            }

            $this->output('');
        }

        $durationMs = (int)(microtime(true) * 1000) - $startMs;

        $this->output('=== FULL SYNC COMPLETE ===');
        $this->output("Results: {$totalSuccess} succeeded, {$totalFailed} failed");
        $this->output('Duration: ' . round($durationMs / 1000, 1) . 's');

        // Log the composite result
        db_query(
            "INSERT INTO ?:sphinx_sync_log (sync_type, status, items_total, items_synced, items_failed, duration_ms, started_at, completed_at) VALUES ('full', ?s, ?i, ?i, ?i, ?i, NOW(), NOW())",
            $totalFailed === 0 ? 'completed' : 'failed',
            count(self::SYNC_SEQUENCE),
            $totalSuccess,
            $totalFailed,
            $durationMs
        );

        return [
            'success' => $totalFailed === 0,
            'stats'   => [
                'total' => count(self::SYNC_SEQUENCE),
                'synced' => $totalSuccess,
                'failed' => $totalFailed,
                'duration_ms' => $durationMs,
                'modes' => $results,
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

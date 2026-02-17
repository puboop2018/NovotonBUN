<?php
namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
use Tygh\Addons\NovotonHolidays\Helpers\Config;

class BatchedSyncCommand extends AbstractCronCommand
{
    public static function getModes(): array
    {
        return ['hotel_info_batched', 'sync_priceinfo_batched'];
    }

    public static function getDescription(): string
    {
        return 'Batched sync with resume capability (hotel info / price info)';
    }

    public function execute(): array
    {
        $mode = $this->params['_mode'] ?? 'hotel_info_batched';

        if ($mode === 'hotel_info_batched') {
            return $this->hotelInfoBatched();
        }
        return $this->priceInfoBatched();
    }

    private function hotelInfoBatched(): array
    {
        $this->output("Batched Hotel Info Sync");
        $this->output("========================");
        $this->output("");

        $helpers_dir = Config::getPath('helpers');
        require_once($helpers_dir . 'BatchedHotelInfoSync.php');

        $sync = new \Tygh\Addons\NovotonHolidays\Helpers\BatchedHotelInfoSync();
        $sync->setOutputCallback(function($msg) { $this->output(rtrim($msg, "\n")); });

        $this->configureBatchSync($sync);

        if (!empty($_REQUEST['status'])) {
            return $this->printBatchStatus($sync);
        }

        $options = $this->getBatchOptions();
        $result = $sync->run($options);

        $this->output("");
        $this->output("Result: {$result['status']}");
        $this->printBatchResult($result, 'hotel_info_batched');

        return ['success' => true, 'stats' => $result];
    }

    private function priceInfoBatched(): array
    {
        $this->output("Batched Price Info Sync");
        $this->output("========================");
        $this->output("");

        $helpers_dir = Config::getPath('helpers');
        require_once($helpers_dir . 'BatchedPriceInfoSync.php');

        $sync = new \Tygh\Addons\NovotonHolidays\Helpers\BatchedPriceInfoSync();
        $sync->setOutputCallback(function($msg) { $this->output(rtrim($msg, "\n")); });

        $this->configureBatchSync($sync);

        if (!empty($_REQUEST['stale_hours']) && method_exists($sync, 'setStaleHours')) {
            $sync->setStaleHours((int)$_REQUEST['stale_hours']);
        }

        if (!empty($_REQUEST['status'])) {
            return $this->printBatchStatus($sync);
        }

        $options = $this->getBatchOptions();
        $result = $sync->run($options);

        $this->output("");
        $this->output("Result: {$result['status']}");
        $this->printBatchResult($result, 'sync_priceinfo_batched');

        return ['success' => true, 'stats' => $result];
    }

    private function configureBatchSync($sync): void
    {
        if (!empty($_REQUEST['batch_size'])) {
            $sync->setBatchSize((int)$_REQUEST['batch_size']);
        }
        if (!empty($_REQUEST['max_time'])) {
            $sync->setMaxExecutionTime((int)$_REQUEST['max_time']);
        }
        if (!empty($_REQUEST['unlimited'])) {
            $sync->setUnlimited(true);
            $this->output("Mode: UNLIMITED (no time limit)");
            $this->output("");
        }
    }

    private function getBatchOptions(): array
    {
        $options = [];
        if (!empty($_REQUEST['force_full'])) {
            $options['force_full'] = true;
            $this->output("Mode: FORCED FULL SYNC");
            $this->output("");
        }
        if (!empty($_REQUEST['reset'])) {
            $options['reset'] = true;
        }
        return $options;
    }

    private function printBatchStatus($sync): array
    {
        $status = $sync->getStatus();
        $this->output("Status: {$status['status']}");

        if ($status['status'] === 'in_progress') {
            $this->output("Sync Type: {$status['sync_type']}");
            $this->output("Started: {$status['started_at']}");
            $this->output("Progress: {$status['processed']}/{$status['total']} ({$status['percent']}%)");
            $this->output("Synced: {$status['synced']}, Errors: {$status['errors']}");
            $this->output("Elapsed: {$status['elapsed']}");
            $this->output("ETA: {$status['eta']}");
        } elseif ($status['status'] === 'idle') {
            $this->output("Last Sync: " . ($status['last_sync'] ?? 'Never'));
            $this->output("Last Type: " . ($status['last_sync_type'] ?? 'N/A'));
            if (isset($status['last_total'])) {
                $this->output("Last Total: {$status['last_total']}");
            }
        }

        return ['success' => true, 'stats' => $status];
    }

    private function printBatchResult(array $result, string $type): void
    {
        if ($result['status'] === 'in_progress') {
            $this->output("Processed this run: " . ($result['synced_this_run'] ?? 0));
            $this->output("Total progress: {$result['processed']}/{$result['total']}");
            $this->output("Remaining: {$result['remaining']}");
            $this->output("Estimated runs remaining: {$result['estimated_runs_remaining']}");
            $this->output("");
            $this->output("Run this cron again to continue.");
        } elseif ($result['status'] === 'completed') {
            $this->output("Total synced: {$result['synced']}");
            $this->output("Errors: {$result['errors']}");
            $this->output("Duration: " . round($result['duration'] / 60, 1) . " minutes");

            $this->sendReport($type, [
                'sync_type' => $result['sync_type'],
                'total' => $result['total'],
                'synced' => $result['synced'],
                'errors' => $result['errors'],
                'duration' => round($result['duration'] / 60, 1) . ' min',
            ]);
        }
    }
}

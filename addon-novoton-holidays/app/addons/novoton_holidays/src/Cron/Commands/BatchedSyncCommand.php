<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
use Tygh\Addons\NovotonHolidays\Services\Container;

class BatchedSyncCommand extends AbstractCronCommand
{
    /**
     * @return list<string>
     */
    #[\Override]
    public static function getModes(): array
    {
        return ['hotel_info_batched', 'sync_priceinfo_batched'];
    }

    public static function getDescription(): string
    {
        return 'Batched sync with resume capability (hotel info / price info)';
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $mode = $this->params['_mode'] ?? 'hotel_info_batched';

        if ($mode === 'hotel_info_batched') {
            return $this->hotelInfoBatched();
        }
        return $this->priceInfoBatched();
    }

    /**
     * @return array<string, mixed>
     */
    private function hotelInfoBatched(): array
    {
        $this->output('Batched Hotel Info Sync');
        $this->output('========================');
        $this->output('');

        // Resolved via Container so the AbstractBatchedSync-based
        // BatchedHotelInfoSyncV2 handles the sync. The legacy
        // BatchedHotelInfoSync helper was deleted in PR #11.
        $sync = Container::getInstance()->batchedHotelInfoSyncV2();
        $sync->setOutputCallback(function ($msg): void {
            $this->output(rtrim($msg, "\n"));
        });

        $this->configureBatchSync($sync);

        if (!empty($this->params['status'])) {
            return $this->printBatchStatus($sync);
        }

        $options = $this->getBatchOptions();
        $result = $sync->run($options);

        $this->output('');
        $this->output("Result: {$result['status']}");
        $this->printBatchResult($result, 'hotel_info_batched');

        return ['success' => true, 'stats' => $result];
    }

    /**
     * @return array<string, mixed>
     */
    private function priceInfoBatched(): array
    {
        $this->output('Batched Price Info Sync');
        $this->output('========================');
        $this->output('');

        // Resolved via Container so the AbstractBatchedSync-based
        // BatchedPriceInfoSyncV2 handles the sync. The legacy
        // BatchedPriceInfoSync helper was deleted in PR #11. The
        // factory returns the concrete V2 type so setStaleHours()
        // (which is not part of SyncInterface) remains callable below.
        $sync = Container::getInstance()->batchedPriceInfoSyncV2();
        $sync->setOutputCallback(function ($msg): void {
            $this->output(rtrim($msg, "\n"));
        });

        $this->configureBatchSync($sync);

        if (!empty($this->params['stale_hours'])) {
            $sync->setStaleHours((int)$this->params['stale_hours']);
        }

        if (!empty($this->params['status'])) {
            return $this->printBatchStatus($sync);
        }

        $options = $this->getBatchOptions();
        $result = $sync->run($options);

        $this->output('');
        $this->output("Result: {$result['status']}");
        $this->printBatchResult($result, 'sync_priceinfo_batched');

        return ['success' => true, 'stats' => $result];
    }

    /** @param \Tygh\Addons\NovotonHolidays\Helpers\AbstractBatchedSync $sync */
    private function configureBatchSync($sync): void
    {
        if (!empty($this->params['batch_size'])) {
            $sync->setBatchSize((int)$this->params['batch_size']);
        }
        if (!empty($this->params['max_time'])) {
            $sync->setMaxExecutionTime((int)$this->params['max_time']);
        }
        if (!empty($this->params['unlimited'])) {
            $sync->setUnlimited(true);
            $this->output('Mode: UNLIMITED (no time limit)');
            $this->output('');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getBatchOptions(): array
    {
        $options = [];
        if (!empty($this->params['force_full'])) {
            $options['force_full'] = true;
            $this->output('Mode: FORCED FULL SYNC');
            $this->output('');
        }
        if (!empty($this->params['reset'])) {
            $options['reset'] = true;
        }
        return $options;
    }

    /**
     * @param \Tygh\Addons\NovotonHolidays\Helpers\AbstractBatchedSync $sync
     * @return array<string, mixed>
     */
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
            $this->output('Last Sync: ' . ($status['last_sync'] ?? 'Never'));
            $this->output('Last Type: ' . ($status['last_sync_type'] ?? 'N/A'));
            if (isset($status['last_total'])) {
                $this->output("Last Total: {$status['last_total']}");
            }
        }

        return ['success' => true, 'stats' => $status];
    }

    /**
     * @param array<string, mixed> $result
     */
    private function printBatchResult(array $result, string $type): void
    {
        if ($result['status'] === 'in_progress') {
            $this->output('Processed this run: ' . ($result['synced_this_run'] ?? 0));
            $this->output("Total progress: {$result['processed']}/{$result['total']}");
            $this->output("Remaining: {$result['remaining']}");
            $this->output("Estimated runs remaining: {$result['estimated_runs_remaining']}");
            $this->output('');
            $this->output('Run this cron again to continue.');
        } elseif ($result['status'] === 'completed') {
            $this->output("Total synced: {$result['synced']}");
            $this->output("Errors: {$result['errors']}");
            $this->output('Duration: ' . round($result['duration'] / 60, 1) . ' minutes');

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

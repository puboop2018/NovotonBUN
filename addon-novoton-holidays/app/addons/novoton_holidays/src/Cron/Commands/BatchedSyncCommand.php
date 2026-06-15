<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

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
        $sync->setOutputCallback(function (string $msg): void {
            $this->output(rtrim($msg, "\n"));
        });

        $this->configureBatchSync($sync);

        if (!empty($this->params['status'])) {
            return $this->printBatchStatus($sync);
        }

        $options = $this->getBatchOptions();
        $result = $sync->run($options);

        $this->output('');
        $this->output('Result: ' . TypeCoerce::toString($result['status'] ?? ''));
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
        $sync->setOutputCallback(function (string $msg): void {
            $this->output(rtrim($msg, "\n"));
        });

        $this->configureBatchSync($sync);

        if (!empty($this->params['stale_hours'])) {
            $sync->setStaleHours(TypeCoerce::toInt($this->params['stale_hours']));
        }

        if (!empty($this->params['status'])) {
            return $this->printBatchStatus($sync);
        }

        $options = $this->getBatchOptions();
        $result = $sync->run($options);

        $this->output('');
        $this->output('Result: ' . TypeCoerce::toString($result['status'] ?? ''));
        $this->printBatchResult($result, 'sync_priceinfo_batched');

        return ['success' => true, 'stats' => $result];
    }

    /** @param \Tygh\Addons\NovotonHolidays\Helpers\AbstractBatchedSync $sync */
    private function configureBatchSync($sync): void
    {
        if (!empty($this->params['batch_size'])) {
            $sync->setBatchSize(TypeCoerce::toInt($this->params['batch_size']));
        }
        if (!empty($this->params['max_time'])) {
            $sync->setMaxExecutionTime(TypeCoerce::toInt($this->params['max_time']));
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
        $this->output('Status: ' . TypeCoerce::toString($status['status'] ?? ''));

        if ($status['status'] === 'in_progress') {
            $this->output('Sync Type: ' . TypeCoerce::toString($status['sync_type'] ?? ''));
            $this->output('Started: ' . TypeCoerce::toString($status['started_at'] ?? ''));
            $this->output('Progress: ' . TypeCoerce::toInt($status['processed'] ?? 0) . '/' . TypeCoerce::toInt($status['total'] ?? 0) . ' (' . TypeCoerce::toString($status['percent'] ?? '0') . '%)');
            $this->output('Synced: ' . TypeCoerce::toInt($status['synced'] ?? 0) . ', Errors: ' . TypeCoerce::toInt($status['errors'] ?? 0));
            $this->output('Elapsed: ' . TypeCoerce::toString($status['elapsed'] ?? ''));
            $this->output('ETA: ' . TypeCoerce::toString($status['eta'] ?? ''));
        } elseif ($status['status'] === 'idle') {
            $this->output('Last Sync: ' . TypeCoerce::toString($status['last_sync'] ?? 'Never'));
            $this->output('Last Type: ' . TypeCoerce::toString($status['last_sync_type'] ?? 'N/A'));
            if (isset($status['last_total'])) {
                $this->output('Last Total: ' . TypeCoerce::toString($status['last_total']));
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
            $this->output('Processed this run: ' . TypeCoerce::toInt($result['synced_this_run'] ?? 0));
            $this->output('Total progress: ' . TypeCoerce::toInt($result['processed'] ?? 0) . '/' . TypeCoerce::toInt($result['total'] ?? 0));
            $this->output('Remaining: ' . TypeCoerce::toInt($result['remaining'] ?? 0));
            $this->output('Estimated runs remaining: ' . TypeCoerce::toInt($result['estimated_runs_remaining'] ?? 0));
            $this->output('');
            $this->output('Run this cron again to continue.');
        } elseif ($result['status'] === 'completed') {
            $this->output('Total synced: ' . TypeCoerce::toInt($result['synced'] ?? 0));
            $this->output('Errors: ' . TypeCoerce::toInt($result['errors'] ?? 0));
            $this->output('Duration: ' . round(TypeCoerce::toFloat($result['duration'] ?? 0) / 60, 1) . ' minutes');

            $this->sendReport($type, [
                'sync_type' => $result['sync_type'],
                'total' => $result['total'],
                'synced' => $result['synced'],
                'errors' => $result['errors'],
                'duration' => round(TypeCoerce::toFloat($result['duration'] ?? 0) / 60, 1) . ' min',
            ]);
        }
    }
}

<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
use Tygh\Addons\NovotonHolidays\Helpers\SyncInterface;
use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Batched hotel facilities sync cron command.
 *
 * Syncs per-hotel facility assignments (which amenities each hotel has)
 * in resumable batches. Each hotel requires 1 API call (function 27).
 *
 * Usage:
 *   mode=hotel_facilities_batched              — run sync (resumes if in progress)
 *   mode=hotel_facilities_batched&status=1     — check progress
 *   mode=hotel_facilities_batched&force_full=1 — force full re-sync
 *   mode=hotel_facilities_batched&reset=1      — clear state and start fresh
 *   mode=hotel_facilities_batched&batch_size=50&max_time=120
 *   mode=hotel_facilities_batched&unlimited=1  — no time limit (CLI usage)
 *
 * @since 3.4.0
 */
class FacilitiesBatchSyncCommand extends AbstractCronCommand
{
    /**
     * @return list<string>
     */
    #[\Override]
    public static function getModes(): array
    {
        return ['hotel_facilities_batched'];
    }

    public static function getDescription(): string
    {
        return 'Batched hotel facilities sync with resume capability';
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $this->output('Batched Hotel Facilities Sync');
        $this->output('==============================');
        $this->output('');

        // Resolved via Container so the AbstractBatchedSync-based
        // BatchedHotelFacilitiesSyncV2 handles the sync. The legacy
        // BatchedHotelFacilitiesSync helper was deleted in PR #11.
        // The local is typed SyncInterface so future migrations
        // (V3, replacement, etc.) don't need to touch this file.
        $sync = Container::getInstance()->batchedHotelFacilitiesSyncV2();
        $sync->setOutputCallback(function (string $msg): void {
            $this->output(rtrim($msg, "\n"));
        });

        // Apply configuration from request params
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

        // Status check only
        if (!empty($this->params['status'])) {
            return $this->printStatus($sync);
        }

        // Build options
        $options = [];
        if (!empty($this->params['force_full'])) {
            $options['force_full'] = true;
            $this->output('Mode: FORCED FULL SYNC');
            $this->output('');
        }
        if (!empty($this->params['reset'])) {
            $options['reset'] = true;
        }

        $result = $sync->run($options);

        $this->output('');
        $this->output('Result: ' . TypeCoerce::toString($result['status'] ?? ''));
        $this->printResult($result);

        return ['success' => true, 'stats' => $result];
    }

    /**
     * @return array<string, mixed>
     */
    private function printStatus(SyncInterface $sync): array
    {
        $status = $sync->getStatus();
        $this->output('Status: ' . TypeCoerce::toString($status['status'] ?? ''));

        if ($status['status'] === 'in_progress') {
            $this->output('Started: ' . TypeCoerce::toString($status['started_at'] ?? ''));
            $this->output('Progress: ' . TypeCoerce::toInt($status['processed'] ?? 0) . '/' . TypeCoerce::toInt($status['total'] ?? 0) . ' (' . TypeCoerce::toString($status['percent'] ?? '0') . '%)');
            $this->output('Synced: ' . TypeCoerce::toInt($status['synced'] ?? 0) . ', Errors: ' . TypeCoerce::toInt($status['errors'] ?? 0));
            $this->output('Elapsed: ' . TypeCoerce::toString($status['elapsed'] ?? ''));
            $this->output('ETA: ' . TypeCoerce::toString($status['eta'] ?? ''));
        } elseif ($status['status'] === 'idle') {
            $this->output('Last Sync: ' . TypeCoerce::toString($status['last_sync'] ?? 'Never'));
            if (isset($status['last_total'])) {
                $this->output('Last Total: ' . TypeCoerce::toString($status['last_total']));
            }
        }

        return ['success' => true, 'stats' => $status];
    }

    /**
     * @param array<string, mixed> $result
     */
    private function printResult(array $result): void
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

            $this->sendReport('hotel_facilities_batched', [
                'sync_type' => $result['sync_type'],
                'total' => $result['total'],
                'synced' => $result['synced'],
                'errors' => $result['errors'],
                'duration' => round(TypeCoerce::toFloat($result['duration'] ?? 0) / 60, 1) . ' min',
            ]);
        }
    }
}

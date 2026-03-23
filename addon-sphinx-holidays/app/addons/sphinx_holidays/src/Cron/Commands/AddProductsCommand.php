<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\TravelCore\Services\FeatureMapper;

/**
 * Create CS-Cart products from unlinked Sphinx hotels.
 *
 * Thin orchestrator: loads batches of unlinked hotels, resolves destination
 * hierarchies, then delegates per-hotel product creation to SphinxProductFactory.
 *
 * Supports resume capability (like Novoton's batched sync):
 * - Saves state to a JSON file after each batch of hotels
 * - findUnlinked() naturally skips already-linked hotels, so resume is implicit
 * - State file tracks progress counts (added/skipped/failed) across runs
 * - Stale state (>6h no activity) is cleared automatically
 *
 * Usage:
 *   index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=add_products
 *   index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=add_products&country=TR
 *   index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=add_products&limit=500
 *   index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=add_products&retry_skipped=1
 *   index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=add_products&retry_skipped=invalid_country
 *   index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=add_products&status=1
 *   index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=add_products&reset=1
 */
class AddProductsCommand extends AbstractSyncCommand
{
    use StatefulCommandTrait;

    private const STATE_FILE_NAME = 'sphinx_add_products_state.json';
    private const STALE_HOURS = 6;
    private const DEFAULT_STATE = [
        'status'          => 'idle',
        'started_at'      => null,
        'last_run_at'     => null,
        'added'           => 0,
        'skipped'         => 0,
        'failed'          => 0,
        'invalid_country' => 0,
        'total'           => 0,
        'country'         => '',
    ];

    public static function getDescription(): string
    {
        return 'Create CS-Cart products from unlinked Sphinx hotels (retry_skipped=1 to retry previously skipped)';
    }

    public function execute(array $params = []): array
    {
        // Handle reset — clears state file
        if (!empty($params['reset'])) {
            $this->clearState();
            $this->output('Add products state cleared. Ready for fresh run.');
            return ['success' => true, 'stats' => ['action' => 'reset']];
        }

        // Handle status check
        if (!empty($params['status'])) {
            return $this->showStatus();
        }

        // Load existing state
        $state = $this->loadState();

        // Check for stale in-progress state
        if ($state['status'] === 'in_progress') {
            if ($this->isStale($state)) {
                $this->output("Stale state detected (no activity since {$state['last_run_at']}). Clearing and starting fresh.");
                $this->clearState();
                $state = self::DEFAULT_STATE;
            } else {
                // Resume — findUnlinked() naturally skips already-linked hotels
                $this->output("Resuming product creation ({$state['added']} added, {$state['failed']} failed so far)...");
                return $this->runWithState($state, $params);
            }
        }

        // Fresh start
        $state = self::DEFAULT_STATE;
        $state['status'] = 'in_progress';
        $state['started_at'] = date('Y-m-d H:i:s');
        $state['last_run_at'] = date('Y-m-d H:i:s');
        $state['country'] = $params['country'] ?? '';
        $this->saveState($state);

        return $this->runWithState($state, $params);
    }

    /**
     * Process hotels in batches, saving state after each batch.
     * Can be interrupted and resumed — findUnlinked() skips already-linked hotels.
     */
    private function runWithState(array $state, array $params): array
    {
        $hotelRepo = Container::getHotelRepository();
        $destRepo = Container::getDestinationRepository();
        $factory = Container::getProductFactory();
        $template = ConfigProvider::getProductCategoryTemplate();

        $countryCode = $params['country'] ?? $state['country'] ?? '';
        $limit = (int) ($params['limit'] ?? 0);
        $batchSize = (int) ($params['batch_size'] ?? 200);
        $retrySkipped = $params['retry_skipped'] ?? '';

        // Handle retry_skipped: reset product_skip_reason so hotels become eligible again
        if ($retrySkipped !== '') {
            $reason = ($retrySkipped === '1') ? '' : $retrySkipped;
            $reset = $hotelRepo->resetSkipped($countryCode, $reason);
            $filter = $countryCode !== '' ? " for country {$countryCode}" : '';
            $reasonFilter = $reason !== '' ? " with reason '{$reason}'" : '';
            $this->output("Reset {$reset} previously skipped hotels{$filter}{$reasonFilter}. They are now eligible for product creation.");
        }

        $factory->loadValidCountryCodes();

        if (!$destRepo->loadParentLookup()) {
            $this->output("WARNING: sphinx_destinations is empty — run destination sync first. Category paths will use hotel fields only.");
        }

        $processed = 0;
        $effectiveBatch = ($limit > 0 && $limit < $batchSize) ? $limit : $batchSize;

        while (true) {
            $remaining = ($limit > 0) ? ($limit - $processed) : $effectiveBatch;
            if ($remaining <= 0) {
                break;
            }

            $hotels = $hotelRepo->findUnlinked($countryCode, min($remaining, $effectiveBatch));
            if (empty($hotels)) {
                break;
            }

            if ($state['total'] === 0 && $processed === 0) {
                $this->output("Processing unlinked hotels in batches of {$effectiveBatch}...");
            }

            $state['total'] += count($hotels);

            // Resolve destination hierarchies for this batch
            $destinationIds = array_filter(array_unique(array_column($hotels, 'destination_id')));
            $hierarchyMap = !empty($destinationIds) ? $destRepo->resolveHierarchies($destinationIds) : [];

            foreach ($hotels as $hotel) {
                $hotelId = $hotel['hotel_id'];
                $hierarchy = $hierarchyMap[(int) $hotel['destination_id']] ?? [];

                $result = $factory->createFromHotel($hotel, $hierarchy, $template);

                $this->output("[{$hotelId}] {$hotel['name']} ... " . strtoupper($result['status'])
                    . ($result['product_id'] ? " (ID: {$result['product_id']})" : '')
                    . ($result['reason'] ? " ({$result['reason']})" : ''));

                match ($result['status']) {
                    'added'   => $state['added']++,
                    'linked'  => $state['skipped']++,
                    'skipped' => str_contains($result['reason'], 'invalid country')
                        ? $state['invalid_country']++
                        : $state['failed']++,
                    'failed'  => $state['failed']++,
                };
            }

            $processed += count($hotels);

            // Save state after every batch (critical for resume)
            $state['last_run_at'] = date('Y-m-d H:i:s');
            $this->saveState($state);
        }

        FeatureMapper::clearCache();

        // Diagnostic: if nothing was processed, check for skipped hotels
        if ($state['total'] === 0 && $processed === 0) {
            $skippedCount = $hotelRepo->countSkipped();
            if ($skippedCount > 0) {
                $byReason = $this->getSkippedBreakdown();
                $this->output("No eligible hotels found, but {$skippedCount} hotel(s) were previously skipped:");
                foreach ($byReason as $reason => $count) {
                    $this->output("  - {$reason}: {$count}");
                }
                $this->output("Run with retry_skipped=1 to make them eligible again.");
                $this->output("  Or use: &cron_mode=add_products&retry_skipped=1");
            }
        }

        if ($state['invalid_country'] > 0) {
            $this->output("WARNING: {$state['invalid_country']} hotels skipped — country codes not in CS-Cart. Check sync health log.");
            $this->output("After enabling the missing countries in CS-Cart, run: cron_mode=add_products&retry_skipped=invalid_country");
        }

        $this->output("Done: {$state['added']} added, {$state['skipped']} skipped, {$state['failed']} failed, {$state['invalid_country']} invalid country.");

        // Mark sync as complete, clear state
        $this->clearState();

        return ['success' => true, 'stats' => [
            'added'           => $state['added'],
            'skipped'         => $state['skipped'],
            'failed'          => $state['failed'],
            'invalid_country' => $state['invalid_country'],
            'total'           => $state['total'],
        ]];
    }

    /**
     * Show current progress without processing any hotels.
     */
    private function showStatus(): array
    {
        $state = $this->loadState();

        if ($state['status'] === 'idle') {
            $this->output('Add Products Status: idle (no run in progress)');

            // Show unlinked count
            $hotelRepo = Container::getHotelRepository();
            $unlinkedCount = (int) db_get_field(
                "SELECT COUNT(*) FROM ?:sphinx_hotels WHERE product_id IS NULL AND sync_status = 'active' AND product_skip_reason IS NULL"
            );
            $this->output("  Unlinked hotels ready: {$unlinkedCount}");

            $skippedCount = $hotelRepo->countSkipped();
            if ($skippedCount > 0) {
                $this->output("  Previously skipped: {$skippedCount}");
                $byReason = $this->getSkippedBreakdown();
                foreach ($byReason as $reason => $count) {
                    $this->output("    - {$reason}: {$count}");
                }
            }

            return ['success' => true, 'stats' => ['status' => 'idle', 'unlinked' => $unlinkedCount, 'skipped' => $skippedCount]];
        }

        $this->output('Add Products Status:');
        $this->output("  Status: {$state['status']}");
        $this->output("  Total processed: {$state['total']}");
        $this->output("  Added: {$state['added']}");
        $this->output("  Skipped: {$state['skipped']}");
        $this->output("  Failed: {$state['failed']}");
        $this->output("  Invalid country: {$state['invalid_country']}");
        if ($state['country'] !== '') {
            $this->output("  Country filter: {$state['country']}");
        }
        $this->output("  Started: {$state['started_at']}");
        $this->output("  Last activity: {$state['last_run_at']}");

        if ($this->isStale($state)) {
            $this->output('  WARNING: State appears stale (no activity for 6+ hours). Run with reset=1 to clear.');
        }

        if (!empty($state['started_at'])) {
            $elapsed = time() - strtotime($state['started_at']);
            $this->output('  Elapsed: ' . $this->formatDuration($elapsed));
        }

        return ['success' => true, 'stats' => [
            'status' => $state['status'],
            'total'  => $state['total'],
            'added'  => $state['added'],
            'failed' => $state['failed'],
        ]];
    }

    /**
     * Get breakdown of skipped hotels by reason.
     *
     * @return array<string, int> reason => count
     */
    private function getSkippedBreakdown(): array
    {
        $rows = db_get_array(
            "SELECT product_skip_reason, COUNT(*) AS cnt
             FROM ?:sphinx_hotels
             WHERE product_skip_reason IS NOT NULL AND sync_status = 'active'
             GROUP BY product_skip_reason
             ORDER BY cnt DESC"
        );

        $result = [];
        foreach ($rows as $row) {
            $result[$row['product_skip_reason']] = (int) $row['cnt'];
        }
        return $result;
    }

    private function output(string $message): void
    {
        if ($this->outputCallback !== null) {
            ($this->outputCallback)($message);
        }
    }
}

<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\ValidationHelpers;
use Tygh\Addons\TravelCore\Services\FeatureMapper;
use Tygh\Registry;

/**
 * Cron command: assign discovered board/meal types as CS-Cart product features.
 *
 * Reads boards_json from sphinx_hotels (populated by discover_boards mode),
 * resolves canonical codes to CS-Cart feature variants via travel_core FeatureMapper,
 * and assigns them as M-type (multiple checkboxes) product features.
 *
 * Supports batch processing with resume capability:
 * - Processes a configurable number of hotels per cron run
 * - Saves state to a JSON file after each batch
 * - Automatically resumes from where it left off on next run
 * - Respects time limits to avoid PHP timeout
 *
 * Diff-based: adds new board variants, removes stale ones.
 *
 * Usage:
 *   php cron.php access_key=KEY mode=assign_boards
 *   php cron.php access_key=KEY mode=assign_boards country=GR
 *   php cron.php access_key=KEY mode=assign_boards max_time=300
 *   php cron.php access_key=KEY mode=assign_boards unlimited=1
 *   php cron.php access_key=KEY mode=assign_boards status=1
 *   php cron.php access_key=KEY mode=assign_boards reset=1
 */
class AssignBoardsCommand extends AbstractSyncCommand
{
    use StatefulCommandTrait;

    /** State file name stored in DIR_CACHE */
    private const string STATE_FILE_NAME = 'sphinx_assign_boards_state.json';

    /** Batch configuration */
    private const int DEFAULT_BATCH_SIZE = 100;         // hotels per DB query batch
    private const int DEFAULT_MAX_TIME = 300;            // 5 minutes
    private const int STALE_HOURS = 6;                   // clear abandoned state after 6h

    /** Default state structure */
    private const array DEFAULT_STATE = [
        'status' => 'idle',
        'started_at' => null,
        'last_run_at' => null,
        'total' => 0,
        'processed' => 0,
        'assigned' => 0,
        'errors' => 0,
        'country_code' => '',
    ];

    #[\Override]
    public static function getDescription(): string
    {
        return 'Assign discovered board/meal types as CS-Cart product features (batched with resume)';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    #[\Override]
    public function execute(array $params = []): array
    {
        // Handle reset
        if (!empty($params['reset'])) {
            $this->clearState();
            $this->output('State cleared. Ready for fresh assignment run.');
            return ['success' => true, 'action' => 'reset'];
        }

        // Handle status check
        if (!empty($params['status'])) {
            return $this->showStatus();
        }

        // Check that feature_id_meals is configured in travel_core
        $featureId = ValidationHelpers::toInt(Registry::get('addons.travel_core.feature_id_meals'));
        if ($featureId <= 0) {
            $this->output('ERROR: travel_core setting "feature_id_meals" is not configured (value: 0).');
            $this->output('Please set the Meals/Board feature ID in Admin > Add-ons > Travel Core settings.');
            return ['success' => false, 'error' => 'feature_id_meals not configured'];
        }

        $this->output("Using CS-Cart feature ID: {$featureId} for board/meals");

        // Load existing state
        $state = $this->loadState();

        // Check for in-progress state
        if (ValidationHelpers::toString($state['status'] ?? 'idle') === 'in_progress') {
            if ($this->isStale($state)) {
                $this->output('Stale state detected (no activity since ' . ValidationHelpers::toString($state['last_run_at'] ?? '') . '). Clearing and starting fresh.');
                $this->clearState();
                $state = self::DEFAULT_STATE;
            } else {
                // Resume
                $sTotal = ValidationHelpers::toInt($state['total'] ?? 0);
                $sProcessed = ValidationHelpers::toInt($state['processed'] ?? 0);
                $pct = $sTotal > 0 ? round($sProcessed / $sTotal * 100, 1) : 0;
                $this->output("Resuming board assignment: {$sProcessed}/{$sTotal} ({$pct}%) done");
                return $this->processBatch($state, $params);
            }
        }

        // Fresh start
        $countryCode = ValidationHelpers::toString($params['country'] ?? '');
        $hotelRepo = Container::getHotelRepository();
        $total = $hotelRepo->countWithBoardsAndProduct($countryCode);

        $this->output('Hotels with boards + products: ' . $total);

        if ($total === 0) {
            $this->output('No hotels to process. Run discover_boards first, then add_products.');
            return ['success' => true, 'stats' => ['processed' => 0]];
        }

        // Create initial state
        $state = [
            'status' => 'in_progress',
            'started_at' => date('Y-m-d H:i:s'),
            'last_run_at' => date('Y-m-d H:i:s'),
            'total' => $total,
            'processed' => 0,
            'assigned' => 0,
            'errors' => 0,
            'country_code' => $countryCode,
        ];

        $this->saveState($state);
        $this->output("Starting board assignment: {$total} hotels");

        return $this->processBatch($state, $params);
    }

    /**
     * Process hotels in batches, respecting time limits.
     * @param array<string, mixed> $state
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function processBatch(array $state, array $params): array
    {
        $maxTime = max(60, ValidationHelpers::toInt($params['max_time'] ?? self::DEFAULT_MAX_TIME));
        $unlimited = !empty($params['unlimited']);
        $startTime = time();

        $hotelRepo = Container::getHotelRepository();
        $featureAssigner = Container::getFeatureAssigner();

        $offset = ValidationHelpers::toInt($state['processed'] ?? 0);
        $total = ValidationHelpers::toInt($state['total'] ?? 0);
        $processedThisRun = 0;

        while ($offset < $total) {
            // Check time limit
            if (!$unlimited && (time() - $startTime) > $maxTime) {
                $this->output('');
                $this->output("Time limit ({$maxTime}s) reached. Saving state for resume.");
                break;
            }

            // Fetch next batch from DB
            $hotels = $hotelRepo->findWithBoardsAndProduct(
                ValidationHelpers::toString($state['country_code'] ?? ''),
                self::DEFAULT_BATCH_SIZE,
                $offset,
            );

            if (empty($hotels)) {
                // No more hotels — we've reached the end
                $offset = $total;
                break;
            }

            foreach ($hotels as $hotel) {
                // Check time limit within batch
                if (!$unlimited && (time() - $startTime) > $maxTime) {
                    break 2;
                }

                $productId = ValidationHelpers::toInt($hotel['product_id'] ?? 0);

                try {
                    $featureAssigner->assignAll($productId, $hotel);
                    $state['assigned'] = ValidationHelpers::toInt($state['assigned'] ?? 0) + 1;
                } catch (\Throwable $e) {
                    $state['errors'] = ValidationHelpers::toInt($state['errors'] ?? 0) + 1;
                    $sErrors = ValidationHelpers::toInt($state['errors'] ?? 0);
                    if ($sErrors <= 10) {
                        $hotelIdStr = ValidationHelpers::toString($hotel['hotel_id'] ?? '');
                        $this->output("  [ERROR] Hotel {$hotelIdStr}: " . $e->getMessage());
                    }
                }

                $offset++;
                $processedThisRun++;
            }

            // Save state after each batch
            $state['processed'] = $offset;
            $state['last_run_at'] = date('Y-m-d H:i:s');
            $this->saveState($state);

            // Progress output
            if ($offset % 200 === 0) {
                $pct = round($offset / $total * 100, 1);
                $this->output("  {$offset}/{$total} ({$pct}%) — {$state['assigned']} assigned");
            }
        }

        // Update final state
        $state['processed'] = $offset;
        $state['last_run_at'] = date('Y-m-d H:i:s');
        $this->saveState($state);

        // Check if complete
        if ($offset >= $total) {
            return $this->completeSync($state);
        }

        // Still in progress
        $remaining = $total - $offset;
        $elapsed = time() - $startTime;
        $this->output("Processed {$processedThisRun} hotels this run ({$elapsed}s).");
        $this->output("Run again to continue ({$remaining} hotels remaining).");

        return [
            'success' => true,
            'status' => 'in_progress',
            'total' => $total,
            'processed' => $offset,
            'remaining' => $remaining,
            'processed_this_run' => $processedThisRun,
            'assigned' => $state['assigned'],
            'errors' => $state['errors'],
        ];
    }

    /**
     * Mark sync as completed, log, and clear state.
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function completeSync(array $state): array
    {
        $durationSeconds = !empty($state['started_at'])
            ? time() - strtotime($state['started_at'])
            : 0;

        // Clear FeatureMapper cache
        FeatureMapper::clearCache();

        // Log to sphinx_sync_log
        db_query(
            "INSERT INTO ?:sphinx_sync_log (sync_type, status, items_total, items_synced, items_failed, sync_mode, duration_ms, started_at, completed_at)
             VALUES ('assign_boards', 'completed', ?i, ?i, ?i, 'full', ?i, ?s, NOW())",
            $state['total'],
            $state['assigned'],
            $state['errors'],
            $durationSeconds * 1000,
            $state['started_at'],
        );

        $this->output('');
        $this->output('Board Assignment Complete:');
        $this->output("  Hotels processed: {$state['processed']}");
        $this->output("  Features assigned: {$state['assigned']}");
        $this->output("  Errors: {$state['errors']}");
        $this->output('  Duration: ' . $this->formatDuration($durationSeconds));

        $this->clearState();

        return [
            'success' => true,
            'status' => 'completed',
            'stats' => [
                'processed' => $state['processed'],
                'assigned' => $state['assigned'],
                'errors' => $state['errors'],
                'duration_seconds' => $durationSeconds,
            ],
        ];
    }

    /**
     * Show current assignment progress.
     * @return array<string, mixed>
     */
    private function showStatus(): array
    {
        $state = $this->loadState();

        if ($state['status'] === 'idle') {
            $this->output('Board Assignment Status: idle (no assignment in progress)');

            $lastRun = db_get_row(
                "SELECT * FROM ?:sphinx_sync_log WHERE sync_type = 'assign_boards' ORDER BY started_at DESC LIMIT 1",
            );
            if (!empty($lastRun)) {
                $this->output("  Last run: {$lastRun['started_at']} — {$lastRun['items_synced']} features assigned");
            }

            return ['success' => true, 'status' => 'idle'];
        }

        $pct = $state['total'] > 0 ? round($state['processed'] / $state['total'] * 100, 1) : 0;
        $remaining = $state['total'] - $state['processed'];

        $this->output('Board Assignment Status:');
        $this->output("  Status: {$state['status']}");
        $this->output("  Progress: {$state['processed']}/{$state['total']} ({$pct}%)");
        $this->output("  Remaining: {$remaining} hotels");
        $this->output("  Assigned: {$state['assigned']}");
        $this->output("  Errors: {$state['errors']}");
        $this->output("  Started: {$state['started_at']}");
        $this->output("  Last activity: {$state['last_run_at']}");

        if ($this->isStale($state)) {
            $this->output('  WARNING: State appears stale (no activity for 6+ hours). Run with reset=1 to clear.');
        }

        return ['success' => true, 'status' => $state['status'], 'processed' => $state['processed'], 'total' => $state['total']];
    }
}

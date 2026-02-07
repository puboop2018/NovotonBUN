<?php
/**
 * Novoton Holidays - Abstract Batched Sync
 *
 * Base class for all batched sync operations. Provides:
 * - State management with resume capability
 * - Unified logging
 * - Progress tracking
 * - Time limit handling
 *
 * Subclasses must implement:
 * - getSyncName(): Unique name for this sync type
 * - determineSyncType(): Decide if full/incremental sync needed
 * - getItemsToSync(): Get item IDs to process
 * - processItem(): Process a single item
 *
 * @package NovotonHolidays
 * @since 3.1.0
 */

namespace Tygh\Addons\NovotonHolidays\Helpers;

use Tygh\Addons\NovotonHolidays\NovotonApi;

abstract class AbstractBatchedSync implements SyncInterface
{
    /**
     * State manager
     * @var StateManager
     */
    protected StateManager $state;

    /**
     * Logger
     * @var SyncLogger
     */
    protected SyncLogger $logger;

    /**
     * API instance
     * @var NovotonApi|null
     */
    protected ?NovotonApi $api = null;

    /**
     * Batch size
     * @var int
     */
    protected int $batchSize;

    /**
     * Maximum execution time per run (seconds)
     * @var int
     */
    protected int $maxExecutionTime;

    /**
     * Unlimited mode (no time limit)
     * @var bool
     */
    protected bool $unlimited = false;

    /**
     * Start time of current run
     * @var int
     */
    protected int $startTime;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->batchSize = Config::DEFAULT_BATCH_SIZE;
        $this->maxExecutionTime = Config::DEFAULT_MAX_EXECUTION_TIME;

        $this->state = new StateManager($this->getSyncName());
        $this->logger = new SyncLogger($this->getSyncName());
    }

    /**
     * Get unique name for this sync type
     *
     * @return string
     */
    abstract protected function getSyncName(): string;

    /**
     * Determine what type of sync is needed
     *
     * @param array $options
     * @return string 'full', 'incremental', or 'none'
     */
    abstract protected function determineSyncType(array $options): string;

    /**
     * Get item IDs to sync based on sync type
     *
     * @param string $syncType
     * @param array $options
     * @return array
     */
    abstract protected function getItemsToSync(string $syncType, array $options): array;

    /**
     * Process a single item
     *
     * @param string|int $itemId
     * @return array ['success' => bool, 'message' => string, 'data' => mixed]
     */
    abstract protected function processItem($itemId): array;

    /**
     * Set batch size
     *
     * @param int $size
     */
    public function setBatchSize(int $size): void
    {
        $this->batchSize = max(Config::MIN_BATCH_SIZE, min(Config::MAX_BATCH_SIZE, $size));
    }

    /**
     * Set maximum execution time
     *
     * @param int $seconds
     */
    public function setMaxExecutionTime(int $seconds): void
    {
        $this->maxExecutionTime = max(Config::MIN_EXECUTION_TIME, min(Config::MAX_EXECUTION_TIME, $seconds));
    }

    /**
     * Set unlimited mode
     *
     * @param bool $unlimited
     */
    public function setUnlimited(bool $unlimited): void
    {
        $this->unlimited = $unlimited;
    }

    /**
     * Set output callback for logger
     *
     * @param callable $callback
     */
    public function setOutputCallback(callable $callback): void
    {
        $this->logger->setOutputCallback($callback);
    }

    /**
     * Get API instance
     *
     * @return NovotonApi
     */
    protected function getApi(): NovotonApi
    {
        if ($this->api === null) {
            $srcDir = Config::getPath('src');
            if (file_exists($srcDir . 'NovotonApi.php')) {
                require_once($srcDir . 'NovotonApi.php');
            }
            $this->api = new NovotonApi();
        }
        return $this->api;
    }

    /**
     * Check if time limit has been reached
     *
     * @return bool
     */
    protected function isTimeLimitReached(): bool
    {
        if ($this->unlimited) {
            return false;
        }
        return (time() - $this->startTime) > $this->maxExecutionTime;
    }

    /**
     * Run the sync operation
     *
     * @param array $options
     * @return array
     */
    public function run(array $options = []): array
    {
        $this->startTime = time();

        // Handle reset option
        if (!empty($options['reset'])) {
            $this->state->clear();
            $this->logger->output("State reset. Ready for new sync.");
            return ['status' => 'reset'];
        }

        // Check for active job to resume
        if ($this->state->shouldResume()) {
            $status = $this->state->getStatus();
            $this->logger->output("Resuming {$status['sync_type']} sync...");
            $this->logger->output("Progress: {$status['processed']}/{$status['total']} ({$status['percent']}%)");
            return $this->resumeSync();
        }

        // Determine sync type needed
        $syncType = $this->determineSyncType($options);

        if ($syncType === 'none') {
            $this->logger->output("No sync needed at this time.");
            return ['status' => 'skipped', 'reason' => 'No sync needed'];
        }

        $this->logger->output("Starting {$syncType} sync...");

        // Get items to sync
        $itemIds = $this->getItemsToSync($syncType, $options);

        if (empty($itemIds)) {
            $this->logger->output("No items to sync.");
            return ['status' => 'skipped', 'reason' => 'No items found'];
        }

        $this->logger->output("Found " . count($itemIds) . " items to sync.");

        // Create new state
        $metadata = $this->getMetadata($options);
        $this->state->start($syncType, $itemIds, $metadata);

        return $this->resumeSync();
    }

    /**
     * Get metadata to store with state
     *
     * @param array $options
     * @return array
     */
    protected function getMetadata(array $options): array
    {
        return [
            'countries' => $options['countries'] ?? Config::getSelectedCountries(),
        ];
    }

    /**
     * Resume an in-progress sync
     *
     * @return array
     */
    protected function resumeSync(): array
    {
        $processedThisRun = 0;
        $syncedThisRun = 0;
        $errorsThisRun = 0;

        $status = $this->state->getStatus();
        $offset = $status['processed'];
        $total = $status['total'];

        while ($offset < $total) {
            // Check time limit
            if ($this->isTimeLimitReached()) {
                $elapsed = time() - $this->startTime;
                $this->logger->output("\nTime limit reached ({$elapsed}s). Saving state for resume.");
                break;
            }

            // Get next batch
            $batch = $this->state->getNextBatch($this->batchSize);

            if (empty($batch)) {
                break;
            }

            foreach ($batch as $itemId) {
                // Check time limit within batch
                if ($this->isTimeLimitReached()) {
                    break 2;
                }

                $result = $this->processItem($itemId);

                $offset++;
                $processedThisRun++;

                if ($result['success']) {
                    $syncedThisRun++;
                    $this->state->increment(1, 1, 0);
                } else {
                    $errorsThisRun++;
                    $this->state->increment(1, 0, 1, (string)$itemId);
                }

                // Small delay to avoid API rate limits
                usleep(Config::API_DELAY_MS * 1000);
            }

            // Progress output
            $this->logger->outputProgress($offset, $total);
        }

        // Check if complete
        if ($offset >= $total) {
            return $this->completeSync();
        }

        // Still in progress
        $remaining = $total - $offset;
        $runsRemaining = ceil($remaining / max(1, $processedThisRun));

        return [
            'status' => 'in_progress',
            'sync_type' => $status['sync_type'],
            'total' => $total,
            'processed' => $offset,
            'remaining' => $remaining,
            'synced_this_run' => $syncedThisRun,
            'errors_this_run' => $errorsThisRun,
            'estimated_runs_remaining' => $runsRemaining,
        ];
    }

    /**
     * Complete the sync
     *
     * @return array
     */
    protected function completeSync(): array
    {
        $state = $this->state->complete();

        // Log to database
        $this->logger->setStat('total', $state['total']);
        $this->logger->setStat('synced', $state['synced']);
        $this->logger->setStat('errors', $state['errors']);

        $extra = [
            'sync_type' => $state['sync_type'],
            'metadata' => $state['metadata'] ?? [],
        ];

        $this->logger->logToDatabase('completed', $extra);
        $this->logger->outputSummary();

        // Send email report
        $countries = $state['metadata']['countries'] ?? [];
        $this->logger->sendEmailReport([], implode(', ', $countries));

        // Clear state file
        $this->state->clear();

        return [
            'status' => 'completed',
            'sync_type' => $state['sync_type'],
            'total' => $state['total'],
            'synced' => $state['synced'],
            'errors' => $state['errors'],
            'duration' => $state['duration_seconds'] ?? 0,
        ];
    }

    /**
     * Get current status
     *
     * @return array
     */
    public function getStatus(): array
    {
        $status = $this->state->getStatus();

        if ($status['status'] === 'idle') {
            // Check last completed sync from database
            $lastSync = db_get_row(
                "SELECT * FROM ?:novoton_sync_log
                 WHERE sync_type = ?s AND status = 'completed'
                 ORDER BY sync_date DESC LIMIT 1",
                $this->getSyncName()
            );

            if ($lastSync) {
                $notes = json_decode($lastSync['notes'] ?? '{}', true);
                $status['last_sync'] = $lastSync['sync_date'];
                $status['last_sync_type'] = $notes['sync_type'] ?? 'unknown';
                $status['last_total'] = $lastSync['products_total'];
            }
        }

        return $status;
    }
}

<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Abstract Batched Sync
 *
 * Base class for all batched sync operations. Provides:
 * - State management with resume capability
 * - Unified logging
 * - Progress tracking
 * - Time limit handling
 * - Memory limit monitoring
 * - Per-item timeout tracking
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
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\NovotonHolidays\Services\PathResolver;

abstract class AbstractBatchedSync implements SyncInterface
{
    /**
     * State manager (interface-typed for testability).
     */
    protected StateManagerInterface $state;

    /**
     * Logger (interface-typed for testability).
     */
    protected SyncLoggerInterface $logger;

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
     * Memory usage threshold as fraction of PHP memory_limit (0.0 to 1.0)
     * @var float
     */
    protected float $memoryThreshold = 0.85;

    /**
     * Per-item warning threshold in seconds
     * @var int
     */
    protected int $itemTimeoutWarning = 30;

    /**
     * Constructor with optional DI.
     *
     * Collaborators are interface-typed and default to the concrete
     * StateManager / SyncLogger so existing subclasses that call
     * `parent::__construct()` with no arguments keep working unchanged.
     * Unit tests inject in-memory fakes or mocks.
     */
    public function __construct(
        ?StateManagerInterface $state = null,
        ?SyncLoggerInterface $logger = null,
    ) {
        $this->batchSize = ConfigProvider::getCronBatchSize();
        $this->maxExecutionTime = ConfigProvider::getCronMaxExecutionTime();

        $this->state = $state ?? new StateManager($this->getSyncName());
        $this->logger = $logger ?? new SyncLogger($this->getSyncName());
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
        $this->batchSize = max(ConfigProvider::MIN_BATCH_SIZE, min(ConfigProvider::MAX_BATCH_SIZE, $size));
    }

    /**
     * Set maximum execution time
     *
     * @param int $seconds
     */
    public function setMaxExecutionTime(int $seconds): void
    {
        $this->maxExecutionTime = max(ConfigProvider::MIN_EXECUTION_TIME, min(ConfigProvider::MAX_EXECUTION_TIME, $seconds));
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
            $srcDir = PathResolver::getPath('src');
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
     * Check if memory usage is approaching the PHP memory_limit.
     *
     * Returns true when current usage exceeds memoryThreshold fraction
     * of the configured limit, giving the process time to save state
     * before OOM.
     */
    protected function isMemoryLimitReached(): bool
    {
        $limit = self::parseMemoryLimit();
        if ($limit <= 0) {
            return false; // unlimited or unparseable
        }
        return memory_get_usage(true) > (int)($limit * $this->memoryThreshold);
    }

    /**
     * Check if any resource limit (time or memory) is reached.
     */
    protected function isLimitReached(): bool
    {
        return $this->isTimeLimitReached() || $this->isMemoryLimitReached();
    }

    /**
     * Sleep between processed items to avoid hammering the upstream API.
     *
     * Overridable in subclasses (and unit-test doubles) to change the delay
     * or skip it entirely. Default reads ConfigProvider::API_DELAY_MS.
     */
    protected function sleepBetweenItems(): void
    {
        usleep(ConfigProvider::API_DELAY_MS * 1000);
    }

    /**
     * Parse PHP memory_limit to bytes.
     */
    private static function parseMemoryLimit(): int
    {
        $limit = ini_get('memory_limit');
        if ($limit === '-1' || $limit === false) {
            return 0; // unlimited
        }
        $limit = trim($limit);
        $value = (int)$limit;
        $unit = strtolower(substr($limit, -1));
        switch ($unit) {
            case 'g': $value *= 1024;
            // fall through
            case 'm': $value *= 1024;
            // fall through
            case 'k': $value *= 1024;
        }
        return $value;
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

        // Load state once and decide: stale → clear, active → resume, else → fresh
        $currentState = $this->state->load();

        if ($currentState['status'] === 'in_progress') {
            if ($this->state->isStale()) {
                $this->logger->output("Stale state detected (no activity since {$currentState['last_run_at']}). Clearing and starting fresh.");
                $this->state->clear();
            } elseif ($currentState['processed'] < $currentState['total']) {
                $status = $this->state->getStatus();
                $this->logger->output("Resuming {$status['sync_type']} sync...");
                $this->logger->output("Progress: {$status['processed']}/{$status['total']} ({$status['percent']}%)");
                return $this->resumeSync();
            }
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
            'countries' => $options['countries'] ?? ConfigProvider::getSelectedCountries(),
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
            // Check time and memory limits before batch
            if ($this->isLimitReached()) {
                $elapsed = time() - $this->startTime;
                $mem = round(memory_get_usage(true) / 1024 / 1024, 1);
                $this->logger->output("\nLimit reached (time: {$elapsed}s, mem: {$mem}MB). Saving state for resume.");
                break;
            }

            // Get next batch
            $batch = $this->state->getNextBatch($this->batchSize);

            if (empty($batch)) {
                break;
            }

            // Accumulate counters per batch to avoid per-item file I/O
            $batchProcessed = 0;
            $batchSynced = 0;
            $batchErrors = 0;
            $batchErrorIds = [];

            foreach ($batch as $itemId) {
                // Check limits within batch
                if ($this->isLimitReached()) {
                    // Save accumulated batch progress before breaking
                    if ($batchProcessed > 0) {
                        $this->state->updateProgress($offset, $status['synced'] + $syncedThisRun, $status['errors'] + $errorsThisRun, $batchErrorIds);
                    }
                    break 2;
                }

                $itemStart = hrtime(true);
                $result = $this->processItem($itemId);
                $itemDurationMs = (int)((hrtime(true) - $itemStart) / 1_000_000);

                // Warn about slow items
                if ($itemDurationMs > $this->itemTimeoutWarning * 1000) {
                    $secs = round($itemDurationMs / 1000, 1);
                    $this->logger->output("Warning: item {$itemId} took {$secs}s (threshold: {$this->itemTimeoutWarning}s)");
                }

                $offset++;
                $processedThisRun++;
                $batchProcessed++;

                if ($result['success']) {
                    $syncedThisRun++;
                    $batchSynced++;
                } else {
                    $errorsThisRun++;
                    $batchErrors++;
                    $batchErrorIds[] = (string)$itemId;
                }

                // Small delay to avoid API rate limits
                $this->sleepBetweenItems();
            }

            // Save state once per batch instead of per item
            $this->state->updateProgress($offset, $status['synced'] + $syncedThisRun, $status['errors'] + $errorsThisRun, $batchErrorIds);

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
            $syncLogRepo = Container::getInstance()->syncLogRepository();
            $lastSync = $syncLogRepo->getLastSync($this->getSyncName());

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

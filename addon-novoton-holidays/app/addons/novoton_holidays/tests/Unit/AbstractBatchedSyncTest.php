<?php
declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Helpers\AbstractBatchedSync;
use Tygh\Addons\NovotonHolidays\Helpers\StateManagerInterface;
use Tygh\Addons\NovotonHolidays\Helpers\SyncLoggerInterface;

/**
 * Tests for the AbstractBatchedSync template-method base class.
 *
 * The three legacy Batched*Sync helpers (BatchedHotelFacilitiesSync,
 * BatchedHotelInfoSync, BatchedPriceInfoSync) do NOT yet extend this class,
 * so this test suite is the first line of safety for the eventual migration
 * tracked by PRs #7–9 of the architectural audit.
 *
 * Every test uses an anonymous subclass that:
 *   - injects StateManagerInterface + SyncLoggerInterface mocks via the
 *     constructor (PR #6 testability refactor),
 *   - no-ops sleepBetweenItems() so the test doesn't wait real 100 ms per item,
 *   - exposes an items list and a deterministic processItem() outcome map.
 *
 * @covers \Tygh\Addons\NovotonHolidays\Helpers\AbstractBatchedSync
 */
final class AbstractBatchedSyncTest extends TestCase
{
    /**
     * Build a concrete subclass of AbstractBatchedSync for testing.
     *
     * @param array<int, string|int>                  $itemsToSync    Items that getItemsToSync() will return.
     * @param string                                  $syncType       Value returned by determineSyncType().
     * @param array<int|string, array<string, mixed>> $itemOutcomes   processItem() returns for each item, keyed by item id.
     * @param bool                                    $limitPredicate Force isLimitReached() to return true after the first item.
     */
    private function makeSync(
        StateManagerInterface $state,
        SyncLoggerInterface $logger,
        string $syncName = 'test_sync',
        string $syncType = 'full',
        array $itemsToSync = [],
        array $itemOutcomes = [],
        bool $limitPredicate = false,
    ): AbstractBatchedSync {
        return new class($state, $logger, $syncName, $syncType, $itemsToSync, $itemOutcomes, $limitPredicate)
            extends AbstractBatchedSync
        {
            public array $processedItems = [];
            private int $limitCheckCount = 0;

            public function __construct(
                StateManagerInterface $state,
                SyncLoggerInterface $logger,
                private readonly string $syncNameValue,
                private readonly string $syncTypeValue,
                private readonly array $itemsToSyncValue,
                private readonly array $itemOutcomesValue,
                private readonly bool $limitPredicate,
            ) {
                parent::__construct($state, $logger);
            }

            protected function getSyncName(): string
            {
                return $this->syncNameValue;
            }

            protected function determineSyncType(array $options): string
            {
                return $this->syncTypeValue;
            }

            protected function getItemsToSync(string $syncType, array $options): array
            {
                return $this->itemsToSyncValue;
            }

            protected function processItem($itemId): array
            {
                $this->processedItems[] = $itemId;
                return $this->itemOutcomesValue[(string) $itemId]
                    ?? ['success' => true, 'message' => '', 'data' => null];
            }

            /** No real sleep inside unit tests. */
            protected function sleepBetweenItems(): void
            {
            }

            /**
             * Force limit-reached after the first item if configured.
             *
             * resumeSync() calls isLimitReached() twice per processed item:
             *   1. once at the top of the outer while loop (before a batch is fetched)
             *   2. once at the top of each foreach iteration (before processItem)
             *
             * To let the FIRST item through and trip on the SECOND, we must
             * allow the first 2 calls (outer + inner-for-item-1) and return
             * true on the 3rd call (inner-for-item-2).
             */
            protected function isLimitReached(): bool
            {
                if (!$this->limitPredicate) {
                    return false;
                }
                $this->limitCheckCount++;
                return $this->limitCheckCount >= 3;
            }
        };
    }

    // ── Config clamping ────────────────────────────────────────────────────

    public function testSetBatchSizeClampsBelowMinimum(): void
    {
        $sync = $this->makeSync($this->createMock(StateManagerInterface::class), $this->createMock(SyncLoggerInterface::class));
        $sync->setBatchSize(1);
        $this->assertSame(10, $this->readProtectedInt($sync, 'batchSize'), 'Should clamp to MIN_BATCH_SIZE = 10');
    }

    public function testSetBatchSizeClampsAboveMaximum(): void
    {
        $sync = $this->makeSync($this->createMock(StateManagerInterface::class), $this->createMock(SyncLoggerInterface::class));
        $sync->setBatchSize(10_000);
        $this->assertSame(500, $this->readProtectedInt($sync, 'batchSize'), 'Should clamp to MAX_BATCH_SIZE = 500');
    }

    public function testSetMaxExecutionTimeClampsBelowMinimum(): void
    {
        $sync = $this->makeSync($this->createMock(StateManagerInterface::class), $this->createMock(SyncLoggerInterface::class));
        $sync->setMaxExecutionTime(5);
        $this->assertSame(60, $this->readProtectedInt($sync, 'maxExecutionTime'), 'Should clamp to MIN_EXECUTION_TIME = 60');
    }

    public function testSetMaxExecutionTimeClampsAboveMaximum(): void
    {
        $sync = $this->makeSync($this->createMock(StateManagerInterface::class), $this->createMock(SyncLoggerInterface::class));
        $sync->setMaxExecutionTime(999_999);
        $this->assertSame(3600, $this->readProtectedInt($sync, 'maxExecutionTime'), 'Should clamp to MAX_EXECUTION_TIME = 3600');
    }

    // ── run() control flow ────────────────────────────────────────────────

    public function testRunWithResetOptionClearsStateAndReturns(): void
    {
        $state = $this->createMock(StateManagerInterface::class);
        $logger = $this->createMock(SyncLoggerInterface::class);

        $state->expects($this->once())->method('clear');
        $state->expects($this->never())->method('load');

        $sync = $this->makeSync($state, $logger);

        $result = $sync->run(['reset' => true]);

        $this->assertSame(['status' => 'reset'], $result);
    }

    public function testRunReturnsSkippedWhenSyncTypeIsNone(): void
    {
        $state = $this->createMock(StateManagerInterface::class);
        $state->method('load')->willReturn(['status' => 'idle']);

        $sync = $this->makeSync(
            $state,
            $this->createMock(SyncLoggerInterface::class),
            syncType: 'none',
        );

        $result = $sync->run();

        $this->assertSame(['status' => 'skipped', 'reason' => 'No sync needed'], $result);
    }

    public function testRunReturnsSkippedWhenNoItemsFound(): void
    {
        $state = $this->createMock(StateManagerInterface::class);
        $state->method('load')->willReturn(['status' => 'idle']);

        $sync = $this->makeSync(
            $state,
            $this->createMock(SyncLoggerInterface::class),
            itemsToSync: [],
        );

        $result = $sync->run();

        $this->assertSame(['status' => 'skipped', 'reason' => 'No items found'], $result);
    }

    public function testRunProcessesAllItemsAndCompletes(): void
    {
        $state = $this->createMock(StateManagerInterface::class);
        $logger = $this->createMock(SyncLoggerInterface::class);

        $items = ['a', 'b', 'c'];

        $state->method('load')->willReturn(['status' => 'idle']);
        $state->expects($this->once())->method('start')->with('full', $items, $this->isType('array'));

        // After start(), getStatus() returns a fresh snapshot.
        $state->method('getStatus')->willReturn([
            'status' => 'in_progress',
            'sync_type' => 'full',
            'processed' => 0,
            'total' => 3,
            'synced' => 0,
            'errors' => 0,
        ]);

        // getNextBatch() returns the whole list in one go, then an empty array.
        $state->method('getNextBatch')->willReturnOnConsecutiveCalls($items, []);

        $state->expects($this->once())->method('updateProgress')
            ->with(3, 3, 0, $this->isType('array'));

        $state->expects($this->once())->method('complete')->willReturn([
            'sync_type' => 'full',
            'total' => 3,
            'synced' => 3,
            'errors' => 0,
            'duration_seconds' => 1,
            'metadata' => ['countries' => ['RO', 'BG']],
        ]);

        $state->expects($this->once())->method('clear');

        $logger->expects($this->once())->method('logToDatabase')->with('completed');
        $logger->expects($this->once())->method('outputSummary');
        $logger->expects($this->once())->method('sendEmailReport')->with([], 'RO, BG');

        $sync = $this->makeSync(
            $state,
            $logger,
            itemsToSync: $items,
            itemOutcomes: [
                'a' => ['success' => true, 'message' => '', 'data' => null],
                'b' => ['success' => true, 'message' => '', 'data' => null],
                'c' => ['success' => true, 'message' => '', 'data' => null],
            ],
        );

        $result = $sync->run(['countries' => ['RO', 'BG']]);

        $this->assertSame('completed', $result['status']);
        $this->assertSame('full', $result['sync_type']);
        $this->assertSame(3, $result['total']);
        $this->assertSame(3, $result['synced']);
        $this->assertSame(0, $result['errors']);
        $this->assertSame(['a', 'b', 'c'], $sync->processedItems);
    }

    public function testRunStaleInProgressStateStartsFreshSync(): void
    {
        $state = $this->createMock(StateManagerInterface::class);
        $logger = $this->createMock(SyncLoggerInterface::class);

        $state->method('load')->willReturn([
            'status' => 'in_progress',
            'processed' => 5,
            'total' => 10,
            'last_run_at' => '2020-01-01 00:00:00',
        ]);
        $state->method('isStale')->willReturn(true);

        // Stale → clear() is called (once), then a fresh sync starts.
        $state->expects($this->once())->method('clear');

        // No items returned → fresh sync skips before touching start().
        $state->expects($this->never())->method('start');

        $sync = $this->makeSync($state, $logger, itemsToSync: []);
        $result = $sync->run();

        $this->assertSame('skipped', $result['status']);
    }

    public function testRunResumesNonStaleInProgressState(): void
    {
        $state = $this->createMock(StateManagerInterface::class);
        $logger = $this->createMock(SyncLoggerInterface::class);

        $state->method('load')->willReturn([
            'status' => 'in_progress',
            'processed' => 1,
            'total' => 3,
            'last_run_at' => date('Y-m-d H:i:s'),
        ]);
        $state->method('isStale')->willReturn(false);

        // Resume path: determineSyncType / getItemsToSync / start MUST NOT be called.
        $state->expects($this->never())->method('start');

        $state->method('getStatus')->willReturn([
            'status' => 'in_progress',
            'sync_type' => 'full',
            'processed' => 1,
            'total' => 3,
            'synced' => 1,
            'errors' => 0,
            'percent' => 33,
        ]);

        $state->method('getNextBatch')->willReturnOnConsecutiveCalls(['b', 'c'], []);
        $state->expects($this->once())->method('complete')->willReturn([
            'sync_type' => 'full',
            'total' => 3,
            'synced' => 3,
            'errors' => 0,
            'duration_seconds' => 2,
            'metadata' => [],
        ]);
        $state->expects($this->once())->method('clear');

        $sync = $this->makeSync(
            $state,
            $logger,
            itemsToSync: ['a', 'b', 'c'],
            itemOutcomes: [
                'b' => ['success' => true, 'message' => '', 'data' => null],
                'c' => ['success' => true, 'message' => '', 'data' => null],
            ],
        );

        $result = $sync->run();

        $this->assertSame('completed', $result['status']);
        $this->assertSame(['b', 'c'], $sync->processedItems, 'Resume should only process the remaining items');
    }

    public function testFailedItemsAreCountedAsErrors(): void
    {
        $state = $this->createMock(StateManagerInterface::class);

        $items = ['ok1', 'err1', 'ok2'];

        $state->method('load')->willReturn(['status' => 'idle']);
        $state->method('getStatus')->willReturn([
            'status' => 'in_progress',
            'sync_type' => 'full',
            'processed' => 0,
            'total' => 3,
            'synced' => 0,
            'errors' => 0,
        ]);
        $state->method('getNextBatch')->willReturnOnConsecutiveCalls($items, []);

        $state->expects($this->once())->method('updateProgress')
            ->with(
                3,
                2, // synced
                1, // errors
                $this->callback(static fn($errorIds): bool => $errorIds === ['err1']),
            );

        $state->method('complete')->willReturn([
            'sync_type' => 'full',
            'total' => 3,
            'synced' => 2,
            'errors' => 1,
            'duration_seconds' => 1,
            'metadata' => [],
        ]);

        $sync = $this->makeSync(
            $state,
            $this->createMock(SyncLoggerInterface::class),
            itemsToSync: $items,
            itemOutcomes: [
                'ok1'  => ['success' => true,  'message' => '', 'data' => null],
                'err1' => ['success' => false, 'message' => 'boom', 'data' => null],
                'ok2'  => ['success' => true,  'message' => '', 'data' => null],
            ],
        );

        $result = $sync->run();

        $this->assertSame('completed', $result['status']);
        $this->assertSame(2, $result['synced']);
        $this->assertSame(1, $result['errors']);
    }

    public function testResumeReturnsInProgressWhenLimitReached(): void
    {
        $state = $this->createMock(StateManagerInterface::class);

        $items = ['a', 'b', 'c', 'd', 'e'];

        $state->method('load')->willReturn(['status' => 'idle']);
        $state->method('getStatus')->willReturn([
            'status' => 'in_progress',
            'sync_type' => 'full',
            'processed' => 0,
            'total' => 5,
            'synced' => 0,
            'errors' => 0,
        ]);
        $state->method('getNextBatch')->willReturnOnConsecutiveCalls($items, []);

        // One item is processed before the limit predicate trips, so
        // updateProgress is invoked exactly once with offset = 1.
        $state->expects($this->once())->method('updateProgress')
            ->with(1, 1, 0, $this->isType('array'));

        // Time limit reached → completeSync() must NOT run.
        $state->expects($this->never())->method('complete');

        $sync = $this->makeSync(
            $state,
            $this->createMock(SyncLoggerInterface::class),
            itemsToSync: $items,
            limitPredicate: true,
        );

        $result = $sync->run();

        $this->assertSame('in_progress', $result['status']);
        $this->assertSame(5, $result['total']);
        $this->assertSame(1, $result['processed']);
        $this->assertSame(4, $result['remaining']);
        $this->assertSame(1, $result['synced_this_run']);
        $this->assertSame(0, $result['errors_this_run']);
        $this->assertCount(1, $sync->processedItems, 'Only the first item should have been processed');
    }

    /**
     * Read a protected int property via reflection for the clamping tests.
     */
    private function readProtectedInt(object $instance, string $property): int
    {
        $ref = new \ReflectionProperty($instance::class, $property);
        return (int) $ref->getValue($instance);
    }
}

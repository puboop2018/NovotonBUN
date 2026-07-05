<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Repository\BookingRepository;
use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;
use Tygh\Addons\NovotonHolidays\Tests\Support\RecordingBookingSyncRepository;

/**
 * Regression guard for the booking-submission atomicity fix (audit C1).
 *
 * BookingRepository::create()/update()/delete() route through a nesting-aware
 * withTransaction() helper. The bug this prevents: when a caller (e.g. the old
 * BookingSubmissionService::submitOrder) had opened its own transaction and then
 * called create(), create()'s unconditional `START TRANSACTION` IMPLICITLY
 * committed the caller's transaction — MySQL has no nested transactions — so the
 * caller's later ROLLBACK became a silent no-op and partial multi-room bookings
 * persisted. These tests prove inner writes now JOIN an already-open transaction
 * instead of opening (and implicitly committing) their own.
 */
#[CoversClass(BookingRepository::class)]
class BookingRepositoryTransactionNestingTest extends TestCase
{
    private RecordingBookingSyncRepository $sync;
    private BookingRepository $repo;
    /** @var list<string> */
    private array $queries = [];

    protected function setUp(): void
    {
        DbStub::reset();
        $this->queries = [];
        $this->sync = new RecordingBookingSyncRepository();
        $this->repo = new BookingRepository($this->sync);
        $this->resetTxDepth();

        // Record every statement; return an insert id for the bookings INSERT.
        DbStub::$query = function (string $query): int {
            $this->queries[] = $query;
            return str_contains($query, 'INSERT INTO ?:novoton_bookings') ? 77 : 1;
        };
    }

    protected function tearDown(): void
    {
        DbStub::reset();
        $this->resetTxDepth();
    }

    /** Reset the repository's static transaction-depth counter between tests. */
    private function resetTxDepth(): void
    {
        (new \ReflectionProperty(BookingRepository::class, 'txDepth'))->setValue(null, 0);
    }

    /** Invoke the private nesting-aware helper as a caller-owned transaction. */
    private function inTransaction(callable $callback): void
    {
        (new \ReflectionMethod(BookingRepository::class, 'withTransaction'))
            ->invoke($this->repo, $callback);
    }

    private function countStatements(string $needle): int
    {
        return count(array_filter($this->queries, static fn (string $q): bool => $q === $needle));
    }

    private function assertRan(string $needle): void
    {
        $hit = array_filter($this->queries, static fn (string $q): bool => str_contains($q, $needle));
        self::assertNotEmpty($hit, "expected a query containing: {$needle}");
    }

    public function testInnerWritesJoinTheOuterTransactionInsteadOfNesting(): void
    {
        $this->inTransaction(function (): void {
            $this->repo->create(['hotel_id' => 'H1', 'status' => 'pending']);
            $this->repo->update(1, ['status' => 'confirmed']);
        });

        // Exactly ONE transaction wraps BOTH writes — not one per repo call.
        // (Before the fix this would be multiple START/COMMIT pairs, each inner
        //  START implicitly committing the outer transaction.)
        self::assertSame(1, $this->countStatements('START TRANSACTION'), 'inner writes must not open their own transaction');
        self::assertSame(1, $this->countStatements('COMMIT'));
        self::assertSame(0, $this->countStatements('ROLLBACK'));
        // ...and the work still happened inside that single transaction.
        $this->assertRan('INSERT INTO ?:novoton_bookings');
        $this->assertRan('UPDATE ?:novoton_bookings');
    }

    public function testInnerFailureRollsBackTheWholeUnitExactlyOnce(): void
    {
        $this->sync->throwOnUpsert = true;

        try {
            $this->inTransaction(function (): void {
                $this->repo->create(['hotel_id' => 'H1']);
            });
            self::fail('expected the inner sync failure to propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('sync failed', $e->getMessage());
        }

        self::assertSame(1, $this->countStatements('START TRANSACTION'));
        self::assertSame(1, $this->countStatements('ROLLBACK'), 'a nested failure must roll the whole unit back');
        self::assertSame(0, $this->countStatements('COMMIT'), 'no partial commit on failure');
    }
}

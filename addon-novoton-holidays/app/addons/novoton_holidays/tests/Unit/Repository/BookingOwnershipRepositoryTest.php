<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Repository\BookingOwnershipRepository;
use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;

/**
 * Characterization coverage for BookingOwnershipRepository — the ownership /
 * security boundary extracted from BookingRepository. The tests pin the exact
 * SQL and parameters each method issues, with particular attention to the
 * ownership-scoping branches and the "no context → return nothing" guard that
 * prevents cross-customer booking leakage. DB access is routed through DbStub.
 */
#[CoversClass(BookingOwnershipRepository::class)]
class BookingOwnershipRepositoryTest extends TestCase
{
    private BookingOwnershipRepository $repo;

    protected function setUp(): void
    {
        DbStub::reset();
        $this->repo = new BookingOwnershipRepository();
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    // ── findByProductIds ─────────────────────────────────────────────────────

    public function testFindByProductIdsReturnsEmptyForNoProducts(): void
    {
        $called = false;
        DbStub::$getArray = static function () use (&$called): array {
            $called = true;
            return [];
        };

        $this->assertSame([], $this->repo->findByProductIds([], [], 'sess', 1));
        $this->assertFalse($called, 'no query should run without product IDs');
    }

    public function testFindByProductIdsReturnsNothingWithoutOwnershipContext(): void
    {
        $called = false;
        DbStub::$getArray = static function () use (&$called): array {
            $called = true;
            return [['booking_id' => 1]];
        };

        // No user_id and no session_id — must refuse to query rather than leak.
        $result = $this->repo->findByProductIds([10, 11], ['P'], '', 0);

        $this->assertSame([], $result);
        $this->assertFalse($called, 'must not query without an ownership context');
    }

    public function testFindByProductIdsScopesToUserAndSession(): void
    {
        $rows = [['booking_id' => 5, 'product_id' => 10]];
        $captured = [];
        DbStub::$getArray = static function (string $query, ...$params) use ($rows, &$captured): array {
            $captured = [$query, $params];
            return $rows;
        };

        $result = $this->repo->findByProductIds([10, 11], ['pending', 'confirmed'], 'sess-1', 42);

        $this->assertSame($rows, $result);
        $this->assertStringContainsString('WHERE product_id IN (?n) AND status IN (?a)', $captured[0]);
        $this->assertStringContainsString('AND (session_id = ?s OR user_id = ?i) ORDER BY booking_id DESC', $captured[0]);
        $this->assertSame([[10, 11], ['pending', 'confirmed'], 'sess-1', 42], $captured[1]);
    }

    public function testFindByProductIdsScopesToUserOnly(): void
    {
        $captured = [];
        DbStub::$getArray = static function (string $query, ...$params) use (&$captured): array {
            $captured = [$query, $params];
            return [];
        };

        $this->repo->findByProductIds([7], ['pending'], '', 42);

        $this->assertStringContainsString('AND user_id = ?i ORDER BY booking_id DESC', $captured[0]);
        $this->assertStringNotContainsString('session_id = ?s', $captured[0]);
        $this->assertSame([[7], ['pending'], 42], $captured[1]);
    }

    public function testFindByProductIdsScopesToSessionOnly(): void
    {
        $captured = [];
        DbStub::$getArray = static function (string $query, ...$params) use (&$captured): array {
            $captured = [$query, $params];
            return [];
        };

        $this->repo->findByProductIds([7], ['pending'], 'sess-9', 0);

        $this->assertStringContainsString('AND session_id = ?s ORDER BY booking_id DESC', $captured[0]);
        $this->assertStringNotContainsString('user_id = ?i', $captured[0]);
        $this->assertSame([[7], ['pending'], 'sess-9'], $captured[1]);
    }

    // ── findByIdWithOwnership ────────────────────────────────────────────────

    public function testFindByIdWithOwnershipReturnsOwnedRow(): void
    {
        $row = ['booking_id' => 3, 'user_id' => 42];
        $captured = [];
        DbStub::$getRow = static function (string $query, ...$params) use ($row, &$captured): array {
            $captured = [$query, $params];
            return $row;
        };

        $result = $this->repo->findByIdWithOwnership(3, 42, 'sess-1');

        $this->assertSame($row, $result);
        $this->assertStringContainsString(
            'WHERE booking_id = ?i AND (user_id = ?i OR session_id = ?s)',
            $captured[0],
        );
        $this->assertSame([3, 42, 'sess-1'], $captured[1]);
    }

    public function testFindByIdWithOwnershipReturnsNullWhenNotOwned(): void
    {
        DbStub::$getRow = static fn (string $query, ...$params): array => [];

        $this->assertNull($this->repo->findByIdWithOwnership(3, 99, 'someone-else'));
    }

    // ── checkOwnership ───────────────────────────────────────────────────────

    public function testCheckOwnershipReturnsIdWhenOwned(): void
    {
        $captured = [];
        DbStub::$getField = static function (string $query, ...$params) use (&$captured): string {
            $captured = [$query, $params];
            return '3';
        };

        $this->assertSame(3, $this->repo->checkOwnership(3, 42, 'sess-1'));
        $this->assertStringContainsString(
            'SELECT booking_id FROM ?:novoton_bookings WHERE booking_id = ?i AND (user_id = ?i OR session_id = ?s)',
            $captured[0],
        );
        $this->assertSame([3, 42, 'sess-1'], $captured[1]);
    }

    public function testCheckOwnershipReturnsNullWhenNotOwned(): void
    {
        DbStub::$getField = static fn (string $query, ...$params) => null;

        $this->assertNull($this->repo->checkOwnership(3, 99, 'someone-else'));
    }
}

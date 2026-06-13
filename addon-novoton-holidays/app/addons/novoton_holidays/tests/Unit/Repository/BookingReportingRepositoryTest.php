<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Repository\BookingReportingRepository;
use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;

/**
 * Characterization coverage for BookingReportingRepository — the admin/aggregate
 * booking queries extracted from BookingRepository. Tests pin the SQL and the
 * parameters issued, including the filter -> WHERE-clause building used by
 * count(). DB access is routed through DbStub.
 */
#[CoversClass(BookingReportingRepository::class)]
class BookingReportingRepositoryTest extends TestCase
{
    private BookingReportingRepository $repo;

    protected function setUp(): void
    {
        DbStub::reset();
        $this->repo = new BookingReportingRepository();
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    public function testCountWithoutFiltersHasNoWhereClause(): void
    {
        $captured = [];
        DbStub::$getField = static function (string $query, ...$params) use (&$captured): string {
            $captured = [$query, $params];
            return '40';
        };

        $this->assertSame(40, $this->repo->count());
        $this->assertStringContainsString('SELECT COUNT(*) FROM ?:novoton_bookings', $captured[0]);
        $this->assertStringNotContainsString('WHERE', $captured[0]);
    }

    public function testCountBuildsWhereClauseFromFilters(): void
    {
        $captured = [];
        DbStub::$getField = static function (string $query, ...$params) use (&$captured): string {
            $captured = [$query, $params];
            return '3';
        };

        $this->assertSame(3, $this->repo->count(['status' => 'pending', 'no_order' => true]));

        // db_quote interpolates the status; no_order is a literal predicate.
        $this->assertStringContainsString("WHERE status = 'pending' AND order_id = 0", $captured[0]);
    }

    public function testCountSupportsAllFilterKeys(): void
    {
        $captured = '';
        DbStub::$getField = static function (string $query, ...$params) use (&$captured): string {
            $captured = $query;
            return '1';
        };

        $this->repo->count([
            'hotel_id' => 'H1',
            'order_id' => 7,
            'user_id' => 9,
            'has_order' => true,
            'check_in_from' => '2026-01-01',
            'check_in_to' => '2026-12-31',
        ]);

        $this->assertStringContainsString("hotel_id = 'H1'", $captured);
        $this->assertStringContainsString('order_id = 7', $captured);
        $this->assertStringContainsString('user_id = 9', $captured);
        $this->assertStringContainsString('order_id > 0', $captured);
        $this->assertStringContainsString("check_in >= '2026-01-01'", $captured);
        $this->assertStringContainsString("check_in <= '2026-12-31'", $captured);
    }

    public function testCountOrphans(): void
    {
        $captured = [];
        DbStub::$getField = static function (string $query, ...$params) use (&$captured): string {
            $captured = [$query, $params];
            return '5';
        };

        $this->assertSame(5, $this->repo->countOrphans(48));
        $this->assertStringContainsString('WHERE order_id = 0 AND created_at < DATE_SUB(NOW(), INTERVAL ?i HOUR)', $captured[0]);
        $this->assertSame([48], $captured[1]);
    }

    public function testFindForAdminListJoinsOrdersAndAppliesCondition(): void
    {
        $rows = [['booking_id' => 1, 'order_status' => 'P']];
        $captured = [];
        DbStub::$getArray = static function (string $query, ...$params) use ($rows, &$captured): array {
            $captured = [$query, $params];
            return $rows;
        };

        $result = $this->repo->findForAdminList(" AND b.status = 'pending'", 250);

        $this->assertSame($rows, $result);
        $this->assertStringContainsString('LEFT JOIN ?:orders o ON b.order_id = o.order_id', $captured[0]);
        $this->assertStringContainsString("WHERE 1=1  AND b.status = 'pending'", $captured[0]);
        $this->assertSame([250], $captured[1]);
    }

    public function testFindWithOrderDetailsReturnsRow(): void
    {
        $row = ['booking_id' => 7, 'product' => 'X'];
        $captured = [];
        DbStub::$getRow = static function (string $query, ...$params) use ($row, &$captured): array {
            $captured = [$query, $params];
            return $row;
        };

        $result = $this->repo->findWithOrderDetails(7);

        $this->assertSame($row, $result);
        $this->assertStringContainsString('LEFT JOIN ?:products p ON b.product_id = p.product_id', $captured[0]);
        $this->assertStringContainsString('WHERE b.booking_id = ?i', $captured[0]);
        $this->assertSame([7], $captured[1]);
    }

    public function testFindWithOrderDetailsReturnsNullWhenMissing(): void
    {
        DbStub::$getRow = static fn (string $query, ...$params): array => [];

        $this->assertNull($this->repo->findWithOrderDetails(404));
    }

    public function testFindAllForExport(): void
    {
        $rows = [['booking_id' => 1], ['booking_id' => 2]];
        $captured = '';
        DbStub::$getArray = static function (string $query, ...$params) use ($rows, &$captured): array {
            $captured = $query;
            return $rows;
        };

        $this->assertSame($rows, $this->repo->findAllForExport());
        $this->assertStringContainsString('SELECT b.*, o.email, o.status as order_status', $captured);
        $this->assertStringContainsString('ORDER BY b.created_at DESC', $captured);
    }
}

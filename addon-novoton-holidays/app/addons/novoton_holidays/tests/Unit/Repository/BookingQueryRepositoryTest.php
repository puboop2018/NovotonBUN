<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Repository\BookingQueryRepository;
use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;
use Tygh\Addons\TravelCore\TravelConstants;

/**
 * Characterization coverage for BookingQueryRepository — the read-model list
 * queries extracted from BookingRepository. Pins the empty-input short-circuit,
 * row narrowing, and that each query targets the right column/params (so the
 * delegation from BookingRepository stays faithful).
 */
#[CoversClass(BookingQueryRepository::class)]
class BookingQueryRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        DbStub::reset();
    }

    private function repo(): BookingQueryRepository
    {
        return new BookingQueryRepository();
    }

    public function testFindByOrderIdsShortCircuitsOnEmptyInput(): void
    {
        DbStub::$getArray = static function (): array {
            throw new \RuntimeException('db should not be queried for empty input');
        };

        self::assertSame([], $this->repo()->findByOrderIds([]));
    }

    public function testFindByOrderIdQueriesByOrderAndNarrowsRows(): void
    {
        $captured = [];
        DbStub::$getArray = static function (string $query, ...$params) use (&$captured): array {
            $captured = [$query, $params];
            return [['booking_id' => '7', 'order_id' => '5']];
        };

        $rows = $this->repo()->findByOrderId(5);

        self::assertCount(1, $rows);
        self::assertSame('7', $rows[0]['booking_id']);
        self::assertStringContainsString('order_id = ?i', $captured[0]);
        self::assertSame([5], $captured[1]);
    }

    public function testFindPendingFiltersByPendingStatus(): void
    {
        $captured = [];
        DbStub::$getArray = static function (string $query, ...$params) use (&$captured): array {
            $captured = $params;
            return [['booking_id' => '1']];
        };

        $this->repo()->findPending(25);

        self::assertSame([TravelConstants::STATUS_PENDING, 25], $captured);
    }

    public function testFindByNovotonStatusPassesStatusListThrough(): void
    {
        $captured = [];
        DbStub::$getArray = static function (string $query, ...$params) use (&$captured): array {
            $captured = $params;
            return [];
        };

        $this->repo()->findByNovotonStatus('ASK', ['pending', 'confirmed'], 10);

        self::assertSame(['ASK', ['pending', 'confirmed'], 10], $captured);
    }
}

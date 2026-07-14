<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Repository\SphinxBookingRepository;
use Tygh\Addons\SphinxHolidays\Tests\Support\DbStub;

/**
 * Dual-write consistency of SphinxBookingRepository::update().
 *
 * Production regression this pins: db_query() returns AFFECTED ROWS for
 * UPDATE, and re-writing an identical value (reconcile re-linking an
 * already-linked booking's order_id) affects 0 rows. The old code treated
 * that 0 as failure and skipped the travel_bookings mirror sync, so the
 * unified admin grid showed Order ID "-" forever while the reconcile tool
 * reported "linked". The mirror sync must run unconditionally.
 */
#[CoversClass(SphinxBookingRepository::class)]
class SphinxBookingRepositoryTest extends TestCase
{
    private SphinxBookingRepository $repo;

    protected function setUp(): void
    {
        DbStub::reset();
        $this->repo = new SphinxBookingRepository();
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    /**
     * @return list<array{sql: string, params: list<mixed>}>
     */
    private function runUpdateCapturingQueries(int $affectedRows): array
    {
        $queries = [];
        DbStub::$query = static function (string $query, ...$params) use (&$queries, $affectedRows): int {
            $queries[] = ['sql' => $query, 'params' => $params];
            return str_contains($query, 'UPDATE ?:sphinx_bookings') ? $affectedRows : 1;
        };

        $result = $this->repo->update(77, ['order_id' => 86]);
        self::assertTrue($result, 'update() must report success (0 affected rows is not failure)');

        return $queries;
    }

    /**
     * @param list<array{sql: string, params: list<mixed>}> $queries
     * @return list<array{sql: string, params: list<mixed>}>
     */
    private static function travelBookingsUpdates(array $queries): array
    {
        return array_values(array_filter(
            $queries,
            static fn (array $q): bool => str_contains($q['sql'], 'UPDATE ?:travel_bookings'),
        ));
    }

    public function testUpdateWithZeroAffectedRowsStillSyncsTravelBookingsMirror(): void
    {
        // sphinx_bookings.order_id already holds 86 → MySQL affects 0 rows.
        $queries = $this->runUpdateCapturingQueries(0);

        $mirror = self::travelBookingsUpdates($queries);
        self::assertCount(
            1,
            $mirror,
            'travel_bookings mirror UPDATE must run even when the sphinx_bookings UPDATE affects 0 rows',
        );
        self::assertSame(['order_id' => 86], $mirror[0]['params'][0]);
        // Shared TravelBookingMirror binds provider then booking id.
        self::assertSame('sphinx', $mirror[0]['params'][1]);
        self::assertSame('77', $mirror[0]['params'][2], 'mirror row is addressed by provider_booking_id (stringified)');
    }

    public function testUpdateWithChangedRowSyncsTravelBookingsMirror(): void
    {
        $queries = $this->runUpdateCapturingQueries(1);

        self::assertCount(1, self::travelBookingsUpdates($queries));
    }

    public function testLinkToOrderRoutesThroughMirrorSync(): void
    {
        $queries = [];
        DbStub::$query = static function (string $query, ...$params) use (&$queries): int {
            $queries[] = ['sql' => $query, 'params' => $params];
            return 0; // idempotent re-link: nothing changes in sphinx_bookings
        };

        $this->repo->linkToOrder(77, 86);

        $mirror = self::travelBookingsUpdates($queries);
        self::assertCount(1, $mirror, 'reconcile path (linkToOrder) must reach the travel_bookings mirror');
        self::assertSame(['order_id' => 86], $mirror[0]['params'][0]);
    }
}

<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Repository;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Repository\OrderLinkCandidateRepository;
use Tygh\Addons\TravelCore\Tests\Support\DbStub;

/**
 * Pins the pre-filter that keeps the order-link reconcile cheap: every
 * order id this repository returns costs a full fn_get_order_info() replay
 * per provider, so the SQL must (a) skip everything when nothing is
 * unlinked, (b) constrain the automatic pass to the unlinked-booking time
 * window, and (c) drop orders whose items never reference a provider
 * booking before any hook fires.
 */
final class OrderLinkCandidateRepositoryTest extends TestCase
{
    private OrderLinkCandidateRepository $repo;

    /** @var list<string> */
    private array $fieldQueries = [];

    protected function setUp(): void
    {
        DbStub::reset();
        $this->repo = new OrderLinkCandidateRepository();
        $this->fieldQueries = [];
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    public function testAutoCandidatesShortCircuitWhenNothingIsUnlinked(): void
    {
        DbStub::$getRow = function (string $query): array {
            self::assertStringContainsString('FROM ?:travel_bookings WHERE order_id IS NULL OR order_id = 0', $query);

            // MIN()/MAX() over zero rows → NULLs.
            return ['t0' => null, 't1' => null];
        };
        DbStub::$getFields = function (string $query): array {
            $this->fieldQueries[] = $query;

            return [];
        };

        self::assertSame([], $this->repo->findAutoCandidates(100));
        self::assertSame([], $this->fieldQueries, 'no order scan may run when nothing is unlinked');
    }

    public function testAutoCandidatesWindowThePaddedUnlinkedRangeAndProbeOrderItems(): void
    {
        DbStub::$getRow = static fn (string $query): array => ['t0' => '1000000', 't1' => '1000600'];

        DbStub::$getFields = function (string $query, ...$params): array {
            $this->fieldQueries[] = $query;

            if (str_contains($query, 'FROM ?:orders')) {
                self::assertStringContainsString('WHERE timestamp BETWEEN ?i AND ?i', $query);
                self::assertStringContainsString('ORDER BY order_id DESC LIMIT ?i', $query);
                // One-day padding on both sides of the unlinked window.
                self::assertSame([1000000 - 86400, 1000600 + 86400, 100], $params);

                return ['12', '11', '10'];
            }

            self::assertStringContainsString('FROM ?:order_details WHERE order_id IN (?n) AND extra LIKE ?l', $query);
            self::assertSame([[12, 11, 10], '%booking_id%'], $params);

            // DISTINCT gives no order guarantee — return shuffled on purpose.
            return ['10', '12'];
        };

        // Newest-first order preserved; the LIKE-missed order 11 is dropped.
        self::assertSame([12, 10], $this->repo->findAutoCandidates(100));
        self::assertCount(2, $this->fieldQueries);
    }

    public function testSweepCandidatesWalkNewestOrdersWithoutTimeWindow(): void
    {
        DbStub::$getRow = static function (): array {
            self::fail('the manual sweep must not depend on the unlinked-booking window');
        };
        DbStub::$getFields = function (string $query, ...$params): array {
            $this->fieldQueries[] = $query;

            if (str_contains($query, 'FROM ?:orders')) {
                self::assertStringContainsString('ORDER BY order_id DESC LIMIT ?i', $query);
                self::assertStringNotContainsString('timestamp', $query);
                self::assertSame([500], $params);

                return ['5', '4', '3'];
            }

            return ['4'];
        };

        self::assertSame([4], $this->repo->findSweepCandidates(500));
    }

    public function testNoCandidatesSurviveWhenNoOrderContainsBookingItems(): void
    {
        DbStub::$getFields = static function (string $query): array {
            return str_contains($query, 'FROM ?:orders') ? ['7'] : [];
        };

        self::assertSame([], $this->repo->findSweepCandidates(10));
    }
}

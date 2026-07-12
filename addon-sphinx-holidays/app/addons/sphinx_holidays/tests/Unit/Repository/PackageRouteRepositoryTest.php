<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Repository\PackageRouteRepository;
use Tygh\Addons\SphinxHolidays\Tests\Support\DbStub;

/**
 * PackageRouteRepository read paths that feed the admin grid: the paginated
 * listing (CS-Cart pagination contract), the freshness count and last-synced.
 */
#[CoversClass(PackageRouteRepository::class)]
final class PackageRouteRepositoryTest extends TestCase
{
    private PackageRouteRepository $repo;

    protected function setUp(): void
    {
        DbStub::reset();
        $this->repo = new PackageRouteRepository();
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    public function testGetListingReturnsRowsAndPagerParams(): void
    {
        $rows = [['route_id' => 5, 'departure_name' => 'Timisoara', 'arrival_name' => 'Corfu']];
        DbStub::$getField = static fn (string $q, ...$p): string => '2000';
        $captured = [];
        DbStub::$getArray = static function (string $q, ...$p) use (&$captured, $rows): array {
            $captured = [$q, $p];
            return $rows;
        };

        [$result, $params] = $this->repo->getListing(['page' => 3, 'items_per_page' => 50]);

        self::assertSame($rows, $result);
        self::assertSame(2000, $params['total_items']);
        self::assertSame(3, $params['page']);
        self::assertSame(50, $params['items_per_page']);
        self::assertStringContainsString('FROM ?:sphinx_package_routes r', $captured[0]);
        self::assertStringContainsString('LIMIT ?i, ?i', $captured[0]);
        // offset = (3-1)*50 = 100.
        self::assertSame(100, $captured[1][1]);
        self::assertSame(50, $captured[1][2]);
    }

    public function testGetListingDefaultsToDepartureNameAsc(): void
    {
        DbStub::$getField = static fn (string $q, ...$p): string => '0';
        $captured = '';
        DbStub::$getArray = static function (string $q, ...$p) use (&$captured): array {
            $captured = $q;
            return [];
        };

        $this->repo->getListing([]);
        self::assertStringContainsString('ORDER BY r.departure_name ASC', $captured);
    }

    public function testGetListingBuildsDurationFilter(): void
    {
        $condition = null;
        DbStub::$getField = static function (string $q, ...$p) use (&$condition): string {
            $condition = $p[0] ?? '';
            return '0';
        };
        DbStub::$getArray = static fn (string $q, ...$p): array => [];

        $this->repo->getListing(['duration' => '7']);
        self::assertStringContainsString('r.duration = 7', (string) $condition);
    }

    public function testGetLastSyncedAtAndCountAll(): void
    {
        DbStub::$getField = static fn (string $q, ...$p): string => '2026-07-02 04:00:00';
        self::assertSame('2026-07-02 04:00:00', $this->repo->getLastSyncedAt());

        DbStub::$getField = static fn (string $q, ...$p): string => '';
        self::assertNull($this->repo->getLastSyncedAt());

        DbStub::$getField = static fn (string $q, ...$p): string => '1980';
        self::assertSame(1980, $this->repo->countAll());
    }
}

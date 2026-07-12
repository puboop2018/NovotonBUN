<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Repository\AlternativeRequestRepository;
use Tygh\Addons\TravelCore\Tests\Support\DbStub;

/**
 * The shared cross-provider "no availability" request registry: create()
 * returns the insert id, and getListing() honors the CS-Cart pagination
 * contract with provider/status filters.
 */
#[CoversClass(AlternativeRequestRepository::class)]
final class AlternativeRequestRepositoryTest extends TestCase
{
    private AlternativeRequestRepository $repo;

    protected function setUp(): void
    {
        DbStub::reset();
        $this->repo = new AlternativeRequestRepository();
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    public function testCreateInsertsAndReturnsId(): void
    {
        $captured = [];
        DbStub::$query = static function (string $q, ...$p) use (&$captured): int {
            $captured = [$q, $p];
            return 77;
        };

        $id = $this->repo->create(['provider' => 'sphinx', 'hotel_id' => 'h1']);

        self::assertSame(77, $id);
        self::assertStringContainsString('INSERT INTO ?:travel_alternative_requests ?e', $captured[0]);
        self::assertSame(['provider' => 'sphinx', 'hotel_id' => 'h1'], $captured[1][0]);
    }

    public function testGetListingReturnsRowsAndPagerParams(): void
    {
        $rows = [['request_id' => 1, 'provider' => 'novoton']];
        DbStub::$getField = static fn (string $q, ...$p): string => '12';
        $captured = [];
        DbStub::$getArray = static function (string $q, ...$p) use (&$captured, $rows): array {
            $captured = [$q, $p];
            return $rows;
        };

        [$result, $params] = $this->repo->getListing(['page' => 2, 'items_per_page' => 10]);

        self::assertSame($rows, $result);
        self::assertSame(12, $params['total_items']);
        self::assertSame(2, $params['page']);
        self::assertSame(10, $params['items_per_page']);
        self::assertStringContainsString('FROM ?:travel_alternative_requests r', $captured[0]);
        self::assertStringContainsString('ORDER BY r.created_at DESC', $captured[0]);
        // offset = (2-1)*10.
        self::assertSame(10, $captured[1][1]);
    }

    public function testGetListingBuildsProviderAndStatusFilters(): void
    {
        $condition = null;
        DbStub::$getField = static function (string $q, ...$p) use (&$condition): string {
            $condition = $p[0] ?? '';
            return '0';
        };
        DbStub::$getArray = static fn (string $q, ...$p): array => [];

        $this->repo->getListing(['provider' => 'sphinx', 'status' => 'pending_manual']);

        self::assertStringContainsString("r.provider = 'sphinx'", (string) $condition);
        self::assertStringContainsString("r.status = 'pending_manual'", (string) $condition);
    }
}

<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Repository\HotelStatsRepository;
use Tygh\Addons\SphinxHolidays\Tests\Support\DbStub;

/**
 * Coverage for HotelStatsRepository — the aggregate / reporting reads extracted
 * from HotelRepository. DB access is routed through DbStub closures; each test
 * asserts the returned value and (where relevant) the SQL/params issued.
 */
#[CoversClass(HotelStatsRepository::class)]
class HotelStatsRepositoryTest extends TestCase
{
    private HotelStatsRepository $stats;

    protected function setUp(): void
    {
        DbStub::reset();
        $this->stats = new HotelStatsRepository();
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    public function testGetCountsByCountryMapsRowsAndDefaultsEmptyToUnknown(): void
    {
        DbStub::$getArray = static fn (string $query, ...$params): array => [
            ['country_code' => 'GR', 'cnt' => '5'],
            ['country_code' => '', 'cnt' => '2'],
        ];

        $this->assertSame(['GR' => 5, 'unknown' => 2], $this->stats->getCountsByCountry());
    }

    public function testGetDistinctCountries(): void
    {
        $captured = [];
        DbStub::$getFields = static function (string $query, ...$params) use (&$captured): array {
            $captured = [$query, $params];
            return ['BG', 'GR', 'TR'];
        };

        $this->assertSame(['BG', 'GR', 'TR'], $this->stats->getDistinctCountries());
        $this->assertStringContainsString('SELECT DISTINCT country_code FROM ?:sphinx_hotels', $captured[0]);
    }

    public function testGetTotalCoercesField(): void
    {
        $captured = [];
        DbStub::$getField = static function (string $query, ...$params) use (&$captured): string {
            $captured = [$query, $params];
            return '42';
        };

        $this->assertSame(42, $this->stats->getTotal());
        $this->assertStringContainsString("COUNT(*) FROM ?:sphinx_hotels WHERE sync_status = 'active'", $captured[0]);
    }

    public function testGetLastSyncedAtPerCountry(): void
    {
        $captured = [];
        DbStub::$getField = static function (string $query, ...$params) use (&$captured): string {
            $captured = [$query, $params];
            return '2026-06-01 10:00:00';
        };

        $this->assertSame('2026-06-01 10:00:00', $this->stats->getLastSyncedAt('GR'));
        $this->assertStringContainsString('MAX(last_synced_at) FROM ?:sphinx_hotels WHERE country_code = ?s', $captured[0]);
        $this->assertSame(['GR'], $captured[1]);
    }

    public function testGetLastSyncedAtNullWhenEmpty(): void
    {
        DbStub::$getField = static fn (string $query, ...$params): string => '';

        $this->assertNull($this->stats->getLastSyncedAt());
    }

    public function testCountLinked(): void
    {
        DbStub::$getField = static fn (string $query, ...$params): string => '7';

        $this->assertSame(7, $this->stats->countLinked());
    }

    public function testGetDestinationIdsByCountryNoFilter(): void
    {
        $captured = [];
        DbStub::$getFields = static function (string $query, ...$params) use (&$captured): array {
            $captured = [$query, $params];
            return ['1', '2'];
        };

        $this->assertSame([1, 2], $this->stats->getDestinationIdsByCountry([]));
        $this->assertStringNotContainsString('country_code IN', $captured[0]);
    }

    public function testGetDestinationIdsByCountryWithFilter(): void
    {
        $captured = [];
        DbStub::$getFields = static function (string $query, ...$params) use (&$captured): array {
            $captured = [$query, $params];
            return ['3'];
        };

        $this->assertSame([3], $this->stats->getDestinationIdsByCountry(['GR', 'BG']));
        $this->assertStringContainsString('country_code IN (?s,?s)', $captured[0]);
        $this->assertSame(['GR', 'BG'], $captured[1]);
    }

    public function testCountOrphanedProducts(): void
    {
        $captured = [];
        DbStub::$getField = static function (string $query, ...$params) use (&$captured): string {
            $captured = [$query, $params];
            return '3';
        };

        $this->assertSame(3, $this->stats->countOrphanedProducts());
        $this->assertStringContainsString('NOT EXISTS', $captured[0]);
    }

    public function testGetDistinctClassifications(): void
    {
        DbStub::$getFields = static fn (string $query, ...$params): array => ['3', '4', '5'];

        $this->assertSame([3, 4, 5], $this->stats->getDistinctClassifications());
    }

    public function testGetDistinctPropertyTypes(): void
    {
        DbStub::$getFields = static fn (string $query, ...$params): array => ['hotel', 'apartment'];

        $this->assertSame(['hotel', 'apartment'], $this->stats->getDistinctPropertyTypes());
    }

    public function testCountWithBoardsAndProduct(): void
    {
        DbStub::$getField = static fn (string $query, ...$params): string => '9';

        $this->assertSame(9, $this->stats->countWithBoardsAndProduct());
    }
}

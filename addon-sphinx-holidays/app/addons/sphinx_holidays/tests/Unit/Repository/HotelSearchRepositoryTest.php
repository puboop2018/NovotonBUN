<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Repository\HotelSearchRepository;
use Tygh\Addons\SphinxHolidays\Tests\Support\DbStub;

/**
 * Coverage for HotelSearchRepository — the name/filter search reads extracted
 * from HotelRepository. DB access is routed through DbStub closures; tests pin
 * the returned shape, the page/offset maths, and the trimmed LIKE search.
 */
#[CoversClass(HotelSearchRepository::class)]
class HotelSearchRepositoryTest extends TestCase
{
    private HotelSearchRepository $repo;

    protected function setUp(): void
    {
        DbStub::reset();
        $this->repo = new HotelSearchRepository();
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    public function testGetFilteredReturnsPagedItemsAndCoercedTotal(): void
    {
        DbStub::$getField = static fn (string $query, ...$params): string => '7';
        $captured = [];
        DbStub::$getArray = static function (string $query, ...$params) use (&$captured): array {
            $captured = [$query, $params];
            return [['hotel_id' => '1', 'name' => 'Alpha']];
        };

        $result = $this->repo->getFiltered('', 0, 0, '', '', 2, 10);

        $this->assertSame(7, $result['total']);
        $this->assertSame([['hotel_id' => '1', 'name' => 'Alpha']], $result['items']);
        $this->assertStringContainsString('FROM ?:sphinx_hotels h', $captured[0]);
        // params = [$condition, $offset, $perPage]; offset = (2-1) * 10
        $this->assertSame(10, $captured[1][1]);
        $this->assertSame(10, $captured[1][2]);
    }

    public function testSearchTrimsQueryAndBuildsLike(): void
    {
        $captured = [];
        DbStub::$getArray = static function (string $query, ...$params) use (&$captured): array {
            $captured = [$query, $params];
            return [['hotel_id' => '9']];
        };

        $result = $this->repo->search('  Beach  ', 5);

        $this->assertSame([['hotel_id' => '9']], $result);
        $this->assertStringContainsString('WHERE name LIKE ?l', $captured[0]);
        $this->assertSame('%Beach%', $captured[1][0]);
        $this->assertSame(5, $captured[1][1]);
    }

    public function testSearchByNameReturnsEmptyForBlankQuery(): void
    {
        $called = false;
        DbStub::$getArray = static function (string $query, ...$params) use (&$called): array {
            $called = true;
            return [];
        };

        $this->assertSame([], $this->repo->searchByName('   '));
        $this->assertFalse($called); // short-circuits before any DB call
    }

    public function testSearchByNameUsesLightweightColumns(): void
    {
        $captured = [];
        DbStub::$getArray = static function (string $query, ...$params) use (&$captured): array {
            $captured = [$query, $params];
            return [['hotel_id' => '1', 'name' => 'Sun Resort']];
        };

        $result = $this->repo->searchByName('Sun', 8);

        $this->assertSame([['hotel_id' => '1', 'name' => 'Sun Resort']], $result);
        $this->assertStringContainsString('SELECT hotel_id, name, classification, country_code, destination_name', $captured[0]);
        $this->assertSame('%Sun%', $captured[1][0]);
        $this->assertSame(8, $captured[1][1]);
    }
}

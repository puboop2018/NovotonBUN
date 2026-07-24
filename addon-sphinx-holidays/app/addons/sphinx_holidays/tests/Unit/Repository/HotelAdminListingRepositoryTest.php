<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Repository;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Repository\HotelAdminListingRepository;
use Tygh\Addons\SphinxHolidays\Tests\Support\DbStub;

/**
 * Behavior tests for the admin hotels-grid listing extracted from
 * fn_sphinx_holidays_get_hotels: parameter clamping, the sort allowlist,
 * WHERE-fragment assembly and the returned search params contract.
 */
final class HotelAdminListingRepositoryTest extends TestCase
{
    /** @var list<string> */
    private array $queries = [];

    /** @var list<list<mixed>> */
    private array $queryParams = [];

    protected function setUp(): void
    {
        DbStub::reset();
        $this->queries = [];
        $this->queryParams = [];
        DbStub::$getField = static fn (): int => 42; // COUNT(*)
        DbStub::$getArray = function (string $query, ...$params): array {
            $this->queries[] = $query;
            $this->queryParams[] = $params;

            return [['hotel_id' => '7', 'name' => 'Edart']];
        };
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    public function testDefaultsClampingAndSortAllowlist(): void
    {
        $repo = new HotelAdminListingRepository(50);
        [$hotels, $params] = $repo->getListing([
            'page' => 0,
            'items_per_page' => -5,
            'sort_by' => 'evil_column',
            'sort_order' => 'DROP TABLE',
            'unknown_param' => 'ignored',
        ]);

        self::assertSame([['hotel_id' => '7', 'name' => 'Edart']], $hotels);
        self::assertSame(1, $params['page'], 'page clamps to >= 1');
        self::assertSame(1, $params['items_per_page'], 'items_per_page clamps to >= 1');
        self::assertSame('name', $params['sort_by'], 'unknown sort columns fall back to name');
        self::assertSame('asc', $params['sort_order'], 'garbage sort order falls back to asc');
        self::assertSame('desc', $params['sort_order_toggle']);
        self::assertSame(42, $params['total_items'], 'COUNT(*) feeds pagination');
        self::assertArrayNotHasKey('unknown_param', $params, 'foreign request params are dropped');
    }

    public function testFiltersLandInTheWhereFragment(): void
    {
        $repo = new HotelAdminListingRepository(50);
        $repo->getListing([
            'country_code' => 'BG',
            'link_status' => 'orphan',
            'q' => 'edart',
            'city' => 'varna',
            'classification' => '0',
        ]);

        // The stubbed db_get_array keeps ?p as a placeholder; the assembled
        // condition (already db_quote-interpolated) rides in as its param.
        $listingQuery = end($this->queries);
        $listingParams = end($this->queryParams);
        self::assertIsString($listingQuery);
        self::assertIsArray($listingParams);
        self::assertStringContainsString('ORDER BY h.name ASC', $listingQuery);

        $condition = (string) ($listingParams[0] ?? '');
        self::assertStringContainsString("h.country_code = 'BG'", $condition);
        self::assertStringContainsString('h.product_id IS NULL OR h.product_id = 0', $condition);
        self::assertStringContainsString("h.name LIKE '%edart%'", $condition);
        self::assertStringContainsString("h.address_city LIKE '%varna%'", $condition);
        self::assertStringContainsString('h.classification IS NULL OR h.classification = 0', $condition);
    }

    public function testConstructorDefaultDrivesItemsPerPage(): void
    {
        $repo = new HotelAdminListingRepository(25);
        [, $params] = $repo->getListing([]);

        self::assertSame(25, $params['items_per_page']);
    }
}

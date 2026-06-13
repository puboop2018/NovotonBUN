<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Repository\HotelSkipRepository;
use Tygh\Addons\SphinxHolidays\Tests\Support\DbStub;

/**
 * Characterization coverage for HotelSkipRepository — the product_skip_reason
 * lifecycle extracted from HotelRepository. Each test pins the SQL and the
 * parameters the repository issues so the extraction provably preserves
 * behaviour. DB access is routed through DbStub closures.
 */
#[CoversClass(HotelSkipRepository::class)]
class HotelSkipRepositoryTest extends TestCase
{
    private HotelSkipRepository $repo;

    protected function setUp(): void
    {
        DbStub::reset();
        $this->repo = new HotelSkipRepository();
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    public function testSkipReasonConstant(): void
    {
        $this->assertSame('no_availability', HotelSkipRepository::SKIP_REASON_NO_AVAILABILITY);
    }

    public function testMarkSkippedIssuesUpdate(): void
    {
        $captured = [];
        DbStub::$query = static function (string $query, ...$params) use (&$captured): int {
            $captured = [$query, $params];
            return 1;
        };

        $this->repo->markSkipped('3371', 'no_description');

        $this->assertStringContainsString(
            'UPDATE ?:sphinx_hotels SET product_skip_reason = ?s WHERE hotel_id = ?s',
            $captured[0],
        );
        $this->assertSame(['no_description', '3371'], $captured[1]);
    }

    public function testMarkSkippedBatchUpdatesOnlyNullReasons(): void
    {
        $captured = [];
        DbStub::$query = static function (string $query, ...$params) use (&$captured): int {
            $captured = [$query, $params];
            return 4;
        };

        $result = $this->repo->markSkippedBatch(['a', 'b'], HotelSkipRepository::SKIP_REASON_NO_AVAILABILITY);

        $this->assertSame(4, $result);
        $this->assertStringContainsString('SET product_skip_reason = ?s', $captured[0]);
        $this->assertStringContainsString('WHERE hotel_id IN (?a) AND product_skip_reason IS NULL', $captured[0]);
        $this->assertSame(['no_availability', ['a', 'b']], $captured[1]);
    }

    public function testMarkSkippedBatchShortCircuitsOnEmpty(): void
    {
        $called = false;
        DbStub::$query = static function () use (&$called): int {
            $called = true;
            return 0;
        };

        $this->assertSame(0, $this->repo->markSkippedBatch([], 'whatever'));
        $this->assertFalse($called, 'db_query must not run for an empty hotel id list');
    }

    public function testClearSkipReasonBatchScopesToReason(): void
    {
        $captured = [];
        DbStub::$query = static function (string $query, ...$params) use (&$captured): int {
            $captured = [$query, $params];
            return 2;
        };

        $result = $this->repo->clearSkipReasonBatch(['x', 'y'], HotelSkipRepository::SKIP_REASON_NO_AVAILABILITY);

        $this->assertSame(2, $result);
        $this->assertStringContainsString('SET product_skip_reason = NULL', $captured[0]);
        $this->assertStringContainsString('WHERE hotel_id IN (?a) AND product_skip_reason = ?s', $captured[0]);
        $this->assertSame([['x', 'y'], 'no_availability'], $captured[1]);
    }

    public function testClearSkipReasonBatchShortCircuitsOnEmpty(): void
    {
        $called = false;
        DbStub::$query = static function () use (&$called): int {
            $called = true;
            return 0;
        };

        $this->assertSame(0, $this->repo->clearSkipReasonBatch([], 'no_availability'));
        $this->assertFalse($called);
    }

    public function testResetSkippedWithoutFiltersClearsAll(): void
    {
        $captured = [];
        DbStub::$query = static function (string $query, ...$params) use (&$captured): int {
            $captured = [$query, $params];
            return 7;
        };

        $result = $this->repo->resetSkipped();

        $this->assertSame(7, $result);
        $this->assertStringContainsString('SET product_skip_reason = NULL', $captured[0]);
        $this->assertStringContainsString('WHERE product_skip_reason IS NOT NULL ?p', $captured[0]);
        // No country/reason → empty condition fragment spliced at ?p.
        $this->assertSame([''], $captured[1]);
    }

    public function testResetSkippedAppendsCountryAndReasonConditions(): void
    {
        $captured = [];
        DbStub::$query = static function (string $query, ...$params) use (&$captured): int {
            $captured = [$query, $params];
            return 1;
        };

        $this->repo->resetSkipped('GR', 'duplicate');

        $condition = $captured[1][0];
        $this->assertIsString($condition);
        $this->assertStringContainsString("AND country_code = 'GR'", $condition);
        $this->assertStringContainsString("AND product_skip_reason = 'duplicate'", $condition);
    }

    public function testCountSkippedWithoutReason(): void
    {
        $captured = [];
        DbStub::$getField = static function (string $query, ...$params) use (&$captured): string {
            $captured = [$query, $params];
            return '12';
        };

        $this->assertSame(12, $this->repo->countSkipped());
        $this->assertStringContainsString(
            'SELECT COUNT(*) FROM ?:sphinx_hotels WHERE product_skip_reason IS NOT NULL ?p',
            $captured[0],
        );
        $this->assertSame([''], $captured[1]);
    }

    public function testCountSkippedScopedToReason(): void
    {
        $captured = [];
        DbStub::$getField = static function (string $query, ...$params) use (&$captured): string {
            $captured = [$query, $params];
            return '3';
        };

        $this->assertSame(3, $this->repo->countSkipped('unrated'));
        $condition = $captured[1][0];
        $this->assertIsString($condition);
        $this->assertStringContainsString("AND product_skip_reason = 'unrated'", $condition);
    }

    public function testFindAvailabilityGateCandidatesShortCircuitsOnEmpty(): void
    {
        $called = false;
        DbStub::$getArray = static function () use (&$called): array {
            $called = true;
            return [];
        };

        $this->assertSame([], $this->repo->findAvailabilityGateCandidates([]));
        $this->assertFalse($called, 'db_get_array must not run for an empty destination list');
    }

    public function testFindAvailabilityGateCandidatesQueriesEligibleRows(): void
    {
        $rows = [
            ['hotel_id' => '10', 'destination_id' => 5, 'product_skip_reason' => null],
            ['hotel_id' => '11', 'destination_id' => 5, 'product_skip_reason' => 'no_availability'],
        ];
        $captured = [];
        DbStub::$getArray = static function (string $query, ...$params) use ($rows, &$captured): array {
            $captured = [$query, $params];
            return $rows;
        };

        $result = $this->repo->findAvailabilityGateCandidates([5, 9]);

        $this->assertSame($rows, $result);
        $this->assertStringContainsString('WHERE destination_id IN (?n)', $captured[0]);
        $this->assertStringContainsString('(product_id IS NULL OR product_id = 0)', $captured[0]);
        $this->assertStringContainsString('(product_skip_reason IS NULL OR product_skip_reason = ?s)', $captured[0]);
        $this->assertSame([[5, 9], 'active', 'no_availability'], $captured[1]);
    }
}

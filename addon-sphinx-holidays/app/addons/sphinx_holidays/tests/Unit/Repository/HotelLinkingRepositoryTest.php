<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Repository\HotelLinkingRepository;
use Tygh\Addons\SphinxHolidays\Tests\Support\DbStub;

/**
 * Coverage for HotelLinkingRepository — the product-pipeline reads extracted
 * from HotelRepository. DB access is routed through DbStub closures; tests pin
 * the returned rows and the distinguishing WHERE clauses / columns of each read.
 */
#[CoversClass(HotelLinkingRepository::class)]
class HotelLinkingRepositoryTest extends TestCase
{
    private HotelLinkingRepository $repo;

    protected function setUp(): void
    {
        DbStub::reset();
        $this->repo = new HotelLinkingRepository();
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    public function testFindMissingImages(): void
    {
        $captured = [];
        DbStub::$getArray = static function (string $query, ...$params) use (&$captured): array {
            $captured = [$query, $params];
            return [['hotel_id' => '1', 'name' => 'Alpha']];
        };

        $this->assertSame([['hotel_id' => '1', 'name' => 'Alpha']], $this->repo->findMissingImages());
        $this->assertStringContainsString('images_json IS NULL', $captured[0]);
        $this->assertStringContainsString("sync_status = 'active'", $captured[0]);
    }

    public function testFindUnlinkedSelectsUnlinkedNonSkippedWithExtraColumns(): void
    {
        $captured = [];
        DbStub::$getArray = static function (string $query, ...$params) use (&$captured): array {
            $captured = [$query, $params];
            return [['hotel_id' => '2']];
        };

        $this->assertSame([['hotel_id' => '2']], $this->repo->findUnlinked());
        $this->assertStringContainsString('h.product_id IS NULL OR h.product_id = 0', $captured[0]);
        $this->assertStringContainsString('h.product_skip_reason IS NULL', $captured[0]);
        // Product creation pulls the extra TEXT/JSON columns beyond the listing set.
        $this->assertStringContainsString('h.description, h.short_description, h.facilities_json, h.boards_json', $captured[0]);
    }

    public function testFindWithBoardsAndProduct(): void
    {
        $captured = [];
        DbStub::$getArray = static function (string $query, ...$params) use (&$captured): array {
            $captured = [$query, $params];
            return [['hotel_id' => '3', 'boards_json' => '["AI"]']];
        };

        $this->assertSame([['hotel_id' => '3', 'boards_json' => '["AI"]']], $this->repo->findWithBoardsAndProduct());
        // The boards/product condition is passed as the first ?p parameter.
        $this->assertStringContainsString('boards_json IS NOT NULL', $captured[1][0]);
        $this->assertStringContainsString('h.product_id > 0', $captured[1][0]);
    }

    public function testFetchLinkedBatchForSeoPassesOffsetAndBatch(): void
    {
        $captured = [];
        DbStub::$getArray = static function (string $query, ...$params) use (&$captured): array {
            $captured = [$query, $params];
            return [['hotel_id' => '4']];
        };

        $this->assertSame([['hotel_id' => '4']], $this->repo->fetchLinkedBatchForSeo(20, 50));
        $this->assertStringContainsString('LIMIT ?i, ?i', $captured[0]);
        $this->assertSame([20, 50], $captured[1]);
    }
}

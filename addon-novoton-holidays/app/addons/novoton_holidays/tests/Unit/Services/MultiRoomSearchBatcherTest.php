<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Api\Contracts\NovotonApiKitInterface;
use Tygh\Addons\NovotonHolidays\Api\Contracts\PricingApiClientInterface;
use Tygh\Addons\NovotonHolidays\Services\AvailabilityResultNormalizer;
use Tygh\Addons\NovotonHolidays\Services\MultiRoomSearchBatcher;
use Tygh\Addons\NovotonHolidays\Services\SearchServiceInterface;
use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;

/**
 * Characterization coverage for MultiRoomSearchBatcher — the parallel multi-room
 * search + aggregation extracted from HotelAvailabilitySearcher. Pins the
 * per-room request fan-out, the aggregation into the availability envelope
 * (first non-empty room as `results`, every room in `all_room_results`, total
 * option count, multi-room flag), the no-availability path, and that debug lines
 * flow back through the injected $log sink.
 */
#[CoversClass(MultiRoomSearchBatcher::class)]
class MultiRoomSearchBatcherTest extends TestCase
{
    private SearchServiceInterface $searchService;

    protected function setUp(): void
    {
        DbStub::reset();
        $this->searchService = $this->createMock(SearchServiceInterface::class);
    }

    private function batcher(): MultiRoomSearchBatcher
    {
        return new MultiRoomSearchBatcher($this->searchService, new AvailabilityResultNormalizer());
    }

    /** @param array<string, array{data: mixed, rawXml: string}> $batch */
    private function apiReturning(array $batch): NovotonApiKitInterface
    {
        $pricing = $this->createMock(PricingApiClientInterface::class);
        $pricing->method('getRoomPriceBatch')->willReturn($batch);
        $api = $this->createMock(NovotonApiKitInterface::class);
        $api->method('pricing')->willReturn($pricing);
        return $api;
    }

    public function testAggregatesAcrossRoomsIntoEnvelope(): void
    {
        $api = $this->apiReturning([
            'room_1' => ['data' => true, 'rawXml' => '<xml1/>'],
            'room_2' => ['data' => true, 'rawXml' => '<xml2/>'],
        ]);

        // Room 1 → 1 option, Room 2 → 2 options (keyed by the roomNum 8th arg).
        $this->searchService->method('parseRoomPriceResponse')->willReturnCallback(
            static fn (...$args): array => $args[7] === 1
                ? [['room_id' => 'DBL 2+1']]
                : [['room_id' => 'SGL 1+0'], ['room_id' => 'SGL 1+1']],
        );

        $logLines = [];
        $result = $this->batcher()->search(
            $api,
            'H1',
            '2026-07-01',
            '2026-07-08',
            7,
            '',
            [['adults' => 2, 'children' => 1, 'childrenAges' => [5]], ['adults' => 1, 'children' => 0]],
            [],
            static function (string $m) use (&$logLines): void {
                $logLines[] = $m;
            },
        );

        $this->assertTrue($result['is_multi_room']);
        $this->assertFalse($result['no_availability']);
        $this->assertSame(3, $result['multi_room_total_options']);          // 1 + 2
        $this->assertSame([['room_id' => 'DBL 2+1']], $result['results']);  // first non-empty room
        $this->assertCount(1, $result['all_room_results'][1]);
        $this->assertCount(2, $result['all_room_results'][2]);
        $this->assertNotEmpty($logLines);                                   // debug flowed through the sink
    }

    public function testNoAvailabilityWhenAllRoomsReturnNoData(): void
    {
        $api = $this->apiReturning([
            'room_1' => ['data' => false, 'rawXml' => ''],
        ]);
        $this->searchService->expects($this->never())->method('parseRoomPriceResponse');

        $result = $this->batcher()->search(
            $api,
            'H1',
            '2026-07-01',
            '2026-07-08',
            7,
            '',
            [['adults' => 2, 'children' => 0]],
            [],
            static function (string $m): void {
                // debug sink not asserted here
            },
        );

        $this->assertTrue($result['no_availability']);
        $this->assertSame(0, $result['multi_room_total_options']);
        $this->assertSame([], $result['results']);
        $this->assertSame([], $result['all_room_results'][1]); // room present, but empty
    }
}

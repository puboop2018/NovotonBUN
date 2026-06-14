<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Repository\HotelPackageRepositoryInterface;
use Tygh\Addons\NovotonHolidays\Services\CalendarPriceBuilder;

/**
 * Characterization coverage for CalendarPriceBuilder — the raw calendar-price
 * computation extracted from PriceInfoService. Pins the subtle rules: per-person
 * (RoomPrice=No) vs per-room (RoomPrice=Yes) totals, the cheapest-room-per-season
 * pick, percentage resolution against the Base code row, the adult/regular-bed
 * filtering, season-range expansion with today/maxDate clamping, and the
 * cross-package minimum merge.
 */
#[CoversClass(CalendarPriceBuilder::class)]
class CalendarPriceBuilderTest extends TestCase
{
    private CalendarPriceBuilder $builder;

    protected function setUp(): void
    {
        // computeRawCalendarPrices is the only method that touches the repo;
        // the pure methods ignore it.
        $repo = $this->createMock(HotelPackageRepositoryInterface::class);
        $this->builder = new CalendarPriceBuilder($repo);
    }

    // ── getCheapestRoomTotalBySeason ─────────────────────────────────────────

    public function testPerPersonMultipliesByAdults(): void
    {
        $result = $this->builder->getCheapestRoomTotalBySeason(
            [['IdRoom' => 'DBL', 'IdAge' => '1', 'IdAcc' => 'RB', 'RoomPrice' => 'No', 'Price1' => '100', 'Price2' => '120']],
            [['Season' => '1'], ['Season' => '2']],
            2,
        );

        $this->assertSame([1 => 200.0, 2 => 240.0], $result);
    }

    public function testPerRoomIgnoresAdultCount(): void
    {
        $result = $this->builder->getCheapestRoomTotalBySeason(
            [['IdRoom' => 'DBL', 'IdAge' => '1', 'IdAcc' => 'RB', 'RoomPrice' => 'Yes', 'Price1' => '100']],
            [['Season' => '1']],
            4,
        );

        $this->assertSame([1 => 100.0], $result);
    }

    public function testPicksCheapestRoomPerSeason(): void
    {
        $result = $this->builder->getCheapestRoomTotalBySeason(
            [
                ['IdRoom' => 'DBL', 'IdAge' => '1', 'IdAcc' => 'RB', 'RoomPrice' => 'Yes', 'Price1' => '150'],
                ['IdRoom' => 'SGL', 'IdAge' => '1', 'IdAcc' => 'RB', 'RoomPrice' => 'Yes', 'Price1' => '100'],
            ],
            [['Season' => '1']],
            2,
        );

        $this->assertSame([1 => 100.0], $result);
    }

    public function testPercentResolvesAgainstBaseRow(): void
    {
        // First DBL row prices Price1 as "50%"; the Base code row supplies the
        // 200 reference, so the resolved unit price is 100.
        $result = $this->builder->getCheapestRoomTotalBySeason(
            [
                ['IdRoom' => 'DBL', 'IdAge' => '1', 'IdAcc' => 'RB', 'RoomPrice' => 'Yes', 'Price1' => '50%'],
                ['IdRoom' => 'DBL', 'IdAge' => '1', 'IdAcc' => 'RB', 'RoomPrice' => 'Yes', 'Code' => 'Base', 'Price1' => '200'],
            ],
            [['Season' => '1']],
            2,
        );

        $this->assertSame([1 => 100.0], $result);
    }

    public function testSkipsChildAndExtraBedRows(): void
    {
        $result = $this->builder->getCheapestRoomTotalBySeason(
            [
                ['IdRoom' => 'DBL', 'IdAge' => '3', 'IdAcc' => 'RB', 'RoomPrice' => 'Yes', 'Price1' => '50'],  // child
                ['IdRoom' => 'DBL', 'IdAge' => '1', 'IdAcc' => 'EB', 'RoomPrice' => 'Yes', 'Price1' => '60'],  // extra bed
                ['IdRoom' => 'DBL', 'IdAge' => '1', 'IdAcc' => 'RB', 'RoomPrice' => 'Yes', 'Price1' => '100'], // adult/regular
            ],
            [['Season' => '1']],
            2,
        );

        $this->assertSame([1 => 100.0], $result);
    }

    // ── buildRawDateMap ──────────────────────────────────────────────────────

    public function testExpandsSeasonRangeIntoPerDatePrices(): void
    {
        $priceinfo = [
            'seasons' => [['Season' => '1', 'FromDate' => '2030-07-01', 'ToDate' => '2030-07-03']],
            'season_price' => [['IdRoom' => 'DBL', 'IdAge' => '1', 'IdAcc' => 'RB', 'RoomPrice' => 'Yes', 'Price1' => '100']],
        ];

        $result = $this->builder->buildRawDateMap($priceinfo, 2, '2030-01-01', '2031-01-01');

        $this->assertSame([
            '2030-07-01' => 100.0,
            '2030-07-02' => 100.0,
            '2030-07-03' => 100.0,
        ], $result);
    }

    public function testClampsSeasonStartToToday(): void
    {
        $priceinfo = [
            'seasons' => [['Season' => '1', 'FromDate' => '2030-07-01', 'ToDate' => '2030-07-03']],
            'season_price' => [['IdRoom' => 'DBL', 'IdAge' => '1', 'IdAcc' => 'RB', 'RoomPrice' => 'Yes', 'Price1' => '100']],
        ];

        // "today" lands mid-season -> earlier dates are dropped.
        $result = $this->builder->buildRawDateMap($priceinfo, 2, '2030-07-02', '2031-01-01');

        $this->assertSame([
            '2030-07-02' => 100.0,
            '2030-07-03' => 100.0,
        ], $result);
    }

    // ── computeRawCalendarPrices ─────────────────────────────────────────────

    public function testMergesMinimumAcrossPackages(): void
    {
        // Two packages cover the same two dates; the cheaper package wins per date.
        $from = date('Y-m-d', (int) strtotime('+2 months'));
        $to = date('Y-m-d', (int) strtotime('+2 months +1 day'));

        $pkg = static fn (string $price): string => (string) json_encode([
            'seasons' => [['Season' => '1', 'FromDate' => $from, 'ToDate' => $to]],
            'season_price' => [['IdRoom' => 'DBL', 'IdAge' => '1', 'IdAcc' => 'RB', 'RoomPrice' => 'Yes', 'Price1' => $price]],
        ]);

        $repo = $this->createMock(HotelPackageRepositoryInterface::class);
        $repo->method('getAllPriceinfoData')->willReturn([$pkg('150'), $pkg('100')]);
        $builder = new CalendarPriceBuilder($repo);

        $result = $builder->computeRawCalendarPrices('H1');

        $this->assertSame(100.0, $result[$from]);
        $this->assertSame(100.0, $result[$to]);
    }
}

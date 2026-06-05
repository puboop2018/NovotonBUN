<?php
declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\PriceInfoShaper;

#[CoversClass(PriceInfoShaper::class)]
class PriceInfoShaperTest extends TestCase
{
    // ── groupByRoom ─────────────────────────────────────────────────────

    public function testGroupByRoomGroupsRecordsByIdRoom(): void
    {
        $prices = [
            ['IdRoom' => 'DBL', 'Price' => 100],
            ['IdRoom' => 'SGL', 'Price' => 70],
            ['IdRoom' => 'DBL', 'Price' => 120],
        ];

        $grouped = PriceInfoShaper::groupByRoom($prices);

        $this->assertSame(['DBL', 'SGL'], array_keys($grouped));
        $this->assertCount(2, $grouped['DBL']);
        $this->assertCount(1, $grouped['SGL']);
    }

    public function testGroupByRoomFallsBackToRoomIdThenUnknown(): void
    {
        $prices = [
            ['room_id' => 'TRPL', 'Price' => 150],
            ['Price' => 200], // no room key → 'unknown'
        ];

        $grouped = PriceInfoShaper::groupByRoom($prices);

        $this->assertArrayHasKey('TRPL', $grouped);
        $this->assertArrayHasKey('unknown', $grouped);
    }

    public function testGroupByRoomSkipsNonArrayRows(): void
    {
        $grouped = PriceInfoShaper::groupByRoom([['IdRoom' => 'DBL'], 'garbage', 42]);

        $this->assertSame(['DBL'], array_keys($grouped));
    }

    // ── extractPrices ───────────────────────────────────────────────────

    public function testExtractPricesReturnsEmptyWhenNoSeasonPrice(): void
    {
        $this->assertSame([], PriceInfoShaper::extractPrices(['seasons' => []]));
    }

    public function testExtractPricesNormalizesSingleEntry(): void
    {
        // A single season_price comes back as an associative record, not a list.
        $result = PriceInfoShaper::extractPrices([
            'season_price' => ['IdRoom' => 'DBL', 'Price' => 100],
        ]);

        $this->assertArrayHasKey('DBL', $result);
        $this->assertCount(1, $result['DBL']);
    }

    public function testExtractPricesGroupsMultipleEntries(): void
    {
        $result = PriceInfoShaper::extractPrices([
            'season_price' => [
                ['IdRoom' => 'DBL', 'Price' => 100],
                ['IdRoom' => 'DBL', 'Price' => 120],
                ['IdRoom' => 'SGL', 'Price' => 70],
            ],
        ]);

        $this->assertCount(2, $result['DBL']);
        $this->assertCount(1, $result['SGL']);
    }

    public function testExtractPricesReturnsEmptyWhenSeasonPriceNotArray(): void
    {
        $this->assertSame([], PriceInfoShaper::extractPrices(['season_price' => 'oops']));
    }

    // ── format ──────────────────────────────────────────────────────────

    public function testFormatAlwaysReturnsStableShape(): void
    {
        $result = PriceInfoShaper::format([], 'H123');

        $this->assertSame('H123', $result['hotel_id']);
        $this->assertSame([], $result['seasons']);
        $this->assertSame([], $result['prices']);
        $this->assertSame([], $result['early_booking']);
        $this->assertSame([], $result['raw']);
    }

    public function testFormatNormalizesSingleSeasonAndEarlyBooking(): void
    {
        $result = PriceInfoShaper::format([
            'seasons' => ['season' => ['IdSeason' => '1', 'Name' => 'Summer']],
            'season_price' => ['IdRoom' => 'DBL', 'Price' => 100],
            'early_booking' => ['Reduction' => ['Perc' => '10']],
        ], 'H1');

        $this->assertCount(1, $result['seasons']);
        $this->assertSame('1', $result['seasons'][0]['IdSeason']);
        $this->assertArrayHasKey('DBL', $result['prices']);
        $this->assertCount(1, $result['early_booking']);
    }

    public function testFormatKeepsListSeasonsAsIs(): void
    {
        $result = PriceInfoShaper::format([
            'seasons' => ['season' => [
                ['IdSeason' => '1'],
                ['IdSeason' => '2'],
            ]],
        ], 'H1');

        $this->assertCount(2, $result['seasons']);
    }
}

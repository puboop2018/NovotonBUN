<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Repository\HotelPackageRepositoryInterface;
use Tygh\Addons\NovotonHolidays\Services\PriceInfoExtractor;

/**
 * Characterization coverage for PriceInfoExtractor — the seasons / early-booking
 * parsing of priceinfo_data JSON, extracted from PriceInfoService. The package
 * repository is mocked; tests pin the list/single normalisation, the field
 * mapping, the reduction-descending sort, and the active-date filtering.
 */
#[CoversClass(PriceInfoExtractor::class)]
class PriceInfoExtractorTest extends TestCase
{
    private HotelPackageRepositoryInterface $packageRepo;

    protected function setUp(): void
    {
        $this->packageRepo = $this->createMock(HotelPackageRepositoryInterface::class);
    }

    private function extractor(): PriceInfoExtractor
    {
        return new PriceInfoExtractor($this->packageRepo);
    }

    // ── getSeasons ───────────────────────────────────────────────────────────

    public function testGetSeasonsParsesList(): void
    {
        $this->packageRepo->method('getLatestPriceinfoData')->willReturn(json_encode([
            'seasons' => ['season' => [
                ['IdSeason' => 1, 'DateFrom' => '2026-06-01', 'DateTo' => '2026-08-31', 'SeasonName' => 'Summer'],
                ['IdSeason' => 2, 'DateFrom' => '2026-12-01', 'DateTo' => '2027-02-28'],
            ]],
        ]) ?: '');

        $seasons = $this->extractor()->getSeasons('H1');

        $this->assertCount(2, $seasons);
        $this->assertSame(1, $seasons[0]['season_number']);
        $this->assertSame('Summer', $seasons[0]['season_name']);
        $this->assertSame('Season 2', $seasons[1]['season_name']); // default name
    }

    public function testGetSeasonsNormalizesSingleSeason(): void
    {
        $this->packageRepo->method('getLatestPriceinfoData')->willReturn(json_encode([
            'seasons' => ['season' => ['IdSeason' => 7, 'DateFrom' => '2026-01-01', 'DateTo' => '2026-01-31']],
        ]) ?: '');

        $seasons = $this->extractor()->getSeasons('H1');

        $this->assertCount(1, $seasons);
        $this->assertSame(7, $seasons[0]['season_number']);
    }

    public function testGetSeasonsEmptyWhenNoData(): void
    {
        $this->packageRepo->method('getLatestPriceinfoData')->willReturn('');
        $this->assertSame([], $this->extractor()->getSeasons('H1'));
    }

    // ── getEarlyBooking ──────────────────────────────────────────────────────

    public function testGetEarlyBookingParsesAndSortsByReductionDesc(): void
    {
        $this->packageRepo->method('findEarlyBookingPackage')->willReturn([
            'priceinfo_data' => json_encode([
                'early_booking' => [
                    ['BookFrom' => '2026-01-01', 'BookTo' => '2026-03-31', 'Reduction' => 10],
                    ['BookFrom' => '2026-01-01', 'BookTo' => '2026-02-28', 'Reduction' => 25],
                ],
            ]) ?: '',
        ]);

        $eb = $this->extractor()->getEarlyBooking('H1');

        $this->assertCount(2, $eb);
        $this->assertSame(25.0, $eb[0]['reduction']); // sorted DESC
        $this->assertSame(10.0, $eb[1]['reduction']);
    }

    public function testGetEarlyBookingEmptyWhenNoData(): void
    {
        $this->packageRepo->method('findEarlyBookingPackage')->willReturn([]);
        $this->assertSame([], $this->extractor()->getEarlyBooking('H1'));
    }

    // ── getActiveEarlyBooking ────────────────────────────────────────────────

    public function testGetActiveEarlyBookingFiltersByDate(): void
    {
        $this->packageRepo->method('findEarlyBookingPackage')->willReturn([
            'priceinfo_data' => json_encode([
                'early_booking' => [['BookFrom' => '2026-01-01', 'BookTo' => '2026-03-31', 'Reduction' => 15]],
            ]) ?: '',
        ]);

        $this->assertNotNull($this->extractor()->getActiveEarlyBooking('H1', '2026-02-15'));
        $this->assertNull($this->extractor()->getActiveEarlyBooking('H1', '2026-09-01'));
    }
}

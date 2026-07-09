<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Services\CalendarPriceBuilder;
use Tygh\Addons\SphinxHolidays\SphinxApi;

final class CalendarPriceBuilderTest extends TestCase
{
    public function testBuildWindowsSpansHorizonWithCorrectNights(): void
    {
        $windows = CalendarPriceBuilder::buildWindows(3, 7, '2026-07-01');

        self::assertSame(
            [
                ['check_in' => '2026-07-02', 'check_out' => '2026-07-09'],
                ['check_in' => '2026-07-03', 'check_out' => '2026-07-10'],
                ['check_in' => '2026-07-04', 'check_out' => '2026-07-11'],
            ],
            $windows,
        );
    }

    public function testBuildWindowsCrossesMonthBoundary(): void
    {
        $windows = CalendarPriceBuilder::buildWindows(2, 3, '2026-07-30');

        self::assertSame(
            [
                ['check_in' => '2026-07-31', 'check_out' => '2026-08-03'],
                ['check_in' => '2026-08-01', 'check_out' => '2026-08-04'],
            ],
            $windows,
        );
    }

    public function testBuildWindowsRejectsInvalidInput(): void
    {
        self::assertSame([], CalendarPriceBuilder::buildWindows(0, 7));
        self::assertSame([], CalendarPriceBuilder::buildWindows(5, 0));
        self::assertSame([], CalendarPriceBuilder::buildWindows(5, 7, 'not-a-date'));
    }

    public function testRowParsingAcceptsAllKnownShapes(): void
    {
        self::assertSame('61992', CalendarPriceBuilder::rowHotelId(['hotel_id' => 61992]));
        self::assertSame('abc-1', CalendarPriceBuilder::rowHotelId(['id' => 'abc-1']));
        self::assertSame('', CalendarPriceBuilder::rowHotelId(['name' => 'no id']));

        self::assertSame(987.0, CalendarPriceBuilder::rowPrice(['price' => 987]));
        self::assertSame(450.5, CalendarPriceBuilder::rowPrice(['min_price' => '450.5']));
        self::assertSame(321.0, CalendarPriceBuilder::rowPrice(['selling_price' => 321]));
        self::assertSame(199.0, CalendarPriceBuilder::rowPrice(['min_price' => ['price' => 199, 'currency' => 'EUR']]));
        self::assertSame(0.0, CalendarPriceBuilder::rowPrice(['price' => 'n/a']));
        self::assertSame(0.0, CalendarPriceBuilder::rowPrice([]));
    }

    public function testProbeDestinationKeepsMinPricePerDateAcrossHotels(): void
    {
        $api = $this->createMock(SphinxApi::class);
        $api->method('cacheHotels')->willReturnCallback(static function (array $params): array {
            // Same two hotels every window; H2's price varies by check-in date.
            return [
                'results' => [
                    ['hotel_id' => 'H1', 'min_price' => ['price' => 500]],
                    ['hotel_id' => 'H2', 'price' => $params['check_in'] === '2026-07-02' ? 300 : 250],
                    ['name' => 'row without id — skipped'],
                    ['hotel_id' => 'H3', 'price' => 0], // no price — skipped
                ],
            ];
        });

        $builder = new CalendarPriceBuilder($api);
        // Freeze the horizon via a tiny window count for determinism.
        $byHotel = $builder->probeDestination(5, 'EUR', 2, 7);

        $dates = array_keys($byHotel['H1']);
        self::assertCount(2, $dates);
        self::assertSame([500.0, 500.0], array_values($byHotel['H1']));
        self::assertCount(2, $byHotel['H2']);
        self::assertArrayNotHasKey('H3', $byHotel);
    }
}

<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Services\AlternativeDateProber;
use Tygh\Addons\SphinxHolidays\SphinxApi;

final class AlternativeDateProberTest extends TestCase
{
    public function testCandidateWindowsShiftAroundTheRequestedDate(): void
    {
        $windows = AlternativeDateProber::candidateWindows('2026-07-14', 7, '2026-07-01');

        self::assertSame(
            [
                ['check_in' => '2026-07-07', 'check_out' => '2026-07-14'],
                ['check_in' => '2026-07-11', 'check_out' => '2026-07-18'],
                ['check_in' => '2026-07-17', 'check_out' => '2026-07-24'],
                ['check_in' => '2026-07-21', 'check_out' => '2026-07-28'],
                ['check_in' => '2026-07-28', 'check_out' => '2026-08-04'],
            ],
            $windows,
        );
    }

    public function testCandidateWindowsSkipPastCheckIns(): void
    {
        // Requested check-in only 2 days out: the -7 and -3 shifts land in the
        // past and must be skipped.
        $windows = AlternativeDateProber::candidateWindows('2026-07-03', 7, '2026-07-01');

        self::assertSame(
            ['2026-07-06', '2026-07-10', '2026-07-17'],
            array_column($windows, 'check_in'),
        );
    }

    public function testCandidateWindowsRejectInvalidInput(): void
    {
        self::assertSame([], AlternativeDateProber::candidateWindows('not-a-date', 7));
        self::assertSame([], AlternativeDateProber::candidateWindows('2026-07-14', 0));
    }

    public function testProbeNarrowsToHotelSkipsPricelessAndCapsAtThree(): void
    {
        $api = $this->createMock(SphinxApi::class);
        $calls = 0;
        $api->method('cacheHotels')->willReturnCallback(static function () use (&$calls): array {
            $calls++;
            // Window 2 has no price for our hotel; every other window does.
            return [
                'results' => [
                    ['hotel_id' => 'OTHER', 'price' => 111],
                    ['hotel_id' => 'H61992', 'min_price' => ['price' => $calls === 2 ? 0 : 900 + $calls]],
                ],
            ];
        });

        $prober = new AlternativeDateProber($api);
        $alts = $prober->probe(5, 'H61992', '2026-07-14', 7, 2, 'EUR');

        self::assertCount(AlternativeDateProber::MAX_ALTERNATIVES, $alts);
        foreach ($alts as $alt) {
            self::assertGreaterThan(0, $alt['price']);
            self::assertNotSame('', $alt['check_in']);
        }
        // Window 2 contributed nothing, so a 4th window was probed to reach the cap.
        self::assertSame(4, $calls);
    }

    public function testProbeReturnsEmptyForMissingInputs(): void
    {
        $api = $this->createMock(SphinxApi::class);
        $api->expects($this->never())->method('cacheHotels');

        $prober = new AlternativeDateProber($api);
        self::assertSame([], $prober->probe(0, 'H1', '2026-07-14', 7, 2, 'EUR'));
        self::assertSame([], $prober->probe(5, '', '2026-07-14', 7, 2, 'EUR'));
    }
}

<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\PriceInfoParser;
use Tygh\Addons\NovotonHolidays\Services\ReductionPercentageCalculator;

/**
 * Characterization coverage for ReductionPercentageCalculator — the percentage
 * reductions extracted from DiscountCalculator. The parser is mocked; tests pin
 * the empty contracts, the additive sum of "additional" percentages, and the
 * marketing rule that picks the best positive discount while honouring the
 * booking-date window and minimum stay.
 */
#[CoversClass(ReductionPercentageCalculator::class)]
class ReductionPercentageCalculatorTest extends TestCase
{
    private PriceInfoParser $parser;

    protected function setUp(): void
    {
        $this->parser = $this->createMock(PriceInfoParser::class);
    }

    /** @param array<string, mixed> $priceinfo */
    private function calc(array $priceinfo): ReductionPercentageCalculator
    {
        $this->parser->method('getPriceinfo')->willReturn($priceinfo);
        return new ReductionPercentageCalculator($this->parser);
    }

    public function testAdditionalEmptyWhenNoEntries(): void
    {
        $result = $this->calc([])->calculateReductionPercAdditional(1000.0);
        $this->assertFalse($result['applicable']);
        $this->assertSame(0, $result['discount']);
    }

    public function testAdditionalSumsPercents(): void
    {
        $result = $this->calc(['reduction_perc_additional' => [
            ['Perc' => 10, 'Name' => 'A'],
            ['Perc' => 5, 'Name' => 'B'],
        ]])->calculateReductionPercAdditional(1000.0);

        $this->assertTrue($result['applicable']);
        $this->assertSame(15.0, $result['percent']);
        $this->assertSame(150.0, $result['discount']); // 1000 * 15%
        $this->assertSame('A, B', $result['name']);
    }

    public function testMarketingEmptyWhenNoEntries(): void
    {
        $result = $this->calc([])->calculateReductionPercMarketing('2026-01-01', '2026-07-01', 7, 'DBL', 1000.0);
        $this->assertFalse($result['applicable']);
    }

    public function testMarketingPicksBestPositiveDiscount(): void
    {
        $result = $this->calc(['reduction_perc_marketing' => [
            ['Perc' => 10, 'Name' => 'Ten'],
            ['Perc' => 20, 'Name' => 'Twenty'],
            ['Perc' => -5, 'Name' => 'Surcharge'], // non-positive: ignored
        ]])->calculateReductionPercMarketing('2026-01-01', '2026-07-01', 7, 'DBL', 1000.0);

        $this->assertTrue($result['applicable']);
        $this->assertSame(20.0, $result['percent']);
        $this->assertSame(200.0, $result['discount']);
        $this->assertSame('Twenty', $result['name']);
        $this->assertFalse($result['is_surcharge']);
    }

    public function testMarketingHonoursBookingWindow(): void
    {
        // Booking date is before the entry's BookingFrom -> not applicable.
        $result = $this->calc(['reduction_perc_marketing' => [
            ['Perc' => 20, 'Name' => 'Spring', 'BookingFrom' => '2026-03-01', 'BookingTo' => '2026-04-30'],
        ]])->calculateReductionPercMarketing('2026-01-01', '2026-07-01', 7, 'DBL', 1000.0);

        $this->assertFalse($result['applicable']);
    }

    public function testMarketingHonoursMinimumStay(): void
    {
        $result = $this->calc(['reduction_perc_marketing' => [
            ['Perc' => 20, 'Name' => 'LongStay', 'MinimumStay' => 7],
        ]])->calculateReductionPercMarketing('2026-01-01', '2026-07-01', 5, 'DBL', 1000.0);

        $this->assertFalse($result['applicable']); // 5 nights < 7 min stay
    }
}

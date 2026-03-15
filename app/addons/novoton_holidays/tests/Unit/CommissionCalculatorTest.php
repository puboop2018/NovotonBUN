<?php
declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Services\CommissionCalculator;

/**
 * @covers \Tygh\Addons\NovotonHolidays\CommissionCalculator
 */
class CommissionCalculatorTest extends TestCase
{
    public function testApplyWithZeroCommission(): void
    {
        $calc = new CommissionCalculator(0.0, 'N');
        $this->assertEqualsWithDelta(100.0, $calc->apply(100.0), 0.001);
    }

    public function testApplyAddsPercentage(): void
    {
        $calc = new CommissionCalculator(10.0, 'N');
        $this->assertEqualsWithDelta(110.0, $calc->apply(100.0), 0.001);
    }

    public function testApplyWithRounding(): void
    {
        $calc = new CommissionCalculator(10.0, 'Y');
        // 99 * 1.10 = 108.9 → rounds to 109
        $this->assertEqualsWithDelta(109.0, $calc->apply(99.0), 0.001);
    }

    public function testApplyWithoutRounding(): void
    {
        $calc = new CommissionCalculator(10.0, 'N');
        // 99 * 1.10 = 108.9 — no rounding
        $this->assertEqualsWithDelta(108.9, $calc->apply(99.0), 0.001);
    }

    public function testNegativeCommissionClampedToZero(): void
    {
        $calc = new CommissionCalculator(-5.0, 'N');
        $this->assertEqualsWithDelta(0.0, $calc->getCommission(), 0.001);
        $this->assertEqualsWithDelta(100.0, $calc->apply(100.0), 0.001);
    }

    public function testApplyZeroPrice(): void
    {
        $calc = new CommissionCalculator(15.0, 'Y');
        $this->assertEqualsWithDelta(0.0, $calc->apply(0.0), 0.001);
    }

    public function testGetCommission(): void
    {
        $calc = new CommissionCalculator(12.5, 'Y');
        $this->assertEqualsWithDelta(12.5, $calc->getCommission(), 0.001);
    }

    public function testGetRoundPrices(): void
    {
        $calc = new CommissionCalculator(0.0, 'N');
        $this->assertSame('N', $calc->getRoundPrices());

        $calc2 = new CommissionCalculator(0.0, 'Y');
        $this->assertSame('Y', $calc2->getRoundPrices());
    }

    public function testLargePrice(): void
    {
        $calc = new CommissionCalculator(15.0, 'Y');
        // 10000 * 1.15 = 11500
        $this->assertEqualsWithDelta(11500.0, $calc->apply(10000.0), 0.001);
    }

    public function testFractionalCommission(): void
    {
        $calc = new CommissionCalculator(7.5, 'N');
        // 200 * 1.075 = 215
        $this->assertEqualsWithDelta(215.0, $calc->apply(200.0), 0.001);
    }
}

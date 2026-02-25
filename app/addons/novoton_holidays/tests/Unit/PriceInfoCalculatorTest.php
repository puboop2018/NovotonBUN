<?php
declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\PriceInfoCalculator;
use Tygh\Addons\NovotonHolidays\Services\PriceInfoParser;

/**
 * @covers \Tygh\Addons\NovotonHolidays\Services\PriceInfoCalculator
 */
class PriceInfoCalculatorTest extends TestCase
{
    // ── applyCommission ─────────────────────────────────────────────────

    public function testApplyCommissionAddsPercentage(): void
    {
        $parser = $this->createMock(PriceInfoParser::class);
        $calc = new PriceInfoCalculator($parser, 10.0);

        $this->assertEqualsWithDelta(110.0, $calc->applyCommission(100.0), 0.001);
    }

    public function testApplyCommissionZeroReturnsOriginal(): void
    {
        $parser = $this->createMock(PriceInfoParser::class);
        $calc = new PriceInfoCalculator($parser, 0.0);

        $this->assertEqualsWithDelta(100.0, $calc->applyCommission(100.0), 0.001);
    }

    public function testApplyCommissionNegativeClampedToZero(): void
    {
        $parser = $this->createMock(PriceInfoParser::class);
        $calc = new PriceInfoCalculator($parser, -5.0);

        // Negative commission is clamped to 0 in constructor
        $this->assertEqualsWithDelta(100.0, $calc->applyCommission(100.0), 0.001);
    }

    // ── getPriceFromRow ─────────────────────────────────────────────────

    public function testGetPriceFromRowNumericValue(): void
    {
        $parser = $this->createMock(PriceInfoParser::class);
        $calc = new PriceInfoCalculator($parser, 0.0);

        $row = ['Price1' => '45.50', 'Code' => 'Base'];
        $this->assertEqualsWithDelta(45.50, $calc->getPriceFromRow($row, 'Price1'), 0.001);
    }

    public function testGetPriceFromRowFallsBackToPrice1(): void
    {
        $parser = $this->createMock(PriceInfoParser::class);
        $calc = new PriceInfoCalculator($parser, 0.0);

        $row = ['Price1' => '30.00', 'Code' => 'Base'];
        // Requesting Price5 which doesn't exist — falls back to Price1
        $this->assertEqualsWithDelta(30.0, $calc->getPriceFromRow($row, 'Price5'), 0.001);
    }

    public function testGetPriceFromRowArrayValueReturnsZero(): void
    {
        $parser = $this->createMock(PriceInfoParser::class);
        $calc = new PriceInfoCalculator($parser, 0.0);

        $row = ['Price1' => ['nested' => 'bad'], 'Code' => 'Test'];
        $this->assertEqualsWithDelta(0.0, $calc->getPriceFromRow($row, 'Price1'), 0.001);
    }

    public function testGetPriceFromRowPercentageResolvesFromBase(): void
    {
        $parser = $this->createMock(PriceInfoParser::class);
        $parser->method('getCodeIndex')->willReturn([
            'Base' => [['Price1' => '200.00', 'Code' => 'Base']],
        ]);

        $calc = new PriceInfoCalculator($parser, 0.0);

        $row = ['Price1' => '50%', 'Code' => 'Child', 'Base' => 'Base'];
        $this->assertEqualsWithDelta(100.0, $calc->getPriceFromRow($row, 'Price1'), 0.001);
    }

    public function testGetPriceFromRowCircularReferenceReturnsZero(): void
    {
        $parser = $this->createMock(PriceInfoParser::class);
        $parser->method('getCodeIndex')->willReturn([
            'A' => [['Price1' => '50%', 'Code' => 'A', 'Base' => 'A']],
        ]);

        $calc = new PriceInfoCalculator($parser, 0.0);

        $row = ['Price1' => '50%', 'Code' => 'A', 'Base' => 'A'];
        // Should not infinite-loop; returns 0 due to visited check
        $this->assertEqualsWithDelta(0.0, $calc->getPriceFromRow($row, 'Price1'), 0.001);
    }

    // ── applyPriorityRules ──────────────────────────────────────────────

    private function makeCalcWithPriority(string $priority, string $priorityEB, string $priorityEXT): PriceInfoCalculator
    {
        $parser = $this->createMock(PriceInfoParser::class);
        $parser->method('getPriceinfo')->willReturn([
            'Priority'    => $priority,
            'PriorityEB'  => $priorityEB,
            'PriorityEXT' => $priorityEXT,
        ]);

        return new PriceInfoCalculator($parser, 0.0);
    }

    public function testPriorityNoCombinesDiscounts(): void
    {
        $calc = $this->makeCalcWithPriority('No', 'No', 'No');

        $result = $calc->applyPriorityRules(
            ['total' => 800],
            ['total' => 200],
            ['applicable' => true, 'discount' => 100],
            ['applicable' => true, 'discount' => 150]
        );

        // Priority = No → combined: base+fees - EB - reduction
        $this->assertEquals('combined', $result['applied_discount']);
        $this->assertEqualsWithDelta(750.0, $result['total'], 0.01);
        $this->assertEqualsWithDelta(250.0, $result['discount_amount'], 0.01);
    }

    public function testPriorityYesPicksBestDiscount(): void
    {
        $calc = $this->makeCalcWithPriority('Yes', 'No', 'No');

        $result = $calc->applyPriorityRules(
            ['total' => 800],
            ['total' => 200],
            ['applicable' => true, 'discount' => 300],  // EB is cheaper
            ['applicable' => true, 'discount' => 150]
        );

        // Priority = Yes, EB gives lower total → picks EB
        $this->assertEquals('early_booking', $result['applied_discount']);
        $this->assertEqualsWithDelta(700.0, $result['total'], 0.01);
    }

    public function testPriorityYesPreferReductionWhenBetter(): void
    {
        $calc = $this->makeCalcWithPriority('Yes', 'No', 'No');

        $result = $calc->applyPriorityRules(
            ['total' => 800],
            ['total' => 200],
            ['applicable' => true, 'discount' => 100],
            ['applicable' => true, 'discount' => 500]  // Reduction is better
        );

        $this->assertEquals('reduction', $result['applied_discount']);
        $this->assertEqualsWithDelta(500.0, $result['total'], 0.01);
    }

    public function testPriorityEBForcesEarlyBooking(): void
    {
        $calc = $this->makeCalcWithPriority('Yes', 'Yes', 'No');

        $result = $calc->applyPriorityRules(
            ['total' => 800],
            ['total' => 200],
            ['applicable' => true, 'discount' => 50],   // EB is worse
            ['applicable' => true, 'discount' => 500]
        );

        // PriorityEB = Yes → always pick EB regardless of discount amount
        $this->assertEquals('early_booking', $result['applied_discount']);
        $this->assertEqualsWithDelta(950.0, $result['total'], 0.01);
    }

    public function testNoDiscountsApplicableReturnsNone(): void
    {
        $calc = $this->makeCalcWithPriority('No', 'No', 'No');

        $result = $calc->applyPriorityRules(
            ['total' => 500],
            ['total' => 50],
            ['applicable' => false, 'discount' => 0],
            ['applicable' => false, 'discount' => 0]
        );

        $this->assertEquals('none', $result['applied_discount']);
        $this->assertEqualsWithDelta(550.0, $result['total'], 0.01);
    }

    public function testTotalNeverNegative(): void
    {
        $calc = $this->makeCalcWithPriority('No', 'No', 'No');

        $result = $calc->applyPriorityRules(
            ['total' => 100],
            ['total' => 0],
            ['applicable' => true, 'discount' => 200],
            ['applicable' => true, 'discount' => 300]
        );

        $this->assertGreaterThanOrEqual(0, $result['total']);
    }

    // ── findSeasonPriceRow ──────────────────────────────────────────────

    public function testFindSeasonPriceRowSelectsMostSpecific(): void
    {
        $parser = $this->createMock(PriceInfoParser::class);
        $parser->method('getIdStar')->willReturn('4*');
        $calc = new PriceInfoCalculator($parser, 0.0);

        $seasonPrices = [
            [
                'IdRoom' => 'DBL', 'IdBoard' => 'AI', 'fAge' => 'ADULT',
                'IdAcc' => 'REGULAR', 'IdStar' => '', 'FromDays' => '1', 'ToDays' => '30',
                'Price1' => '50',
            ],
            [
                'IdRoom' => 'DBL', 'IdBoard' => 'AI', 'fAge' => 'ADULT',
                'IdAcc' => 'REGULAR', 'IdStar' => '', 'FromDays' => '7', 'ToDays' => '14',
                'Price1' => '42',
            ],
        ];

        $row = $calc->findSeasonPriceRow($seasonPrices, 'DBL', 'AI', 'ADULT', 'REGULAR', 10);

        // Should pick the row with FromDays=7 (more specific) over FromDays=1
        $this->assertNotNull($row);
        $this->assertEquals('42', $row['Price1']);
    }

    public function testFindSeasonPriceRowReturnsNullWhenNoMatch(): void
    {
        $parser = $this->createMock(PriceInfoParser::class);
        $parser->method('getIdStar')->willReturn('4*');
        $calc = new PriceInfoCalculator($parser, 0.0);

        $row = $calc->findSeasonPriceRow([], 'DBL', 'AI', 'ADULT', 'REGULAR', 7);
        $this->assertNull($row);
    }

    public function testFindSeasonPriceRowRespectsNightsRange(): void
    {
        $parser = $this->createMock(PriceInfoParser::class);
        $parser->method('getIdStar')->willReturn('4*');
        $calc = new PriceInfoCalculator($parser, 0.0);

        $seasonPrices = [
            [
                'IdRoom' => 'DBL', 'IdBoard' => 'AI', 'fAge' => 'ADULT',
                'IdAcc' => 'REGULAR', 'IdStar' => '', 'FromDays' => '10', 'ToDays' => '14',
                'Price1' => '42',
            ],
        ];

        // 5 nights is outside the 10-14 range
        $row = $calc->findSeasonPriceRow($seasonPrices, 'DBL', 'AI', 'ADULT', 'REGULAR', 5);
        $this->assertNull($row);

        // 12 nights is inside the range
        $row = $calc->findSeasonPriceRow($seasonPrices, 'DBL', 'AI', 'ADULT', 'REGULAR', 12);
        $this->assertNotNull($row);
    }
}

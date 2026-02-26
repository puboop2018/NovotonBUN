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

    // ── calculateReductionPercAdditional ─────────────────────────────────

    public function testReductionPercAdditionalAppliesPercentage(): void
    {
        $parser = $this->createMock(PriceInfoParser::class);
        $parser->method('getPriceinfo')->willReturn([
            'reduction_perc_additional' => [
                ['Perc' => '3', 'Name' => 'Promo discount'],
            ],
        ]);

        $calc = new PriceInfoCalculator($parser, 0.0);
        $result = $calc->calculateReductionPercAdditional(1000.0);

        $this->assertTrue($result['applicable']);
        $this->assertEqualsWithDelta(30.0, $result['discount'], 0.01);
        $this->assertEqualsWithDelta(3.0, $result['percent'], 0.01);
        $this->assertEquals('Promo discount', $result['name']);
    }

    public function testReductionPercAdditionalSumsMultipleEntries(): void
    {
        $parser = $this->createMock(PriceInfoParser::class);
        $parser->method('getPriceinfo')->willReturn([
            'reduction_perc_additional' => [
                ['Perc' => '3', 'Name' => 'Promo A'],
                ['Perc' => '2', 'Name' => 'Promo B'],
            ],
        ]);

        $calc = new PriceInfoCalculator($parser, 0.0);
        $result = $calc->calculateReductionPercAdditional(1000.0);

        $this->assertTrue($result['applicable']);
        $this->assertEqualsWithDelta(50.0, $result['discount'], 0.01);
        $this->assertEqualsWithDelta(5.0, $result['percent'], 0.01);
    }

    public function testReductionPercAdditionalEmptyReturnsNotApplicable(): void
    {
        $parser = $this->createMock(PriceInfoParser::class);
        $parser->method('getPriceinfo')->willReturn([]);

        $calc = new PriceInfoCalculator($parser, 0.0);
        $result = $calc->calculateReductionPercAdditional(1000.0);

        $this->assertFalse($result['applicable']);
        $this->assertEqualsWithDelta(0.0, $result['discount'], 0.01);
    }

    public function testReductionPercAdditionalSingleEntry(): void
    {
        // Single entry (not wrapped in array) — common with SimpleXML->JSON conversion
        $parser = $this->createMock(PriceInfoParser::class);
        $parser->method('getPriceinfo')->willReturn([
            'reduction_perc_additional' => ['Perc' => '5', 'Name' => 'Solo promo'],
        ]);

        $calc = new PriceInfoCalculator($parser, 0.0);
        $result = $calc->calculateReductionPercAdditional(200.0);

        $this->assertTrue($result['applicable']);
        $this->assertEqualsWithDelta(10.0, $result['discount'], 0.01);
    }

    // ── calculateReductionPercMarketing ──────────────────────────────────

    public function testReductionPercMarketingAppliesWhenInRange(): void
    {
        $parser = $this->createMock(PriceInfoParser::class);
        $parser->method('getPriceinfo')->willReturn([
            'reduction_perc_marketing' => [
                [
                    'Perc' => '3',
                    'Name' => 'Promo Discount',
                    'BookingFrom' => '2025-08-15',
                    'BookingTo' => '2025-10-31',
                    'TravelTimeFrom' => '2025-12-01',
                    'TravelTimeTo' => '2026-04-15',
                    'RoomTypes' => '',
                    'MinimumStay' => '0',
                    'Type' => '',
                ],
            ],
        ]);

        $calc = new PriceInfoCalculator($parser, 0.0);
        $result = $calc->calculateReductionPercMarketing(
            '2025-09-01',  // booking date within range
            '2026-01-15',  // check-in within travel range
            7,
            'DBL',
            1000.0
        );

        $this->assertTrue($result['applicable']);
        $this->assertEqualsWithDelta(30.0, $result['discount'], 0.01);
        $this->assertEqualsWithDelta(3.0, $result['percent'], 0.01);
    }

    public function testReductionPercMarketingRejectsOutOfBookingRange(): void
    {
        $parser = $this->createMock(PriceInfoParser::class);
        $parser->method('getPriceinfo')->willReturn([
            'reduction_perc_marketing' => [
                [
                    'Perc' => '3',
                    'Name' => 'Promo',
                    'BookingFrom' => '2025-08-15',
                    'BookingTo' => '2025-10-31',
                    'TravelTimeFrom' => '2025-12-01',
                    'TravelTimeTo' => '2026-04-15',
                ],
            ],
        ]);

        $calc = new PriceInfoCalculator($parser, 0.0);

        // Booking date is outside range
        $result = $calc->calculateReductionPercMarketing('2025-11-15', '2026-01-15', 7, 'DBL', 1000.0);
        $this->assertFalse($result['applicable']);
    }

    public function testReductionPercMarketingRejectsOutOfTravelRange(): void
    {
        $parser = $this->createMock(PriceInfoParser::class);
        $parser->method('getPriceinfo')->willReturn([
            'reduction_perc_marketing' => [
                [
                    'Perc' => '3',
                    'Name' => 'Promo',
                    'BookingFrom' => '2025-08-15',
                    'BookingTo' => '2025-10-31',
                    'TravelTimeFrom' => '2025-12-01',
                    'TravelTimeTo' => '2026-04-15',
                ],
            ],
        ]);

        $calc = new PriceInfoCalculator($parser, 0.0);

        // Check-in is outside travel range
        $result = $calc->calculateReductionPercMarketing('2025-09-01', '2026-05-01', 7, 'DBL', 1000.0);
        $this->assertFalse($result['applicable']);
    }

    public function testReductionPercMarketingRespectsRoomTypeFilter(): void
    {
        $parser = $this->createMock(PriceInfoParser::class);
        $parser->method('getPriceinfo')->willReturn([
            'reduction_perc_marketing' => [
                [
                    'Perc' => '5',
                    'Name' => 'Room promo',
                    'BookingFrom' => '',
                    'BookingTo' => '',
                    'TravelTimeFrom' => '',
                    'TravelTimeTo' => '',
                    'RoomTypes' => 'DBL,SUI',
                    'MinimumStay' => '0',
                ],
            ],
        ]);

        $calc = new PriceInfoCalculator($parser, 0.0);

        // DBL is in the allowed list
        $result = $calc->calculateReductionPercMarketing('2025-09-01', '2026-01-15', 7, 'DBL', 1000.0);
        $this->assertTrue($result['applicable']);

        // SGL is NOT in the allowed list
        $result = $calc->calculateReductionPercMarketing('2025-09-01', '2026-01-15', 7, 'SGL', 1000.0);
        $this->assertFalse($result['applicable']);
    }

    public function testReductionPercMarketingRespectsMinimumStay(): void
    {
        $parser = $this->createMock(PriceInfoParser::class);
        $parser->method('getPriceinfo')->willReturn([
            'reduction_perc_marketing' => [
                [
                    'Perc' => '5',
                    'Name' => 'Long stay promo',
                    'BookingFrom' => '',
                    'BookingTo' => '',
                    'TravelTimeFrom' => '',
                    'TravelTimeTo' => '',
                    'RoomTypes' => '',
                    'MinimumStay' => '7',
                ],
            ],
        ]);

        $calc = new PriceInfoCalculator($parser, 0.0);

        // 5 nights < 7 minimum
        $result = $calc->calculateReductionPercMarketing('2025-09-01', '2026-01-15', 5, 'DBL', 1000.0);
        $this->assertFalse($result['applicable']);

        // 7 nights >= 7 minimum
        $result = $calc->calculateReductionPercMarketing('2025-09-01', '2026-01-15', 7, 'DBL', 1000.0);
        $this->assertTrue($result['applicable']);
    }

    public function testReductionPercMarketingPicksBestPercent(): void
    {
        $parser = $this->createMock(PriceInfoParser::class);
        $parser->method('getPriceinfo')->willReturn([
            'reduction_perc_marketing' => [
                ['Perc' => '3', 'Name' => 'Small promo'],
                ['Perc' => '7', 'Name' => 'Big promo'],
                ['Perc' => '5', 'Name' => 'Medium promo'],
            ],
        ]);

        $calc = new PriceInfoCalculator($parser, 0.0);
        $result = $calc->calculateReductionPercMarketing('2025-09-01', '2026-01-15', 7, 'DBL', 1000.0);

        $this->assertTrue($result['applicable']);
        $this->assertEqualsWithDelta(7.0, $result['percent'], 0.01);
        $this->assertEqualsWithDelta(70.0, $result['discount'], 0.01);
        $this->assertEquals('Big promo', $result['name']);
    }

    // ── calculateReductionPeriod ────────────────────────────────────────

    public function testReductionPeriodCapsNightsToMaxDays(): void
    {
        $parser = $this->createMock(PriceInfoParser::class);
        $parser->method('getPriceinfo')->willReturn([
            'reduction_period' => [
                [
                    'FromDays' => '14',
                    'ToDays' => '20',
                    'MaxDays' => '10',
                    'FromDate' => '2026-01-01',
                    'ToDate' => '2026-12-31',
                ],
            ],
        ]);

        $calc = new PriceInfoCalculator($parser, 0.0);

        // 14 nights, but only pay for 10
        $basePrice = [
            'total' => 700.0,
            'by_night' => array_fill(0, 14, ['price' => 50.0]),
        ];

        $result = $calc->calculateReductionPeriod('2026-03-01', 14, $basePrice);

        $this->assertTrue($result['applicable']);
        $this->assertEquals(10, $result['max_days']);
        $this->assertEquals(4, $result['capped_nights']);
        // 4 excess nights * 50 = 200
        $this->assertEqualsWithDelta(200.0, $result['discount'], 0.01);
    }

    public function testReductionPeriodNotApplicableWhenNightsOutOfRange(): void
    {
        $parser = $this->createMock(PriceInfoParser::class);
        $parser->method('getPriceinfo')->willReturn([
            'reduction_period' => [
                [
                    'FromDays' => '14',
                    'ToDays' => '20',
                    'MaxDays' => '10',
                    'FromDate' => '2026-01-01',
                    'ToDate' => '2026-12-31',
                ],
            ],
        ]);

        $calc = new PriceInfoCalculator($parser, 0.0);

        // 7 nights — outside the 14-20 range
        $result = $calc->calculateReductionPeriod('2026-03-01', 7, ['total' => 350, 'by_night' => []]);
        $this->assertFalse($result['applicable']);
    }

    public function testReductionPeriodNotApplicableWhenDateOutOfRange(): void
    {
        $parser = $this->createMock(PriceInfoParser::class);
        $parser->method('getPriceinfo')->willReturn([
            'reduction_period' => [
                [
                    'FromDays' => '14',
                    'ToDays' => '20',
                    'MaxDays' => '10',
                    'FromDate' => '2026-06-01',
                    'ToDate' => '2026-08-31',
                ],
            ],
        ]);

        $calc = new PriceInfoCalculator($parser, 0.0);

        // March check-in is outside June-August range
        $result = $calc->calculateReductionPeriod('2026-03-01', 14, ['total' => 700, 'by_night' => []]);
        $this->assertFalse($result['applicable']);
    }

    public function testReductionPeriodUsesAvgWhenNoByNight(): void
    {
        $parser = $this->createMock(PriceInfoParser::class);
        $parser->method('getPriceinfo')->willReturn([
            'reduction_period' => [
                ['FromDays' => '14', 'ToDays' => '20', 'MaxDays' => '10', 'FromDate' => '', 'ToDate' => ''],
            ],
        ]);

        $calc = new PriceInfoCalculator($parser, 0.0);

        // No by_night breakdown, uses average
        $result = $calc->calculateReductionPeriod('2026-03-01', 14, ['total' => 700.0]);

        $this->assertTrue($result['applicable']);
        // avg = 700/14 = 50, excess = 4, discount = 50*4 = 200
        $this->assertEqualsWithDelta(200.0, $result['discount'], 0.01);
    }

    public function testReductionPeriodEmptyReturnsNotApplicable(): void
    {
        $parser = $this->createMock(PriceInfoParser::class);
        $parser->method('getPriceinfo')->willReturn([]);

        $calc = new PriceInfoCalculator($parser, 0.0);
        $result = $calc->calculateReductionPeriod('2026-03-01', 14, ['total' => 700.0]);

        $this->assertFalse($result['applicable']);
    }

    // ── calculateReduction ValidFor ─────────────────────────────────────

    public function testReductionValidForStayUsesOverlap(): void
    {
        $parser = $this->createMock(PriceInfoParser::class);
        $parser->method('getPriceinfo')->willReturn([
            'reduction' => [
                [
                    'FromNights' => '7',
                    'ToNights' => '14',
                    'FreeNights' => '1',
                    'CheckInFrom' => '2026-01-15',
                    'CheckInTo' => '2026-01-20',
                    'Type' => 'End',
                    'ValidFor' => 'Stay',
                ],
            ],
            'EXTToDaily' => 'No',
            'EXTToRooms' => 'No',
            'EXTToBoard' => 'No',
        ]);

        $calc = new PriceInfoCalculator($parser, 0.0);

        // Check-in Jan 10, 7 nights → stay is Jan 10-17, overlaps with Jan 15-20
        $basePrice = ['total' => 700.0, 'by_night' => array_fill(0, 7, ['price' => 100.0])];
        $result = $calc->calculateReduction('2026-01-10', 7, [], [], '', '', $basePrice, ['extras_daily' => 0, 'extras_rooms' => 0, 'extras_board' => 0]);

        $this->assertTrue($result['applicable']);
        $this->assertEqualsWithDelta(100.0, $result['discount'], 0.01);
    }

    public function testReductionValidForArrivalRejectsOutOfRange(): void
    {
        $parser = $this->createMock(PriceInfoParser::class);
        $parser->method('getPriceinfo')->willReturn([
            'reduction' => [
                [
                    'FromNights' => '7',
                    'ToNights' => '14',
                    'FreeNights' => '1',
                    'CheckInFrom' => '2026-01-15',
                    'CheckInTo' => '2026-01-20',
                    'Type' => 'End',
                    'ValidFor' => 'Arrival',
                ],
            ],
            'EXTToDaily' => 'No',
            'EXTToRooms' => 'No',
            'EXTToBoard' => 'No',
        ]);

        $calc = new PriceInfoCalculator($parser, 0.0);

        // Check-in Jan 10 is BEFORE range — with Arrival mode, this should be rejected
        $basePrice = ['total' => 700.0, 'by_night' => array_fill(0, 7, ['price' => 100.0])];
        $result = $calc->calculateReduction('2026-01-10', 7, [], [], '', '', $basePrice, ['extras_daily' => 0, 'extras_rooms' => 0, 'extras_board' => 0]);

        $this->assertFalse($result['applicable']);
    }

    // ── applyPriorityRules with reduction_period ────────────────────────

    public function testPriorityRulesIncludesReductionPeriod(): void
    {
        $calc = $this->makeCalcWithPriority('No', 'No', 'No');

        $result = $calc->applyPriorityRules(
            ['total' => 800],
            ['total' => 200],
            ['applicable' => false, 'discount' => 0],
            ['applicable' => false, 'discount' => 0],
            ['applicable' => true, 'discount' => 100]  // reduction_period
        );

        // No EB or reduction, but reduction_period saves 100
        $this->assertEquals('none', $result['applied_discount']);
        $this->assertEqualsWithDelta(900.0, $result['total'], 0.01);
        $this->assertEqualsWithDelta(100.0, $result['discount_amount'], 0.01);
    }

    public function testPriorityRulesReductionPeriodCombinesWithEB(): void
    {
        $calc = $this->makeCalcWithPriority('No', 'No', 'No');

        $result = $calc->applyPriorityRules(
            ['total' => 800],
            ['total' => 200],
            ['applicable' => true, 'discount' => 50],
            ['applicable' => false, 'discount' => 0],
            ['applicable' => true, 'discount' => 100]  // reduction_period
        );

        // base+fees (1000) - reduction_period (100) = 900, then - EB (50) = 850
        $this->assertEquals('combined', $result['applied_discount']);
        $this->assertEqualsWithDelta(850.0, $result['total'], 0.01);
    }
}

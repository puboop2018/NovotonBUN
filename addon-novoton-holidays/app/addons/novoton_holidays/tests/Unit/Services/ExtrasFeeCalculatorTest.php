<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\ExtrasFeeCalculator;
use Tygh\Addons\NovotonHolidays\Services\PriceInfoParser;

/**
 * Characterization coverage for ExtrasFeeCalculator — the extras supplement
 * routines extracted from FeeCalculator. The parser is mocked; tests pin the
 * "no extras configured -> 0" contract for each category, the single-occupancy
 * gate, the empty-board short-circuit, and a representative board-supplement sum.
 */
#[CoversClass(ExtrasFeeCalculator::class)]
class ExtrasFeeCalculatorTest extends TestCase
{
    private PriceInfoParser $parser;

    protected function setUp(): void
    {
        $this->parser = $this->createMock(PriceInfoParser::class);
    }

    /** @param array<string, mixed>|null $priceinfo */
    private function calc(?array $priceinfo): ExtrasFeeCalculator
    {
        $this->parser->method('getPriceinfo')->willReturn($priceinfo);
        return new ExtrasFeeCalculator($this->parser);
    }

    public function testDailyZeroWhenNoExtras(): void
    {
        $this->assertSame(0.0, $this->calc(['extras_daily' => []])->calculateDaily([], '2026-07-01', 3));
    }

    public function testRoomsZeroWhenNoExtras(): void
    {
        $this->assertSame(0.0, $this->calc([])->calculateRooms([], '2026-07-01', 3, 'R1'));
    }

    public function testSingleZeroWhenNoExtras(): void
    {
        $this->assertSame(0.0, $this->calc([])->calculateSingle(['adults' => [[]]], '2026-07-01', 3));
    }

    public function testSingleZeroWhenNotSingleOccupancy(): void
    {
        // Two adults -> the single supplement does not apply.
        $calc = $this->calc(['extras_single' => [['Price' => 20, 'Type' => 'Stay']]]);
        $this->assertSame(0.0, $calc->calculateSingle(['adults' => [[], []]], '2026-07-01', 3));
    }

    public function testBoardZeroWhenNoBoardId(): void
    {
        $this->assertSame(0.0, $this->calc(['extras_board' => [['Price' => 5]]])->calculateBoard([], '2026-07-01', 3, ''));
    }

    public function testBoardZeroWhenNoExtras(): void
    {
        $this->assertSame(0.0, $this->calc([])->calculateBoard([], '2026-07-01', 3, 'HB'));
    }

    public function testBoardStaySumsPricePerPerson(): void
    {
        // 'Stay' type, no IdAge -> price * (adults + children). 2 people, price 10 -> 20.
        $calc = $this->calc(['extras_board' => [['IdBoard' => 'HB', 'Price' => 10.0, 'Type' => 'Stay']]]);

        $total = $calc->calculateBoard(
            ['adults' => [[], []], 'children' => []],
            '2026-07-01',
            3,
            'HB',
        );

        $this->assertSame(20.0, $total);
    }

    public function testBoardSkipsNonMatchingBoardCode(): void
    {
        $calc = $this->calc(['extras_board' => [['IdBoard' => 'AI', 'Price' => 10.0, 'Type' => 'Stay']]]);

        // Booked board HB, extra is for AI -> skipped -> 0.
        $this->assertSame(0.0, $calc->calculateBoard(['adults' => [[]]], '2026-07-01', 3, 'HB'));
    }
}

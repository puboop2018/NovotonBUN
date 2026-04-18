<?php
declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\PriceInfoFormatter;

#[CoversClass(PriceInfoFormatter::class)]
class PriceInfoFormatterTest extends TestCase
{
    // ── countMatchingPersons — positional adult patterns ─────────────────

    /**
     * When all adults in occupancy are plain "ADULT", positional patterns
     * like "3 RD ADULT" must return 0 (no double-counting).
     */
    public function testCountMatchingPersonsPositionalAdultReturnsZeroWhenAllAdultsArePlain(): void
    {
        $occupancy = [
            'adults' => [
                ['index' => 1, 'age_type' => 'ADULT ', 'acc_type' => 'REGULAR'],
                ['index' => 2, 'age_type' => 'ADULT ', 'acc_type' => 'REGULAR'],
                ['index' => 3, 'age_type' => 'ADULT ', 'acc_type' => 'REGULAR'],
                ['index' => 4, 'age_type' => 'ADULT ', 'acc_type' => 'REGULAR'],
            ],
            'children' => [],
        ];

        // Non-positional ADULT matches all 4
        $this->assertEquals(4, PriceInfoFormatter::countMatchingPersons($occupancy, 'ADULT'));

        // Positional patterns should NOT match — no occupant has that age_type
        $this->assertEquals(0, PriceInfoFormatter::countMatchingPersons($occupancy, '3 RD ADULT'));
        $this->assertEquals(0, PriceInfoFormatter::countMatchingPersons($occupancy, '4 TH ADULT'));
        $this->assertEquals(0, PriceInfoFormatter::countMatchingPersons($occupancy, '3RD ADULT'));
        $this->assertEquals(0, PriceInfoFormatter::countMatchingPersons($occupancy, '4TH ADULT'));
    }

    /**
     * When occupancy explicitly has "3 RD ADULT", the positional pattern matches.
     */
    public function testCountMatchingPersonsPositionalAdultReturnsOneWhenExplicitlyPresent(): void
    {
        $occupancy = [
            'adults' => [
                ['index' => 1, 'age_type' => 'ADULT ', 'acc_type' => 'REGULAR'],
                ['index' => 2, 'age_type' => 'ADULT ', 'acc_type' => 'REGULAR'],
                ['index' => 3, 'age_type' => '3 RD ADULT', 'acc_type' => 'EXTRA BED'],
            ],
            'children' => [],
        ];

        $this->assertEquals(3, PriceInfoFormatter::countMatchingPersons($occupancy, 'ADULT'));
        $this->assertEquals(1, PriceInfoFormatter::countMatchingPersons($occupancy, '3 RD ADULT'));
        $this->assertEquals(0, PriceInfoFormatter::countMatchingPersons($occupancy, '4 TH ADULT'));
    }

    /**
     * Multiple positional adults present.
     */
    public function testCountMatchingPersonsMultiplePositionalAdults(): void
    {
        $occupancy = [
            'adults' => [
                ['index' => 1, 'age_type' => 'ADULT ', 'acc_type' => 'REGULAR'],
                ['index' => 2, 'age_type' => 'ADULT ', 'acc_type' => 'REGULAR'],
                ['index' => 3, 'age_type' => '3 RD ADULT', 'acc_type' => 'EXTRA BED'],
                ['index' => 4, 'age_type' => '4 TH ADULT', 'acc_type' => 'EXTRA BED'],
            ],
            'children' => [],
        ];

        $this->assertEquals(4, PriceInfoFormatter::countMatchingPersons($occupancy, 'ADULT'));
        $this->assertEquals(1, PriceInfoFormatter::countMatchingPersons($occupancy, '3 RD ADULT'));
        $this->assertEquals(1, PriceInfoFormatter::countMatchingPersons($occupancy, '4 TH ADULT'));
    }

    /**
     * Positional child matching still works (unchanged behavior).
     */
    public function testCountMatchingPersonsPositionalChildStillWorks(): void
    {
        $occupancy = [
            'adults' => [
                ['index' => 1, 'age_type' => 'ADULT ', 'acc_type' => 'REGULAR'],
            ],
            'children' => [
                ['index' => 1, 'age' => 5, 'age_band' => '2-11,99'],
                ['index' => 2, 'age' => 8, 'age_band' => '2-11,99'],
            ],
        ];

        $this->assertEquals(2, PriceInfoFormatter::countMatchingPersons($occupancy, 'CHD 2-11,99'));
        $this->assertEquals(1, PriceInfoFormatter::countMatchingPersons($occupancy, '1 ST CHD 2-11,99'));
        $this->assertEquals(1, PriceInfoFormatter::countMatchingPersons($occupancy, '2 ND CHD 2-11,99'));
        $this->assertEquals(0, PriceInfoFormatter::countMatchingPersons($occupancy, '3 RD CHD 2-11,99'));
    }

    public function testCountMatchingPersonsEmptyReturnsZero(): void
    {
        $occupancy = ['adults' => [], 'children' => []];
        $this->assertEquals(0, PriceInfoFormatter::countMatchingPersons($occupancy, ''));
        $this->assertEquals(0, PriceInfoFormatter::countMatchingPersons($occupancy, 'ADULT'));
    }

    // ── correlatesWithSeasonAgeTypes ────────────────────────────────────

    public function testCorrelatesExactMatch(): void
    {
        $this->assertTrue(
            PriceInfoFormatter::correlatesWithSeasonAgeTypes('ADULT', ['ADULT', 'CHD 2-11.99'])
        );
    }

    public function testCorrelatesPositionalDoesNotMatchPlainAdult(): void
    {
        // This is the key fix: "3 RD ADULT" must NOT match plain "ADULT"
        $this->assertFalse(
            PriceInfoFormatter::correlatesWithSeasonAgeTypes('3 RD ADULT', ['ADULT', 'CHD 2-11.99'])
        );
        $this->assertFalse(
            PriceInfoFormatter::correlatesWithSeasonAgeTypes('4 TH ADULT', ['ADULT', 'CHD 2-11.99'])
        );
    }

    public function testCorrelatesPositionalMatchesWhenExplicitlyPresent(): void
    {
        $this->assertTrue(
            PriceInfoFormatter::correlatesWithSeasonAgeTypes('3 RD ADULT', ['ADULT', '3 RD ADULT', 'CHD 2-11.99'])
        );
    }

    public function testCorrelatesNormalizesCommaDot(): void
    {
        $this->assertTrue(
            PriceInfoFormatter::correlatesWithSeasonAgeTypes('CHD 2-11,99', ['ADULT', 'CHD 2-11.99'])
        );
        $this->assertTrue(
            PriceInfoFormatter::correlatesWithSeasonAgeTypes('CHD 2-11.99', ['ADULT', 'CHD 2-11,99'])
        );
    }

    public function testCorrelatesNormalizesWhitespace(): void
    {
        $this->assertTrue(
            PriceInfoFormatter::correlatesWithSeasonAgeTypes('  ADULT  ', ['ADULT'])
        );
        $this->assertTrue(
            PriceInfoFormatter::correlatesWithSeasonAgeTypes('3  RD  ADULT', ['3 RD ADULT'])
        );
    }

    public function testCorrelatesEmptyReturnsFalse(): void
    {
        $this->assertFalse(PriceInfoFormatter::correlatesWithSeasonAgeTypes('', ['ADULT']));
        $this->assertFalse(PriceInfoFormatter::correlatesWithSeasonAgeTypes('ADULT', []));
    }
}

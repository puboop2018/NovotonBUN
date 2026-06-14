<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\AgeBandResolver;
use Tygh\Addons\NovotonHolidays\Services\OccupancyStructureBuilder;

/**
 * Characterization coverage for OccupancyStructureBuilder — the bed-assignment
 * routine extracted from PriceInfoParser. Pins regular-then-extra-bed placement,
 * the ordinal "3 RD ADULT" vs plain "ADULT" split driven by extra-bed adult
 * pricing, oldest-first child ordinals + the by_1_ad flag, and the reclassify-
 * child-as-adult path when its age band has no matching price row.
 */
#[CoversClass(OccupancyStructureBuilder::class)]
class OccupancyStructureBuilderTest extends TestCase
{
    private OccupancyStructureBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new OccupancyStructureBuilder(new AgeBandResolver());
    }

    public function testAdultsFillRegularBedsThenExtraWithoutOrdinalPricing(): void
    {
        // 3 adults, only 2 regular beds, no room/board context -> the 3rd adult
        // lands on an extra bed but keeps the generic "ADULT " / REGULAR type.
        $occ = $this->builder->build(3, [], ['RB' => 2], null, [], '', '');

        $this->assertCount(3, $occ['adults']);
        $this->assertSame(2, $occ['total_rb_used']);
        $this->assertSame(1, $occ['total_eb_used']);
        $this->assertSame('EXTRA BED', $occ['adults'][2]['bed_type']);
        $this->assertSame('ADULT ', $occ['adults'][2]['age_type']);
        $this->assertSame('REGULAR', $occ['adults'][2]['acc_type']);
    }

    public function testThirdAdultGetsOrdinalWhenExtraBedPricingExists(): void
    {
        $priceinfo = ['season_price' => [
            ['IdRoom' => 'DBL', 'IdBoard' => 'AI', 'fAge' => '3 RD ADULT', 'IdAcc' => 'EXTRA BED'],
        ]];

        $occ = $this->builder->build(3, [], ['RB' => 2], $priceinfo, [], 'DBL', 'AI');

        $this->assertSame('EXTRA BED', $occ['adults'][2]['bed_type']);
        $this->assertSame('3 RD ADULT', $occ['adults'][2]['age_type']);
        $this->assertSame('EXTRA BED', $occ['adults'][2]['acc_type']);
    }

    public function testChildrenSortedOldestFirstWithOrdinals(): void
    {
        // Plenty of regular beds; children keep REGULAR and get ordinal CHD types.
        $occ = $this->builder->build(2, [5, 8], ['RB' => 4], null, [], '', '');

        $this->assertCount(2, $occ['children']);
        $this->assertSame(8, $occ['children'][0]['age']); // oldest first
        $this->assertSame('1 ST CHD 2-11,99', $occ['children'][0]['age_type']);
        $this->assertSame(5, $occ['children'][1]['age']);
        $this->assertSame('2 ND CHD 2-11,99', $occ['children'][1]['age_type']);
        $this->assertFalse($occ['children'][0]['by_1_ad']);
    }

    public function testByOneAdultFlagWhenSingleAdult(): void
    {
        $occ = $this->builder->build(1, [5], ['RB' => 2], null, [], '', '');

        $this->assertCount(1, $occ['children']);
        $this->assertTrue($occ['children'][0]['by_1_ad']);
        $this->assertSame('1 ST CHD 2-11,99', $occ['children'][0]['age_type']);
    }

    public function testChildReclassifiedAsAdultWhenBandHasNoPricing(): void
    {
        // The room only prices the 0-1,99 child band; a 5-year-old (2-11,99 band)
        // has no price row and is reclassified as an additional adult.
        $priceinfo = ['season_price' => [
            ['IdRoom' => 'DBL', 'IdBoard' => 'AI', 'fAge' => '1 ST CHD 0-1,99'],
        ]];

        $occ = $this->builder->build(1, [5], ['RB' => 2], $priceinfo, [], 'DBL', 'AI');

        $this->assertCount(0, $occ['children']);
        $this->assertCount(1, $occ['children_as_adults']);
        $this->assertSame(5, $occ['children_as_adults'][0]['original_child_age']);
        $this->assertTrue($occ['children_as_adults'][0]['reclassified']);
        $this->assertCount(2, $occ['adults']); // original adult + reclassified child
    }
}

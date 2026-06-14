<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\AgeBandResolver;

/**
 * Characterization coverage for AgeBandResolver — the child-age-band logic
 * extracted from PriceInfoParser. Pins the default-band fallback and its 12.0
 * boundary, custom band matching with the out-of-range label, hotelinfo band
 * parsing (child-only, sorted), the numeric IdAge -> label mapping when reading
 * season_price rows, and the 3rd-adult extra-bed detection.
 */
#[CoversClass(AgeBandResolver::class)]
class AgeBandResolverTest extends TestCase
{
    private AgeBandResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new AgeBandResolver();
    }

    // ── getAgeBand (default fallback, no configured bands) ────────────────────

    public function testDefaultBandsByAge(): void
    {
        $this->assertSame('0-1,99', $this->resolver->getAgeBand(1.5, []));
        $this->assertSame('2-11,99', $this->resolver->getAgeBand(11.99, [])); // just under 12
        $this->assertSame('12-17,99', $this->resolver->getAgeBand(12.0, []));
    }

    // ── getAgeBand (configured bands) ─────────────────────────────────────────

    public function testCustomBandMatch(): void
    {
        $bands = [
            ['from' => 0.0, 'to' => 1.99, 'label' => '0-1,99'],
            ['from' => 2.0, 'to' => 11.99, 'label' => '2-11,99'],
        ];

        $this->assertSame('2-11,99', $this->resolver->getAgeBand(5.0, $bands));
    }

    public function testAgeBeyondConfiguredBandsFallsBackToFloorLabel(): void
    {
        $bands = [['from' => 0.0, 'to' => 11.99, 'label' => '0-11,99']];

        // 15 is outside the only band -> floor(age)-17,99 label.
        $this->assertSame('15-17,99', $this->resolver->getAgeBand(15.0, $bands));
    }

    // ── parseChildAgeBands ────────────────────────────────────────────────────

    public function testParsesChildBandsSortedAndSkipsAdults(): void
    {
        $hotelinfo = ['ages' => ['age' => [
            ['fAge' => '1', 'FromYear' => '2', 'ToYear' => '11.99', 'IdAge' => 'C2'],
            ['fAge' => '1', 'FromYear' => '0', 'ToYear' => '1.99', 'IdAge' => 'C1'],
            ['fAge' => '0', 'FromYear' => '18', 'ToYear' => '99', 'IdAge' => 'A1'], // adult -> skipped
        ]]];

        $bands = $this->resolver->parseChildAgeBands($hotelinfo);

        $this->assertCount(2, $bands);
        $this->assertSame(0.0, $bands[0]['from']);
        $this->assertSame('0-1,99', $bands[0]['label']);
        $this->assertSame('C1', $bands[0]['id_age']);
        $this->assertSame(2.0, $bands[1]['from']);
        $this->assertSame('2-11,99', $bands[1]['label']);
    }

    public function testParseChildAgeBandsNullHotelinfo(): void
    {
        $this->assertSame([], $this->resolver->parseChildAgeBands(null));
    }

    // ── getAvailableChildAgeBands ─────────────────────────────────────────────

    public function testAvailableBandsFromFAgeAndNumericIdAge(): void
    {
        $priceinfo = ['season_price' => [
            ['IdRoom' => 'DBL', 'IdBoard' => 'AI', 'fAge' => 'CHD 2-11,99'],
            ['IdRoom' => 'DBL', 'IdBoard' => 'AI', 'IdAge' => '2'],   // -> CHD 0-1.99
            ['IdRoom' => 'DBL', 'IdBoard' => 'AI', 'fAge' => 'ADULT'], // not a child -> skipped
        ]];

        // Empty room/board => every row matches.
        $this->assertSame(['2-11,99', '0-1,99'], $this->resolver->getAvailableChildAgeBands($priceinfo, '', ''));
    }

    // ── hasAdultExtraBedPricing ───────────────────────────────────────────────

    public function testDetectsThirdAdultExtraBed(): void
    {
        $priceinfo = ['season_price' => [
            ['IdRoom' => 'DBL', 'IdBoard' => 'AI', 'fAge' => '3 RD ADULT', 'IdAcc' => 'EXTRA BED'],
        ]];

        $this->assertTrue($this->resolver->hasAdultExtraBedPricing($priceinfo, '', ''));
    }

    public function testNoExtraBedPricingWhenAdultIsRegular(): void
    {
        $priceinfo = ['season_price' => [
            ['IdRoom' => 'DBL', 'IdBoard' => 'AI', 'fAge' => '3 RD ADULT', 'IdAcc' => 'REGULAR'],
        ]];

        $this->assertFalse($this->resolver->hasAdultExtraBedPricing($priceinfo, '', ''));
    }
}

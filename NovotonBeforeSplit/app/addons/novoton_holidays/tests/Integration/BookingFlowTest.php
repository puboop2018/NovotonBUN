<?php
declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\SearchParameterNormalizer;
use Tygh\Addons\TravelCore\Services\CommissionCalculator;
use Tygh\Addons\NovotonHolidays\NovotonXmlParser;

/**
 * Integration test: verifies the search → normalize → price → commission flow
 * using mocked API responses (no live API calls).
 *
 * @group integration
 */
class BookingFlowTest extends TestCase
{
    private SearchParameterNormalizer $normalizer;
    private CommissionCalculator $commission;
    private NovotonXmlParser $xmlParser;

    protected function setUp(): void
    {
        $this->normalizer = new SearchParameterNormalizer();
        $this->commission = new CommissionCalculator(15.0, 'Y');
        $this->xmlParser = new NovotonXmlParser();
    }

    /**
     * Full flow: search params → normalize → parse price XML → apply commission
     */
    public function testSearchToPriceFlow(): void
    {
        // Step 1: Normalize raw search parameters (as if from HTTP request)
        $searchParams = [
            'check_in' => '2026-07-01',
            'check_out' => '2026-07-08',
            'adults' => '2',
            'children' => '1',
            'children_ages' => '5',
            'hotel_id' => 'H999',
            'meal_plan' => 'AI',
        ];

        $normalized = $this->normalizer->normalize($searchParams);

        $this->assertSame('2026-07-01', $normalized['check_in']);
        $this->assertSame('2026-07-08', $normalized['check_out']);
        $this->assertSame(7, $normalized['nights']);
        $this->assertSame(2, $normalized['adults']);
        $this->assertSame([5], $normalized['children']);
        $this->assertSame('H999', $normalized['hotel_id']);

        // Step 2: Simulate API room_price XML response
        $priceXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<RoomPrice>
    <Price>850.50</Price>
    <Currency>EUR</Currency>
    <TermsOfPayment>Payment due 30 days before arrival</TermsOfPayment>
    <TermsOfCancellation>Free cancellation until 14 days before</TermsOfCancellation>
    <remark>Sea view room</remark>
</RoomPrice>
XML;

        $priceData = $this->xmlParser->cleanAndParse($priceXml);

        $this->assertInstanceOf(\SimpleXMLElement::class, $priceData);
        $rawPrice = (float) (string) $priceData->Price;
        $this->assertEqualsWithDelta(850.50, $rawPrice, 0.01);

        // Step 3: Apply commission
        $finalPrice = $this->commission->apply($rawPrice);

        // 850.50 * 1.15 = 978.075 → rounded to 978
        $this->assertEqualsWithDelta(978.0, $finalPrice, 0.01);

        // Step 4: Extract terms
        $termsOfPayment = (string) $priceData->TermsOfPayment;
        $termsOfCancellation = (string) $priceData->TermsOfCancellation;

        $this->assertStringContainsString('30 days', $termsOfPayment);
        $this->assertStringContainsString('14 days', $termsOfCancellation);
    }

    /**
     * Multi-room flow: multiple rooms with children → price per room → total
     */
    public function testMultiRoomPriceAggregation(): void
    {
        $searchParams = [
            'check_in' => '2026-08-15',
            'nights' => '5',
            'rooms_data' => json_encode([
                ['adults' => 2, 'children' => 1, 'childrenAges' => [3]],
                ['adults' => 2, 'children' => 0, 'childrenAges' => []],
            ]),
        ];

        $normalized = $this->normalizer->normalize($searchParams);

        $this->assertSame(2, $normalized['num_rooms']);
        $this->assertSame(4, $normalized['adults']); // 2 + 2
        $this->assertSame(1, $normalized['children_count']);

        // Simulate two room_price responses
        $roomPrices = [500.0, 450.0];
        $totalBase = array_sum($roomPrices);

        $this->assertEqualsWithDelta(950.0, $totalBase, 0.01);

        // Apply commission to total
        $finalTotal = $this->commission->apply($totalBase);
        // 950 * 1.15 = 1092.5 → rounded to 1093
        $this->assertEqualsWithDelta(1093.0, $finalTotal, 0.01);
    }

    /**
     * XML with bare ampersands (common in hotel names) → clean → parse → price
     */
    public function testXmlWithBareAmpersandsInHotelData(): void
    {
        $rawXml = '<hotel><name>Sun & Beach Resort</name><Price>1200</Price></hotel>';

        $parsed = $this->xmlParser->cleanAndParse($rawXml);

        $this->assertSame('Sun & Beach Resort', (string) $parsed->name);
        $price = (float) (string) $parsed->Price;
        $this->assertEqualsWithDelta(1200.0, $price, 0.01);

        $finalPrice = $this->commission->apply($price);
        // 1200 * 1.15 = 1380
        $this->assertEqualsWithDelta(1380.0, $finalPrice, 0.01);
    }

    /**
     * Edge case: zero-night search defaults to 7 and still produces valid output
     */
    public function testZeroNightSearchDefaultsToSeven(): void
    {
        $normalized = $this->normalizer->normalize([
            'check_in' => '2026-06-01',
            'nights' => '0',
        ]);

        // 0 is falsy, so resolveNights returns 7
        $this->assertSame(7, $normalized['nights']);
        $this->assertSame('2026-06-08', $normalized['check_out']);
    }
}

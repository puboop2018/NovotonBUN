<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Helpers\SearchOfferNormalizer;

#[CoversClass(SearchOfferNormalizer::class)]
class SearchOfferNormalizerTest extends TestCase
{
    /**
     * The nested shape returned by the live /hotels/search results endpoint.
     *
     * @return array<string, mixed>
     */
    private function nestedOffer(): array
    {
        return [
            'offer_id' => 'abc-123',
            'hotel_id' => 59833,
            'hotel_name' => 'Hotel Royal Ariston',
            'destination_name' => 'Dubrovnik',
            'pricing' => [
                'selling_price' => 842.5,
                'currency' => 'EUR',
            ],
            'rooms' => [
                ['room_name' => 'Double Room Sea View'],
            ],
            'meal_type_name' => 'Half Board',
        ];
    }

    public function testFlattenMapsNestedApiShapeToFlatTemplateKeys(): void
    {
        $flat = SearchOfferNormalizer::flatten($this->nestedOffer());

        $this->assertSame('abc-123', $flat['offer_id']);
        $this->assertSame('59833', $flat['hotel_id']);
        $this->assertSame('Hotel Royal Ariston', $flat['hotel_name']);
        $this->assertSame('Dubrovnik', $flat['destination']);
        $this->assertSame(842.5, $flat['price']);
        $this->assertSame('EUR', $flat['currency']);
        $this->assertSame('Double Room Sea View', $flat['room_name']);
        $this->assertSame('Half Board', $flat['board_name']);
    }

    public function testFlattenReadsRoomTypeAndNameFallbacks(): void
    {
        $offer = $this->nestedOffer();
        $offer['rooms'] = [['room_type' => 'Triple Standard']];
        $this->assertSame('Triple Standard', SearchOfferNormalizer::flatten($offer)['room_name']);

        $offer['rooms'] = [['name' => 'Studio Apartment']];
        $this->assertSame('Studio Apartment', SearchOfferNormalizer::flatten($offer)['room_name']);
    }

    public function testFlattenIsIdempotentOnAlreadyFlatOffers(): void
    {
        $flat = [
            'offer_id' => 'x',
            'hotel_id' => '42',
            'hotel_name' => 'Flat Hotel',
            'destination' => 'Paris',
            'price' => 100.0,
            'currency' => 'EUR',
            'room_name' => 'Suite',
            'board_name' => 'All Inclusive',
        ];

        $this->assertSame($flat, SearchOfferNormalizer::flatten($flat));
    }

    public function testFlattenHandlesMissingPricingGracefully(): void
    {
        $flat = SearchOfferNormalizer::flatten([
            'offer_id' => 'no-price',
            'hotel_id' => 7,
            'hotel_name' => 'Budget Inn',
        ]);

        $this->assertSame(0.0, $flat['price']);
        $this->assertSame('', $flat['currency']);
        $this->assertSame('', $flat['room_name']);
        $this->assertSame('', $flat['board_name']);
    }

    public function testFlattenFallsBackToIdAndNameKeys(): void
    {
        $flat = SearchOfferNormalizer::flatten([
            'offer_id' => 'o1',
            'id' => 999,
            'name' => 'Aliased Hotel',
            'pricing' => ['selling_price' => 50, 'currency' => 'USD'],
        ]);

        $this->assertSame('999', $flat['hotel_id']);
        $this->assertSame('Aliased Hotel', $flat['hotel_name']);
    }

    public function testFlattenPreservesConfirmationField(): void
    {
        // The availability filter relies on confirmation surviving flatten().
        $offer = $this->nestedOffer();
        $offer['confirmation'] = 'immediate';

        $this->assertSame('immediate', SearchOfferNormalizer::flatten($offer)['confirmation']);
    }

    public function testFlattenAllProcessesEveryOffer(): void
    {
        $result = SearchOfferNormalizer::flattenAll([
            $this->nestedOffer(),
            $this->nestedOffer(),
        ]);

        $this->assertCount(2, $result);
        $this->assertSame(842.5, $result[0]['price']);
        $this->assertSame(842.5, $result[1]['price']);
    }
}

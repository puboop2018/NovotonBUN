<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Dto\Hotel\HotelSeoData;
use Tygh\Addons\TravelCore\Services\HotelLocationLine;

/**
 * The PDP location line rule is keyed on data, not provider: a street address
 * switches to postal style "street, city, country" (sphinx hotels); without
 * one it is "city, region, country" (novoton hotels). Empty parts vanish.
 */
#[CoversClass(HotelLocationLine::class)]
final class HotelLocationLineTest extends TestCase
{
    public function testStreetAddressWinsPostalStyle(): void
    {
        $hotel = new HotelSeoData(
            hotelId: 's1',
            providerName: 'sphinx',
            name: 'Ozkaymak Falez Otel',
            city: 'Antalya City',
            region: 'Antalya',
            country: 'Turkey',
            address: 'Sirinyali Mah. 1520 Sk.',
        );

        self::assertSame('Sirinyali Mah. 1520 Sk., Antalya City, Turkey', HotelLocationLine::build($hotel));
    }

    public function testWithoutStreetUsesCityRegionCountry(): void
    {
        $hotel = new HotelSeoData(
            hotelId: '4535',
            providerName: 'novoton',
            name: 'Monaco Hotel',
            city: 'Golem',
            region: 'Tirana',
            country: 'Albania',
        );

        self::assertSame('Golem, Tirana, Albania', HotelLocationLine::build($hotel));
    }

    public function testEmptyPartsAreFiltered(): void
    {
        $hotel = new HotelSeoData(
            hotelId: 'x',
            providerName: 'novoton',
            name: 'Hotel',
            city: 'Golem',
            region: '  ',
            country: null,
        );

        self::assertSame('Golem', HotelLocationLine::build($hotel));
    }

    public function testAllEmptyGivesEmptyString(): void
    {
        $hotel = new HotelSeoData(hotelId: 'x', providerName: 'novoton', name: 'Hotel');

        self::assertSame('', HotelLocationLine::build($hotel));
    }
}

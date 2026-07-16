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

    /**
     * Sphinx hotel 3612 (Rixos Downtown): the API crams CRLF + the ALL-CAPS
     * country/city into the street. The line must keep only the street part
     * and never repeat what city/country already show.
     */
    public function testMessyStreetWithEmbeddedCountryCityIsDeduplicated(): void
    {
        $hotel = new HotelSeoData(
            hotelId: '3612',
            providerName: 'sphinx',
            name: 'Rixos Downtown',
            city: 'Antalya',
            country: 'Turkey',
            address: "Sakip Sabancibulvari\r\nTURKEY, ANTALYA\r\n",
        );

        self::assertSame('Sakip Sabancibulvari, Antalya, Turkey', HotelLocationLine::build($hotel));
    }

    /**
     * Sphinx hotel 3371 (Porto Bello): trailing "/ Antalya" duplicates the
     * city and must go; the slash INSIDE the house number (4A/4B) must not.
     */
    public function testTrailingSlashCitySuffixIsStrippedButHouseNumberSlashSurvives(): void
    {
        $hotel = new HotelSeoData(
            hotelId: '3371',
            providerName: 'sphinx',
            name: 'Porto Bello Hotel Resort&Spa',
            city: 'Antalya',
            country: 'Turkey',
            address: 'Liman Mah. No: 4A/4B Konyaaltı / Antalya',
        );

        self::assertSame('Liman Mah. No: 4A/4B Konyaaltı, Antalya, Turkey', HotelLocationLine::build($hotel));
    }

    /**
     * Novoton city/region/country arrive ALL-CAPS from the API (GOLEM,
     * ALBANIA) and must display Title Case — while the Nominatim-geocoded
     * street keeps its deliberate lowercase particles ('Rruga e Plazhit').
     */
    public function testAllCapsPlacesAreTitleCasedWithoutDamagingGeocodedStreet(): void
    {
        $hotel = new HotelSeoData(
            hotelId: '4535',
            providerName: 'novoton',
            name: 'Monaco Hotel',
            city: 'GOLEM',
            country: 'ALBANIA',
            address: 'Rruga e Plazhit',
        );

        self::assertSame('Rruga e Plazhit, Golem, Albania', HotelLocationLine::build($hotel));
    }

    public function testAllCapsDestinationStyleIsTitleCased(): void
    {
        $hotel = new HotelSeoData(
            hotelId: '4535',
            providerName: 'novoton',
            name: 'Monaco Hotel',
            city: 'GOLEM',
            region: 'TIRANA',
            country: 'ALBANIA',
        );

        self::assertSame('Golem, Tirana, Albania', HotelLocationLine::build($hotel));
    }

    public function testCityEqualToRegionAppearsOnce(): void
    {
        $hotel = new HotelSeoData(
            hotelId: 's2',
            providerName: 'sphinx',
            name: 'Hotel',
            city: 'Antalya',
            region: 'ANTALYA',
            country: 'Turkey',
        );

        self::assertSame('Antalya, Turkey', HotelLocationLine::build($hotel));
    }

    /**
     * Dedup is by segment EQUALITY, not containment: a street that
     * legitimately contains the city name mid-text must survive.
     */
    public function testStreetContainingCityNameMidTextIsNotStripped(): void
    {
        $hotel = new HotelSeoData(
            hotelId: 's3',
            providerName: 'sphinx',
            name: 'Hotel',
            city: 'Antalya',
            country: 'Turkey',
            address: 'Antalya Caddesi 12',
        );

        self::assertSame('Antalya Caddesi 12, Antalya, Turkey', HotelLocationLine::build($hotel));
    }
}

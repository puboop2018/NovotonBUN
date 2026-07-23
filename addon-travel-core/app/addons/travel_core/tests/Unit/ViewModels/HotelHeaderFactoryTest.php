<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\ViewModels;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Dto\Hotel\HotelSeoData;
use Tygh\Addons\TravelCore\ViewModels\HotelHeaderFactory;

final class HotelHeaderFactoryTest extends TestCase
{
    private function seo(?float $lat = 41.32, ?float $lng = 19.45): HotelSeoData
    {
        return new HotelSeoData(
            hotelId: 'H1',
            providerName: 'novoton',
            name: 'Edart Hotel',
            city: 'DURRES',
            region: 'DURRES',
            country: 'ALBANIA',
            latitude: $lat,
            longitude: $lng,
        );
    }

    public function testDerivesLineMapUrlAndVmFromTheSeoDto(): void
    {
        $vm = HotelHeaderFactory::fromSeo($this->seo(), 4, 6);

        self::assertSame('Edart Hotel', $vm->name);
        self::assertSame(4, $vm->stars);
        self::assertSame(6, $vm->productId);
        // HotelLocationLine dedups city==region and Title-Cases.
        self::assertSame('Durres, Albania', $vm->locationLine);
        // With coordinates the map URL is the coordinate pin.
        self::assertStringContainsString('41.32', $vm->mapUrl);
    }

    public function testMapUrlFallsBackToPlaceSearchWithoutCoordinates(): void
    {
        $vm = HotelHeaderFactory::fromSeo($this->seo(null, null), 3, 6);

        self::assertNotSame('', $vm->mapUrl, 'the map link must survive without coordinates');
        self::assertStringNotContainsString('41.32', $vm->mapUrl);
    }

    public function testLocationLineFallbackAppliesOnlyWhenTheSanitizerIsEmpty(): void
    {
        $emptySeo = new HotelSeoData(hotelId: 'H2', providerName: 'novoton', name: 'X Hotel');
        $vm = HotelHeaderFactory::fromSeo($emptySeo, 0, 0, 'City, Country');
        self::assertSame('City, Country', $vm->locationLine);

        $vmWithLine = HotelHeaderFactory::fromSeo($this->seo(), 0, 0, 'IGNORED');
        self::assertSame('Durres, Albania', $vmWithLine->locationLine, 'fallback must not override the sanitized line');
    }

    public function testBareBuildsHeaderWithoutLocationData(): void
    {
        $vm = HotelHeaderFactory::bare('Some Hotel', 9, -3);

        self::assertSame('Some Hotel', $vm->name);
        self::assertSame(5, $vm->stars, 'VM clamping still applies');
        self::assertSame(0, $vm->productId);
        self::assertSame('', $vm->locationLine);
        self::assertSame('', $vm->mapUrl);
    }
}

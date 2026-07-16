<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Dto\Hotel\HotelSeoData;
use Tygh\Addons\TravelCore\Services\HotelMapUrl;

/**
 * The PDP map URL is built server-side: full-precision coordinate pin when
 * the provider has coordinates (2-dp truncation put pins ~1 km off — the
 * sphinx ?d bug), else a Google place search by "name, address" (the
 * user-selected fallback for hotels whose coordinates the API can't
 * establish), else no link.
 */
#[CoversClass(HotelMapUrl::class)]
final class HotelMapUrlTest extends TestCase
{
    private function hotel(?float $lat, ?float $lng, string $name = 'Rixos Downtown'): HotelSeoData
    {
        return new HotelSeoData(
            hotelId: '3612',
            providerName: 'sphinx',
            name: $name,
            latitude: $lat,
            longitude: $lng,
        );
    }

    public function testCoordinatesRenderAtFullPrecision(): void
    {
        $url = HotelMapUrl::build($this->hotel(36.887069, 30.674622), 'Antalya, Turkey');

        self::assertSame('https://www.google.com/maps?q=36.887069,30.674622', $url);
    }

    public function testTrailingZerosAreTrimmed(): void
    {
        self::assertSame(
            'https://www.google.com/maps?q=30.7,36',
            HotelMapUrl::build($this->hotel(30.7, 36.0), ''),
        );
    }

    public function testNegativeCoordinatesSurvive(): void
    {
        self::assertSame(
            'https://www.google.com/maps?q=-33.8567844,151.2152967',
            HotelMapUrl::build($this->hotel(-33.8567844, 151.2152967), ''),
        );
    }

    public function testNullCoordinatesFallBackToPlaceSearch(): void
    {
        $url = HotelMapUrl::build(
            $this->hotel(null, null, 'Porto Bello Hotel Resort&Spa'),
            'Liman Mah. No: 4A/4B Konyaaltı, Antalya, Turkey',
        );

        self::assertSame(
            'https://www.google.com/maps/search/?api=1&query='
            . rawurlencode('Porto Bello Hotel Resort&Spa, Liman Mah. No: 4A/4B Konyaaltı, Antalya, Turkey'),
            $url,
        );
        // The encoded query keeps the ampersand and diacritics intact.
        self::assertStringContainsString('Resort%26Spa', (string) $url);
        self::assertStringContainsString(rawurlencode('Konyaaltı'), (string) $url);
    }

    public function testBothZeroSentinelFallsBackToPlaceSearch(): void
    {
        $url = HotelMapUrl::build($this->hotel(0.0, 0.0), 'Antalya, Turkey');

        self::assertSame(
            'https://www.google.com/maps/search/?api=1&query=' . rawurlencode('Rixos Downtown, Antalya, Turkey'),
            $url,
        );
    }

    public function testNameOnlyFallbackWhenLineIsEmpty(): void
    {
        $url = HotelMapUrl::build($this->hotel(null, null), '');

        self::assertSame(
            'https://www.google.com/maps/search/?api=1&query=' . rawurlencode('Rixos Downtown'),
            $url,
        );
    }

    public function testNothingUsableGivesNull(): void
    {
        self::assertNull(HotelMapUrl::build($this->hotel(null, null, ''), ''));
        self::assertNull(HotelMapUrl::build($this->hotel(null, null, '  '), '  '));
    }

    public function testEighthDecimalSurvives(): void
    {
        // DECIMAL(10,8)/(11,8) storage keeps 8 decimals; the formatter (%.8F)
        // must not drop the 8th the way the older %.7F did.
        self::assertSame(
            'https://www.google.com/maps?q=36.88706912,30.67462198',
            HotelMapUrl::build($this->hotel(36.88706912, 30.67462198), ''),
        );
    }
}

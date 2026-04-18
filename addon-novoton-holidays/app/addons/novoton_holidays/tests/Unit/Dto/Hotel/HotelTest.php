<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Dto\Hotel;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Dto\Hotel\GeoPoint;
use Tygh\Addons\TravelCore\Dto\Hotel\Hotel;

#[CoversClass(Hotel::class)]
#[CoversClass(GeoPoint::class)]
final class HotelTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        /** @var array<string, mixed> $row */
        $row = require __DIR__ . '/../../../Fixtures/hotel_row.php';
        return $row;
    }

    public function testFromDbRowMapsAllFields(): void
    {
        $hotel = Hotel::fromDbRow(self::fixture());

        $this->assertSame('NVT12345', $hotel->hotelId);
        $this->assertSame(4201, $hotel->productId);
        $this->assertSame('Hotel Example Palace', $hotel->name);
        $this->assertSame('Barcelona', $hotel->city);
        $this->assertSame('Catalonia', $hotel->region);
        $this->assertSame('Spain', $hotel->country);
        $this->assertSame('4*', $hotel->hotelType);
        $this->assertSame(4, $hotel->starRating);
        $this->assertSame('hotel', $hotel->propertyType);
        $this->assertFalse($hotel->isAdultsOnly);
        $this->assertTrue($hotel->hasRoomPrice);
        $this->assertSame(7, $hotel->packagesCount);
    }

    public function testFromDbRowDecodesCoordinates(): void
    {
        $hotel = Hotel::fromDbRow(self::fixture());

        $this->assertInstanceOf(GeoPoint::class, $hotel->coords);
        $this->assertEqualsWithDelta(41.385, $hotel->coords->latitude, 0.0001);
        $this->assertEqualsWithDelta(2.1734, $hotel->coords->longitude, 0.0001);
    }

    public function testFromDbRowDecodesJsonBlobs(): void
    {
        $hotel = Hotel::fromDbRow(self::fixture());

        $this->assertIsArray($hotel->rawHotelData);
        $this->assertSame('NVT12345', $hotel->rawHotelData['IdHotel'] ?? null);

        $this->assertIsArray($hotel->rawCalendarPrices);
        $this->assertSame(145.5, $hotel->rawCalendarPrices['2026-05-01'] ?? null);
    }

    public function testFromDbRowParsesDatetimeFields(): void
    {
        $hotel = Hotel::fromDbRow(self::fixture());

        $this->assertInstanceOf(DateTimeImmutable::class, $hotel->hotelinfoSyncedAt);
        $this->assertSame('2026-04-15 10:30:45', $hotel->hotelinfoSyncedAt->format('Y-m-d H:i:s'));
        $this->assertInstanceOf(DateTimeImmutable::class, $hotel->updatedAt);
    }

    public function testFromDbRowRejectsEmptyHotelId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Hotel::fromDbRow(['hotel_id' => '']);
    }

    public function testFromDbRowWithMinimalRowProducesNullsForMissingFields(): void
    {
        $hotel = Hotel::fromDbRow(['hotel_id' => 'X1']);

        $this->assertSame('X1', $hotel->hotelId);
        $this->assertNull($hotel->productId);
        $this->assertNull($hotel->name);
        $this->assertNull($hotel->coords);
        $this->assertNull($hotel->rawHotelData);
        $this->assertNull($hotel->hotelinfoSyncedAt);
        $this->assertSame('hotel', $hotel->propertyType);
        $this->assertSame('', $hotel->hotelType);
        $this->assertSame(0, $hotel->packagesCount);
        $this->assertFalse($hotel->isAdultsOnly);
        $this->assertFalse($hotel->hasRoomPrice);
    }

    public function testFromDbRowTreatsZeroZeroCoordsAsMissing(): void
    {
        $row = self::fixture();
        $row['latitude'] = '0.0';
        $row['longitude'] = '0.0';
        $this->assertNull(Hotel::fromDbRow($row)->coords);
    }

    public function testFromDbRowRejectsOutOfRangeCoords(): void
    {
        $row = self::fixture();
        $row['latitude'] = '91.0'; // > 90
        $this->assertNull(Hotel::fromDbRow($row)->coords);
    }

    public function testFromDbRowHandlesMalformedJson(): void
    {
        $row = self::fixture();
        $row['hotel_data'] = '{not valid json';
        $hotel = Hotel::fromDbRow($row);
        $this->assertNull($hotel->rawHotelData);
    }

    public function testFromDbRowTreatsZeroDateAsNull(): void
    {
        $row = self::fixture();
        $row['hotelinfo_synced_at'] = '0000-00-00 00:00:00';
        $hotel = Hotel::fromDbRow($row);
        $this->assertNull($hotel->hotelinfoSyncedAt);
    }

    public function testToArrayRoundTrip(): void
    {
        $original = Hotel::fromDbRow(self::fixture());
        $roundTripped = Hotel::fromDbRow($original->toArray());

        $this->assertEquals($original, $roundTripped);
    }

    public function testToArrayUsesLegacyKeys(): void
    {
        $hotel = Hotel::fromDbRow(self::fixture());
        $arr = $hotel->toArray();

        $this->assertSame('NVT12345', $arr['hotel_id']);
        $this->assertSame(4201, $arr['product_id']);
        $this->assertSame('Hotel Example Palace', $arr['hotel_name']);
        // Y/N re-emitted
        $this->assertSame('N', $arr['is_adults_only']);
        $this->assertSame('Y', $arr['has_room_price']);
        // Dates re-emitted as strings
        $this->assertSame('2026-04-15 10:30:45', $arr['hotelinfo_synced_at']);
    }

    public function testGeoPointFromMixedReturnsNullOnMissingInputs(): void
    {
        $this->assertNull(GeoPoint::fromMixed(null, '1.0'));
        $this->assertNull(GeoPoint::fromMixed('1.0', null));
        $this->assertNull(GeoPoint::fromMixed('', '2.0'));
    }

    public function testGeoPointFromMixedParsesStrings(): void
    {
        $p = GeoPoint::fromMixed('41.385', '2.1734');
        $this->assertNotNull($p);
        $this->assertEqualsWithDelta(41.385, $p->latitude, 0.0001);
    }
}

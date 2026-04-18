<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Dto\Hotel;

use DateTimeImmutable;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Typed view of a row from `?:novoton_hotels`.
 *
 * Replaces the `array<string, mixed>` shape returned by
 * `HotelRepository::findById()` for new code paths. Existing array callers
 * keep working via `Hotel::toArray()` at the boundary — see PR plan for
 * the migration sequence.
 *
 * The two JSON blobs (`hotel_data`, `calendar_prices_raw`) are kept as
 * nullable decoded arrays for now; dedicated sub-DTOs for their interior
 * (priceinfo, facilities, images, calendar entries) land in later PRs
 * when concrete consumers migrate to them.
 */
final readonly class Hotel
{
    /**
     * @param array<string, mixed>|null $rawHotelData Decoded `hotel_data` JSON blob
     *                                                (raw hotelinfo API response).
     * @param array<string, mixed>|null $rawCalendarPrices Decoded `calendar_prices_raw` JSON blob.
     */
    public function __construct(
        public string $hotelId,
        public ?int $productId,
        public ?string $name,
        public ?string $city,
        public ?string $region,
        public ?string $country,
        public string $hotelType,
        public ?int $starRating,
        public string $propertyType,
        public bool $isAdultsOnly,
        public ?GeoPoint $coords,
        public ?array $rawHotelData,
        public bool $hasRoomPrice,
        public int $packagesCount,
        public ?DateTimeImmutable $hotelinfoSyncedAt,
        public ?DateTimeImmutable $hotelListSyncedAt,
        public ?DateTimeImmutable $lastPriceCheck,
        public ?array $rawCalendarPrices,
        public ?DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * Build from a raw `?:novoton_hotels` row.
     *
     * Tolerant of the CS-Cart DB layer's habit of returning numeric columns
     * as strings. The only hard requirement is a non-empty `hotel_id` (PK).
     *
     * @param array<string, mixed> $row
     */
    public static function fromDbRow(array $row): self
    {
        $hotelId = TypeCoerce::toString($row['hotel_id'] ?? '');
        if ($hotelId === '') {
            throw new \InvalidArgumentException('Hotel::fromDbRow requires non-empty hotel_id');
        }

        return new self(
            hotelId: $hotelId,
            productId: self::optInt($row['product_id'] ?? null),
            name: self::optString($row['hotel_name'] ?? null),
            city: self::optString($row['city'] ?? null),
            region: self::optString($row['region'] ?? null),
            country: self::optString($row['country'] ?? null),
            hotelType: TypeCoerce::toString($row['hotel_type'] ?? ''),
            starRating: self::optInt($row['star_rating'] ?? null),
            propertyType: TypeCoerce::toString($row['property_type'] ?? 'hotel') ?: 'hotel',
            isAdultsOnly: TypeCoerce::toBool($row['is_adults_only'] ?? false),
            coords: GeoPoint::fromMixed($row['latitude'] ?? null, $row['longitude'] ?? null),
            rawHotelData: self::decodeJson($row['hotel_data'] ?? null),
            hasRoomPrice: TypeCoerce::toBool($row['has_room_price'] ?? false),
            packagesCount: TypeCoerce::toInt($row['packages_count'] ?? 0),
            hotelinfoSyncedAt: self::optDate($row['hotelinfo_synced_at'] ?? null),
            hotelListSyncedAt: self::optDate($row['hotel_list_synced_at'] ?? null),
            lastPriceCheck: self::optDate($row['last_price_check'] ?? null),
            rawCalendarPrices: self::decodeJson($row['calendar_prices_raw'] ?? null),
            createdAt: self::optDate($row['created_at'] ?? null),
            updatedAt: self::optDate($row['updated_at'] ?? null),
        );
    }

    /**
     * Re-emit in the legacy `findById()` shape for back-compat at hook/template
     * boundaries that still consume arrays.
     *
     * Datetime fields are serialised as 'Y-m-d H:i:s' (CS-Cart convention);
     * JSON blobs are re-encoded; Y/N booleans are re-emitted as 'Y'/'N'.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'hotel_id' => $this->hotelId,
            'product_id' => $this->productId,
            'hotel_name' => $this->name,
            'city' => $this->city,
            'region' => $this->region,
            'country' => $this->country,
            'hotel_type' => $this->hotelType,
            'star_rating' => $this->starRating,
            'property_type' => $this->propertyType,
            'is_adults_only' => $this->isAdultsOnly ? 'Y' : 'N',
            'latitude' => $this->coords?->latitude,
            'longitude' => $this->coords?->longitude,
            'hotel_data' => $this->rawHotelData === null ? null : json_encode($this->rawHotelData),
            'has_room_price' => $this->hasRoomPrice ? 'Y' : 'N',
            'packages_count' => $this->packagesCount,
            'hotelinfo_synced_at' => $this->hotelinfoSyncedAt?->format('Y-m-d H:i:s'),
            'hotel_list_synced_at' => $this->hotelListSyncedAt?->format('Y-m-d H:i:s'),
            'last_price_check' => $this->lastPriceCheck?->format('Y-m-d H:i:s'),
            'calendar_prices_raw' => $this->rawCalendarPrices === null ? null : json_encode($this->rawCalendarPrices),
            'created_at' => $this->createdAt?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt?->format('Y-m-d H:i:s'),
        ];
    }

    private static function optString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = TypeCoerce::toString($value);
        return $s === '' ? null : $s;
    }

    private static function optInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        return TypeCoerce::toInt($value);
    }

    /**
     * Parse a CS-Cart datetime string ('Y-m-d H:i:s' or similar) into an
     * immutable DateTime. Empty / null / '0000-00-00 00:00:00' → null.
     */
    private static function optDate(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }
        if (!is_string($value)) {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decodeJson(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            /** @var array<string, mixed> $value */
            return $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return null;
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}

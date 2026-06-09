<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Providers;

use Tygh\Addons\TravelCore\Contracts\HotelProductProviderInterface;
use Tygh\Addons\TravelCore\Dto\Hotel\HotelSeoData;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Novoton implementation of HotelProductProviderInterface.
 *
 * Claims products whose product_code starts with 'NVT' and resolves
 * hotel data from ?:novoton_hotels for SEO/booking-form use.
 */
final class NovotonHotelProductProvider implements HotelProductProviderInterface
{
    #[\Override]
    public function resolveProduct(int $productId, string $productCode): ?HotelSeoData
    {
        if (!str_starts_with($productCode, 'NVT')) {
            return null;
        }

        $hotelId = substr($productCode, 3);
        if ($hotelId === '') {
            return null;
        }

        $row = TypeCoerce::toStringMap(db_get_row(
            'SELECT hotel_name AS name, star_rating AS classification, hotel_type AS property_type,
                    city, region, country, latitude, longitude
             FROM ?:novoton_hotels WHERE hotel_id = ?s',
            $hotelId,
        ));

        if ($row === []) {
            return null;
        }

        return new HotelSeoData(
            hotelId: $hotelId,
            providerName: 'novoton',
            name: TypeCoerce::toString($row['name'] ?? ''),
            classification: self::optInt($row['classification'] ?? null),
            propertyType: self::optString($row['property_type'] ?? null),
            city: self::optString($row['city'] ?? null),
            region: self::optString($row['region'] ?? null),
            country: self::optString($row['country'] ?? null),
            latitude: self::optFloat($row['latitude'] ?? null),
            longitude: self::optFloat($row['longitude'] ?? null),
        );
    }

    #[\Override]
    public function ownsHotelId(string $hotelId): bool
    {
        // Novoton hotel ids are numeric.
        if ($hotelId === '' || !ctype_digit($hotelId)) {
            return false;
        }

        return (bool) db_get_field(
            'SELECT hotel_id FROM ?:novoton_hotels WHERE hotel_id = ?i LIMIT 1',
            (int) $hotelId,
        );
    }

    private static function optString(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = TypeCoerce::toString($v);
        return $s === '' ? null : $s;
    }

    private static function optInt(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_numeric($v)) {
            return null;
        }
        $i = TypeCoerce::toInt($v);
        return $i > 0 ? $i : null;
    }

    private static function optFloat(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        $f = TypeCoerce::toFloat($v);
        return $f !== 0.0 ? $f : null;
    }
}

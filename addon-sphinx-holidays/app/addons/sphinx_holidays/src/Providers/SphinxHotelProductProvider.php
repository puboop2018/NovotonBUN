<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Providers;

use Tygh\Addons\TravelCore\Contracts\HotelProductProviderInterface;
use Tygh\Addons\TravelCore\Dto\Hotel\HotelSeoData;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Sphinx implementation of HotelProductProviderInterface.
 *
 * Claims products that have a matching row in ?:sphinx_hotels (identified
 * by product_id, since sphinx product codes use country-code prefixes rather
 * than a fixed "SPX" prefix). All SQL against ?:sphinx_hotels lives here so
 * travel_core never queries provider-specific tables directly.
 */
final class SphinxHotelProductProvider implements HotelProductProviderInterface
{
    #[\Override]
    public function resolveProduct(int $productId, string $productCode): ?HotelSeoData
    {
        $row = TypeCoerce::toStringMap(db_get_row(
            'SELECT hotel_id, name, classification, property_type,
                    destination_name AS city, region_name AS region, country_name AS country,
                    latitude, longitude, image_url, address, phone, email, website
             FROM ?:sphinx_hotels WHERE product_id = ?i LIMIT 1',
            $productId,
        ));

        if ($row === []) {
            return null;
        }

        return new HotelSeoData(
            hotelId: TypeCoerce::toString($row['hotel_id'] ?? ''),
            providerName: 'sphinx',
            name: TypeCoerce::toString($row['name'] ?? ''),
            classification: self::optInt($row['classification'] ?? null),
            propertyType: self::optString($row['property_type'] ?? null),
            city: self::optString($row['city'] ?? null),
            region: self::optString($row['region'] ?? null),
            country: self::optString($row['country'] ?? null),
            latitude: self::optFloat($row['latitude'] ?? null),
            longitude: self::optFloat($row['longitude'] ?? null),
            imageUrl: self::optString($row['image_url'] ?? null),
            address: self::optString($row['address'] ?? null),
            phone: self::optString($row['phone'] ?? null),
            email: self::optString($row['email'] ?? null),
            website: self::optString($row['website'] ?? null),
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

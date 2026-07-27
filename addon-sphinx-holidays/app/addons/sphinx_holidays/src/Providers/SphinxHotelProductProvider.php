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
        $row = $this->fetchHotelRow('product_id = ?i', $productId);

        // Self-heal a lost product link. Sphinx product codes are
        // <ISO-2 country><hotel_id> (TR3612), so the code alone identifies
        // the hotel even when ?:sphinx_hotels.product_id was wiped (fresh
        // reinstall, table re-sync) — without this, such hotels render as
        // plain products forever: no booking form, no location line.
        if ($row === [] && preg_match('/^[A-Z]{2}(\d+)$/', $productCode, $m) === 1) {
            $row = $this->fetchHotelRow('hotel_id = ?s', $m[1]);
            if ($row !== []) {
                $linked = TypeCoerce::toInt($row['product_id'] ?? 0);
                if ($linked <= 0) {
                    db_query(
                        'UPDATE ?:sphinx_hotels SET product_id = ?i WHERE hotel_id = ?s',
                        $productId,
                        $m[1],
                    );
                } elseif ($linked !== $productId) {
                    // Another product already owns this hotel — serve the PDP
                    // but leave the link alone and make the conflict visible.
                    fn_log_event('general', 'runtime', [
                        'message' => 'Sphinx: hotel linked to a different product; code-based resolve served without relinking',
                        'hotel_id' => $m[1],
                        'linked_product_id' => $linked,
                        'requested_product_id' => $productId,
                    ]);
                }
            }
        }

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

    /**
     * One row from ?:sphinx_hotels by a single-placeholder WHERE clause.
     * City/country prefer the hotel's own API address fields (clean,
     * per-hotel) over the destination-tree names, which are irregular.
     *
     * @return array<string, mixed>
     */
    private function fetchHotelRow(string $where, int|string $value): array
    {
        return TypeCoerce::toStringMap(db_get_row(
            'SELECT hotel_id, product_id, name, classification, property_type,
                    COALESCE(NULLIF(address_city, ?s), destination_name) AS city,
                    region_name AS region,
                    COALESCE(NULLIF(address_country, ?s), country_name) AS country,
                    latitude, longitude, image_url, address, phone, email, website
             FROM ?:sphinx_hotels WHERE ' . $where . ' LIMIT 1',
            '',
            '',
            $value,
        ));
    }

    #[\Override]
    public function ownsHotelId(string $hotelId): bool
    {
        if ($hotelId === '') {
            return false;
        }

        return (bool) db_get_field(
            'SELECT hotel_id FROM ?:sphinx_hotels WHERE hotel_id = ?s LIMIT 1',
            $hotelId,
        );
    }

    #[\Override]
    public function productIdForHotelId(string $hotelId): ?int
    {
        if ($hotelId === '') {
            return null;
        }

        $productId = TypeCoerce::toInt(db_get_field(
            'SELECT product_id FROM ?:sphinx_hotels WHERE hotel_id = ?s LIMIT 1',
            $hotelId,
        ));

        return $productId > 0 ? $productId : null;
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

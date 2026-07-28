<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services\Geocoding;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * What a reverse-geocode lookup found at a coordinate pair.
 *
 * Nominatim answers with a loose `address` object whose keys vary by
 * country; this value object pins the three things travel addons actually
 * need — an approximate street line, a city and a region — so callers never
 * have to know the key soup.
 */
final class ReverseGeocodeResult
{
    public function __construct(
        public readonly string $street = '',
        public readonly string $city = '',
        public readonly string $region = '',
        public readonly string $postcode = '',
        public readonly string $countryCode = '',
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->street === '' && $this->city === '' && $this->region === '';
    }

    /**
     * Build from Nominatim's `address` object.
     *
     * City: the settlement, whatever Nominatim called it.
     *
     * Region: `region` is deliberately LAST. Verified against the live API
     * for Adalya Port Hotel (36.885640, 30.702412), which answers
     * `province: "Antalya"` alongside `region: "Akdeniz Bölgesi"` — the
     * Mediterranean macro-region. Taking `region` first would store a
     * grouping no Sphinx region node matches; `province` is the level that
     * lines up with the destination tree.
     *
     * @param array<array-key, mixed> $address
     */
    public static function fromNominatimAddress(array $address): self
    {
        return new self(
            street: self::street($address),
            city: self::firstOf($address, ['city', 'town', 'village', 'municipality', 'hamlet']),
            region: self::firstOf($address, ['state', 'province', 'county', 'state_district', 'region']),
            postcode: self::str($address['postcode'] ?? null),
            countryCode: strtoupper(self::str($address['country_code'] ?? null)),
        );
    }

    /**
     * "road house_number" when both are known (Romanian postal style puts the
     * number after the road). The nearest mapped way may be a pedestrian zone
     * or footpath; failing those, a neighbourhood still localizes the hotel.
     *
     * @param array<array-key, mixed> $address
     */
    private static function street(array $address): string
    {
        $road = self::firstOf($address, ['road', 'pedestrian', 'footway', 'neighbourhood']);
        if ($road === '') {
            return '';
        }

        $number = self::str($address['house_number'] ?? null);

        return mb_substr($number !== '' ? "{$road} {$number}" : $road, 0, 255);
    }

    /**
     * @param array<array-key, mixed> $address
     * @param list<string> $keys
     */
    private static function firstOf(array $address, array $keys): string
    {
        foreach ($keys as $key) {
            $value = self::str($address[$key] ?? null);
            if ($value !== '') {
                return mb_substr($value, 0, 255);
            }
        }

        return '';
    }

    private static function str(mixed $value): string
    {
        return trim(TypeCoerce::toString($value));
    }
}

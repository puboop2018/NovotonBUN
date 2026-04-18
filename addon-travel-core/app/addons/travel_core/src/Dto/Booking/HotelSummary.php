<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Dto\Booking;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Minimal hotel identity/location snapshot embedded in a cart item.
 *
 * Distinct from {@see \Tygh\Addons\TravelCore\Dto\Hotel\Hotel} — that's the
 * full `?:novoton_hotels` row; this is the subset actually used by the
 * cart display (hotel_name, city, region, country + id).
 */
final readonly class HotelSummary
{
    public function __construct(
        public string $hotelId,
        public string $name,
        public string $city,
        public string $region,
        public string $country,
    ) {
    }

    /**
     * @param array<string, mixed> $extra cart-item extra bag
     */
    public static function fromCartExtra(array $extra, string $defaultCountry = ''): self
    {
        return new self(
            hotelId: TypeCoerce::toString($extra['hotel_id'] ?? ''),
            name: TypeCoerce::toString($extra['hotel_name'] ?? ''),
            city: TypeCoerce::toString($extra['hotel_city'] ?? ''),
            region: TypeCoerce::toString($extra['hotel_region'] ?? ''),
            country: TypeCoerce::toString($extra['hotel_country'] ?? $defaultCountry),
        );
    }
}

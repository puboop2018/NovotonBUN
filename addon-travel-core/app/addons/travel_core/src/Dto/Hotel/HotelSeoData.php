<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Dto\Hotel;

/**
 * Provider-neutral SEO/display snapshot for a hotel product.
 *
 * Returned by HotelProductProviderInterface::resolveProduct() so that
 * travel_core can build JSON-LD, OG tags, and booking-form config without
 * containing any provider-specific SQL.
 */
final readonly class HotelSeoData
{
    public function __construct(
        public string $hotelId,
        public string $providerName,
        public string $name,
        public ?int $classification = null,
        public ?string $propertyType = null,
        public ?string $city = null,
        public ?string $region = null,
        public ?string $country = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?string $imageUrl = null,
        public ?string $address = null,
        public ?string $phone = null,
        public ?string $email = null,
        public ?string $website = null,
    ) {
    }
}

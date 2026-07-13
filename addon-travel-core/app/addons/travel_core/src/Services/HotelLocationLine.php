<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Dto\Hotel\HotelSeoData;

/**
 * Builds the human-readable location line shown under the hotel name on the
 * product page. The rule is keyed on DATA, not provider: when a street
 * address exists the postal style wins — "street, city, country" (sphinx
 * hotels carry the API address); otherwise the destination style —
 * "city, region, country" (novoton has no street field).
 *
 * @since 1.5.0
 */
final class HotelLocationLine
{
    public static function build(HotelSeoData $hotel): string
    {
        $street = trim((string) ($hotel->address ?? ''));
        $city = trim((string) ($hotel->city ?? ''));
        $country = trim((string) ($hotel->country ?? ''));

        $parts = $street !== ''
            ? [$street, $city, $country]
            : [$city, trim((string) ($hotel->region ?? '')), $country];

        return implode(', ', array_filter($parts, static fn (string $part): bool => $part !== ''));
    }
}

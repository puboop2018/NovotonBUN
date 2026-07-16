<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Dto\Hotel\HotelSeoData;

/**
 * Builds the PDP "show on map" URL server-side, for both providers.
 *
 * With coordinates: a full-precision Google Maps pin. The precision matters —
 * coordinates truncated to 2 dp put the pin ~1 km off the hotel (the sphinx
 * ?d write-path bug), so the formatter here is explicit and locale-safe.
 *
 * Without coordinates (the API "cannot accurately establish" them — e.g.
 * sphinx hotel 3371): a Google Maps PLACE SEARCH for "Hotel Name, street,
 * city, country". Google snaps to its own listing of the hotel, which is
 * usually building-accurate — strictly better than no link at all.
 *
 * Returns null only when there is nothing usable to link.
 */
final class HotelMapUrl
{
    public static function build(HotelSeoData $hotel, string $locationLine): ?string
    {
        $lat = $hotel->latitude;
        $lng = $hotel->longitude;

        // Providers null out the 0.0 sentinel, but guard both-zero anyway —
        // there is no hotel at "null island".
        if ($lat !== null && $lng !== null && ($lat !== 0.0 || $lng !== 0.0)) {
            return 'https://www.google.com/maps?q=' . self::coord($lat) . ',' . self::coord($lng);
        }

        $query = trim(trim($hotel->name) . ', ' . trim($locationLine), ', ');
        if ($query === '') {
            return null;
        }

        return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($query);
    }

    /**
     * Full-precision, locale-independent coordinate string: %F always uses
     * '.', 7 decimals match the DECIMAL(10,7) storage, trailing zeros are
     * trimmed for URL aesthetics (30.7000000 → 30.7, 36.0000000 → 36).
     */
    private static function coord(float $value): string
    {
        $s = rtrim(rtrim(sprintf('%.7F', $value), '0'), '.');

        return $s === '' || $s === '-' ? '0' : $s;
    }
}

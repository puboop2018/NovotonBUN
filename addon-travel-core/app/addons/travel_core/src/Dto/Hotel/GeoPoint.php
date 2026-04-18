<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Dto\Hotel;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Geographic coordinate pair (latitude, longitude in WGS-84 degrees).
 *
 * Produced only when both values are present AND within valid ranges.
 * Callers use {@see self::fromMixed()} to turn raw DB columns into a
 * coord pair or null — no half-populated points.
 */
final readonly class GeoPoint
{
    public function __construct(
        public float $latitude,
        public float $longitude,
    ) {
    }

    /**
     * Build from two raw mixed values (e.g. decimal columns from the DB).
     *
     * Returns null if either value is missing, non-numeric, or out of range
     * (lat ∈ [-90, 90], lon ∈ [-180, 180]). The (0, 0) "null island" point
     * is treated as missing data since the underlying schema allows NULL.
     */
    public static function fromMixed(mixed $lat, mixed $lon): ?self
    {
        if ($lat === null || $lon === null || $lat === '' || $lon === '') {
            return null;
        }
        $latF = TypeCoerce::toFloat($lat);
        $lonF = TypeCoerce::toFloat($lon);
        if ($latF === 0.0 && $lonF === 0.0) {
            return null;
        }
        if ($latF < -90.0 || $latF > 90.0 || $lonF < -180.0 || $lonF > 180.0) {
            return null;
        }
        return new self($latF, $lonF);
    }
}

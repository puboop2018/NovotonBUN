<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Builds per-date "from" prices for the booking-engine calendar, the sphinx
 * counterpart of novoton's CalendarPriceBuilder.
 *
 * Novoton computes calendar prices from locally stored priceinfo; sphinx has
 * no local price data, so this probes the synchronous cache endpoint
 * (`POST /api/v1/cache/hotels`) once per destination per check-in date —
 * every hotel of the destination is covered by the same call, so a 60-day
 * horizon costs 60 requests per destination, not per hotel.
 *
 * Prices are stored RAW (API currency, no commission); the storefront applies
 * commission at read time, mirroring novoton's raw-storage semantics.
 */
class CalendarPriceBuilder
{
    public const int DEFAULT_DAYS_AHEAD = 60;
    public const int DEFAULT_NIGHTS = 7;
    private const int ADULTS = 2;

    public function __construct(private readonly SphinxApi $api)
    {
    }

    /**
     * Check-in/check-out windows from tomorrow to +$daysAhead.
     *
     * @return list<array{check_in: string, check_out: string}>
     */
    public static function buildWindows(int $daysAhead, int $nights, string $fromDate = 'today'): array
    {
        $windows = [];
        $base = strtotime($fromDate);
        if ($base === false || $daysAhead < 1 || $nights < 1) {
            return [];
        }
        for ($offset = 1; $offset <= $daysAhead; $offset++) {
            $windows[] = [
                'check_in' => date('Y-m-d', $base + $offset * 86400),
                'check_out' => date('Y-m-d', $base + ($offset + $nights) * 86400),
            ];
        }
        return $windows;
    }

    /**
     * Probe one destination across the horizon.
     *
     * @return array<string, array<string, float>> hotel_id => [check-in date => min raw price]
     */
    public function probeDestination(
        int $destinationId,
        string $currency,
        int $daysAhead = self::DEFAULT_DAYS_AHEAD,
        int $nights = self::DEFAULT_NIGHTS,
    ): array {
        $byHotel = [];

        foreach (self::buildWindows($daysAhead, $nights) as $window) {
            $response = $this->api->cacheHotels([
                'destination_id' => $destinationId,
                'check_in' => $window['check_in'],
                'check_out' => $window['check_out'],
                'occupancy' => [['adults' => self::ADULTS, 'children_ages' => []]],
                'currency' => $currency,
            ]);

            if (!is_array($response)) {
                continue;
            }

            foreach (TypeCoerce::toRowList($response['results'] ?? []) as $row) {
                $hotelId = self::rowHotelId($row);
                $price = self::rowPrice($row);
                if ($hotelId === '' || $price <= 0) {
                    continue;
                }
                $existing = $byHotel[$hotelId][$window['check_in']] ?? null;
                if ($existing === null || $price < $existing) {
                    $byHotel[$hotelId][$window['check_in']] = $price;
                }
            }
        }

        return $byHotel;
    }

    /**
     * Hotel id from a cache/hotels data row (key names vary by endpoint age).
     *
     * @param array<string, mixed> $row
     */
    public static function rowHotelId(array $row): string
    {
        return TypeCoerce::toString($row['hotel_id'] ?? $row['id'] ?? '');
    }

    /**
     * Cheapest price on a cache/hotels data row: accepts scalar `price` /
     * `min_price` / `selling_price`, or the same keys as nested maps carrying
     * a `price` field.
     *
     * @param array<string, mixed> $row
     */
    public static function rowPrice(array $row): float
    {
        foreach (['price', 'min_price', 'selling_price'] as $key) {
            $value = $row[$key] ?? null;
            if (is_numeric($value)) {
                return (float) $value;
            }
            if (is_array($value) && is_numeric($value['price'] ?? null)) {
                return (float) $value['price'];
            }
        }
        return 0.0;
    }
}

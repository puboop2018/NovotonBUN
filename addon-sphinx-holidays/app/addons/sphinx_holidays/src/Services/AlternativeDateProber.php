<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Probes nearby date windows when a hotel search comes back empty — the
 * sphinx counterpart of novoton's AlternativeDateSearcher UX ("try these
 * dates instead" links under the no-results message).
 *
 * Uses the synchronous cache/hotels endpoint (one request per candidate
 * window, no cursor polling) and narrows the destination response to the
 * requested hotel with the same tolerant row parsing the calendar builder
 * uses. Prices returned are RAW (no commission) — callers apply commission
 * for display, like every other sphinx price path.
 */
class AlternativeDateProber
{
    /** Check-in shifts (days) probed around the requested date, in order. */
    public const array SHIFTS_DAYS = [-7, -3, 3, 7, 14];
    public const int MAX_ALTERNATIVES = 3;

    public function __construct(private readonly SphinxApi $api)
    {
    }

    /**
     * Shifted candidate windows; past check-ins are skipped, the original
     * window is never included.
     *
     * @return list<array{check_in: string, check_out: string}>
     */
    public static function candidateWindows(string $checkIn, int $nights, string $today = 'today'): array
    {
        $base = strtotime($checkIn);
        $todayTs = strtotime($today);
        if ($base === false || $todayTs === false || $nights < 1) {
            return [];
        }

        $windows = [];
        foreach (self::SHIFTS_DAYS as $shift) {
            $start = $base + $shift * 86400;
            if ($start <= $todayTs) {
                continue;
            }
            $windows[] = [
                'check_in' => date('Y-m-d', $start),
                'check_out' => date('Y-m-d', $start + $nights * 86400),
            ];
        }

        return $windows;
    }

    /**
     * Probe candidate windows for the hotel; stops after MAX_ALTERNATIVES hits.
     *
     * @return list<array{check_in: string, check_out: string, price: float}>
     */
    public function probe(
        int $destinationId,
        string $hotelId,
        string $checkIn,
        int $nights,
        int $adults,
        string $currency,
    ): array {
        if ($destinationId <= 0 || $hotelId === '') {
            return [];
        }

        $alternatives = [];
        foreach (self::candidateWindows($checkIn, $nights) as $window) {
            $response = $this->api->cacheHotels([
                'destination_id' => $destinationId,
                'check_in' => $window['check_in'],
                'check_out' => $window['check_out'],
                'occupancy' => [['adults' => max(1, $adults), 'children_ages' => []]],
                'currency' => $currency,
            ]);

            if (!is_array($response)) {
                continue;
            }

            foreach (TypeCoerce::toRowList($response['data'] ?? []) as $row) {
                if (CalendarPriceBuilder::rowHotelId($row) !== $hotelId) {
                    continue;
                }
                $price = CalendarPriceBuilder::rowPrice($row);
                if ($price > 0) {
                    $alternatives[] = [
                        'check_in' => $window['check_in'],
                        'check_out' => $window['check_out'],
                        'price' => $price,
                    ];
                }
                break;
            }

            if (count($alternatives) >= self::MAX_ALTERNATIVES) {
                break;
            }
        }

        return $alternatives;
    }
}

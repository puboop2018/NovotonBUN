<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

/**
 * Stateless shaping of raw priceinfo payloads into lookup/display structures.
 *
 * Extracted from {@see PriceInfoService} (SRP) to isolate the pure
 * response-transformation concern — no DB, API, cache, or debug state — so it
 * can be unit-tested in isolation and reused. All methods are deterministic
 * functions of their input.
 *
 * @package NovotonHolidays
 * @since   3.7.0
 */
final class PriceInfoShaper
{
    /**
     * Extract season prices from a priceinfo payload, grouped by room.
     *
     * @param array<mixed, mixed> $priceinfo Priceinfo data
     * @return array<string, mixed> Prices grouped by room
     */
    public static function extractPrices(array $priceinfo): array
    {
        if (!isset($priceinfo['season_price'])) {
            return [];
        }

        $seasonPrices = $priceinfo['season_price'];
        if (!is_array($seasonPrices)) {
            return [];
        }
        // Normalize single entry to array
        if (isset($seasonPrices['IdRoom'])) {
            $seasonPrices = [$seasonPrices];
        }

        return self::groupByRoom($seasonPrices);
    }

    /**
     * Format priceinfo for display (seasons, prices grouped by room, EB).
     *
     * @param array<mixed, mixed> $priceinfo Raw priceinfo data
     * @param string $hotelId Hotel ID
     * @return array<string, mixed> Formatted priceinfo
     */
    public static function format(array $priceinfo, string $hotelId): array
    {
        $result = [
            'hotel_id' => $hotelId,
            'seasons' => [],
            'prices' => [],
            'early_booking' => [],
            'raw' => $priceinfo,
        ];

        // Extract seasons
        $seasonsBlock = $priceinfo['seasons'] ?? null;
        if (is_array($seasonsBlock) && isset($seasonsBlock['season']) && is_array($seasonsBlock['season'])) {
            $seasons = $seasonsBlock['season'];
            if (isset($seasons['IdSeason'])) {
                $seasons = [$seasons];
            }
            $result['seasons'] = $seasons;
        }

        // Extract prices
        if (isset($priceinfo['season_price']) && is_array($priceinfo['season_price'])) {
            $prices = $priceinfo['season_price'];
            if (isset($prices['IdRoom'])) {
                $prices = [$prices];
            }
            $result['prices'] = self::groupByRoom($prices);
        }

        // Extract early booking
        if (isset($priceinfo['early_booking']) && is_array($priceinfo['early_booking'])) {
            $eb = $priceinfo['early_booking'];
            if (isset($eb['Reduction'])) {
                $eb = [$eb];
            }
            $result['early_booking'] = $eb;
        }

        return $result;
    }

    /**
     * Group price records by their room id.
     *
     * @param array<mixed, mixed> $prices List of price records (non-array rows skipped)
     * @return array<string, mixed> Prices grouped by room
     */
    public static function groupByRoom(array $prices): array
    {
        $result = [];

        foreach ($prices as $price) {
            if (!is_array($price)) {
                continue;
            }
            $roomId = PriceInfoFormatter::toScalar($price['IdRoom'] ?? $price['room_id'] ?? 'unknown');

            if (!isset($result[$roomId])) {
                $result[$roomId] = [];
            }

            $result[$roomId][] = $price;
        }

        return $result;
    }
}

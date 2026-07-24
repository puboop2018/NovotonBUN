<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Transforms a novoton_hotel_packages DB record into the normalized package
 * shape the hotel-data pipeline serves (IdCont/PackageName + optional
 * decoded priceinfo with seasons, early booking and season prices).
 * Body extracted from functions/hotels.php; the
 * fn_novoton_holidays_normalize_package shell delegates here.
 */
final class PackageNormalizer
{
    /**
     * @param array<string, mixed> $pkg Package record from novoton_hotel_packages
     * @param bool $include_priceinfo_details Whether to extract detailed priceinfo (seasons, prices).
     *                                        Prefetch callers pass false and exclude priceinfo_data
     *                                        from the SELECT (50-200KB of JSON per package row).
     * @return array<string, mixed> Normalized package data
     */
    public static function normalize(array $pkg, bool $include_priceinfo_details = false): array
    {
        $packageData = [
            'IdCont' => PriceInfoFormatter::toScalar($pkg['package_id'] ?? ''),
            'PackageName' => PriceInfoFormatter::toScalar($pkg['package_name'] ?? ''),
            'min_price' => PriceInfoFormatter::toFloat($pkg['min_price'] ?? 0),
            'has_early_booking' => $pkg['has_early_booking'] ?? false,
            'seasons_count' => PriceInfoFormatter::toInt($pkg['seasons_count'] ?? 0),
            'synced_at' => PriceInfoFormatter::toScalar($pkg['synced_at'] ?? ''),
        ];

        if (!$include_priceinfo_details || empty($pkg['priceinfo_data'])) {
            return $packageData;
        }

        $piJson = PriceInfoFormatter::toScalar($pkg['priceinfo_data']);
        $priceinfo = TypeCoerce::toStringMap(json_decode($piJson, true));
        if ($priceinfo === []) {
            return $packageData;
        }
        $packageData['priceinfo'] = $priceinfo;

        // Seasons for display — a single season object is normalized to a list.
        $seasons = $priceinfo['seasons'] ?? null;
        if (is_array($seasons) && isset($seasons['season'])) {
            $seasonData = $seasons['season'];
            if (is_array($seasonData) && isset($seasonData['IdSeason'])) {
                $seasonData = [$seasonData];
            }
            $packageData['seasons'] = $seasonData;
        }

        // Early booking for display.
        if (isset($priceinfo['early_booking'])) {
            $packageData['early_booking'] = $priceinfo['early_booking'];
        }

        // Season prices for display — single entry normalized to a list.
        if (isset($priceinfo['season_price'])) {
            $seasonPrice = $priceinfo['season_price'];
            if (is_array($seasonPrice) && isset($seasonPrice['IdRoom'])) {
                $seasonPrice = [$seasonPrice];
            }
            $packageData['season_price'] = $seasonPrice;
        }

        return $packageData;
    }
}

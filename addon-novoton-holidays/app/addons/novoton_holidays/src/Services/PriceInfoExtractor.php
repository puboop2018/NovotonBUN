<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Repository\HotelPackageRepositoryInterface;

/**
 * Extracts seasons and early-booking structures from the priceinfo_data JSON
 * stored on novoton_hotel_packages.
 *
 * Lifted out of PriceInfoService so the priceinfo-JSON shape knowledge (single
 * vs. list normalisation, field mapping, date filtering) is a small, testable
 * collaborator. PriceInfoService keeps thin public methods that delegate here.
 * Behaviour is preserved verbatim.
 */
class PriceInfoExtractor
{
    public function __construct(
        private readonly HotelPackageRepositoryInterface $packageRepo,
    ) {
    }

    /**
     * Get the seasons defined for a hotel (from priceinfo_data JSON).
     *
     * @return list<array<string, mixed>>
     */
    public function getSeasons(string $hotelId): array
    {
        $priceinfoJson = $this->packageRepo->getLatestPriceinfoData($hotelId);

        if (empty($priceinfoJson)) {
            return [];
        }

        $priceinfo = json_decode($priceinfoJson, true);
        if (!is_array($priceinfo)) {
            return [];
        }
        $seasonsRaw = $priceinfo['seasons'] ?? null;
        if (!is_array($seasonsRaw) || !isset($seasonsRaw['season'])) {
            return [];
        }

        $seasons = $seasonsRaw['season'];
        // Normalize single season to array
        if (is_array($seasons) && isset($seasons['IdSeason'])) {
            $seasons = [$seasons];
        }
        if (!is_array($seasons)) {
            return [];
        }

        $result = [];
        foreach ($seasons as $idx => $season) {
            if (!is_array($season)) {
                continue;
            }
            $seasonNum = isset($season['IdSeason']) ? PriceInfoFormatter::toInt($season['IdSeason']) : ($idx + 1);
            $result[$seasonNum] = [
                'season_number' => $seasonNum,
                'date_from' => PriceInfoFormatter::toScalar($season['DateFrom'] ?? ''),
                'date_to' => PriceInfoFormatter::toScalar($season['DateTo'] ?? ''),
                'season_name' => PriceInfoFormatter::toScalar($season['SeasonName'] ?? "Season {$seasonNum}"),
            ];
        }

        return array_values($result);
    }

    /**
     * Get early booking discount periods for a hotel (from priceinfo_data JSON),
     * sorted by reduction descending.
     *
     * @return list<array<string, mixed>>
     */
    public function getEarlyBooking(string $hotelId): array
    {
        $row = $this->packageRepo->findEarlyBookingPackage($hotelId);
        $priceinfoJson = PriceInfoFormatter::toScalar($row['priceinfo_data'] ?? '');

        if ($priceinfoJson === '') {
            return [];
        }

        $priceinfo = json_decode($priceinfoJson, true);
        if (!is_array($priceinfo) || !isset($priceinfo['early_booking'])) {
            return [];
        }

        $eb_data = $priceinfo['early_booking'];
        // Normalize single entry to array
        if (is_array($eb_data) && isset($eb_data['Reduction'])) {
            $eb_data = [$eb_data];
        }
        if (!is_array($eb_data)) {
            return [];
        }

        $result = [];
        foreach ($eb_data as $eb) {
            if (!is_array($eb)) {
                continue;
            }
            $result[] = [
                'booking_from' => PriceInfoFormatter::toScalar($eb['BookFrom'] ?? ''),
                'booking_to' => PriceInfoFormatter::toScalar($eb['BookTo'] ?? ''),
                'stay_from' => PriceInfoFormatter::toScalar($eb['StayFrom'] ?? ''),
                'stay_to' => PriceInfoFormatter::toScalar($eb['StayTo'] ?? ''),
                'reduction' => PriceInfoFormatter::toFloat($eb['Reduction'] ?? 0),
                'payment_date' => PriceInfoFormatter::toScalar($eb['PaymentDate'] ?? ''),
                'payment_percent' => PriceInfoFormatter::toFloat($eb['PaymentPercent'] ?? 0),
                'room_types' => PriceInfoFormatter::toScalar($eb['RoomTypes'] ?? 'all'),
                'min_stay' => PriceInfoFormatter::toInt($eb['MinStay'] ?? 0),
            ];
        }

        // Sort by reduction DESC
        usort($result, fn ($a, $b): int => $b['reduction'] <=> $a['reduction']);

        return $result;
    }

    /**
     * Get the early booking discount active on the given date (default today).
     *
     * @return array<string, mixed>|null
     */
    public function getActiveEarlyBooking(string $hotelId, ?string $date = null): ?array
    {
        $date ??= date('Y-m-d');

        $discounts = $this->getEarlyBooking($hotelId);

        foreach ($discounts as $eb) {
            if ($date >= $eb['booking_from'] && $date <= $eb['booking_to']) {
                return $eb;
            }
        }

        return null;
    }
}

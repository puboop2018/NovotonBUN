<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Repository\HotelPackageRepositoryInterface;

/**
 * Computes raw (EUR, no commission, no conversion) calendar prices for a hotel:
 * the cheapest suitable room's 1-night total for N adults, expanded per date
 * across every season of every package.
 *
 * Extracted from PriceInfoService, where this season-expansion / percentage-ref
 * / boundary-date logic made up nearly half the class. Behaviour is preserved
 * verbatim; PriceInfoService::precomputeCalendarPrices() delegates here. The
 * only adjustments are honest-typing guards around two offset-on-mixed accesses
 * (the nested seasons['season'] read and the codeIndex Base-row lookup) so the
 * extracted, non-baselined file stays PHPStan-clean.
 */
class CalendarPriceBuilder
{
    public function __construct(
        private readonly HotelPackageRepositoryInterface $packageRepo,
    ) {
    }

    /**
     * Compute raw EUR date => price map across all packages for a hotel.
     *
     * No commission, no currency conversion, no rounding. Prices are raw API EUR
     * values — the cheapest room total for 2 adults per date.
     *
     * @return array<string, mixed> [date => rawEurPrice]
     */
    public function computeRawCalendarPrices(string $hotelId): array
    {
        $allPriceinfoRows = $this->packageRepo->getAllPriceinfoData($hotelId);

        if (empty($allPriceinfoRows)) {
            return [];
        }

        $dateMap = [];
        $today = date('Y-m-d');
        $maxDate = date('Y-m-d', strtotime('+18 months'));
        $adults = 2; // Standard default for calendar display

        foreach ($allPriceinfoRows as $priceinfoJson) {
            if (empty($priceinfoJson)) {
                continue;
            }

            $priceinfo = json_decode($priceinfoJson, true);
            if (empty($priceinfo) || !is_array($priceinfo)) {
                continue;
            }

            $packageDateMap = $this->buildRawDateMap($priceinfo, $adults, $today, $maxDate);

            // Merge: keep minimum price per date across all packages
            foreach ($packageDateMap as $date => $price) {
                if (!isset($dateMap[$date]) || $price < $dateMap[$date]) {
                    $dateMap[$date] = $price;
                }
            }
        }

        return $dateMap;
    }

    /**
     * Build a raw date => price map from a single package's priceinfo data.
     *
     * Returns raw API prices (EUR, no commission, no conversion).
     *
     * @param array<mixed, mixed> $priceinfo Decoded priceinfo_data JSON
     * @param int $adults Number of adults
     * @param string $today Today's date (Y-m-d)
     * @param string $maxDate Max future date (Y-m-d)
     * @return array<string, mixed> [date => rawPrice]
     */
    public function buildRawDateMap(array $priceinfo, int $adults, string $today, string $maxDate): array
    {
        // 1. Parse seasons (handle both nested 'season' key and flat array formats)
        $seasonsRaw = $priceinfo['seasons'] ?? [];
        $seasons = is_array($seasonsRaw) && isset($seasonsRaw['season']) ? $seasonsRaw['season'] : $seasonsRaw;
        if (!is_array($seasons)) {
            return [];
        }
        if (isset($seasons['IdSeason']) || isset($seasons['Season']) || isset($seasons['SeasonNr'])) {
            $seasons = [$seasons];
        }
        if (empty($seasons)) {
            return [];
        }

        // 2. Parse season_price rows
        $seasonPrices = $priceinfo['season_price'] ?? [];
        if (!is_array($seasonPrices)) {
            return [];
        }
        if (isset($seasonPrices['IdRoom'])) {
            $seasonPrices = [$seasonPrices];
        }
        if (empty($seasonPrices)) {
            return [];
        }

        // 3. For each season, find the cheapest room total for N adults
        $cheapestBySeason = $this->getCheapestRoomTotalBySeason($seasonPrices, $seasons, $adults);

        // 4. Expand season ranges into per-date raw prices
        $dateMap = [];

        foreach ($seasons as $season) {
            if (!is_array($season)) {
                continue;
            }
            $seasonNum = PriceInfoFormatter::toInt($season['Season'] ?? $season['IdSeason'] ?? $season['SeasonNr'] ?? 0);
            if ($seasonNum <= 0 || !isset($cheapestBySeason[$seasonNum])) {
                continue;
            }

            $from = PriceInfoFormatter::toScalar($season['FromDate'] ?? $season['DateFrom'] ?? '');
            $to = PriceInfoFormatter::toScalar($season['ToDate'] ?? $season['DateTo'] ?? '');
            if (empty($from) || empty($to)) {
                continue;
            }

            $rawPrice = round($cheapestBySeason[$seasonNum], 2);

            $startDate = max($from, $today);
            $endDate = min($to, $maxDate);

            if ($startDate > $endDate) {
                continue;
            }

            try {
                $current = new \DateTime($startDate);
                $end = new \DateTime($endDate);

                while ($current <= $end) {
                    $dateKey = $current->format('Y-m-d');
                    if (!isset($dateMap[$dateKey]) || $rawPrice < $dateMap[$dateKey]) {
                        $dateMap[$dateKey] = $rawPrice;
                    }
                    $current->modify('+1 day');
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return $dateMap;
    }

    /**
     * Find the cheapest room's nightly total for N adults, per season.
     *
     * Groups season_price rows by IdRoom, then for each room calculates
     * the 1-night cost for the given number of adults:
     *  - RoomPrice=Yes → use Price{N} once (it's a per-room rate)
     *  - RoomPrice=No  → Price{N} × $adults (it's per-person)
     *
     * Picks the minimum total across all rooms for each season.
     *
     * @param array<mixed, mixed> $seasonPrices season_price rows
     * @param array<mixed, mixed> $seasons seasons array
     * @param int $adults number of adults
     * @return array<int, float> [seasonNum => cheapestRoomTotal]
     */
    public function getCheapestRoomTotalBySeason(array $seasonPrices, array $seasons, int $adults): array
    {
        // Build a code index for percentage resolution
        $codeIndex = [];
        foreach ($seasonPrices as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = $this->toScalarSafe($row['Code'] ?? '');
            if ($code !== '') {
                $codeIndex[$code][] = $row;
            }
        }

        // Get max season number
        $maxSeason = 0;
        foreach ($seasons as $s) {
            if (!is_array($s)) {
                continue;
            }
            $num = PriceInfoFormatter::toInt($s['Season'] ?? $s['IdSeason'] ?? $s['SeasonNr'] ?? 0);
            if ($num > $maxSeason) {
                $maxSeason = $num;
            }
        }

        // Group adult regular rows by room
        $roomRows = [];
        foreach ($seasonPrices as $row) {
            if (!is_array($row)) {
                continue;
            }
            $fAge = $this->toScalarSafe($row['fAge'] ?? '');
            $idAge = $this->toScalarSafe($row['IdAge'] ?? '');
            $accType = strtoupper(trim($this->toScalarSafe($row['IdAcc'] ?? '')));

            // Resolve age type — same logic as PriceInfoCalculator. The numeric
            // IdAge codes 1-4 map to canonical age-type labels; anything else
            // (already a label) passes through unchanged.
            $rowAge = '';
            if (!empty($fAge)) {
                $rowAge = strtoupper(trim($fAge));
            } else {
                $mappedAge = match ($idAge) {
                    '1' => 'ADULT',
                    '2' => 'CHD 0-1.99',
                    '3' => 'CHD 2-11.99',
                    '4' => 'CHD 12-17.99',
                    default => $idAge,
                };
                $rowAge = strtoupper(trim($mappedAge));
            }

            // Only consider adult entries (ADULT, 1ST ADULT, 2ND ADULT, etc.)
            $isAdult = str_contains(strtolower($rowAge), strtolower('ADULT'));
            if (!$isAdult) {
                continue;
            }

            // Only consider regular bed (not extra bed)
            if ($accType !== '' && $accType !== 'REGULAR' && $accType !== 'RB') {
                continue;
            }

            $roomId = $this->toScalarSafe($row['IdRoom'] ?? '');
            if ($roomId === '') {
                $roomId = '_default';
            }

            $roomRows[$roomId][] = $row;
        }

        if (empty($roomRows)) {
            return [];
        }

        // For each season, find the cheapest room total
        $result = [];

        foreach ($roomRows as $roomId => $rows) {
            // Use the first matching row for this room (most general)
            $row = $rows[0];
            $isRoomPrice = strtoupper($this->toScalarSafe($row['RoomPrice'] ?? 'No')) === 'YES';

            for ($s = 1; $s <= min($maxSeason, 20); $s++) {
                $priceKey = 'Price' . $s;
                $rawPrice = $row[$priceKey] ?? null;

                if ($rawPrice === null || $rawPrice === '' || $rawPrice === 0 || $rawPrice === '0') {
                    continue;
                }

                $unitPrice = $this->resolveCalendarPrice($rawPrice, $priceKey, $codeIndex);
                if ($unitPrice <= 0) {
                    continue;
                }

                // Calculate nightly total for the given occupancy
                $nightlyTotal = $isRoomPrice ? $unitPrice : ($unitPrice * $adults);

                if (!isset($result[$s]) || $nightlyTotal < $result[$s]) {
                    $result[$s] = $nightlyTotal;
                }
            }
        }

        return $result;
    }

    /**
     * Resolve a single price value, handling percentage references.
     *
     * @param mixed $rawPrice Price value (numeric or "85%")
     * @param string $priceKey Column key (e.g. "Price2")
     * @param array<string, mixed> $codeIndex Code-indexed season_price rows
     * @return float Resolved price
     */
    private function resolveCalendarPrice($rawPrice, string $priceKey, array $codeIndex): float
    {
        if (is_array($rawPrice) || is_object($rawPrice)) {
            return 0.0;
        }

        if (is_string($rawPrice) && str_contains($rawPrice, '%')) {
            $percent = (float) str_replace('%', '', $rawPrice);
            // Resolve from Base code row
            $baseRows = $codeIndex['Base'] ?? null;
            if (is_array($baseRows) && isset($baseRows[0]) && is_array($baseRows[0])) {
                $baseRaw = $baseRows[0][$priceKey] ?? 0;
                if (is_string($baseRaw) && str_contains($baseRaw, '%')) {
                    return 0.0; // Avoid infinite recursion
                }
                $basePrice = PriceInfoFormatter::toFloat($baseRaw);
                return round($basePrice * ($percent / 100), 2);
            }
            return 0.0;
        }

        return PriceInfoFormatter::toFloat($rawPrice);
    }

    /**
     * Safely convert a value to scalar string.
     * @param mixed $val
     */
    private function toScalarSafe($val): string
    {
        if (!is_scalar($val)) {
            return '';
        }
        return (string) $val;
    }
}

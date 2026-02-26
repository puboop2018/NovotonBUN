<?php
declare(strict_types=1);
/**
 * Novoton PriceInfo Service
 *
 * Handles season-based price information for product tab display.
 * This is DIFFERENT from real-time booking prices (RoomPriceService).
 *
 * V3 Architecture:
 * - Season prices are stored in novoton_hotel_packages.priceinfo_data JSON
 * - Synced via cron job (mode=hotel_info_batched or mode=sync_priceinfo)
 * - NO CACHE needed - database is the source of truth
 *
 * Use Cases:
 * - Product detail page "Prices" tab
 * - Price sync/cron operations
 * - Reference pricing for browsing
 *
 * @package NovotonHolidays
 * @since 3.0.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

class PriceInfoService implements PriceInfoServiceInterface
{
    /** @var \Tygh\Addons\NovotonHolidays\NovotonApi */
    private $api;

    /** @var float Commission percentage */
    private $commission;

    /** @var bool Debug mode */
    private $debug = false;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->api = fn_novoton_holidays_get_api();
        $this->commission = ConfigProvider::getCommission();
        $this->debug = ConfigProvider::isDebugLogging();
    }

    /**
     * Get price info for hotel package (for product tab)
     * V3: Reads from novoton_hotel_packages.priceinfo_data JSON
     *
     * @param string $hotelId Hotel ID
     * @param string $packageName Package name
     * @param string $lang Language
     * @return array|null Parsed price info
     */
    public function getPriceInfo(string $hotelId, string $packageName, string $lang = 'UK'): ?array
    {
        // V3: Get from packages table
        $priceinfo = fn_novoton_holidays_get_package_priceinfo_by_name($hotelId, $packageName);

        if (!empty($priceinfo)) {
            $this->log('PriceInfo from database', ['hotel_id' => $hotelId, 'package' => $packageName]);
            return $this->formatPriceInfo($priceinfo, $hotelId);
        }

        // Fallback: Call API directly (for first-time or if cron hasn't run)
        $this->log('PriceInfo fallback to API', ['hotel_id' => $hotelId, 'package' => $packageName]);

        $response = $this->api->getPriceInfo($hotelId, $packageName, $lang);

        if (!$response) {
            return null;
        }

        // Parse response
        $result = $this->parsePriceInfoResponse($response, $hotelId);

        return $result;
    }

    /**
     * Get prices stored in database for product
     * V3: Reads from novoton_hotel_packages
     *
     * @param int|string $productIdOrHotelId Product ID or Hotel ID
     * @param string|null $packageName Optional package name filter
     * @return array Prices grouped by room
     */
    public function getStoredPrices($productIdOrHotelId, ?string $packageName = null): array
    {
        $hotelId = $productIdOrHotelId;

        // If numeric, get hotel_id from product
        if (is_numeric($productIdOrHotelId)) {
            $hotelId = fn_novoton_holidays_get_hotel_id_by_product((int)$productIdOrHotelId);
            if (empty($hotelId)) {
                return [];
            }
        }

        // Get priceinfo from first package with data
        $query = "SELECT priceinfo_data FROM ?:novoton_hotel_packages
                  WHERE hotel_id = ?s AND priceinfo_data IS NOT NULL";
        $params = [$hotelId];

        if (!empty($packageName)) {
            $query .= " AND package_name = ?s";
            $params[] = $packageName;
        }

        $query .= " ORDER BY synced_at DESC LIMIT 1";

        $priceinfoJson = db_get_field($query, ...$params);

        if (empty($priceinfoJson)) {
            return [];
        }

        $priceinfo = json_decode($priceinfoJson, true);
        if (empty($priceinfo)) {
            return [];
        }

        return $this->extractPricesFromPriceInfo($priceinfo);
    }

    /**
     * Get last update time for product prices
     * V3: Reads from novoton_hotel_packages.synced_at
     *
     * @param int $productId Product ID
     * @return string|null Last update datetime
     */
    public function getLastUpdate(int $productId): ?string
    {
        $hotelId = fn_novoton_holidays_get_hotel_id_by_product($productId);
        if (empty($hotelId)) {
            return null;
        }

        return db_get_field(
            "SELECT MAX(synced_at) FROM ?:novoton_hotel_packages WHERE hotel_id = ?s",
            $hotelId
        );
    }

    /**
     * Get active package name for product
     * V3: Reads from novoton_hotel_packages
     *
     * @param int $productId Product ID
     * @return string|null Package name
     */
    public function getActivePackage(int $productId): ?string
    {
        $hotelId = fn_novoton_holidays_get_hotel_id_by_product($productId);
        if (empty($hotelId)) {
            return null;
        }

        return db_get_field(
            "SELECT package_name FROM ?:novoton_hotel_packages
             WHERE hotel_id = ?s AND priceinfo_data IS NOT NULL
             ORDER BY synced_at DESC LIMIT 1",
            $hotelId
        );
    }

    /**
     * Get seasons for hotel
     * V3: Extracts from priceinfo_data JSON
     *
     * @param string $hotelId Hotel ID
     * @return array Seasons with dates
     */
    public function getSeasons(string $hotelId): array
    {
        $priceinfoJson = db_get_field(
            "SELECT priceinfo_data FROM ?:novoton_hotel_packages
             WHERE hotel_id = ?s AND priceinfo_data IS NOT NULL
             ORDER BY synced_at DESC LIMIT 1",
            $hotelId
        );

        if (empty($priceinfoJson)) {
            return [];
        }

        $priceinfo = json_decode($priceinfoJson, true);
        if (empty($priceinfo) || !isset($priceinfo['seasons']['season'])) {
            return [];
        }

        $seasons = $priceinfo['seasons']['season'];
        // Normalize single season to array
        if (isset($seasons['IdSeason'])) {
            $seasons = [$seasons];
        }

        $result = [];
        foreach ($seasons as $idx => $season) {
            $seasonNum = isset($season['IdSeason']) ? (int)$season['IdSeason'] : ($idx + 1);
            $result[$seasonNum] = [
                'season_number' => $seasonNum,
                'date_from' => $season['DateFrom'] ?? '',
                'date_to' => $season['DateTo'] ?? '',
                'season_name' => $season['SeasonName'] ?? "Season {$seasonNum}"
            ];
        }

        return $result;
    }

    /**
     * Get early booking discounts for hotel
     * V3: Extracts from priceinfo_data JSON
     *
     * @param string $hotelId Hotel ID
     * @return array Early booking periods
     */
    public function getEarlyBooking(string $hotelId): array
    {
        $priceinfoJson = db_get_field(
            "SELECT priceinfo_data FROM ?:novoton_hotel_packages
             WHERE hotel_id = ?s AND has_early_booking = 'Y' AND priceinfo_data IS NOT NULL
             ORDER BY synced_at DESC LIMIT 1",
            $hotelId
        );

        if (empty($priceinfoJson)) {
            return [];
        }

        $priceinfo = json_decode($priceinfoJson, true);
        if (empty($priceinfo) || !isset($priceinfo['early_booking'])) {
            return [];
        }

        $eb_data = $priceinfo['early_booking'];
        // Normalize single entry to array
        if (isset($eb_data['Reduction'])) {
            $eb_data = [$eb_data];
        }

        $result = [];
        foreach ($eb_data as $eb) {
            $result[] = [
                'booking_from' => $eb['BookFrom'] ?? '',
                'booking_to' => $eb['BookTo'] ?? '',
                'stay_from' => $eb['StayFrom'] ?? '',
                'stay_to' => $eb['StayTo'] ?? '',
                'reduction' => $eb['Reduction'] ?? 0,
                'payment_date' => $eb['PaymentDate'] ?? '',
                'payment_percent' => $eb['PaymentPercent'] ?? 0,
                'room_types' => $eb['RoomTypes'] ?? 'all',
                'min_stay' => $eb['MinStay'] ?? 0
            ];
        }

        // Sort by reduction DESC
        usort($result, function($a, $b) {
            return $b['reduction'] <=> $a['reduction'];
        });

        return $result;
    }

    /**
     * Get active early booking discount
     *
     * @param string $hotelId Hotel ID
     * @param string|null $date Date to check (default: today)
     * @return array|null Active discount or null
     */
    public function getActiveEarlyBooking(string $hotelId, ?string $date = null): ?array
    {
        $date = $date ?? date('Y-m-d');

        $discounts = $this->getEarlyBooking($hotelId);

        foreach ($discounts as $eb) {
            if ($date >= $eb['booking_from'] && $date <= $eb['booking_to']) {
                return $eb;
            }
        }

        return null;
    }

    /**
     * Get per-date calendar prices for a hotel.
     *
     * Returns a flat map of "YYYY-MM-DD" => price (float) representing
     * the estimated 1-night-stay total for a given number of adults in
     * the cheapest suitable room across ALL packages. Prices include
     * commission and are converted to the target currency.
     *
     * Reads precomputed raw EUR prices from novoton_hotels.calendar_prices_raw
     * (populated by sync cron via precomputeCalendarPrices()), then applies
     * commission and currency conversion per date.
     *
     * If the column is empty (cron hasn't run yet), returns an empty price map.
     * The Calendar UI should treat missing dates as "no price" (grey/dash).
     *
     * @param string      $hotelId        Hotel ID
     * @param string|null $targetCurrency  Target currency code (null = display currency)
     * @param int         $adults          Number of adults (default 2)
     * @return array ['prices' => [date => price], 'currency' => string]
     */
    public function getCalendarPrices(string $hotelId, ?string $targetCurrency = null, int $adults = 2): array
    {
        $currency = $targetCurrency ?? CurrencyService::getDisplayCurrency();

        // 1. Try precomputed raw prices (written by sync cron)
        $rawJson = db_get_field(
            "SELECT calendar_prices_raw FROM ?:novoton_hotels WHERE hotel_id = ?s",
            $hotelId
        );

        $rawPrices = !empty($rawJson) ? json_decode($rawJson, true) : null;

        if (empty($rawPrices)) {
            return ['prices' => [], 'currency' => $currency];
        }

        // 3. Apply commission + currency conversion (cheap per-date multiply)
        $dateMap = [];
        $commission = $this->commission;
        $roundPrices = ConfigProvider::isRoundPrices();
        $today = date('Y-m-d');

        foreach ($rawPrices as $date => $rawPrice) {
            if ($date < $today) {
                continue;
            }
            $price = $rawPrice * (1 + $commission / 100);
            $price = CurrencyService::convertFromApiCurrency((float) $price, $currency);
            if ($roundPrices) {
                $price = round($price);
            }
            $dateMap[$date] = $price;
        }

        $this->log('Calendar prices loaded', [
            'hotel_id' => $hotelId,
            'currency' => $currency,
            'dates_count' => count($dateMap)
        ]);

        return ['prices' => $dateMap, 'currency' => $currency];
    }

    /**
     * Precompute raw calendar prices for a hotel and store in novoton_hotels.
     *
     * Called after priceinfo sync. Computes the cheapest raw EUR price per date
     * across ALL packages (no commission, no currency conversion). The result is
     * stored as JSON in novoton_hotels.calendar_prices_raw.
     *
     * At display time, getCalendarPrices() reads this column and applies
     * commission + currency — a trivial per-date multiply instead of full
     * JSON parsing and room grouping.
     *
     * @param string $hotelId Hotel ID
     * @return void
     */
    public static function precomputeCalendarPrices(string $hotelId): void
    {
        $rawPrices = self::computeRawCalendarPrices($hotelId);

        db_query(
            "UPDATE ?:novoton_hotels SET calendar_prices_raw = ?s WHERE hotel_id = ?s",
            !empty($rawPrices) ? json_encode($rawPrices, JSON_UNESCAPED_UNICODE) : null,
            $hotelId
        );
    }

    /**
     * Compute raw EUR date → price map across all packages for a hotel.
     *
     * No commission, no currency conversion, no rounding. Prices are raw API EUR
     * values — the cheapest room total for 2 adults per date.
     *
     * @param string $hotelId Hotel ID
     * @return array [date => rawEurPrice]
     */
    private static function computeRawCalendarPrices(string $hotelId): array
    {
        $allPriceinfoRows = db_get_fields(
            "SELECT priceinfo_data FROM ?:novoton_hotel_packages
             WHERE hotel_id = ?s AND priceinfo_data IS NOT NULL",
            $hotelId
        );

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
            if (empty($priceinfo)) {
                continue;
            }

            $packageDateMap = self::buildRawDateMap($priceinfo, $adults, $today, $maxDate);

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
     * Build a raw date → price map from a single package's priceinfo data.
     *
     * Returns raw API prices (EUR, no commission, no conversion).
     *
     * @param array  $priceinfo Decoded priceinfo_data JSON
     * @param int    $adults    Number of adults
     * @param string $today     Today's date (Y-m-d)
     * @param string $maxDate   Max future date (Y-m-d)
     * @return array [date => rawPrice]
     */
    private static function buildRawDateMap(array $priceinfo, int $adults, string $today, string $maxDate): array
    {
        // 1. Parse seasons
        $seasons = $priceinfo['seasons']['season'] ?? $priceinfo['seasons'] ?? [];
        if (isset($seasons['IdSeason']) || isset($seasons['Season'])) {
            $seasons = [$seasons];
        }
        if (empty($seasons) || !is_array($seasons)) {
            return [];
        }

        // 2. Parse season_price rows
        $seasonPrices = $priceinfo['season_price'] ?? [];
        if (isset($seasonPrices['IdRoom'])) {
            $seasonPrices = [$seasonPrices];
        }
        if (empty($seasonPrices)) {
            return [];
        }

        // 3. For each season, find the cheapest room total for N adults
        // getCheapestRoomTotalBySeason is instance method, but the logic is stateless
        // so we inline the static call via a temporary instance
        $instance = new self();
        $cheapestBySeason = $instance->getCheapestRoomTotalBySeason($seasonPrices, $seasons, $adults);

        // 4. Expand season ranges into per-date raw prices
        $dateMap = [];

        foreach ($seasons as $season) {
            $seasonNum = (int) ($season['Season'] ?? $season['IdSeason'] ?? 0);
            if ($seasonNum <= 0 || !isset($cheapestBySeason[$seasonNum])) {
                continue;
            }

            $from = $season['FromDate'] ?? $season['DateFrom'] ?? '';
            $to = $season['ToDate'] ?? $season['DateTo'] ?? '';
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
     * @param array $seasonPrices season_price rows
     * @param array $seasons      seasons array
     * @param int   $adults       number of adults
     * @return array [seasonNum => cheapestRoomTotal]
     */
    private function getCheapestRoomTotalBySeason(array $seasonPrices, array $seasons, int $adults): array
    {
        // Build a code index for percentage resolution
        $codeIndex = [];
        foreach ($seasonPrices as $row) {
            $code = $this->toScalarSafe($row['Code'] ?? '');
            if ($code !== '') {
                $codeIndex[$code][] = $row;
            }
        }

        // Get max season number
        $maxSeason = 0;
        foreach ($seasons as $s) {
            $num = (int) ($s['Season'] ?? $s['IdSeason'] ?? 0);
            if ($num > $maxSeason) $maxSeason = $num;
        }

        // Group adult regular rows by room
        $roomRows = [];
        foreach ($seasonPrices as $row) {
            $fAge = $this->toScalarSafe($row['fAge'] ?? '');
            $idAge = $this->toScalarSafe($row['IdAge'] ?? '');
            $accType = strtoupper(trim($this->toScalarSafe($row['IdAcc'] ?? '')));

            // Only consider adult entries
            $isAdult = false;
            if (stripos($fAge, 'ADULT') !== false) {
                $isAdult = true;
            } elseif ($idAge === '1') {
                $isAdult = true;
            }
            if (!$isAdult) continue;

            // Only consider regular bed (not extra bed)
            if ($accType !== '' && $accType !== 'REGULAR') continue;

            $roomId = $this->toScalarSafe($row['IdRoom'] ?? '');
            if ($roomId === '') $roomId = '_default';

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
                if ($unitPrice <= 0) continue;

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
     * @param mixed  $rawPrice  Price value (numeric or "85%")
     * @param string $priceKey  Column key (e.g. "Price2")
     * @param array  $codeIndex Code-indexed season_price rows
     * @return float Resolved price
     */
    private function resolveCalendarPrice($rawPrice, string $priceKey, array $codeIndex): float
    {
        if (is_array($rawPrice) || is_object($rawPrice)) {
            return 0.0;
        }

        if (is_string($rawPrice) && strpos($rawPrice, '%') !== false) {
            $percent = (float) str_replace('%', '', $rawPrice);
            // Resolve from Base code row
            if (isset($codeIndex['Base'][0])) {
                $baseRaw = $codeIndex['Base'][0][$priceKey] ?? 0;
                if (is_string($baseRaw) && strpos($baseRaw, '%') !== false) {
                    return 0.0; // Avoid infinite recursion
                }
                $basePrice = (float) $baseRaw;
                return round($basePrice * ($percent / 100), 2);
            }
            return 0.0;
        }

        return (float) $rawPrice;
    }

    /**
     * Safely convert a value to scalar string.
     */
    private function toScalarSafe($val): string
    {
        if (is_array($val) || is_object($val)) {
            return '';
        }
        return (string) $val;
    }

    /**
     * Extract prices from priceinfo response
     *
     * @param array $priceinfo Priceinfo data
     * @return array Prices grouped by room
     */
    private function extractPricesFromPriceInfo(array $priceinfo): array
    {
        if (!isset($priceinfo['season_price'])) {
            return [];
        }

        $seasonPrices = $priceinfo['season_price'];
        // Normalize single entry to array
        if (isset($seasonPrices['IdRoom'])) {
            $seasonPrices = [$seasonPrices];
        }

        return $this->groupPricesByRoom($seasonPrices);
    }

    /**
     * Format priceinfo for display
     *
     * @param array $priceinfo Raw priceinfo data
     * @param string $hotelId Hotel ID
     * @return array Formatted priceinfo
     */
    private function formatPriceInfo(array $priceinfo, string $hotelId): array
    {
        $result = [
            'hotel_id' => $hotelId,
            'seasons' => [],
            'prices' => [],
            'early_booking' => [],
            'raw' => $priceinfo
        ];

        // Extract seasons
        if (isset($priceinfo['seasons']['season'])) {
            $seasons = $priceinfo['seasons']['season'];
            if (isset($seasons['IdSeason'])) {
                $seasons = [$seasons];
            }
            $result['seasons'] = $seasons;
        }

        // Extract prices
        if (isset($priceinfo['season_price'])) {
            $prices = $priceinfo['season_price'];
            if (isset($prices['IdRoom'])) {
                $prices = [$prices];
            }
            $result['prices'] = $this->groupPricesByRoom($prices);
        }

        // Extract early booking
        if (isset($priceinfo['early_booking'])) {
            $eb = $priceinfo['early_booking'];
            if (isset($eb['Reduction'])) {
                $eb = [$eb];
            }
            $result['early_booking'] = $eb;
        }

        return $result;
    }

    /**
     * Group prices by room
     *
     * @param array $prices Array of price records
     * @return array Prices grouped by room
     */
    private function groupPricesByRoom(array $prices): array
    {
        $result = [];

        foreach ($prices as $price) {
            $roomId = $price['IdRoom'] ?? $price['room_id'] ?? 'unknown';

            if (!isset($result[$roomId])) {
                $result[$roomId] = [];
            }

            $result[$roomId][] = $price;
        }

        return $result;
    }

    /**
     * Parse priceinfo API response
     *
     * @param \SimpleXMLElement $response API response
     * @param string $hotelId Hotel ID
     * @return array Parsed data
     */
    private function parsePriceInfoResponse($response, string $hotelId): array
    {
        // Convert SimpleXML to array
        $data = json_decode(json_encode($response), true);

        return $this->formatPriceInfo($data, $hotelId);
    }

    /**
     * Log debug message
     *
     * @param string $message Message
     * @param array $context Context data
     */
    private function log(string $message, array $context = []): void
    {
        if (!$this->debug) {
            return;
        }

        fn_log_event('novoton_holidays', 'priceinfo', array_merge(
            ['message' => $message],
            $context
        ));
    }
}

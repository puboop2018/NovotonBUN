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

class PriceInfoService
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
        $this->api = fn_novoton_get_api();
        $this->commission = ConfigService::getCommission();
        $this->debug = ConfigService::isDebugLogging();
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
        $priceinfo = fn_novoton_get_package_priceinfo_by_name($hotelId, $packageName);

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
            $hotelId = fn_novoton_get_hotel_id_by_product((int)$productIdOrHotelId);
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
        $hotelId = fn_novoton_get_hotel_id_by_product($productId);
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
        $hotelId = fn_novoton_get_hotel_id_by_product($productId);
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

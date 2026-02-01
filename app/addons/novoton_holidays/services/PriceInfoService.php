<?php
/**
 * Novoton PriceInfo Service
 * 
 * Handles season-based price information for product tab display.
 * This is DIFFERENT from real-time booking prices (PriceService).
 * 
 * Data Storage:
 * - Season prices are stored in novoton_hotel_prices table
 * - Synced via cron job (weekly/monthly)
 * - NO CACHE needed - database is the source of truth
 * 
 * Use Cases:
 * - Product detail page "Prices" tab
 * - Price sync/cron operations
 * - Reference pricing for browsing
 * 
 * @package NovotonHolidays
 * @since 2.7.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Registry;

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
        $this->commission = floatval(Registry::get('addons.novoton_holidays.commission') ?? '0');
        $this->debug = (Registry::get('addons.novoton_holidays.debug_logging') ?? 'N') === 'Y';
    }
    
    /**
     * Get price info for hotel package (for product tab)
     * Reads from database (synced via cron), falls back to API if empty
     * 
     * @param string $hotelId Hotel ID
     * @param string $packageName Package name
     * @param string $lang Language
     * @return array|null Parsed price info
     */
    public function getPriceInfo(string $hotelId, string $packageName, string $lang = 'UK'): ?array
    {
        // First try to get from database (synced via cron)
        $dbPrices = $this->getStoredPrices($hotelId, $packageName);
        
        if (!empty($dbPrices)) {
            $this->log('PriceInfo from database', ['hotel_id' => $hotelId, 'package' => $packageName]);
            return $dbPrices;
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
     * This is the primary data source (synced via cron)
     * 
     * @param int|string $productIdOrHotelId Product ID or Hotel ID
     * @param string|null $packageName Optional package name filter
     * @return array Prices grouped by room
     */
    public function getStoredPrices($productIdOrHotelId, ?string $packageName = null): array
    {
        // Check if it's a product ID (numeric) or hotel ID (string)
        if (is_numeric($productIdOrHotelId)) {
            $prices = db_get_array(
                "SELECT * FROM ?:novoton_hotel_prices 
                 WHERE product_id = ?i 
                 ORDER BY room_id, board_id",
                (int)$productIdOrHotelId
            );
        } else {
            $condition = "hotel_id = ?s";
            $params = [$productIdOrHotelId];
            
            $prices = db_get_array(
                "SELECT * FROM ?:novoton_hotel_prices 
                 WHERE hotel_id = ?s 
                 ORDER BY room_id, board_id",
                $productIdOrHotelId
            );
        }
        
        return $this->groupPricesByRoom($prices);
    }
    
    /**
     * Get last update time for product prices
     * 
     * @param int $productId Product ID
     * @return string|null Last update datetime
     */
    public function getLastUpdate(int $productId): ?string
    {
        return db_get_field(
            "SELECT MAX(updated_at) FROM ?:novoton_hotel_prices WHERE product_id = ?i",
            $productId
        );
    }
    
    /**
     * Get active package name for product
     * 
     * @param int $productId Product ID
     * @return string|null Package name
     */
    public function getActivePackage(int $productId): ?string
    {
        return db_get_field(
            "SELECT package_name FROM ?:novoton_hotel_prices 
             WHERE product_id = ?i AND package_name IS NOT NULL 
             LIMIT 1",
            $productId
        );
    }
    
    /**
     * Get seasons for hotel
     * 
     * @param string $hotelId Hotel ID
     * @return array Seasons with dates
     */
    public function getSeasons(string $hotelId): array
    {
        return db_get_hash_array(
            "SELECT season_number, date_from, date_to, season_name 
             FROM ?:novoton_seasons 
             WHERE hotel_id = ?s 
             ORDER BY season_number",
            'season_number',
            $hotelId
        );
    }
    
    /**
     * Get early booking discounts for hotel
     * 
     * @param string $hotelId Hotel ID
     * @return array Early booking periods
     */
    public function getEarlyBooking(string $hotelId): array
    {
        return db_get_array(
            "SELECT * FROM ?:novoton_early_booking 
             WHERE hotel_id = ?s 
             ORDER BY reduction DESC",
            $hotelId
        );
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
     * Determine active season for a date
     * 
     * @param string $hotelId Hotel ID
     * @param string $checkIn Check-in date
     * @return int Season number (default 1)
     */
    public function getActiveSeason(string $hotelId, string $checkIn): int
    {
        $seasons = $this->getSeasons($hotelId);
        $checkInTs = strtotime($checkIn);
        
        foreach ($seasons as $num => $season) {
            $from = strtotime($season['date_from']);
            $to = strtotime($season['date_to']);
            
            if ($checkInTs >= $from && $checkInTs <= $to) {
                return intval($num);
            }
        }
        
        return 1; // Default to season 1
    }
    
    /**
     * Calculate price for specific season and nights
     * 
     * @param array $priceRow Price row from database
     * @param int $seasonNum Season number
     * @param int $nights Number of nights (for price_5, price_7, etc.)
     * @return float Price
     */
    public function calculateSeasonPrice(array $priceRow, int $seasonNum, int $nights = 7): float
    {
        // Try exact night match
        $priceKey = 'price_' . $seasonNum;
        if (isset($priceRow[$priceKey]) && floatval($priceRow[$priceKey]) > 0) {
            return floatval($priceRow[$priceKey]);
        }
        
        // Fallback: find any available price
        for ($i = 1; $i <= 10; $i++) {
            $key = 'price_' . $i;
            if (isset($priceRow[$key]) && floatval($priceRow[$key]) > 0) {
                return floatval($priceRow[$key]);
            }
        }
        
        return 0;
    }
    
    /**
     * Get complete price data for product tab
     * 
     * @param int $productId Product ID
     * @param string $hotelId Hotel ID
     * @return array Complete price data for template
     */
    public function getProductTabData(int $productId, string $hotelId): array
    {
        return [
            'prices' => $this->getStoredPrices($productId),
            'last_update' => $this->getLastUpdate($productId),
            'active_package' => $this->getActivePackage($productId),
            'seasons' => $this->getSeasons($hotelId),
            'early_booking' => $this->getEarlyBooking($hotelId),
            'active_eb' => $this->getActiveEarlyBooking($hotelId),
        ];
    }
    
    /**
     * Sync prices from API to database
     * 
     * @param int $productId Product ID
     * @param string $hotelId Hotel ID
     * @param string $packageName Package name
     * @return int Number of prices saved
     */
    public function syncPrices(int $productId, string $hotelId, string $packageName): int
    {
        $priceInfo = $this->getPriceInfo($hotelId, $packageName);
        
        if (!$priceInfo || empty($priceInfo['prices'])) {
            return 0;
        }
        
        // Delete existing prices
        db_query('DELETE FROM ?:novoton_hotel_prices WHERE product_id = ?i', $productId);
        
        $count = 0;
        
        foreach ($priceInfo['prices'] as $price) {
            $record = [
                'product_id' => $productId,
                'hotel_id' => $hotelId,
                'package_name' => $packageName,
                'room_id' => $price['room_id'],
                'board_id' => $price['board_id'],
                'age_type' => $price['age_type'] ?? 'ADULT',
                'acc_type' => $price['acc_type'] ?? 'REGULAR',
            ];
            
            // Add season prices
            for ($i = 1; $i <= 10; $i++) {
                $key = 'price_' . $i;
                $record[$key] = $price[$key] ?? null;
            }
            
            db_query('INSERT INTO ?:novoton_hotel_prices ?e', $record);
            $count++;
        }
        
        $this->log('Prices synced', [
            'product_id' => $productId,
            'hotel_id' => $hotelId,
            'count' => $count
        ]);
        
        return $count;
    }
    
    /**
     * Parse priceinfo API response
     * 
     * @param object $response API response
     * @param string $hotelId Hotel ID
     * @return array Parsed data
     */
    private function parsePriceInfoResponse($response, string $hotelId): array
    {
        $result = [
            'hotel_id' => $hotelId,
            'package_name' => '',
            'seasons' => [],
            'prices' => [],
            'extras' => [],
            'terms' => [],
        ];
        
        // Parse package name
        if (isset($response->PackageName)) {
            $result['package_name'] = (string)$response->PackageName;
        }
        
        // Parse seasons
        if (isset($response->Seasons->Season)) {
            $seasons = is_array($response->Seasons->Season) 
                ? $response->Seasons->Season 
                : [$response->Seasons->Season];
                
            foreach ($seasons as $season) {
                $result['seasons'][] = [
                    'number' => (int)($season->Number ?? 1),
                    'date_from' => (string)($season->DateFrom ?? ''),
                    'date_to' => (string)($season->DateTo ?? ''),
                    'name' => (string)($season->Name ?? ''),
                ];
            }
        }
        
        // Parse room prices
        if (isset($response->Rooms->Room)) {
            $rooms = is_array($response->Rooms->Room) 
                ? $response->Rooms->Room 
                : [$response->Rooms->Room];
                
            foreach ($rooms as $room) {
                $roomData = [
                    'room_id' => (string)($room->IdRoom ?? ''),
                    'room_name' => (string)($room->RoomName ?? ''),
                    'board_id' => (string)($room->IdBoard ?? ''),
                    'age_type' => (string)($room->AgeType ?? 'ADULT'),
                    'acc_type' => (string)($room->AccType ?? 'REGULAR'),
                    'max_adults' => (int)($room->MaxAdt ?? 2),
                    'max_children' => (int)($room->MaxChd ?? 2),
                    'min_pax' => (int)($room->MinPax ?? 1),
                ];
                
                // Parse season prices
                if (isset($room->Prices->Price)) {
                    $prices = is_array($room->Prices->Price) 
                        ? $room->Prices->Price 
                        : [$room->Prices->Price];
                        
                    foreach ($prices as $price) {
                        $seasonNum = (int)($price->Season ?? 1);
                        $priceValue = floatval((string)($price->Value ?? 0));
                        
                        // Apply commission
                        $priceWithCommission = $this->applyCommission($priceValue);
                        $roomData['price_' . $seasonNum] = $priceWithCommission;
                    }
                }
                
                $result['prices'][] = $roomData;
            }
        }
        
        // Parse extras (supplements, etc.)
        if (isset($response->Extras)) {
            $result['extras'] = $this->parseExtras($response->Extras);
        }
        
        // Parse terms
        if (isset($response->Terms)) {
            $result['terms'] = [
                'payment' => (string)($response->Terms->Payment ?? ''),
                'cancellation' => (string)($response->Terms->Cancellation ?? ''),
            ];
        }
        
        return $result;
    }
    
    /**
     * Parse extras from response
     * 
     * @param object $extras Extras node
     * @return array Parsed extras
     */
    private function parseExtras($extras): array
    {
        $result = [];
        
        if (isset($extras->Extra)) {
            $items = is_array($extras->Extra) ? $extras->Extra : [$extras->Extra];
            
            foreach ($items as $extra) {
                $result[] = [
                    'name' => (string)($extra->Name ?? ''),
                    'type' => (string)($extra->Type ?? ''),
                    'price' => floatval((string)($extra->Price ?? 0)),
                    'mandatory' => (string)($extra->Mandatory ?? 'N') === 'Y',
                ];
            }
        }
        
        return $result;
    }
    
    /**
     * Group prices by room
     * 
     * @param array $prices Flat prices array
     * @return array Prices grouped by room_id
     */
    private function groupPricesByRoom(array $prices): array
    {
        $grouped = [];
        
        foreach ($prices as $price) {
            $roomId = $price['room_id'];
            
            if (!isset($grouped[$roomId])) {
                $grouped[$roomId] = [
                    'room_id' => $roomId,
                    'room_name' => fn_novoton_format_room_type($roomId),
                    'boards' => [],
                ];
            }
            
            $boardId = $price['board_id'];
            if (!isset($grouped[$roomId]['boards'][$boardId])) {
                $grouped[$roomId]['boards'][$boardId] = [
                    'board_id' => $boardId,
                    'prices' => [],
                ];
            }
            
            $grouped[$roomId]['boards'][$boardId]['prices'][] = $price;
        }
        
        return $grouped;
    }
    
    /**
     * Apply commission to price
     * 
     * @param float $price Base price
     * @return float Price with commission
     */
    private function applyCommission(float $price): float
    {
        if ($this->commission <= 0) {
            return $price;
        }
        
        return round($price * (1 + ($this->commission / 100)), 2);
    }
    
    /**
     * Log debug message
     * 
     * @param string $message Message
     * @param array $context Context
     */
    private function log(string $message, array $context = []): void
    {
        if ($this->debug) {
            fn_log_event('general', 'runtime', array_merge(
                ['message' => 'NovotonPriceInfo: ' . $message],
                $context
            ));
        }
    }
}

<?php
declare(strict_types=1);
/**
 * Novoton Search Service
 * 
 * Handles hotel availability search, result processing, and flexible date searches.
 * Extracted from novoton_booking.php for better maintainability.
 * 
 * @package NovotonHolidays
 * @since 2.7.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Registry;
use Tygh\Addons\NovotonHolidays\Constants;

class SearchService
{
    /** @var \Tygh\Addons\NovotonHolidays\NovotonApi */
    private $api;
    
    /** @var CacheServiceInterface */
    private $cache;
    
    /** @var bool */
    private $debug = false;
    
    /**
     * Constructor
     */
    public function __construct(?CacheServiceInterface $cache = null)
    {
        $this->api = fn_novoton_get_api();
        $this->cache = $cache ?? new CacheService();
        $this->debug = (Registry::get(\Tygh\Addons\NovotonHolidays\Constants::SETTING_DEBUG_LOGGING) ?? 'N') === 'Y';
    }
    
    /**
     * Parse search parameters from request
     * 
     * @param array $request Request parameters
     * @return array Normalized search parameters
     */
    public function parseSearchParams(array $request): array
    {
        $params = [
            'check_in' => $request['check_in'] ?? '',
            'nights' => intval($request['nights'] ?? 7),
            'adults' => intval($request['adults'] ?? 2),
            'children' => intval($request['children'] ?? 0),
            'num_rooms' => intval($request['rooms'] ?? 1),
            'flex_days' => intval($request['flex_days'] ?? 0),
            'hotel_id' => $request['hotel_id'] ?? '',
            'product_id' => intval($request['product_id'] ?? 0),
            'destination' => $request['destination'] ?? '',
            'country' => $request['country'] ?? '',
            'region' => $request['region'] ?? '',
            'city' => $request['city'] ?? '',
        ];
        
        // Parse multi-room data
        $rooms_data = [];
        if (!empty($request['room_data'])) {
            $rooms_data = json_decode($request['room_data'], true);
            if (!is_array($rooms_data)) {
                $rooms_data = [];
            }
        }
        
        // Create default single room if no room_data
        if (empty($rooms_data)) {
            $children_ages = $this->parseChildrenAges($request, $params['children']);
            $rooms_data = [[
                'adults' => $params['adults'],
                'children' => $params['children'],
                'childrenAges' => $children_ages
            ]];
        }
        
        // Calculate totals from rooms
        $totals = $this->calculateRoomTotals($rooms_data);
        $params['total_adults'] = $totals['adults'];
        $params['total_children'] = $totals['children'];
        $params['children_ages'] = $totals['ages'];
        $params['rooms_data'] = $rooms_data;
        $params['num_rooms'] = count($rooms_data);
        
        return $params;
    }
    
    /**
     * Parse children ages from request
     * 
     * @param array $request Request data
     * @param int $children_count Number of children
     * @return array Children ages
     */
    private function parseChildrenAges(array $request, int $children_count): array
    {
        $ages = [];
        for ($i = 1; $i <= $children_count; $i++) {
            if (isset($request['child_age_' . $i])) {
                $age = $request['child_age_' . $i];
                if ($age !== '' && $age !== 'age_needed') {
                    $ages[] = intval($age);
                }
            }
        }
        return $ages;
    }
    
    /**
     * Calculate totals from rooms data
     * 
     * @param array $rooms_data Rooms configuration
     * @return array Totals [adults, children, ages]
     */
    public function calculateRoomTotals(array $rooms_data): array
    {
        $total_adults = 0;
        $total_children = 0;
        $all_ages = [];
        
        foreach ($rooms_data as $room) {
            $total_adults += intval($room['adults'] ?? 2);
            $total_children += intval($room['children'] ?? 0);
            if (!empty($room['childrenAges'])) {
                foreach ($room['childrenAges'] as $age) {
                    if ($age !== null && $age !== 'age_needed') {
                        $all_ages[] = intval($age);
                    }
                }
            }
        }
        
        return [
            'adults' => $total_adults,
            'children' => $total_children,
            'ages' => $all_ages
        ];
    }
    
    /**
     * Search hotel availability
     * 
     * @param array $params Search parameters
     * @return array Search results
     */
    public function searchAvailability(array $params): array
    {
        // Check cache first
        $cache_key = $this->buildCacheKey('availability', $params);
        $cached = $this->cache->get($cache_key);
        if ($cached !== null) {
            $this->log('Search cache hit', ['key' => $cache_key]);
            return $cached;
        }
        
        // Build API parameters
        $api_params = $this->buildApiParams($params);

        // Call API - use searchAvailability method
        $response = $this->api->searchAvailability($api_params);
        
        if (!$response) {
            $this->log('Search API returned empty', ['params' => $api_params]);
            return [];
        }
        
        // Process results
        $results = $this->processSearchResults($response, $params);
        
        // Cache results (5 minutes for availability)
        $this->cache->set($cache_key, $results, 300);
        
        return $results;
    }
    
    /**
     * Search with flexible dates
     * 
     * @param array $params Base search parameters
     * @param int $flex_days Number of days flexibility (+/-)
     * @return array Results grouped by date
     */
    public function searchFlexibleDates(array $params, int $flex_days): array
    {
        $base_date = strtotime($params['check_in']);
        $all_results = [];
        
        // Search each date in range
        for ($offset = -$flex_days; $offset <= $flex_days; $offset++) {
            $search_date = date('Y-m-d', strtotime("{$offset} days", $base_date));
            $params['check_in'] = $search_date;
            
            $results = $this->searchAvailability($params);
            
            if (!empty($results)) {
                $all_results[$search_date] = $results;
            }
        }
        
        return $all_results;
    }
    
    /**
     * Build API parameters from search params
     * 
     * @param array $params Search parameters
     * @return array API-ready parameters
     */
    private function buildApiParams(array $params): array
    {
        $api_params = [
            'check_in' => $params['check_in'],
            'nights' => $params['nights'],
            'adults' => $params['total_adults'] ?? $params['adults'],
            'children' => $params['children_ages'] ?? [],
        ];
        
        if (!empty($params['hotel_id'])) {
            $api_params['hotel_id'] = $params['hotel_id'];
        }
        
        if (!empty($params['country'])) {
            $api_params['country'] = $params['country'];
        }
        
        if (!empty($params['region'])) {
            $api_params['region'] = $params['region'];
        }
        
        if (!empty($params['city'])) {
            $api_params['city'] = $params['city'];
        }
        
        return $api_params;
    }
    
    /**
     * Process raw API search results
     * 
     * @param object $response API response
     * @param array $params Original search parameters
     * @return array Processed results
     */
    public function processSearchResults($response, array $params): array
    {
        $results = [];
        
        // Handle different response formats
        if (isset($response->Hotels->Hotel)) {
            $hotels = is_array($response->Hotels->Hotel) 
                ? $response->Hotels->Hotel 
                : [$response->Hotels->Hotel];
                
            foreach ($hotels as $hotel) {
                $processed = $this->processHotelResult($hotel, $params);
                if ($processed) {
                    $results[] = $processed;
                }
            }
        } elseif (isset($response->Hotel)) {
            // Single hotel response
            $processed = $this->processHotelResult($response->Hotel, $params);
            if ($processed) {
                $results[] = $processed;
            }
        }
        
        return $results;
    }
    
    /**
     * Process single hotel result
     * 
     * @param object $hotel Hotel data from API
     * @param array $params Search parameters
     * @return array|null Processed hotel data
     */
    private function processHotelResult($hotel, array $params): ?array
    {
        $hotel_id = (string)($hotel->IdHotel ?? $hotel->HotelId ?? '');
        if (empty($hotel_id)) {
            return null;
        }
        
        // Get hotel details from database or cache
        $hotel_info = fn_novoton_get_hotel_data($hotel_id);
        
        $result = [
            'hotel_id' => $hotel_id,
            'hotel_name' => (string)($hotel->HotelName ?? $hotel_info['hotel_name'] ?? 'Hotel ' . $hotel_id),
            'star_rating' => (string)($hotel->Stars ?? $hotel_info['star_rating'] ?? ''),
            'city' => (string)($hotel->City ?? $hotel_info['city'] ?? ''),
            'region' => (string)($hotel->Region ?? $hotel_info['region'] ?? ''),
            'country' => (string)($hotel->Country ?? $hotel_info['country'] ?? ''),
            'image_url' => $hotel_info['image_url'] ?? '',
            'product_id' => $hotel_info['product_id'] ?? 0,
            'rooms' => [],
            'min_price' => PHP_INT_MAX,
            'search_params' => $params,
        ];
        
        // Process rooms
        if (isset($hotel->Rooms->Room)) {
            $rooms = is_array($hotel->Rooms->Room) 
                ? $hotel->Rooms->Room 
                : [$hotel->Rooms->Room];
                
            foreach ($rooms as $room) {
                $room_data = $this->processRoomResult($room, $params);
                if ($room_data) {
                    $result['rooms'][] = $room_data;
                    if ($room_data['price'] < $result['min_price']) {
                        $result['min_price'] = $room_data['price'];
                    }
                }
            }
        }
        
        // Fix min_price if no rooms found
        if ($result['min_price'] === PHP_INT_MAX) {
            $result['min_price'] = 0;
        }
        
        return $result;
    }
    
    /**
     * Process single room result
     * 
     * @param object $room Room data from API
     * @param array $params Search parameters
     * @return array|null Processed room data
     */
    private function processRoomResult($room, array $params): ?array
    {
        $room_id = (string)($room->IdRoom ?? $room->RoomId ?? '');
        if (empty($room_id)) {
            return null;
        }
        
        $raw_price = floatval((string)($room->Price ?? 0));
        $price_with_commission = $this->api->applyCommission($raw_price);
        
        return [
            'room_id' => $room_id,
            'room_name' => fn_novoton_format_room_type($room_id),
            'board_id' => (string)($room->IdBoard ?? $room->BoardId ?? 'BB'),
            'board_name' => $this->getBoardName((string)($room->IdBoard ?? $room->BoardId ?? 'BB')),
            'price' => $price_with_commission,
            'price_raw' => $raw_price,
            'availability' => (string)($room->Availability ?? $room->Status ?? 'OK'),
            'quota' => intval($room->Quota ?? $room->Available ?? 0),
            'cancellation_policy' => (string)($room->CancellationPolicy ?? ''),
            'payment_terms' => (string)($room->PaymentTerms ?? ''),
        ];
    }
    
    /**
     * Get board name from ID
     *
     * Delegates to BoardType value object (single source of truth).
     *
     * @param string $board_id Board ID (e.g. "AI", "FB+", "ALL INCL")
     * @return string Board display name
     */
    public function getBoardName(string $board_id): string
    {
        return \Tygh\Addons\NovotonHolidays\ValueObjects\BoardType::toDisplayName($board_id);
    }
    
    /**
     * Build cache key for search
     * 
     * @param string $prefix Key prefix
     * @param array $params Parameters to hash
     * @return string Cache key
     */
    private function buildCacheKey(string $prefix, array $params): string
    {
        $key_data = [
            'check_in' => $params['check_in'] ?? '',
            'nights' => $params['nights'] ?? 7,
            'adults' => $params['total_adults'] ?? $params['adults'] ?? 2,
            'children' => $params['children_ages'] ?? [],
            'hotel_id' => $params['hotel_id'] ?? '',
            'country' => $params['country'] ?? '',
        ];
        
        return 'nvt_' . $prefix . '_' . md5(json_encode($key_data));
    }
    
    /**
     * Log debug message
     * 
     * @param string $message Log message
     * @param array $context Additional context
     */
    private function log(string $message, array $context = []): void
    {
        if ($this->debug) {
            fn_log_event('general', 'runtime', array_merge(
                ['message' => 'NovotonSearch: ' . $message],
                $context
            ));
        }
    }
}

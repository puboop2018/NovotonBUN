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
    
    // =========================================================================
    // BOARD / MEAL PLAN FILTERING (single source of truth)
    // =========================================================================

    /**
     * Check if a board ID matches the requested meal plan.
     *
     * Uses Constants::BOARD_MAPPING to resolve user-facing codes (AI, UAI, FB…)
     * to the API board strings they map to.
     *
     * @param string $boardId  Board identifier from the API response
     * @param string $mealPlan User-selected meal plan code (e.g. 'AI', 'HB')
     * @return bool
     */
    public static function matchesMealPlan(string $boardId, string $mealPlan): bool
    {
        if (empty($mealPlan)) {
            return true; // "All boards" — everything matches
        }

        $mapping = Constants::BOARD_MAPPING;
        $candidates = $mapping[$mealPlan] ?? [$mealPlan];

        foreach ($candidates as $candidate) {
            if (stripos($boardId, $candidate) !== false) {
                return true;
            }
        }

        return false;
    }

    // =========================================================================
    // QUOTA INTERPRETATION
    // =========================================================================

    /**
     * Interpret a raw quota value from the hotel_quota API.
     *
     * @param string|null $quotaValue Raw quota (e.g. "5", "RQ", "REQUEST", "")
     * @return array{availability: int|null, is_on_request: bool}
     */
    public static function parseQuotaValue(?string $quotaValue): array
    {
        if ($quotaValue === null) {
            return ['availability' => null, 'is_on_request' => false];
        }

        $quotaValue = trim($quotaValue);
        $upper = strtoupper($quotaValue);

        if ($upper === 'RQ' || $upper === 'REQUEST' || $quotaValue === '') {
            return ['availability' => 0, 'is_on_request' => true];
        }

        $intVal = intval($quotaValue);
        if ($intVal === 0) {
            return ['availability' => 0, 'is_on_request' => true];
        }

        return ['availability' => $intVal, 'is_on_request' => false];
    }

    // =========================================================================
    // ROOM PRICE RESPONSE PARSING
    // =========================================================================

    /**
     * Parse a room_price API XML response into a structured result array.
     *
     * Handles both single-result and multi-result responses, applies meal plan
     * filtering, commission, quota lookup, and builds the standard result items.
     *
     * @param string      $rawXml       Raw XML string from room_price API
     * @param int         $nights       Number of nights
     * @param string      $checkIn      Check-in date (Y-m-d)
     * @param string      $checkOut     Check-out date (Y-m-d)
     * @param string      $mealPlan     Requested meal plan code (empty = all)
     * @param array       $quotaMap     Room ID => quota value map (from hotel_quota)
     * @param array       $roomTypeMap  Room ID => Type map (from hotelinfo)
     * @param int|null    $forRoom      Room number (multi-room), null for single
     * @param string|null $occupancyStr Occupancy string for display
     * @return array List of result items
     */
    public function parseRoomPriceResponse(
        string $rawXml,
        int    $nights,
        string $checkIn,
        string $checkOut,
        string $mealPlan = '',
        array  $quotaMap = [],
        array  $roomTypeMap = [],
        ?int   $forRoom = null,
        ?string $occupancyStr = null
    ): array {
        $results = [];

        $prevLibxml = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($rawXml);
        libxml_clear_errors();
        libxml_use_internal_errors($prevLibxml);

        if ($xml === false) {
            return [];
        }

        $priceElements = $xml->xpath('//Price');
        $numPrices = count($priceElements);

        if ($numPrices === 0) {
            return [];
        }

        $searchAllBoards = empty($mealPlan);

        if ($numPrices > 1 || ($xml->getName() !== 'room_price' && $numPrices > 0)) {
            // Multi-result: parallel xpath arrays
            $idRooms        = $xml->xpath('//IdRoom');
            $boards          = $xml->xpath('//Board');
            $prices          = $xml->xpath('//Price');
            $packageNames    = $xml->xpath('//PackageName');
            $remarks         = $xml->xpath('//remark');
            $earlyBookings   = $xml->xpath('//early_booking');
            $extras          = $xml->xpath('//extras');
            $moreInfos       = $xml->xpath('//MoreInfo');
            $importants      = $xml->xpath('//Important');
            $termsPayment    = $xml->xpath('//TermsOfPayment');
            $termsCancellation = $xml->xpath('//TermsOfCancellation');

            for ($i = 0; $i < count($prices); $i++) {
                $roomId  = isset($idRooms[$i]) ? (string)$idRooms[$i] : '';
                $boardId = isset($boards[$i])  ? (string)$boards[$i]  : '';
                $price   = isset($prices[$i])  ? floatval((string)$prices[$i]) : 0;

                if (empty($roomId) || $price <= 0) {
                    continue;
                }

                if (!$searchAllBoards && !self::matchesMealPlan($boardId, $mealPlan)) {
                    continue;
                }

                $finalPrice = $this->api->applyCommission($price);
                $quota = self::parseQuotaValue($quotaMap[$roomId] ?? null);

                $item = [
                    'room'                   => null,
                    'room_id'                => $roomId,
                    'room_name'              => str_replace(['%2b', '%2B'], '+', $roomId),
                    'room_type_display'      => fn_novoton_format_room_type($roomId, $roomTypeMap[$roomId] ?? ''),
                    'board_id'               => $boardId,
                    'board_name'             => fn_novoton_format_board_name($boardId),
                    'package_name'           => urldecode(self::xpathValue($packageNames, $i)),
                    'price_data'             => null,
                    'nights'                 => $nights,
                    'total_price'            => $finalPrice,
                    'price_per_night'        => round($finalPrice / max($nights, 1), 2),
                    'check_in'               => $checkIn,
                    'check_out'              => $checkOut,
                    'rooms_available'        => $quota['availability'],
                    'is_on_request'          => $quota['is_on_request'],
                    'remark'                 => self::xpathValue($remarks, $i),
                    'important'              => self::xpathValue($importants, $i),
                    'more_info'              => self::xpathValue($moreInfos, $i),
                    'early_booking_discount' => floatval(self::xpathValue($earlyBookings, $i, '0')),
                    'extras'                 => self::xpathValue($extras, $i),
                    'terms_of_payment'       => isset($termsPayment[0]) ? $termsPayment[0]->asXML() : '',
                    'terms_of_cancellation'  => isset($termsCancellation[0]) ? $termsCancellation[0]->asXML() : '',
                    'free_cancellation_date' => isset($termsCancellation[0])
                        ? fn_novoton_get_free_cancellation_date($termsCancellation[0]->asXML())
                        : null,
                ];

                if ($forRoom !== null) {
                    $item['for_room'] = $forRoom;
                }
                if ($occupancyStr !== null) {
                    $item['occupancy'] = $occupancyStr;
                }

                $results[] = $item;
            }
        } else {
            // Single result (root is room_price itself)
            $roomId      = (string)$xml->IdRoom;
            $boardId     = (string)$xml->Board;
            $price       = floatval((string)$xml->Price);
            $packageName = (string)$xml->PackageName;
            $remark      = isset($xml->remark) ? (string)$xml->remark : '';

            if (empty($roomId) || $price <= 0) {
                return [];
            }

            if (!$searchAllBoards && !self::matchesMealPlan($boardId, $mealPlan)) {
                return [];
            }

            $finalPrice = $this->api->applyCommission($price);
            $quota = self::parseQuotaValue($quotaMap[$roomId] ?? null);

            $item = [
                'room'                   => null,
                'room_id'                => $roomId,
                'room_name'              => str_replace(['%2b', '%2B'], '+', $roomId),
                'room_type_display'      => fn_novoton_format_room_type($roomId, $roomTypeMap[$roomId] ?? ''),
                'board_id'               => $boardId,
                'board_name'             => fn_novoton_format_board_name($boardId),
                'package_name'           => urldecode($packageName),
                'price_data'             => $xml,
                'nights'                 => $nights,
                'total_price'            => $finalPrice,
                'price_per_night'        => round($finalPrice / max($nights, 1), 2),
                'check_in'               => $checkIn,
                'check_out'              => $checkOut,
                'rooms_available'        => $quota['availability'],
                'is_on_request'          => $quota['is_on_request'],
                'remark'                 => $remark,
                'important'              => isset($xml->Important) ? (string)$xml->Important : '',
                'more_info'              => isset($xml->MoreInfo) ? (string)$xml->MoreInfo : '',
                'early_booking_discount' => isset($xml->early_booking) ? floatval((string)$xml->early_booking) : 0,
                'extras'                 => isset($xml->extras) ? (string)$xml->extras : '',
                'terms_of_payment'       => isset($xml->TermsOfPayment) ? $xml->TermsOfPayment->asXML() : '',
                'terms_of_cancellation'  => isset($xml->TermsOfCancellation) ? $xml->TermsOfCancellation->asXML() : '',
                'free_cancellation_date' => isset($xml->TermsOfCancellation)
                    ? fn_novoton_get_free_cancellation_date($xml->TermsOfCancellation->asXML())
                    : null,
            ];

            if ($forRoom !== null) {
                $item['for_room'] = $forRoom;
            }
            if ($occupancyStr !== null) {
                $item['occupancy'] = $occupancyStr;
            }

            $results[] = $item;
        }

        return $results;
    }

    // =========================================================================
    // EARLY BOOKING DISCOUNTS
    // =========================================================================

    /**
     * Extract active early booking discounts from the priceinfo_data JSON
     * stored in novoton_hotel_packages.
     *
     * @param string $hotelId  Hotel ID
     * @param string $checkIn  Guest check-in date (Y-m-d)
     * @param string $checkOut Guest check-out date (Y-m-d)
     * @return array List of applicable discount records
     */
    public static function getEarlyBookingDiscounts(string $hotelId, string $checkIn, string $checkOut): array
    {
        $discounts = [];

        $eb_package = db_get_row(
            "SELECT priceinfo_data FROM ?:novoton_hotel_packages
             WHERE hotel_id = ?s AND has_early_booking = 'Y' AND priceinfo_data IS NOT NULL
             ORDER BY synced_at DESC LIMIT 1",
            $hotelId
        );

        if (empty($eb_package['priceinfo_data'])) {
            return [];
        }

        $priceinfo = json_decode($eb_package['priceinfo_data'], true);
        if (empty($priceinfo['early_booking'])) {
            return [];
        }

        $eb_data = $priceinfo['early_booking'];
        // Normalize single entry to array
        if (isset($eb_data['Reduction'])) {
            $eb_data = [$eb_data];
        }

        $today = date('Y-m-d');
        foreach ($eb_data as $eb) {
            $bookTo   = $eb['BookTo']   ?? '';
            $stayFrom = $eb['StayFrom'] ?? '';
            $stayTo   = $eb['StayTo']   ?? '';

            if (!empty($bookTo) && $bookTo < $today) continue;
            if (!empty($stayFrom) && $stayFrom > $checkOut) continue;
            if (!empty($stayTo) && $stayTo < $checkIn) continue;

            $discounts[] = [
                'discount'   => floatval($eb['Reduction'] ?? 0),
                'room_types' => $eb['RoomTypes'] ?? 'all',
                'package'    => $eb['PackageId'] ?? '',
                'min_stay'   => intval($eb['MinStay'] ?? 0),
                'booking_to' => $bookTo,
            ];
        }

        // Sort by discount DESC, limit to 10
        usort($discounts, fn($a, $b) => $b['discount'] <=> $a['discount']);
        return array_slice($discounts, 0, 10);
    }

    /**
     * Calculate discount range from a list of early booking discounts.
     *
     * @param array $discounts From getEarlyBookingDiscounts()
     * @return array {min, max, all} or empty
     */
    public static function getDiscountRange(array $discounts): array
    {
        if (empty($discounts)) {
            return [];
        }

        $values = array_column($discounts, 'discount');
        $range = [
            'min' => min($values),
            'max' => max($values),
            'all' => array_unique($values),
        ];
        sort($range['all']);
        return $range;
    }

    // =========================================================================
    // RESULT DEDUPLICATION
    // =========================================================================

    /**
     * Deduplicate results, keeping the lowest price for each room/board/package.
     *
     * @param array $results
     * @return array Deduplicated results (re-indexed)
     */
    public static function deduplicateResults(array $results): array
    {
        $unique = [];
        foreach ($results as $result) {
            $key = $result['room_id'] . '|' . $result['board_id'] . '|' . ($result['package_name'] ?? '');
            if (!isset($unique[$key])
                || ($result['total_price'] > 0 && $result['total_price'] < $unique[$key]['total_price'])) {
                $unique[$key] = $result;
            }
        }
        return array_values($unique);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Safe xpath value accessor.
     */
    private static function xpathValue(?array $elements, int $index, string $default = ''): string
    {
        if ($elements === null || !isset($elements[$index])) {
            return $default;
        }
        return (string)$elements[$index];
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

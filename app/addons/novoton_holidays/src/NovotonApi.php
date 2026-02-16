<?php
/**
 * Novoton API Integration Class
 * Thin facade delegating to NovotonHttpClient, NovotonXmlParser, and CommissionCalculator.
 *
 * Path: app/addons/novoton_holidays/src/NovotonApi.php
 */

namespace Tygh\Addons\NovotonHolidays;

use Tygh\Registry;
use Tygh\Addons\NovotonHolidays\Exceptions\ApiException;
use Tygh\Addons\NovotonHolidays\Exceptions\XmlParsingException;
use Tygh\Addons\NovotonHolidays\Exceptions\ValidationException;

require_once __DIR__ . '/Exceptions/NovotonException.php';
require_once __DIR__ . '/Exceptions/ApiException.php';
require_once __DIR__ . '/Exceptions/XmlParsingException.php';
require_once __DIR__ . '/Exceptions/ValidationException.php';
require_once __DIR__ . '/Exceptions/SyncException.php';
require_once __DIR__ . '/NovotonHttpClient.php';
require_once __DIR__ . '/NovotonXmlParser.php';
require_once __DIR__ . '/CommissionCalculator.php';

class NovotonApi
{
    /** @var NovotonHttpClient */
    private $httpClient;

    /** @var NovotonXmlParser */
    private $xmlParser;

    /** @var CommissionCalculator */
    private $commissionCalculator;

    /** @var \Tygh\Addons\NovotonHolidays\Services\CacheService|null */
    private $cache = null;

    /** @var bool Enable caching */
    private $enableCache = true;

    /**
     * Cache TTL by function (seconds)
     * ONLY cache live API calls that are expensive and frequently requested
     * Static data (hotel_list, hotel_info, priceinfo) is stored in database via cron
     */
    private $cacheTtl = [
        'room_price' => 300,       // 5 minutes - live booking prices
        'hotel_quota' => 180,      // 3 minutes - live availability
        'search' => 300,           // 5 minutes - search results (combines live data)
    ];

    /**
     * Functions that should NOT be cached (use database instead)
     * These are synced via cron and stored in database tables
     * V3 Architecture: priceinfo stored in novoton_hotel_packages.priceinfo_data JSON
     */
    private $noCacheFunctions = [
        'hotel_list',    // Stored in novoton_hotels table
        'hotelinfo',     // Stored in novoton_hotels.hotel_data JSON
        'priceinfo',     // Stored in novoton_hotel_packages.priceinfo_data JSON
    ];

    // Debug properties
    public $lastRequest = '';
    public $lastResponse = '';
    public $lastResponseRaw = '';
    public $lastRequestFormatted = [];
    public $lastError = '';
    public $lastHttpCode = 0;

    public function __construct()
    {
        $settings = Registry::get('addons.novoton_holidays') ?? [];

        $this->httpClient = new NovotonHttpClient($settings);
        $this->xmlParser = new NovotonXmlParser();
        $this->commissionCalculator = new CommissionCalculator(
            floatval($settings['commission'] ?? 8),
            $settings['round_prices'] ?? 'Y'
        );

        // Initialize cache service
        $this->enableCache = ($settings['enable_api_cache'] ?? 'Y') === 'Y';
        if ($this->enableCache) {
            $this->cache = new \Tygh\Addons\NovotonHolidays\Services\CacheService('file');
        }
    }

    // ========== INTERNAL HELPERS ==========

    /**
     * Send API request, clean the response, and sync debug state.
     * Returns cleaned response string or false on failure.
     *
     * @param string $function API function name
     * @param string $xml XML request body
     * @param string $lang Language code
     * @return string|false Cleaned response or false
     */
    private function callApi(string $function, string $xml, string $lang = 'UK')
    {
        try {
            $raw = $this->httpClient->sendRequest($function, $xml, $lang);
        } catch (ApiException $e) {
            $this->syncDebugState();
            return false;
        }

        $this->syncDebugState();
        $cleaned = $this->xmlParser->clean($raw);
        $this->lastResponse = $cleaned;
        return $cleaned;
    }

    /**
     * Send API request, clean and parse the XML response.
     * Catches ApiException and XmlParsingException for backward compatibility.
     *
     * @param string $function API function name
     * @param string $xml XML request body
     * @param string $lang Language code
     * @return \SimpleXMLElement|false Parsed XML or false on failure
     */
    private function callApiAndParse(string $function, string $xml, string $lang = 'UK')
    {
        $response = $this->callApi($function, $xml, $lang);

        if ($response === false) {
            return false;
        }

        try {
            return $this->xmlParser->parse($response);
        } catch (XmlParsingException $e) {
            return false;
        }
    }

    /**
     * Sync debug state from HTTP client after each request
     */
    private function syncDebugState(): void
    {
        $this->lastResponseRaw = $this->httpClient->lastResponseRaw;
        $this->lastError = $this->httpClient->lastError;
        $this->lastHttpCode = $this->httpClient->lastHttpCode;
    }

    // ========== CACHE ==========

    /**
     * Get cached response or null
     * Only caches live API calls (room_price, hotel_quota, search)
     */
    private function getFromCache(string $function, string $cacheKey)
    {
        if (in_array($function, $this->noCacheFunctions)) {
            return null;
        }

        if (!$this->enableCache || !$this->cache) {
            return null;
        }

        return $this->cache->get($cacheKey);
    }

    /**
     * Save response to cache
     * Only caches live API calls (room_price, hotel_quota, search)
     */
    private function saveToCache(string $function, string $cacheKey, $data): void
    {
        if (in_array($function, $this->noCacheFunctions)) {
            return;
        }

        if (!$this->enableCache || !$this->cache || $data === null) {
            return;
        }

        $ttl = $this->cacheTtl[$function] ?? 300;
        $this->cache->set($cacheKey, $data, $ttl);
    }

    /**
     * Build cache key for request
     */
    private function buildCacheKey(string $function, array $params): string
    {
        return 'nvt_api_' . $function . '_' . md5(json_encode($params));
    }

    /**
     * Clear cache for specific function or all
     *
     * @param string|null $function Function name or null for all
     * @return int Number of items cleared
     */
    public function clearCache(?string $function = null): int
    {
        if (!$this->cache) {
            return 0;
        }

        $prefix = $function ? 'nvt_api_' . $function : 'nvt_api_';
        return $this->cache->clear($prefix);
    }

    // ========== DELEGATING METHODS ==========

    /**
     * Apply commission and rounding to price
     */
    public function applyCommission(float $price): float
    {
        return $this->commissionCalculator->apply($price);
    }

    /**
     * Get circuit breaker status (for monitoring)
     */
    public function getCircuitStatus(): array
    {
        return $this->httpClient->getCircuitStatus();
    }

    /**
     * Manually reset circuit breaker (for admin use)
     */
    public function resetCircuitBreaker(): void
    {
        $this->httpClient->resetCircuitBreaker();
    }

    // ========== API FUNCTIONS ==========

    /**
     * 1. hotel_list - List with hotel names
     * Per API docs: use % as wildcard (e.g., <Hotel>%</Hotel> for all hotels)
     *
     * @return \SimpleXMLElement|false
     */
    public function getHotelList(string $country = '%', string $city = '%', string $hotel = '%', string $hotelType = '%')
    {
        $country = empty($country) ? '%' : $country;
        $city = empty($city) ? '%' : $city;
        $hotel = empty($hotel) ? '%' : $hotel;
        $hotelType = empty($hotelType) ? '%' : $hotelType;

        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <hotel_list>
            <hotelinfo>
                <Country>' . htmlspecialchars($country) . '</Country>
                <City>' . htmlspecialchars($city) . '</City>
                <Hotel>' . htmlspecialchars($hotel) . '</Hotel>
                <HotelType>' . htmlspecialchars($hotelType) . '</HotelType>
            </hotelinfo>
        </hotel_list>';

        return $this->callApiAndParse('hotel_list', $xml);
    }

    /**
     * 2. hotelinfo - Information for hotel services
     *
     * @return \SimpleXMLElement|false
     */
    public function getHotelInfo(string $hotelId, string $lang = 'UK')
    {
        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <hotelinfo>
            <IdHotel>' . htmlspecialchars($hotelId) . '</IdHotel>
        </hotelinfo>';

        return $this->callApiAndParse('hotelinfo', $xml, $lang);
    }

    /**
     * 2b. hotelinfo batch - fetch multiple hotels in parallel using curl_multi
     *
     * @param array $hotelIds Array of hotel IDs
     * @param string $lang Language code
     * @param int $concurrency Max simultaneous requests
     * @return array hotel_id => SimpleXMLElement|false
     */
    public function getHotelInfoBatch(array $hotelIds, string $lang = 'UK', int $concurrency = 5): array
    {
        if (empty($hotelIds)) {
            return [];
        }

        // Build requests for HttpClient
        $requests = [];
        foreach ($hotelIds as $hotelId) {
            $xml = '<?xml version="1.0" encoding="windows-1251"?>
                <hotelinfo>
                    <IdHotel>' . htmlspecialchars($hotelId) . '</IdHotel>
                </hotelinfo>';

            $requests[$hotelId] = ['function' => 'hotelinfo', 'xml' => $xml, 'lang' => $lang];
        }

        // Send batch via HttpClient
        $rawResponses = $this->httpClient->sendBatchRequests($requests, $concurrency);

        // Parse results
        $results = [];
        foreach ($rawResponses as $hotelId => $raw) {
            if ($raw === false) {
                $results[$hotelId] = false;
            } else {
                try {
                    $results[$hotelId] = $this->xmlParser->cleanAndParse($raw);
                } catch (XmlParsingException $e) {
                    $results[$hotelId] = false;
                }
            }
        }

        return $results;
    }

    /**
     * 3. room_price - Accommodation prices (REAL-TIME RATES)
     *
     * @return \SimpleXMLElement|false
     */
    public function getRoomPrice(array $params)
    {
        // Allow bypassing cache with 'nocache' param
        $bypassCache = !empty($params['nocache']);

        // Build cache key from params
        $cacheParams = [
            'hotel_id' => $params['hotel_id'] ?? '',
            'room_id' => $params['room_id'] ?? '',
            'board_id' => $params['board_id'] ?? '',
            'check_in' => $params['check_in'] ?? '',
            'check_out' => $params['check_out'] ?? '',
            'adults' => $params['adults'] ?? 2,
            'children' => $params['children'] ?? [],
        ];
        $cacheKey = $this->buildCacheKey('room_price', $cacheParams);

        // Check cache first (unless bypassed)
        if (!$bypassCache) {
            $cachedXml = $this->getFromCache('room_price', $cacheKey);
            if ($cachedXml !== null && is_string($cachedXml)) {
                $this->lastResponse = $cachedXml;
                try {
                    return $this->xmlParser->parse($cachedXml);
                } catch (XmlParsingException $e) {
                    // Cached data corrupted, fall through to fresh API call
                }
            }
        }

        // Room ID and Board ID - empty = return all combinations
        $roomId = $params['room_id'] ?? '';
        $boardId = $params['board_id'] ?? '';

        $checkIn = $params['check_in'] ?? '';
        $checkOut = $params['check_out'] ?? '';

        $adultsCount = intval($params['adults'] ?? 2);
        if ($adultsCount < 1) {
            $adultsCount = 2;
        }

        // Build children ages XML
        $childrenXml = '';
        if (!empty($params['children']) && is_array($params['children'])) {
            foreach ($params['children'] as $age) {
                $childrenXml .= '<Age>' . intval($age) . '</Age>';
            }
        }

        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <room_price>
            <usr>' . htmlspecialchars($this->httpClient->getApiUser()) . '</usr>
            <psw>' . htmlspecialchars($this->httpClient->getApiPassword()) . '</psw>
            <IdHotel>' . htmlspecialchars($params['hotel_id']) . '</IdHotel>
            <PackageName></PackageName>
            <IdRoom>' . htmlspecialchars($roomId) . '</IdRoom>
            <IdBoard>' . htmlspecialchars($boardId) . '</IdBoard>
            <IdExtBoard></IdExtBoard>
            <IdStar></IdStar>
            <CheckIn>' . htmlspecialchars($checkIn) . '</CheckIn>
            <CheckOut>' . htmlspecialchars($checkOut) . '</CheckOut>
            <Currency>EUR</Currency>
            <Adt>' . $adultsCount . '</Adt>
            <Chd>' . $childrenXml . '</Chd>
            <Remark>Yes</Remark>
            <Important>Yes</Important>
        </room_price>';

        // Store last request for debugging
        $this->lastRequest = $xml;
        $this->lastRequestFormatted = [
            'hotel_id' => $params['hotel_id'],
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'room_id' => $roomId ?: '(empty - all rooms)',
            'board_id' => $boardId ?: '(empty - all boards)',
            'adults' => $params['adults'] ?? 2
        ];

        $response = $this->callApi('room_price', $xml, $params['lang'] ?? 'UK');

        if ($response === false) {
            return false;
        }

        // Log raw response for debugging
        fn_log_event('general', 'runtime', [
            'message' => 'Novoton room_price - Raw API response',
            'response_length' => strlen($response),
            'response_first_500' => substr($response, 0, 500)
        ]);

        try {
            $result = $this->xmlParser->parse($response);
        } catch (XmlParsingException $e) {
            return false;
        }

        // Save RAW XML to cache (not SimpleXMLElement) ONLY if we have valid price data
        $prices = $result->xpath('//Price');
        if (!empty($prices) && count($prices) > 0) {
            $this->saveToCache('room_price', $cacheKey, $response);
        }

        return $result;
    }

    /**
     * Get last API request (for debugging)
     */
    public function getLastRequest(): string
    {
        return $this->lastRequest ?? '';
    }

    /**
     * Get last API response (for debugging)
     */
    public function getLastResponse(): string
    {
        return $this->lastResponse ?? '';
    }

    /**
     * Get last request formatted params (for debugging)
     */
    public function getLastRequestFormatted(): array
    {
        return $this->lastRequestFormatted ?? [];
    }

    /**
     * Get last error (for debugging)
     */
    public function getLastError(): string
    {
        $error = $this->lastError ?? '';
        if ($this->lastHttpCode && $this->lastHttpCode != 200) {
            $error .= " (HTTP {$this->lastHttpCode})";
        }
        return $error;
    }

    /**
     * Get last raw response before XML cleaning (for debugging)
     */
    public function getLastResponseRaw(): string
    {
        return $this->lastResponseRaw ?? '';
    }

    /**
     * 4. hotel_quota - Free allotments (AVAILABILITY)
     *
     * @param string $hotelId Hotel ID
     * @param string $checkIn Check-in date (Y-m-d)
     * @param string $checkOut Check-out date (Y-m-d)
     * @return array Associative array of room_id => quota value
     */
    public function getHotelQuotaAll(string $hotelId, string $checkIn, string $checkOut): array
    {
        // Build cache key
        $cacheParams = ['hotel_id' => $hotelId, 'check_in' => $checkIn, 'check_out' => $checkOut];
        $cacheKey = $this->buildCacheKey('hotel_quota', $cacheParams);

        // Check cache first
        $cached = $this->getFromCache('hotel_quota', $cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <hotel_quota>
            <IdHotel>' . htmlspecialchars($hotelId) . '</IdHotel>
            <IdRoom></IdRoom>
            <CheckIn>' . htmlspecialchars($checkIn) . '</CheckIn>
            <CheckOut>' . htmlspecialchars($checkOut) . '</CheckOut>
        </hotel_quota>';

        $response = $this->callApi('hotel_quota', $xml);

        if ($response === false) {
            return [];
        }

        try {
            $parsed = $this->xmlParser->parse($response);
        } catch (XmlParsingException $e) {
            return [];
        }

        $quotaMap = [];

        if (isset($parsed->Package)) {
            foreach ($parsed->Package as $package) {
                $packageXml = $package->asXML();

                preg_match_all('/<IdRoom>([^<]+)<\/IdRoom>/', $packageXml, $roomMatches);
                preg_match_all('/<Quota>([^<]+)<\/Quota>/', $packageXml, $quotaMatches);

                if (!empty($roomMatches[1]) && !empty($quotaMatches[1])) {
                    for ($i = 0; $i < count($roomMatches[1]); $i++) {
                        $roomId = trim($roomMatches[1][$i]);
                        $quota = isset($quotaMatches[1][$i]) ? trim($quotaMatches[1][$i]) : '0';

                        if (!isset($quotaMap[$roomId])) {
                            $quotaMap[$roomId] = $quota;
                        } else {
                            $existing = is_numeric($quotaMap[$roomId]) ? intval($quotaMap[$roomId]) : 0;
                            $new = is_numeric($quota) ? intval($quota) : 0;
                            if ($new < $existing || $quotaMap[$roomId] === 'RQ') {
                                $quotaMap[$roomId] = $quota;
                            }
                        }
                    }
                }
            }
        }

        if (defined('NOVOTON_DEBUG') || !empty($_REQUEST['debug'])) {
            fn_log_event('general', 'runtime', [
                'message' => "hotel_quota for hotel {$hotelId}: " . json_encode($quotaMap)
            ]);
        }

        // Save to cache
        $this->saveToCache('hotel_quota', $cacheKey, $quotaMap);

        return $quotaMap;
    }

    /**
     * 4. hotel_quota - Free allotments for a single room (AVAILABILITY)
     *
     * @return \SimpleXMLElement|false
     */
    public function getHotelQuota(string $hotelId, string $roomId, string $checkIn, string $checkOut, string $roomType = '')
    {
        $roomTypeXml = $roomType ? '<IdRoomType>' . htmlspecialchars($roomType) . '</IdRoomType>' : '';

        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <hotel_quota>
            <IdHotel>' . htmlspecialchars($hotelId) . '</IdHotel>
            <IdRoom>' . htmlspecialchars($roomId) . '</IdRoom>
            ' . $roomTypeXml . '
            <CheckIn>' . htmlspecialchars($checkIn) . '</CheckIn>
            <CheckOut>' . htmlspecialchars($checkOut) . '</CheckOut>
        </hotel_quota>';

        $response = $this->callApi('hotel_quota', $xml);

        if (defined('NOVOTON_DEBUG') || !empty($_REQUEST['debug'])) {
            fn_log_event('general', 'runtime', [
                'message' => "hotel_quota response for {$hotelId}/{$roomId}: " . substr($response ?: '', 0, 500)
            ]);
        }

        if ($response === false) {
            return false;
        }

        try {
            return $this->xmlParser->parse($response);
        } catch (XmlParsingException $e) {
            return false;
        }
    }

    /**
     * Search availability using frmsearch API endpoint
     *
     * @return array Search results
     */
    public function searchAvailability(array $params): array
    {
        $adultsCount = intval($params['adults'] ?? 2);
        $adultAges = $params['adult_ages'] ?? [];
        $adultsXml = '';
        for ($i = 0; $i < $adultsCount; $i++) {
            $age = isset($adultAges[$i]) ? intval($adultAges[$i]) : 33;
            $adultsXml .= '<Age>' . $age . '</Age>';
        }

        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <frmsearch>
            <usr>' . htmlspecialchars($this->httpClient->getApiUser()) . '</usr>
            <psw>' . htmlspecialchars($this->httpClient->getApiPassword()) . '</psw>
            <Country>' . htmlspecialchars(strtoupper($params['country'] ?? 'BULGARIA')) . '</Country>
            <City>' . htmlspecialchars(strtoupper($params['city'] ?? '')) . '</City>
            <Hotel>' . htmlspecialchars(strtoupper($params['hotel'] ?? '')) . '</Hotel>
            <Arr1>' . htmlspecialchars($params['check_in'] ?? '') . '</Arr1>
            <Dep1>' . htmlspecialchars($params['check_out'] ?? '') . '</Dep1>
            <OfferType>hotel</OfferType>
            <Adt>' . $adultsXml . '</Adt>
            <Currency>EUR</Currency>
        </frmsearch>';

        if (defined('NOVOTON_DEBUG') || !empty($_REQUEST['debug'])) {
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton frmsearch Request',
                'xml' => $xml,
                'params' => $params
            ]);
        }

        $response = $this->callApi('frmsearch', $xml);

        if (defined('NOVOTON_DEBUG') || !empty($_REQUEST['debug'])) {
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton frmsearch Response',
                'response' => substr($response ?: '', 0, 2000)
            ]);
        }

        if ($response === false) {
            return [];
        }

        try {
            $result = $this->xmlParser->parse($response);
        } catch (XmlParsingException $e) {
            return [];
        }

        return $this->parseSearchResults($result, $params);
    }

    /**
     * Parse search results from frmsearch API
     */
    private function parseSearchResults($result, $params)
    {
        $results = [];

        if (!$result) {
            return $results;
        }

        $offers = [];

        if (isset($result->offer)) {
            $offers = is_array($result->offer) ? $result->offer : [$result->offer];
        } elseif (isset($result->hotel->offer)) {
            $offers = is_array($result->hotel->offer) ? $result->hotel->offer : [$result->hotel->offer];
        } elseif (isset($result->room)) {
            $offers = is_array($result->room) ? $result->room : [$result->room];
        }

        foreach ($offers as $offer) {
            $roomType = (string)($offer->IdRoom ?? $offer->Room ?? $offer->room ?? '');
            $boardType = (string)($offer->IdBoard ?? $offer->Board ?? $offer->board ?? '');
            $price = floatval($offer->Price ?? $offer->price ?? 0);
            $nights = intval($offer->Nights ?? $offer->nights ?? 7);
            $availability = intval($offer->Availability ?? $offer->Avail ?? $offer->avail ?? 0);

            if ($price <= 0) continue;

            $results[] = [
                'room_id' => $roomType,
                'room_name' => $roomType,
                'board_id' => $boardType,
                'board_name' => $boardType,
                'check_in' => $params['check_in'],
                'check_out' => $params['check_out'],
                'nights' => $nights,
                'total_price' => $this->applyCommission($price),
                'price_per_night' => round($this->applyCommission($price) / max($nights, 1), 2),
                'currency' => 'EUR',
                'availability' => $availability
            ];
        }

        if (empty($results) && $result) {
            $data = json_decode(json_encode($result), true);

            if (is_array($data)) {
                $this->extractOffersRecursive($data, $results, $params);
            }
        }

        return $results;
    }

    /**
     * Recursively extract offers from nested array
     */
    private function extractOffersRecursive($data, &$results, $params)
    {
        if (!is_array($data)) return;

        if (isset($data['Price']) || isset($data['price'])) {
            $price = floatval($data['Price'] ?? $data['price'] ?? 0);
            if ($price > 0) {
                $nights = intval($data['Nights'] ?? $data['nights'] ?? 7);
                $results[] = [
                    'room_id' => $data['IdRoom'] ?? $data['Room'] ?? 'ROOM',
                    'room_name' => $data['Room'] ?? $data['IdRoom'] ?? 'Room',
                    'board_id' => $data['IdBoard'] ?? $data['Board'] ?? 'AI',
                    'board_name' => $data['Board'] ?? $data['IdBoard'] ?? 'All Inclusive',
                    'check_in' => $params['check_in'],
                    'check_out' => $params['check_out'],
                    'nights' => $nights,
                    'total_price' => $this->applyCommission($price),
                    'price_per_night' => round($this->applyCommission($price) / max($nights, 1), 2),
                    'currency' => 'EUR',
                    'availability' => intval($data['Availability'] ?? $data['Avail'] ?? 1)
                ];
            }
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $this->extractOffersRecursive($value, $results, $params);
            }
        }
    }

    /**
     * 5. hotel_description - Description of hotel
     *
     * @return \SimpleXMLElement|false
     */
    public function getHotelDescription(string $hotelId, string $lang = 'UK', bool $includePackage = false)
    {
        $packageXml = $includePackage ? '<PackageDescription>Yes</PackageDescription>' : '';

        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <hotel_description>
            <IdHotel>' . htmlspecialchars($hotelId) . '</IdHotel>
            ' . $packageXml . '
        </hotel_description>';

        return $this->callApiAndParse('hotel_description', $xml, $lang);
    }

    /**
     * 6. hotel_images - Pictures of hotel
     *
     * @return \SimpleXMLElement|false
     */
    public function getHotelImages(string $hotelId, string $lang = 'UK')
    {
        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <hotel_images>
            <IdHotel>' . htmlspecialchars($hotelId) . '</IdHotel>
        </hotel_images>';

        return $this->callApiAndParse('hotel_images', $xml, $lang);
    }

    /**
     * 7. hotel_res_RQ - Reservation request
     *
     * @return \SimpleXMLElement|false
     */
    public function createReservation(array $bookingData)
    {
        $settings = Registry::get('addons.novoton_holidays') ?? [];
        $isTestMode = ($settings['test_booking'] ?? 'N') === 'Y';

        $remark = $bookingData['remark'] ?? '';
        $comment = $bookingData['comment'] ?? '';

        if ($isTestMode) {
            $remark = 'test reservation, do not proceed';
            $comment = 'test reservation, do not proceed';
        }

        // Build guests XML
        $guestsXml = '';
        $idGuest = 1;
        $allGuests = $bookingData['guests'] ?? [];
        foreach ($allGuests as $guest) {
            $guestsXml .= '
    <Guests>
        <IdGuest>' . $idGuest . '</IdGuest>
        <Name>' . htmlspecialchars($guest['name']) . '</Name>
        <BirthDay>' . htmlspecialchars($guest['birthday'] ?? '') . '</BirthDay>
        <Age>' . intval($guest['age']) . '</Age>
    </Guests>';
            $idGuest++;
        }

        // Check if this is multi-room booking
        $rooms = $bookingData['rooms'] ?? [];
        $hotelAccXml = '';

        if (!empty($rooms) && count($rooms) > 1) {
            $guestIdCounter = 1;
            foreach ($rooms as $roomIdx => $roomData) {
                $roomGuests = $roomData['guests'] ?? [];

                $roomAccXml = '';
                foreach ($roomGuests as $guest) {
                    $roomAccXml .= '
            <room_acc>
                <IdGuest>' . $guestIdCounter . '</IdGuest>
                <Name>' . htmlspecialchars($guest['name']) . '</Name>
            </room_acc>';
                    $guestIdCounter++;
                }

                $hotelAccXml .= '
    <hotel_acc>
        <ConfNum></ConfNum>
        <CheckIn>' . htmlspecialchars($bookingData['check_in']) . '</CheckIn>
        <CheckOut>' . htmlspecialchars($bookingData['check_out']) . '</CheckOut>
        <IdRoom>' . htmlspecialchars($roomData['room_id']) . '</IdRoom>
        <IdBoard>' . htmlspecialchars($roomData['board_id']) . '</IdBoard>
        <IdExtBoard></IdExtBoard>
        <IdStar>' . htmlspecialchars($bookingData['star_rating'] ?? '4*') . '</IdStar>
        <Holder>' . htmlspecialchars($roomGuests[0]['name'] ?? $bookingData['holder']) . '</Holder>
        <ISO_National>' . htmlspecialchars($bookingData['iso_national'] ?? 'RO') . '</ISO_National>
        <Remark>' . htmlspecialchars($remark) . '</Remark>
        <Comment>' . htmlspecialchars($comment . ' [Room ' . ($roomIdx + 1) . ']') . '</Comment>' . $roomAccXml . '
    </hotel_acc>';
            }
        } else {
            $roomAccXml = '';
            $idGuest = 1;
            foreach ($allGuests as $guest) {
                $roomAccXml .= '
            <room_acc>
                <IdGuest>' . $idGuest . '</IdGuest>
                <Name>' . htmlspecialchars($guest['name']) . '</Name>
            </room_acc>';
                $idGuest++;
            }

            $hotelAccXml = '
    <hotel_acc>
        <ConfNum></ConfNum>
        <CheckIn>' . htmlspecialchars($bookingData['check_in']) . '</CheckIn>
        <CheckOut>' . htmlspecialchars($bookingData['check_out']) . '</CheckOut>
        <IdRoom>' . htmlspecialchars($bookingData['room_id']) . '</IdRoom>
        <IdBoard>' . htmlspecialchars($bookingData['board_id']) . '</IdBoard>
        <IdExtBoard></IdExtBoard>
        <IdStar>' . htmlspecialchars($bookingData['star_rating'] ?? '4*') . '</IdStar>
        <Holder>' . htmlspecialchars($bookingData['holder']) . '</Holder>
        <ISO_National>' . htmlspecialchars($bookingData['iso_national'] ?? 'RO') . '</ISO_National>
        <Remark>' . htmlspecialchars($remark) . '</Remark>
        <Comment>' . htmlspecialchars($comment) . '</Comment>' . $roomAccXml . '
    </hotel_acc>';
        }

        $discountType = $bookingData['discount_type'] ?? '';

        $xml = '<?xml version="1.0" encoding="windows-1251"?>
<hotel_res_RQ>
    <usr>' . htmlspecialchars($this->httpClient->getApiUser()) . '</usr>
    <psw>' . htmlspecialchars($this->httpClient->getApiPassword()) . '</psw>
    <IdHotel>' . htmlspecialchars($bookingData['hotel_id']) . '</IdHotel>
    <CreatedBy>CS-Cart</CreatedBy>
    <PackageName>' . htmlspecialchars($bookingData['package_name'] ?? '') . '</PackageName>
    <CheckIn>' . htmlspecialchars($bookingData['check_in']) . '</CheckIn>
    <CheckOut>' . htmlspecialchars($bookingData['check_out']) . '</CheckOut>
    <DiscountType>' . htmlspecialchars($discountType) . '</DiscountType>' . $guestsXml . $hotelAccXml . '
    <OrderNum>' . htmlspecialchars($bookingData['order_num'] ?? '') . '</OrderNum>
</hotel_res_RQ>';

        $this->lastRequest = $xml;

        if (defined('NOVOTON_DEBUG') || !empty($_REQUEST['debug'])) {
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton hotel_res_RQ Request (Test Mode: ' . ($isTestMode ? 'YES' : 'NO') . ')',
                'xml' => $xml
            ]);
        }

        $response = $this->callApi('hotel_res_RQ', $xml, $bookingData['lang'] ?? 'UK');

        if (defined('NOVOTON_DEBUG') || !empty($_REQUEST['debug'])) {
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton hotel_res_RQ Response',
                'response' => $response
            ]);
        }

        if ($response === false) {
            return false;
        }

        try {
            return $this->xmlParser->parse($response);
        } catch (XmlParsingException $e) {
            return false;
        }
    }

    /**
     * 8. hotel_acc_RQ_html - Request for invoice - HTML
     *
     * @return string|false HTML response or false
     */
    public function getInvoiceHtml(string $idNum, string $lang = 'UK')
    {
        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <hotel_acc_RQ_html>
            <usr>' . htmlspecialchars($this->httpClient->getApiUser()) . '</usr>
            <psw>' . htmlspecialchars($this->httpClient->getApiPassword()) . '</psw>
            <IdNum>' . htmlspecialchars($idNum) . '</IdNum>
        </hotel_acc_RQ_html>';

        $response = $this->callApi('hotel_acc_RQ_html', $xml, $lang);
        return $response; // Returns HTML directly
    }

    /**
     * 9. hotel_acc_RQ - Request for invoice - XML
     *
     * @return \SimpleXMLElement|false
     */
    public function getInvoiceXml(string $idNum, string $lang = 'UK')
    {
        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <hotel_acc_RQ>
            <usr>' . htmlspecialchars($this->httpClient->getApiUser()) . '</usr>
            <psw>' . htmlspecialchars($this->httpClient->getApiPassword()) . '</psw>
            <IdNum>' . htmlspecialchars($idNum) . '</IdNum>
        </hotel_acc_RQ>';

        return $this->callApiAndParse('hotel_acc_RQ', $xml, $lang);
    }

    /**
     * 10. spo - EB (Early booking), extras and other discounts
     *
     * @return \SimpleXMLElement|false
     */
    public function getSpecialOffers(string $hotelId, string $packageName = '', string $lang = 'UK')
    {
        $packageXml = $packageName ? '<PackageName>' . htmlspecialchars($packageName) . '</PackageName>' : '';

        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <spo>
            <usr>' . htmlspecialchars($this->httpClient->getApiUser()) . '</usr>
            <psw>' . htmlspecialchars($this->httpClient->getApiPassword()) . '</psw>
            <IdHotel>' . htmlspecialchars($hotelId) . '</IdHotel>
            ' . $packageXml . '
        </spo>';

        return $this->callApiAndParse('spo', $xml, $lang);
    }

    /**
     * 13. priceinfo - Season prices request XML
     *
     * @return \SimpleXMLElement|false
     */
    public function getPriceInfo(string $hotelId, string $packageName, string $lang = 'UK')
    {
        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <priceinfo>
            <usr>' . htmlspecialchars($this->httpClient->getApiUser()) . '</usr>
            <psw>' . htmlspecialchars($this->httpClient->getApiPassword()) . '</psw>
            <IdHotel>' . htmlspecialchars($hotelId) . '</IdHotel>
            <PackageName>' . htmlspecialchars($packageName) . '</PackageName>
        </priceinfo>';

        return $this->callApiAndParse('priceinfo', $xml, $lang);
    }

    /**
     * 14. list_invoices - List Invoices
     *
     * @return \SimpleXMLElement|false
     */
    public function listInvoices(string $arrFrom = '', string $arrTo = '', string $lang = 'UK')
    {
        $arrFromXml = $arrFrom ? '<ArrFrom>' . htmlspecialchars($arrFrom) . '</ArrFrom>' : '';
        $arrToXml = $arrTo ? '<ArrTo>' . htmlspecialchars($arrTo) . '</ArrTo>' : '';

        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <list_invoices>
            <usr>' . htmlspecialchars($this->httpClient->getApiUser()) . '</usr>
            <psw>' . htmlspecialchars($this->httpClient->getApiPassword()) . '</psw>
            ' . $arrFromXml . '
            ' . $arrToXml . '
        </list_invoices>';

        return $this->callApiAndParse('list_invoices', $xml, $lang);
    }

    /**
     * 15. resinfo - Reservations Info
     *
     * @return \SimpleXMLElement|false
     */
    public function getReservationInfo(string $idNum = '', string $confirmAgency = '', string $lang = 'UK')
    {
        $searchXml = $idNum ? '<IdNum>' . htmlspecialchars($idNum) . '</IdNum>' :
                              '<ConfirmAgency>' . htmlspecialchars($confirmAgency) . '</ConfirmAgency>';

        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <resinfo>
            <usr>' . htmlspecialchars($this->httpClient->getApiUser()) . '</usr>
            <psw>' . htmlspecialchars($this->httpClient->getApiPassword()) . '</psw>
            ' . $searchXml . '
        </resinfo>';

        return $this->callApiAndParse('resinfo', $xml, $lang);
    }

    /**
     * 16. resort_list - Destinations List
     *
     * @return \SimpleXMLElement|false
     */
    public function getResortList(string $country = 'BULGARIA', string $lang = 'UK')
    {
        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <resort_list>
            <Country>' . htmlspecialchars($country) . '</Country>
        </resort_list>';

        return $this->callApiAndParse('resort_list', $xml, $lang);
    }

    /**
     * Get room prices for an entire resort (much more efficient than per-hotel)
     *
     * @return \SimpleXMLElement|false
     */
    public function getRoomPriceByResort(array $params)
    {
        $resort = $params['resort'] ?? '';
        $checkIn = $params['check_in'] ?? '';
        $checkOut = $params['check_out'] ?? '';
        $adultsCount = intval($params['adults'] ?? 2);
        $boardId = $params['board_id'] ?? '';

        $adultAges = $params['adult_ages'] ?? [];
        $adultsXml = '';
        for ($i = 0; $i < $adultsCount; $i++) {
            $age = isset($adultAges[$i]) ? intval($adultAges[$i]) : 33;
            $adultsXml .= '<Age>' . $age . '</Age>';
        }

        $childrenXml = '';
        if (!empty($params['children']) && is_array($params['children'])) {
            foreach ($params['children'] as $age) {
                $childrenXml .= '<Age>' . intval($age) . '</Age>';
            }
        }

        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <room_price>
            <usr>' . htmlspecialchars($this->httpClient->getApiUser()) . '</usr>
            <psw>' . htmlspecialchars($this->httpClient->getApiPassword()) . '</psw>
            <IdHotel></IdHotel>
            <Resort>' . htmlspecialchars($resort) . '</Resort>
            <IdRoom></IdRoom>
            <IdBoard>' . htmlspecialchars($boardId) . '</IdBoard>
            <IdExtBoard></IdExtBoard>
            <IdStar></IdStar>
            <CheckIn>' . htmlspecialchars($checkIn) . '</CheckIn>
            <CheckOut>' . htmlspecialchars($checkOut) . '</CheckOut>
            <Currency>EUR</Currency>
            <Adt>' . $adultsXml . '</Adt>
            <Chd>' . $childrenXml . '</Chd>
            <Remark>Yes</Remark>
            <Important>Yes</Important>
        </room_price>';

        $this->lastRequest = $xml;
        $this->lastRequestFormatted = [
            'resort' => $resort,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'adults' => $adultsCount
        ];

        return $this->callApiAndParse('room_price', $xml, $params['lang'] ?? 'UK');
    }

    /**
     * Get room prices for an entire resort - RAW response (no XML parsing)
     * Much faster for large responses (800KB+) like GOLDEN SANDS
     *
     * @return string|false Raw XML response or false
     */
    public function getRoomPriceByResortRaw(array $params)
    {
        $resort = $params['resort'] ?? '';
        $checkIn = $params['check_in'] ?? '';
        $checkOut = $params['check_out'] ?? '';
        $adultsCount = intval($params['adults'] ?? 2);
        if ($adultsCount < 1) {
            $adultsCount = 2;
        }
        $boardId = $params['board_id'] ?? '';

        $childrenXml = '';
        if (!empty($params['children']) && is_array($params['children'])) {
            foreach ($params['children'] as $age) {
                $childrenXml .= '<Age>' . intval($age) . '</Age>';
            }
        }

        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <room_price>
            <usr>' . htmlspecialchars($this->httpClient->getApiUser()) . '</usr>
            <psw>' . htmlspecialchars($this->httpClient->getApiPassword()) . '</psw>
            <IdHotel></IdHotel>
            <PackageName></PackageName>
            <Resort>' . htmlspecialchars($resort) . '</Resort>
            <IdRoom></IdRoom>
            <IdBoard>' . htmlspecialchars($boardId) . '</IdBoard>
            <IdExtBoard></IdExtBoard>
            <IdStar></IdStar>
            <CheckIn>' . htmlspecialchars($checkIn) . '</CheckIn>
            <CheckOut>' . htmlspecialchars($checkOut) . '</CheckOut>
            <Currency>EUR</Currency>
            <Adt>' . $adultsCount . '</Adt>
            <Chd>' . $childrenXml . '</Chd>
            <Remark>No</Remark>
            <Important>No</Important>
        </room_price>';

        $this->lastRequest = $xml;

        $response = $this->callApi('room_price', $xml, $params['lang'] ?? 'UK');

        return $response;
    }

    // Transfer functions (17-20) not implemented - not needed for hotel booking

    /**
     * 21. hotel_quota_add - Allotments additional
     *
     * @return \SimpleXMLElement|false
     */
    public function getHotelQuotaAdditional(string $hotelId, string $roomId, string $checkIn, string $checkOut)
    {
        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <hotel_quota>
            <IdHotel>' . htmlspecialchars($hotelId) . '</IdHotel>
            <IdRoom>' . htmlspecialchars($roomId) . '</IdRoom>
            <CheckIn>' . htmlspecialchars($checkIn) . '</CheckIn>
            <CheckOut>' . htmlspecialchars($checkOut) . '</CheckOut>
        </hotel_quota>';

        return $this->callApiAndParse('hotel_quota_add', $xml);
    }

    /**
     * 22. hotel_request - Request alternatives when no prices available from room_price
     *
     * @return \SimpleXMLElement|array|false
     */
    public function createHotelRequest(array $requestData, string $lang = 'UK', bool $returnXml = false)
    {
        $guestsXml = '';
        if (!empty($requestData['guests'])) {
            foreach ($requestData['guests'] as $guest) {
                $guestsXml .= '
<Guests>
  <IdGuest>' . htmlspecialchars($guest['id'] ?? 1) . '</IdGuest>
  <Name>' . htmlspecialchars($guest['name'] ?? '') . '</Name>
  <BirthDay>' . htmlspecialchars($guest['birthday'] ?? '') . '</BirthDay>
  <Age>' . intval($guest['age'] ?? 30) . '</Age>
</Guests>';
            }
        }

        $roomAccXml = '';
        if (!empty($requestData['room_guests'])) {
            foreach ($requestData['room_guests'] as $roomGuest) {
                $roomAccXml .= '
<room_acc>
  <IdGuest>' . htmlspecialchars($roomGuest['id'] ?? 1) . '</IdGuest>
  <Name>' . htmlspecialchars($roomGuest['name'] ?? '') . '</Name>
</room_acc>';
            }
        }

        $xml = '<?xml version="1.0" encoding="windows-1251"?>
<hotel_request>
  <usr>' . htmlspecialchars($this->httpClient->getApiUser()) . '</usr>
  <psw>' . htmlspecialchars($this->httpClient->getApiPassword()) . '</psw>
  <IdHotel>' . htmlspecialchars($requestData['hotel_id']) . '</IdHotel>
  <CreatedBy>' . htmlspecialchars($requestData['created_by'] ?? 'CS-Cart') . '</CreatedBy>
  <PackageName>' . htmlspecialchars($requestData['package_name'] ?? '') . '</PackageName>
  <CheckIn>' . htmlspecialchars($requestData['check_in']) . '</CheckIn>
  <CheckOut>' . htmlspecialchars($requestData['check_out']) . '</CheckOut>
' . $guestsXml . '
<hotel_acc>
  <CheckIn>' . htmlspecialchars($requestData['check_in']) . '</CheckIn>
  <CheckOut>' . htmlspecialchars($requestData['check_out']) . '</CheckOut>
  <IdRoom>' . htmlspecialchars($requestData['room_id'] ?? '') . '</IdRoom>
  <IdBoard>' . htmlspecialchars($requestData['board_id'] ?? '') . '</IdBoard>
  <IdExtBoard>' . htmlspecialchars($requestData['ext_board_id'] ?? '') . '</IdExtBoard>
  <IdStar>' . htmlspecialchars($requestData['star_rating'] ?? '') . '</IdStar>
  <Holder>' . htmlspecialchars($requestData['holder'] ?? '') . '</Holder>
  <Remark>' . htmlspecialchars($requestData['remark'] ?? '') . '</Remark>
  <Comment>' . htmlspecialchars($requestData['comment'] ?? '') . '</Comment>
' . $roomAccXml . '
</hotel_acc>
</hotel_request>';

        fn_log_event('general', 'runtime', [
            'message' => 'Novoton hotel_request Request',
            'xml' => $xml
        ]);

        $response = $this->callApi('hotel_request', $xml, $lang);

        if ($response === false) {
            $parsed = false;
        } else {
            try {
                $parsed = $this->xmlParser->parse($response);
            } catch (XmlParsingException $e) {
                $parsed = false;
            }
        }

        if ($returnXml) {
            $xmlMasked = preg_replace('/<usr>.*?<\/usr>/', '<usr>*****</usr>', $xml);
            $xmlMasked = preg_replace('/<psw>.*?<\/psw>/', '<psw>*****</psw>', $xmlMasked);

            return [
                'xml_sent' => $xmlMasked,
                'xml_response' => $response,
                'parsed' => $parsed,
                'id_num' => $parsed && isset($parsed->IdNum) ? (string)$parsed->IdNum : null
            ];
        }

        return $parsed;
    }

    /**
     * Generate hotel_request XML without sending (for preview/testing)
     */
    public function generateHotelRequestXml(array $requestData): string
    {
        $guestsXml = '';
        if (!empty($requestData['guests'])) {
            foreach ($requestData['guests'] as $guest) {
                $guestsXml .= '
<Guests>
  <IdGuest>' . htmlspecialchars($guest['id'] ?? 1) . '</IdGuest>
  <Name>' . htmlspecialchars($guest['name'] ?? '') . '</Name>
  <BirthDay>' . htmlspecialchars($guest['birthday'] ?? '') . '</BirthDay>
  <Age>' . intval($guest['age'] ?? 30) . '</Age>
</Guests>';
            }
        }

        $roomAccXml = '';
        if (!empty($requestData['room_guests'])) {
            foreach ($requestData['room_guests'] as $roomGuest) {
                $roomAccXml .= '
<room_acc>
  <IdGuest>' . htmlspecialchars($roomGuest['id'] ?? 1) . '</IdGuest>
  <Name>' . htmlspecialchars($roomGuest['name'] ?? '') . '</Name>
</room_acc>';
            }
        }

        $xml = '<?xml version="1.0" encoding="windows-1251"?>
<hotel_request>
  <usr>' . htmlspecialchars($this->httpClient->getApiUser()) . '</usr>
  <psw>' . htmlspecialchars($this->httpClient->getApiPassword()) . '</psw>
  <IdHotel>' . htmlspecialchars($requestData['hotel_id']) . '</IdHotel>
  <CreatedBy>' . htmlspecialchars($requestData['created_by'] ?? 'CS-Cart') . '</CreatedBy>
  <PackageName>' . htmlspecialchars($requestData['package_name'] ?? '') . '</PackageName>
  <CheckIn>' . htmlspecialchars($requestData['check_in']) . '</CheckIn>
  <CheckOut>' . htmlspecialchars($requestData['check_out']) . '</CheckOut>
' . $guestsXml . '
<hotel_acc>
  <CheckIn>' . htmlspecialchars($requestData['check_in']) . '</CheckIn>
  <CheckOut>' . htmlspecialchars($requestData['check_out']) . '</CheckOut>
  <IdRoom>' . htmlspecialchars($requestData['room_id'] ?? '') . '</IdRoom>
  <IdBoard>' . htmlspecialchars($requestData['board_id'] ?? '') . '</IdBoard>
  <IdExtBoard>' . htmlspecialchars($requestData['ext_board_id'] ?? '') . '</IdExtBoard>
  <IdStar>' . htmlspecialchars($requestData['star_rating'] ?? '') . '</IdStar>
  <Holder>' . htmlspecialchars($requestData['holder'] ?? '') . '</Holder>
  <Remark>' . htmlspecialchars($requestData['remark'] ?? '') . '</Remark>
  <Comment>' . htmlspecialchars($requestData['comment'] ?? '') . '</Comment>
' . $roomAccXml . '
</hotel_acc>
</hotel_request>';

        // Mask credentials
        $xmlMasked = preg_replace('/<usr>.*?<\/usr>/', '<usr>*****</usr>', $xml);
        $xmlMasked = preg_replace('/<psw>.*?<\/psw>/', '<psw>*****</psw>', $xmlMasked);

        return $xmlMasked;
    }

    /**
     * 23. alternative_RS - Check for available requested alternatives
     *
     * @return \SimpleXMLElement|false
     */
    public function getAlternatives(string $idNum, string $lang = 'UK')
    {
        $xml = '<?xml version="1.0" encoding="windows-1251"?>
<alternative_RS>
  <usr>' . htmlspecialchars($this->httpClient->getApiUser()) . '</usr>
  <psw>' . htmlspecialchars($this->httpClient->getApiPassword()) . '</psw>
  <IdNum>' . htmlspecialchars($idNum) . '</IdNum>
</alternative_RS>';

        fn_log_event('general', 'runtime', [
            'message' => 'Novoton alternative_RS Request',
            'xml' => $xml
        ]);

        return $this->callApiAndParse('alternative_RS', $xml, $lang);
    }

    /**
     * 24. kickback_RS - Check for kickback (commission)
     *
     * @return \SimpleXMLElement|false
     */
    public function getKickbackInfo(string $lang = 'UK')
    {
        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <kickback_RS>
            <usr>' . htmlspecialchars($this->httpClient->getApiUser()) . '</usr>
            <psw>' . htmlspecialchars($this->httpClient->getApiPassword()) . '</psw>
        </kickback_RS>';

        return $this->callApiAndParse('kickback_RS', $xml, $lang);
    }

    /**
     * 25. offers_update - Updated/New Offers
     *
     * @return \SimpleXMLElement|false
     */
    public function getOffersUpdate(string $dateTime, string $country = '', string $resort = '', string $hotel = '')
    {
        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <offers_update>
            <usr>' . htmlspecialchars($this->httpClient->getApiUser()) . '</usr>
            <psw>' . htmlspecialchars($this->httpClient->getApiPassword()) . '</psw>
            <DateTime>' . htmlspecialchars($dateTime) . '</DateTime>
            <Country>' . htmlspecialchars($country) . '</Country>
            <Resort>' . htmlspecialchars($resort) . '</Resort>
            <Hotel>' . htmlspecialchars($hotel) . '</Hotel>
        </offers_update>';

        return $this->callApiAndParse('offers_update', $xml);
    }

    /**
     * 26. list_facilities - List all facilities
     *
     * @return \SimpleXMLElement|false
     */
    public function listFacilities()
    {
        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <list_facilities>
        </list_facilities>';

        return $this->callApiAndParse('list_facilities', $xml);
    }

    /**
     * 27. hotel_facilities - Hotel facilities
     *
     * @return \SimpleXMLElement|false
     */
    public function getHotelFacilities(string $hotelId)
    {
        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <hotel_facilities>
            <IdHotel>' . htmlspecialchars($hotelId) . '</IdHotel>
        </hotel_facilities>';

        return $this->callApiAndParse('hotel_facilities', $xml);
    }
}

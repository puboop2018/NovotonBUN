<?php
/**
 * Novoton API Integration Class
 * Path: app/addons/novoton_holidays/src/NovotonApi.php
 */

namespace Tygh\Addons\NovotonHolidays;

use Tygh\Registry;

class NovotonApi
{
    private $apiUrl;
    private $apiKey;
    private $apiId;
    private $apiUser;
    private $apiPassword;
    private $commission;
    private $roundPrices;
    
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

    // Retry configuration (loaded from addon settings)
    private $maxRetries;
    private $retryDelayMs;
    private $retryMultiplier;

    // Circuit breaker configuration (loaded from addon settings)
    private $circuitBreakerThreshold;
    private $circuitBreakerTimeout;
    private static $failureCount = 0;
    private static $lastFailureTime = 0;
    private static $circuitOpen = false;

    public function __construct()
    {
        $settings = Registry::get('addons.novoton_holidays') ?? [];

        $this->apiUrl = !empty($settings['api_url']) ? $settings['api_url'] : 'b2b.allinclusivebg.com';
        $this->apiKey = !empty($settings['api_key']) ? $settings['api_key'] : 'TEST-TEST-TEST-TEST-TEST';
        $this->apiId = !empty($settings['api_id']) ? $settings['api_id'] : '713';
        $this->apiUser = !empty($settings['api_user']) ? $settings['api_user'] : 'EHROM117';
        $this->apiPassword = !empty($settings['api_password']) ? $settings['api_password'] : 'EUP359YJX';
        $this->commission = floatval($settings['commission'] ?? 8);
        $this->roundPrices = $settings['round_prices'] ?? 'Y';

        // Initialize cache service
        $this->enableCache = ($settings['enable_api_cache'] ?? 'Y') === 'Y';
        if ($this->enableCache) {
            $this->cache = new \Tygh\Addons\NovotonHolidays\Services\CacheService('file');
        }

        // Load API resilience settings (with defaults)
        $this->maxRetries = (int)($settings['api_max_retries'] ?? 3);
        $this->retryDelayMs = (int)($settings['api_retry_delay_ms'] ?? 1000);
        $this->retryMultiplier = (int)($settings['api_retry_multiplier'] ?? 2);
        $this->circuitBreakerThreshold = (int)($settings['circuit_breaker_threshold'] ?? 5);
        $this->circuitBreakerTimeout = (int)($settings['circuit_breaker_timeout'] ?? 60);
    }
    
    /**
     * Get cached response or null
     * Only caches live API calls (room_price, hotel_quota, search)
     * 
     * @param string $function API function name
     * @param string $cacheKey Cache key
     * @return mixed|null Cached response or null
     */
    private function getFromCache(string $function, string $cacheKey)
    {
        // Skip cache for functions that use database storage
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
     * 
     * @param string $function API function name
     * @param string $cacheKey Cache key
     * @param mixed $data Data to cache
     */
    private function saveToCache(string $function, string $cacheKey, $data): void
    {
        // Skip cache for functions that use database storage
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
     * 
     * @param string $function API function
     * @param array $params Request parameters
     * @return string Cache key
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

    /**
     * Check if circuit breaker allows requests
     *
     * @return bool True if requests are allowed
     */
    private function isCircuitClosed(): bool
    {
        if (!self::$circuitOpen) {
            return true;
        }

        // Check if timeout has passed
        if (time() - self::$lastFailureTime >= $this->circuitBreakerTimeout) {
            // Half-open state - allow one request to test
            self::$circuitOpen = false;
            return true;
        }

        return false;
    }

    /**
     * Record API failure for circuit breaker
     */
    private function recordFailure(): void
    {
        self::$failureCount++;
        self::$lastFailureTime = time();

        if (self::$failureCount >= $this->circuitBreakerThreshold) {
            self::$circuitOpen = true;
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton API circuit breaker OPENED after ' . self::$failureCount . ' failures',
                'threshold' => $this->circuitBreakerThreshold,
                'timeout_seconds' => $this->circuitBreakerTimeout
            ]);
        }
    }

    /**
     * Record API success - reset circuit breaker
     */
    private function recordSuccess(): void
    {
        if (self::$failureCount > 0 || self::$circuitOpen) {
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton API circuit breaker RESET after success',
                'previous_failures' => self::$failureCount
            ]);
        }
        self::$failureCount = 0;
        self::$circuitOpen = false;
    }

    /**
     * Get circuit breaker status (for monitoring)
     *
     * @return array Circuit breaker status
     */
    public function getCircuitStatus(): array
    {
        return [
            'is_open' => self::$circuitOpen,
            'failure_count' => self::$failureCount,
            'threshold' => $this->circuitBreakerThreshold,
            'last_failure' => self::$lastFailureTime > 0 ? date('Y-m-d H:i:s', self::$lastFailureTime) : null,
            'timeout_seconds' => $this->circuitBreakerTimeout,
            'seconds_until_retry' => self::$circuitOpen ? max(0, $this->circuitBreakerTimeout - (time() - self::$lastFailureTime)) : 0
        ];
    }

    /**
     * Manually reset circuit breaker (for admin use)
     * Call this to force-reset the circuit breaker state
     */
    public function resetCircuitBreaker(): void
    {
        $wasOpen = self::$circuitOpen;
        $previousFailures = self::$failureCount;

        self::$failureCount = 0;
        self::$lastFailureTime = 0;
        self::$circuitOpen = false;

        fn_log_event('general', 'runtime', [
            'message' => 'Novoton API circuit breaker manually reset',
            'was_open' => $wasOpen,
            'previous_failures' => $previousFailures
        ]);
    }

    /**
     * Send POST request to Novoton API with retry and circuit breaker
     */
    private function sendRequest($function, $xml = '', $lang = 'UK')
    {
        // Check circuit breaker
        if (!$this->isCircuitClosed()) {
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton API request blocked by circuit breaker',
                'function' => $function,
                'seconds_until_retry' => $this->circuitBreakerTimeout - (time() - self::$lastFailureTime)
            ]);
            $this->lastError = 'Circuit breaker open - API temporarily unavailable';
            return false;
        }

        $url = 'http://' . $this->apiUrl . '/index.php';
        $key = $this->apiKey;
        $id = $this->apiId;

        $postData = [
            'fn' => $function,
            'key' => $key,
            'id' => $id,
            'xml' => $xml,
            'lang' => $lang
        ];

        $lastError = '';
        $lastHttpCode = 0;
        $response = false;

        // Retry loop with exponential backoff
        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            $ch = curl_init();

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
            curl_setopt($ch, CURLOPT_REFERER, "Referer: http://booking.allinclusive.bg");
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 60);
            curl_setopt($ch, CURLOPT_TIMEOUT, 120);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

            $response = curl_exec($ch);
            $lastHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $lastError = curl_error($ch);

            curl_close($ch);

            // Success - break retry loop
            if (!$lastError && $lastHttpCode >= 200 && $lastHttpCode < 300) {
                $this->recordSuccess();
                break;
            }

            // Determine if error is retryable
            $isRetryable = $this->isRetryableError($lastError, $lastHttpCode);

            if ($isRetryable && $attempt < $this->maxRetries) {
                // Calculate delay with exponential backoff
                $delayMs = $this->retryDelayMs * pow($this->retryMultiplier, $attempt - 1);

                fn_log_event('general', 'runtime', [
                    'message' => "Novoton API retry attempt $attempt/$this->maxRetries",
                    'function' => $function,
                    'error' => $lastError,
                    'http_code' => $lastHttpCode,
                    'delay_ms' => $delayMs
                ]);

                usleep($delayMs * 1000); // Convert to microseconds
            } else if (!$isRetryable) {
                // Non-retryable error - break immediately
                break;
            }
        }

        // Store for debugging
        $this->lastHttpCode = $lastHttpCode;
        $this->lastError = $lastError;
        $this->lastResponseRaw = $response;

        if ($lastError || $lastHttpCode < 200 || $lastHttpCode >= 300) {
            $this->recordFailure();
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton API Error after ' . min($attempt, $this->maxRetries) . ' attempts: ' . $lastError,
                'function' => $function,
                'http_code' => $lastHttpCode,
                'circuit_status' => $this->getCircuitStatus()
            ]);
            return false;
        }

        // Clean XML response
        $response = $this->xmlEntities($response);
        $this->lastResponse = $response;

        return $response;
    }

    /**
     * Determine if an error is retryable
     *
     * @param string $error cURL error message
     * @param int $httpCode HTTP status code
     * @return bool True if error should be retried
     */
    private function isRetryableError(string $error, int $httpCode): bool
    {
        // Network errors are retryable
        $retryableErrors = [
            'Connection timed out',
            'Connection refused',
            'Could not resolve host',
            'Operation timed out',
            'SSL connection timeout',
            'Network is unreachable',
            'Empty reply from server'
        ];

        foreach ($retryableErrors as $retryable) {
            if (stripos($error, $retryable) !== false) {
                return true;
            }
        }

        // Server errors (5xx) are retryable
        if ($httpCode >= 500 && $httpCode < 600) {
            return true;
        }

        // Rate limiting (429) is retryable
        if ($httpCode === 429) {
            return true;
        }

        // 0 usually means connection failed
        if ($httpCode === 0 && !empty($error)) {
            return true;
        }

        return false;
    }
    
    /**
     * Clean XML entities (from working code)
     * Note: Only encode bare ampersands that would break XML parsing
     * Do NOT remove ampersands inside CDATA sections (they're valid there)
     */
    private function xmlEntities($string)
    {
        if (empty($string)) {
            return $string;
        }
        
        // For very large responses (>500KB), be more conservative to avoid memory issues
        $is_large = strlen($string) > 500000;
        
        // Only replace bare & that are not part of valid entities
        // Pattern: & not followed by amp; or lt; or gt; or quot; or apos; or #
        // For large strings, limit number of replacements
        if ($is_large) {
            // Quick check if there are any bare ampersands at all
            if (strpos($string, '&') !== false && strpos($string, '&amp;') === false) {
                $string = preg_replace('/&(?!amp;|lt;|gt;|quot;|apos;|#)/', '&amp;', $string, 1000);
            }
        } else {
            $string = preg_replace('/&(?!amp;|lt;|gt;|quot;|apos;|#)/', '&amp;', $string);
        }
        
        // Handle plus signs in URLs (these appear in IdRoom values like "DBL 2+0")
        // The API returns these as literal + which is valid in XML
        // but we need %2b for URL encoding when passing to forms
        // NOTE: Don't do this replacement here - it corrupts the XML
        // The URL encoding should happen when building URLs, not in XML parsing
        // $string = str_replace('+', '%2b', $string);
        
        return $string;
    }

    /**
     * Parse XML response
     */
    private function parseXml($xmlString)
    {
        if (empty($xmlString)) {
            fn_log_event('general', 'runtime', [
                'message' => 'XML Parse Error - Empty response',
                'raw_response' => '(empty)'
            ]);
            return false;
        }
        
        libxml_use_internal_errors(true);
        
        // For large responses, use LIBXML options to handle better
        $options = LIBXML_NOCDATA | LIBXML_NONET;
        if (strlen($xmlString) > 500000) { // > 500KB
            // Big mode - compact and don't preserve whitespace
            $options |= LIBXML_COMPACT | LIBXML_NOBLANKS;
        }
        
        $xml = simplexml_load_string($xmlString, 'SimpleXMLElement', $options);
        
        if ($xml === false) {
            $errors = libxml_get_errors();
            $error_messages = [];
            foreach ($errors as $err) {
                $error_messages[] = "Line {$err->line}: {$err->message}";
            }
            fn_log_event('general', 'runtime', [
                'message' => 'XML Parse Error',
                'errors' => implode('; ', array_slice($error_messages, 0, 5)),
                'response_size' => strlen($xmlString),
                'raw_response_first_500' => substr($xmlString, 0, 500)
            ]);
            libxml_clear_errors();
            return false;
        }
        
        return $xml;
    }

    /**
     * Apply commission and rounding to price
     */
    public function applyCommission($price)
    {
        $finalPrice = $price * (1 + ($this->commission / 100));
        
        if ($this->roundPrices == 'Y') {
            $finalPrice = round($finalPrice);
        }
        
        return $finalPrice;
    }

    // ========== API FUNCTIONS ==========

    /**
     * 1. hotel_list - List with hotel names
     * Per API docs: use % as wildcard (e.g., <Hotel>%</Hotel> for all hotels)
     * Pass '%' or empty string for wildcard search
     */
    public function getHotelList($country = '%', $city = '%', $hotel = '%', $hotelType = '%')
    {
        // Use % as wildcard per API documentation
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
        
        $response = $this->sendRequest('hotel_list', $xml);
        return $this->parseXml($response);
    }

    /**
     * 2. hotelinfo - Information for hotel services
     */
    public function getHotelInfo($hotelId, $lang = 'UK')
    {
        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <hotelinfo>
            <IdHotel>' . htmlspecialchars($hotelId) . '</IdHotel>
        </hotelinfo>';
        
        $response = $this->sendRequest('hotelinfo', $xml, $lang);
        return $this->parseXml($response);
    }

    /**
     * 3. room_price - Accommodation prices (REAL-TIME RATES)
     *
     * Per API docs:
     * - IdHotel OR Resort - use one, not both (we use IdHotel for hotel-specific searches)
     * - PackageName - leave empty to get ALL packages, or specify to filter
     * - IdRoom - leave empty to get ALL room types
     * - IdBoard - leave empty to get ALL board types
     * - IdExtBoard - extended board options (leave empty)
     * - IdStar - star rating filter (leave empty)
     * - CheckIn/CheckOut in YYYY-MM-DD format
     * - Currency - EUR
     * - Adt - number of adults (integer)
     * - Chd - children ages as <Age> elements, e.g. <Chd><Age>2</Age><Age>7</Age></Chd>
     * - Remark=Yes and Important=Yes to get additional booking info
     */
    public function getRoomPrice($params)
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
        // Cache stores raw XML string, not SimpleXMLElement
        if (!$bypassCache) {
            $cachedXml = $this->getFromCache('room_price', $cacheKey);
            if ($cachedXml !== null && is_string($cachedXml)) {
                // Store for debugging
                $this->lastResponse = $cachedXml;
                return $this->parseXml($cachedXml);
            }
        }
        
        // Room ID and Board ID - empty = return all combinations
        $roomId = $params['room_id'] ?? '';
        $boardId = $params['board_id'] ?? '';

        // Keep dates in YYYY-MM-DD format
        $checkIn = $params['check_in'] ?? '';
        $checkOut = $params['check_out'] ?? '';

        // Ensure adults count is at least 1
        $adultsCount = intval($params['adults'] ?? 2);
        if ($adultsCount < 1) {
            $adultsCount = 2;  // Default to 2 adults
        }

        // Build children ages XML - API expects <Age> elements inside <Chd>
        $childrenXml = '';
        if (!empty($params['children']) && is_array($params['children'])) {
            foreach ($params['children'] as $age) {
                $childrenXml .= '<Age>' . intval($age) . '</Age>';
            }
        }

        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <room_price>
            <usr>' . htmlspecialchars($this->apiUser) . '</usr>
            <psw>' . htmlspecialchars($this->apiPassword) . '</psw>
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
        
        $response = $this->sendRequest('room_price', $xml, $params['lang'] ?? 'UK');
        
        // Store last response for debugging  
        $this->lastResponse = $response;
        
        // A73t: Log raw response for debugging
        fn_log_event('general', 'runtime', [
            'message' => 'Novoton room_price - Raw API response',
            'response_length' => strlen($response ?? ''),
            'response_first_500' => substr($response ?? '', 0, 500)
        ]);
        
        $result = $this->parseXml($response);
        
        // Save RAW XML to cache (not SimpleXMLElement) ONLY if we have valid price data
        if ($result !== null && $result !== false && !empty($response)) {
            // Verify there's actual price data before caching
            $hasPriceData = false;
            if ($result instanceof \SimpleXMLElement) {
                $prices = $result->xpath('//Price');
                $hasPriceData = !empty($prices) && count($prices) > 0;
            }
            
            if ($hasPriceData) {
                // Cache the RAW XML STRING, not the SimpleXMLElement
                $this->saveToCache('room_price', $cacheKey, $response);
            }
        }
        
        return $result;
    }
    
    /**
     * Get last API request (for debugging)
     */
    public function getLastRequest()
    {
        return $this->lastRequest ?? '';
    }
    
    /**
     * Get last API response (for debugging)
     */
    public function getLastResponse()
    {
        return $this->lastResponse ?? '';
    }
    
    /**
     * Get last request formatted params (for debugging)
     */
    public function getLastRequestFormatted()
    {
        return $this->lastRequestFormatted ?? [];
    }
    
    /**
     * Get last error (for debugging)
     */
    public function getLastError()
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
    public function getLastResponseRaw()
    {
        return $this->lastResponseRaw ?? '';
    }

    /**
     * 4. hotel_quota - Free allotments (AVAILABILITY)
     * 
     * Per API docs:
     * - IdHotel from hotel_list->hotelinfo->IdHotel
     * - IdRoom from hotel->rooms->IdRoom (optional - if empty, returns all rooms)
     * - CheckIn/CheckOut in YYYY-MM-DD format
     * 
     * Response structure (when IdRoom is empty):
     * <hotel_quota>
     *   <IdHotel>288</IdHotel>
     *   <Package>
     *     <PackageName>HOTEL NAME</PackageName>
     *     <IdRoom>DBL 2+1</IdRoom><Quota>10</Quota>
     *     <IdRoom>SGL</IdRoom><Quota>2</Quota>
     *     ...
     *   </Package>
     * </hotel_quota>
     * 
     * @param string $hotelId Hotel ID
     * @param string $checkIn Check-in date (Y-m-d)
     * @param string $checkOut Check-out date (Y-m-d)
     * @return array Associative array of room_id => quota value
     */
    public function getHotelQuotaAll($hotelId, $checkIn, $checkOut)
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
        
        $response = $this->sendRequest('hotel_quota', $xml);
        $parsed = $this->parseXml($response);
        
        $quotaMap = [];
        
        if ($parsed && isset($parsed->Package)) {
            // Can be single Package or multiple
            $packages = is_array($parsed->Package) ? $parsed->Package : [$parsed->Package];
            
            foreach ($parsed->Package as $package) {
                // Get all IdRoom and Quota elements as arrays
                $roomIds = [];
                $quotas = [];
                
                // Convert to string and parse manually for better handling
                $packageXml = $package->asXML();
                
                // Extract all IdRoom values
                preg_match_all('/<IdRoom>([^<]+)<\/IdRoom>/', $packageXml, $roomMatches);
                preg_match_all('/<Quota>([^<]+)<\/Quota>/', $packageXml, $quotaMatches);
                
                if (!empty($roomMatches[1]) && !empty($quotaMatches[1])) {
                    for ($i = 0; $i < count($roomMatches[1]); $i++) {
                        $roomId = trim($roomMatches[1][$i]);
                        $quota = isset($quotaMatches[1][$i]) ? trim($quotaMatches[1][$i]) : '0';
                        
                        // Store the quota - if room appears multiple times, keep the minimum
                        if (!isset($quotaMap[$roomId])) {
                            $quotaMap[$roomId] = $quota;
                        } else {
                            // Keep the minimum quota if room appears in multiple packages
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
        
        // Log for debugging
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
     * Per API docs:
     * - IdHotel from hotel_list->hotelinfo->IdHotel
     * - IdRoom from hotel->rooms->IdRoom
     * - CheckIn/CheckOut in YYYY-MM-DD format
     * 
     * Response: <Quota></Quota> - returns smallest available count for period
     */
    public function getHotelQuota($hotelId, $roomId, $checkIn, $checkOut, $roomType = '')
    {
        // Keep dates in YYYY-MM-DD format (per API docs)
        $roomTypeXml = $roomType ? '<IdRoomType>' . htmlspecialchars($roomType) . '</IdRoomType>' : '';
        
        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <hotel_quota>
            <IdHotel>' . htmlspecialchars($hotelId) . '</IdHotel>
            <IdRoom>' . htmlspecialchars($roomId) . '</IdRoom>
            ' . $roomTypeXml . '
            <CheckIn>' . htmlspecialchars($checkIn) . '</CheckIn>
            <CheckOut>' . htmlspecialchars($checkOut) . '</CheckOut>
        </hotel_quota>';
        
        $response = $this->sendRequest('hotel_quota', $xml);
        
        // Log the raw response for debugging
        if (defined('NOVOTON_DEBUG') || !empty($_REQUEST['debug'])) {
            fn_log_event('general', 'runtime', [
                'message' => "hotel_quota response for {$hotelId}/{$roomId}: " . substr($response, 0, 500)
            ]);
        }
        
        return $this->parseXml($response);
    }
    
    /**
     * Search availability using frmsearch API endpoint
     * This is the B2B API search function that searches by hotel name
     * 
     * @param array $params Search parameters:
     *   - country: Country name (e.g., 'BULGARIA')
     *   - city: City name (e.g., 'GOLDEN SANDS')
     *   - hotel: Hotel name (e.g., 'ARENA MAR')
     *   - check_in: Arrival date (Y-m-d format)
     *   - check_out: Departure date (Y-m-d format)
     *   - adults: Number of adults (default 2)
     * @return array|false Array of available rooms/prices or false on error
     */
    public function searchAvailability($params)
    {
        // Build adult ages XML (default age 33 for each adult)
        $adultsCount = intval($params['adults'] ?? 2);
        $adultAges = $params['adult_ages'] ?? [];
        $adultsXml = '';
        for ($i = 0; $i < $adultsCount; $i++) {
            $age = isset($adultAges[$i]) ? intval($adultAges[$i]) : 33;
            $adultsXml .= '<Age>' . $age . '</Age>';
        }

        // Build XML for frmsearch - search by hotel name
        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <frmsearch>
            <usr>' . htmlspecialchars($this->apiUser) . '</usr>
            <psw>' . htmlspecialchars($this->apiPassword) . '</psw>
            <Country>' . htmlspecialchars(strtoupper($params['country'] ?? 'BULGARIA')) . '</Country>
            <City>' . htmlspecialchars(strtoupper($params['city'] ?? '')) . '</City>
            <Hotel>' . htmlspecialchars(strtoupper($params['hotel'] ?? '')) . '</Hotel>
            <Arr1>' . htmlspecialchars($params['check_in'] ?? '') . '</Arr1>
            <Dep1>' . htmlspecialchars($params['check_out'] ?? '') . '</Dep1>
            <OfferType>hotel</OfferType>
            <Adt>' . $adultsXml . '</Adt>
            <Currency>EUR</Currency>
        </frmsearch>';
        
        // Log the request for debugging
        if (defined('NOVOTON_DEBUG') || !empty($_REQUEST['debug'])) {
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton frmsearch Request',
                'xml' => $xml,
                'params' => $params
            ]);
        }
        
        $response = $this->sendRequest('frmsearch', $xml);
        
        // Log response
        if (defined('NOVOTON_DEBUG') || !empty($_REQUEST['debug'])) {
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton frmsearch Response',
                'response' => substr($response, 0, 2000)
            ]);
        }
        
        // Parse XML response
        $result = $this->parseXml($response);
        
        if (!$result) {
            return [];
        }
        
        return $this->parseSearchResults($result, $params);
    }
    
    /**
     * Parse search results from frmsearch API
     * 
     * @param object $result SimpleXML result
     * @param array $params Original search params
     * @return array Array of results
     */
    private function parseSearchResults($result, $params)
    {
        $results = [];
        
        if (!$result) {
            return $results;
        }
        
        // Check for offers in the result
        $offers = [];
        
        // Try different possible structures
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
            
            // Skip if no price or no availability
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
        
        // If no offers found in structured format, try to extract from raw result
        if (empty($results) && $result) {
            // Convert to array and look for price data
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
        
        // Check if this looks like an offer
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
        
        // Recurse into nested arrays
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $this->extractOffersRecursive($value, $results, $params);
            }
        }
    }

    /**
     * 5. hotel_description - Description of hotel
     */
    public function getHotelDescription($hotelId, $lang = 'UK', $includePackage = false)
    {
        $packageXml = $includePackage ? '<PackageDescription>Yes</PackageDescription>' : '';
        
        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <hotel_description>
            <IdHotel>' . htmlspecialchars($hotelId) . '</IdHotel>
            ' . $packageXml . '
        </hotel_description>';
        
        $response = $this->sendRequest('hotel_description', $xml, $lang);
        return $this->parseXml($response);
    }

    /**
     * 6. hotel_images - Pictures of hotel
     */
    public function getHotelImages($hotelId, $lang = 'UK')
    {
        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <hotel_images>
            <IdHotel>' . htmlspecialchars($hotelId) . '</IdHotel>
        </hotel_images>';
        
        $response = $this->sendRequest('hotel_images', $xml, $lang);
        return $this->parseXml($response);
    }

    /**
     * 7. hotel_res_RQ - Reservation request
     * 
     * Creates a booking reservation with Novoton API
     * 
     * @param array $bookingData Contains:
     *   - hotel_id: Hotel ID
     *   - package_name: Package name (e.g., "ARENA MAR **** 2026")
     *   - check_in: Check-in date YYYY-MM-DD
     *   - check_out: Check-out date YYYY-MM-DD
     *   - discount_type: "EB" for early booking, empty otherwise
     *   - guests: Array of guests with name, birthday, age
     *   - room_id: Room type ID
     *   - board_id: Board type ID
     *   - holder: Main guest name
     *   - remark: Special requests
     *   - order_num: CS-Cart order number (optional)
     * 
     * @return SimpleXMLElement Response with IdNum, Price, Currency, Quota, Status
     */
    public function createReservation($bookingData)
    {
        // Check if test booking mode is enabled
        $settings = Registry::get('addons.novoton_holidays') ?? [];
        $isTestMode = ($settings['test_booking'] ?? 'N') === 'Y';
        
        // Set remark and comment based on test mode
        $remark = $bookingData['remark'] ?? '';
        $comment = $bookingData['comment'] ?? '';
        
        if ($isTestMode) {
            $remark = 'test reservation, do not proceed';
            $comment = 'test reservation, do not proceed';
        }
        
        // Build guests XML - ALL guests for ALL rooms
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
            // Multi-room: Build multiple <hotel_acc> blocks
            $guestIdCounter = 1;
            foreach ($rooms as $roomIdx => $roomData) {
                $roomGuests = $roomData['guests'] ?? [];
                
                // Build room_acc for this room's guests
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
            // Single room: Original behavior
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
        
        // Determine discount type
        $discountType = $bookingData['discount_type'] ?? '';
        
        $xml = '<?xml version="1.0" encoding="windows-1251"?>
<hotel_res_RQ>
    <usr>' . htmlspecialchars($this->apiUser) . '</usr>
    <psw>' . htmlspecialchars($this->apiPassword) . '</psw>
    <IdHotel>' . htmlspecialchars($bookingData['hotel_id']) . '</IdHotel>
    <CreatedBy>CS-Cart</CreatedBy>
    <PackageName>' . htmlspecialchars($bookingData['package_name'] ?? '') . '</PackageName>
    <CheckIn>' . htmlspecialchars($bookingData['check_in']) . '</CheckIn>
    <CheckOut>' . htmlspecialchars($bookingData['check_out']) . '</CheckOut>
    <DiscountType>' . htmlspecialchars($discountType) . '</DiscountType>' . $guestsXml . $hotelAccXml . '
    <OrderNum>' . htmlspecialchars($bookingData['order_num'] ?? '') . '</OrderNum>
</hotel_res_RQ>';
        
        // Store request for debugging
        $this->lastRequest = $xml;
        
        if (defined('NOVOTON_DEBUG') || !empty($_REQUEST['debug'])) {
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton hotel_res_RQ Request (Test Mode: ' . ($isTestMode ? 'YES' : 'NO') . ')',
                'xml' => $xml
            ]);
        }
        
        $response = $this->sendRequest('hotel_res_RQ', $xml, $bookingData['lang'] ?? 'UK');
        $this->lastResponse = $response;
        
        if (defined('NOVOTON_DEBUG') || !empty($_REQUEST['debug'])) {
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton hotel_res_RQ Response',
                'response' => $response
            ]);
        }
        
        return $this->parseXml($response);
    }

    /**
     * 8. hotel_acc_RQ_html - Request for invoice - HTML
     */
    public function getInvoiceHtml($idNum, $lang = 'UK')
    {
        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <hotel_acc_RQ_html>
            <usr>' . htmlspecialchars($this->apiUser) . '</usr>
            <psw>' . htmlspecialchars($this->apiPassword) . '</psw>
            <IdNum>' . htmlspecialchars($idNum) . '</IdNum>
        </hotel_acc_RQ_html>';
        
        $response = $this->sendRequest('hotel_acc_RQ_html', $xml, $lang);
        return $response; // Returns HTML directly
    }

    /**
     * 9. hotel_acc_RQ - Request for invoice - XML
     */
    public function getInvoiceXml($idNum, $lang = 'UK')
    {
        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <hotel_acc_RQ>
            <usr>' . htmlspecialchars($this->apiUser) . '</usr>
            <psw>' . htmlspecialchars($this->apiPassword) . '</psw>
            <IdNum>' . htmlspecialchars($idNum) . '</IdNum>
        </hotel_acc_RQ>';
        
        $response = $this->sendRequest('hotel_acc_RQ', $xml, $lang);
        return $this->parseXml($response);
    }

    /**
     * 10. spo - EB (Early booking), extras and other discounts
     */
    public function getSpecialOffers($hotelId, $packageName = '', $lang = 'UK')
    {
        $packageXml = $packageName ? '<PackageName>' . htmlspecialchars($packageName) . '</PackageName>' : '';
        
        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <spo>
            <usr>' . htmlspecialchars($this->apiUser) . '</usr>
            <psw>' . htmlspecialchars($this->apiPassword) . '</psw>
            <IdHotel>' . htmlspecialchars($hotelId) . '</IdHotel>
            ' . $packageXml . '
        </spo>';
        
        $response = $this->sendRequest('spo', $xml, $lang);
        return $this->parseXml($response);
    }

    /**
     * 13. priceinfo - Season prices request   XML
     */
    public function getPriceInfo($hotelId, $packageName, $lang = 'UK')
    {
        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <priceinfo>
            <usr>' . htmlspecialchars($this->apiUser) . '</usr>
            <psw>' . htmlspecialchars($this->apiPassword) . '</psw>
            <IdHotel>' . htmlspecialchars($hotelId) . '</IdHotel>
            <PackageName>' . htmlspecialchars($packageName) . '</PackageName>
        </priceinfo>';
        
        $response = $this->sendRequest('priceinfo', $xml, $lang);
        return $this->parseXml($response);
    }

    /**
     * 14. list_invoices - List Invoices
     */
    public function listInvoices($arrFrom = '', $arrTo = '', $lang = 'UK')
    {
        $arrFromXml = $arrFrom ? '<ArrFrom>' . htmlspecialchars($arrFrom) . '</ArrFrom>' : '';
        $arrToXml = $arrTo ? '<ArrTo>' . htmlspecialchars($arrTo) . '</ArrTo>' : '';
        
        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <list_invoices>
            <usr>' . htmlspecialchars($this->apiUser) . '</usr>
            <psw>' . htmlspecialchars($this->apiPassword) . '</psw>
            ' . $arrFromXml . '
            ' . $arrToXml . '
        </list_invoices>';
        
        $response = $this->sendRequest('list_invoices', $xml, $lang);
        return $this->parseXml($response);
    }

    /**
     * 15. resinfo - Reservations Info
     */
    public function getReservationInfo($idNum = '', $confirmAgency = '', $lang = 'UK')
    {
        $searchXml = $idNum ? '<IdNum>' . htmlspecialchars($idNum) . '</IdNum>' : 
                              '<ConfirmAgency>' . htmlspecialchars($confirmAgency) . '</ConfirmAgency>';
        
        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <resinfo>
            <usr>' . htmlspecialchars($this->apiUser) . '</usr>
            <psw>' . htmlspecialchars($this->apiPassword) . '</psw>
            ' . $searchXml . '
        </resinfo>';
        
        $response = $this->sendRequest('resinfo', $xml, $lang);
        return $this->parseXml($response);
    }

    /**
     * 16. resort_list - Destinations List
     */
    public function getResortList($country = 'BULGARIA', $lang = 'UK')
    {
        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <resort_list>
            <Country>' . htmlspecialchars($country) . '</Country>
        </resort_list>';
        
        $response = $this->sendRequest('resort_list', $xml, $lang);
        return $this->parseXml($response);
    }

    /**
     * Get room prices for an entire resort (much more efficient than per-hotel)
     * Use this for bulk price checking instead of iterating through each hotel
     * 
     * @param array $params [resort, check_in, check_out, adults, children, board_id]
     * @return SimpleXMLElement|false
     */
    public function getRoomPriceByResort($params)
    {
        $resort = $params['resort'] ?? '';
        $checkIn = $params['check_in'] ?? '';
        $checkOut = $params['check_out'] ?? '';
        $adultsCount = intval($params['adults'] ?? 2);
        $boardId = $params['board_id'] ?? '';

        // Build adult ages XML (default age 33 for each adult)
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
            <usr>' . htmlspecialchars($this->apiUser) . '</usr>
            <psw>' . htmlspecialchars($this->apiPassword) . '</psw>
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
        
        // Store last request for debugging
        $this->lastRequest = $xml;
        $this->lastRequestFormatted = [
            'resort' => $resort,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'adults' => $adults
        ];
        
        $response = $this->sendRequest('room_price', $xml, $params['lang'] ?? 'UK');
        
        // Store last response for debugging  
        $this->lastResponse = $response;
        
        return $this->parseXml($response);
    }
    
    /**
     * Get room prices for an entire resort - RAW response (no XML parsing)
     * Use this for bulk price checking where you only need hotel IDs
     * Much faster for large responses (800KB+) like GOLDEN SANDS
     * 
     * @param array $params [resort, check_in, check_out, adults, children, board_id]
     * @return string Raw XML response string
     */
    public function getRoomPriceByResortRaw($params)
    {
        $resort = $params['resort'] ?? '';
        $checkIn = $params['check_in'] ?? '';
        $checkOut = $params['check_out'] ?? '';
        $adultsCount = intval($params['adults'] ?? 2);
        $boardId = $params['board_id'] ?? '';

        // Build adult ages XML (default age 33 for each adult)
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
            <usr>' . htmlspecialchars($this->apiUser) . '</usr>
            <psw>' . htmlspecialchars($this->apiPassword) . '</psw>
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
            <Remark>No</Remark>
            <Important>No</Important>
        </room_price>';
        
        // Store last request for debugging
        $this->lastRequest = $xml;
        
        // Return raw response - NO XML parsing
        $response = $this->sendRequest('room_price', $xml, $params['lang'] ?? 'UK');
        $this->lastResponse = $response;
        
        return $response;
    }

    // Transfer functions (17-20) not implemented - not needed for hotel booking
    // If needed in future, refer to API documentation

    /**
     * 21. hotel_quota_add - Allotments additional
     */
    public function getHotelQuotaAdditional($hotelId, $roomId, $checkIn, $checkOut)
    {
        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <hotel_quota>
            <IdHotel>' . htmlspecialchars($hotelId) . '</IdHotel>
            <IdRoom>' . htmlspecialchars($roomId) . '</IdRoom>
            <CheckIn>' . htmlspecialchars($checkIn) . '</CheckIn>
            <CheckOut>' . htmlspecialchars($checkOut) . '</CheckOut>
        </hotel_quota>';
        
        $response = $this->sendRequest('hotel_quota_add', $xml);
        return $this->parseXml($response);
    }


    /**
     * 22. hotel_request - Request alternatives when no prices available from room_price
     * Used when room_price returns no results for the requested dates
     * Response contains IdNum which is used to check alternatives via alternative_RS (typically 24-48 hours later)
     * 
     * Note: For bookings (both Quota > 0 and Quota = 0/RQ), we use hotel_res_RQ
     */
    public function createHotelRequest($requestData, $lang = 'UK', $returnXml = false)
    {
        // Build Guests XML - using Name tag per API docs
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
        
        // Build room_acc XML for guests in room
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
  <usr>' . htmlspecialchars($this->apiUser) . '</usr>
  <psw>' . htmlspecialchars($this->apiPassword) . '</psw>
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
        
        $response = $this->sendRequest('hotel_request', $xml, $lang);
        $parsed = $this->parseXml($response);
        
        // Return both XML and response if requested
        if ($returnXml) {
            // Mask credentials in XML for storage
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
    public function generateHotelRequestXml($requestData)
    {
        // Build Guests XML
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
        
        // Build room_acc XML
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
  <usr>' . htmlspecialchars($this->apiUser) . '</usr>
  <psw>' . htmlspecialchars($this->apiPassword) . '</psw>
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
     * Used to check status of a previously submitted hotel_request
     */
    public function getAlternatives($idNum, $lang = 'UK')
    {
        $xml = '<?xml version="1.0" encoding="windows-1251"?>
<alternative_RS>
  <usr>' . htmlspecialchars($this->apiUser) . '</usr>
  <psw>' . htmlspecialchars($this->apiPassword) . '</psw>
  <IdNum>' . htmlspecialchars($idNum) . '</IdNum>
</alternative_RS>';
        
        fn_log_event('general', 'runtime', [
            'message' => 'Novoton alternative_RS Request',
            'xml' => $xml
        ]);
        
        $response = $this->sendRequest('alternative_RS', $xml, $lang);
        return $this->parseXml($response);
    }
    /**
     * 24. kickback_RS - Check for kickback (commission)
     */
    public function getKickbackInfo($lang = 'UK')
    {
        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <kickback_RS>
            <usr>' . htmlspecialchars($this->apiUser) . '</usr>
            <psw>' . htmlspecialchars($this->apiPassword) . '</psw>
        </kickback_RS>';
        
        $response = $this->sendRequest('kickback_RS', $xml, $lang);
        return $this->parseXml($response);
    }

    /**
     * 25. offers_update - Updated/New Offers
     */
    public function getOffersUpdate($dateTime, $country = '', $resort = '', $hotel = '')
    {
        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <offers_update>
            <usr>' . htmlspecialchars($this->apiUser) . '</usr>
            <psw>' . htmlspecialchars($this->apiPassword) . '</psw>
            <DateTime>' . htmlspecialchars($dateTime) . '</DateTime>
            <Country>' . htmlspecialchars($country) . '</Country>
            <Resort>' . htmlspecialchars($resort) . '</Resort>
            <Hotel>' . htmlspecialchars($hotel) . '</Hotel>
        </offers_update>';
        
        $response = $this->sendRequest('offers_update', $xml);
        return $this->parseXml($response);
    }

    /**
     * 26. list_facilities - List all facilities
     */
    public function listFacilities()
    {
        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <list_facilities>
        </list_facilities>';
        
        $response = $this->sendRequest('list_facilities', $xml);
        return $this->parseXml($response);
    }

    /**
     * 27. hotel_facilities - Hotel facilities
     */
    public function getHotelFacilities($hotelId)
    {
        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <hotel_facilities>
            <IdHotel>' . htmlspecialchars($hotelId) . '</IdHotel>
        </hotel_facilities>';
        
        $response = $this->sendRequest('hotel_facilities', $xml);
        return $this->parseXml($response);
    }
}
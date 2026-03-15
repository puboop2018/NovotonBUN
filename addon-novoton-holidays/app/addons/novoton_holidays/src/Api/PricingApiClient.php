<?php
declare(strict_types=1);
namespace Tygh\Addons\NovotonHolidays\Api;

use Tygh\Addons\TravelCore\Services\CommissionCalculator;
use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\NovotonHttpClient;
use Tygh\Addons\NovotonHolidays\NovotonXmlParser;
use Tygh\Addons\NovotonHolidays\Services\CacheService;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Exceptions\XmlParsingException;

class PricingApiClient extends ApiClientBase
{
    private CommissionCalculator $commissionCalculator;

    protected array $noCacheFunctions = [
        Constants::API_FUNCTION_PRICE_INFO,
    ];

    public function __construct(
        NovotonHttpClient $httpClient,
        NovotonXmlParser $xmlParser,
        ?CacheService $cache,
        bool $enableCache,
        CommissionCalculator $commissionCalculator
    ) {
        parent::__construct($httpClient, $xmlParser, $cache, $enableCache);
        $this->commissionCalculator = $commissionCalculator;
        $this->cacheTtl = [
            Constants::API_FUNCTION_ROOM_PRICE => ConfigProvider::getCacheTtlRoomPrice(),
        ];
    }

    public function applyCommission(float $price): float
    {
        return $this->commissionCalculator->apply($price);
    }

    /**
     * Build the room_price XML request body.
     *
     * Extracted from getRoomPrice() so it can be reused by getRoomPriceBatch().
     *
     * @param array $params Same parameters as getRoomPrice()
     * @return string XML request body
     */
    public function buildRoomPriceXml(array $params): string
    {
        $roomId = $params['room_id'] ?? '';
        $boardId = $params['board_id'] ?? '';
        $checkIn = $params['check_in'] ?? '';
        $checkOut = $params['check_out'] ?? '';
        $adultsCount = max(1, (int) ($params['adults'] ?? 2));

        $childrenXml = !empty($params['children']) && is_array($params['children'])
            ? $this->buildChildrenAgesXml($params['children'])
            : '';

        return $this->xmlHeader() . '
        <room_price>
            ' . $this->xmlCredentials() . '
            <IdHotel>' . htmlspecialchars($params['hotel_id'] ?? '') . '</IdHotel>
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
    }

    /**
     * Batch room_price requests using curl_multi.
     *
     * Checks cache for each request first, then sends uncached requests in parallel.
     * Returns both the parsed SimpleXMLElement and the raw cleaned XML for each key.
     *
     * @param array<string, array> $requestParams Keyed array: key => room_price params
     * @param int $concurrency Max simultaneous requests (default 5)
     * @return array<string, array{data: \SimpleXMLElement|false, rawXml: string}> key => result
     */
    public function getRoomPriceBatch(array $requestParams, int $concurrency = 5): array
    {
        $concurrency = max(1, min(50, $concurrency));

        if (empty($requestParams)) {
            return [];
        }

        $requests = [];
        $results = [];

        foreach ($requestParams as $key => $params) {
            $cacheParams = [
                'hotel_id' => $params['hotel_id'] ?? '',
                'room_id' => $params['room_id'] ?? '',
                'board_id' => $params['board_id'] ?? '',
                'check_in' => $params['check_in'] ?? '',
                'check_out' => $params['check_out'] ?? '',
                'adults' => $params['adults'] ?? 2,
                'children' => $params['children'] ?? [],
            ];
            $cacheKey = $this->buildCacheKey(Constants::API_FUNCTION_ROOM_PRICE, $cacheParams);

            $cachedXml = $this->getFromCache(Constants::API_FUNCTION_ROOM_PRICE, $cacheKey);
            if ($cachedXml !== null && is_string($cachedXml)) {
                try {
                    $results[$key] = [
                        'data' => $this->xmlParser->parse($cachedXml),
                        'rawXml' => $cachedXml,
                    ];
                    continue;
                } catch (XmlParsingException $e) {
                    // Cached data corrupted, fall through to API call
                }
            }

            $requests[$key] = [
                'function' => Constants::API_FUNCTION_ROOM_PRICE,
                'xml' => $this->buildRoomPriceXml($params),
                'lang' => $params['lang'] ?? 'UK',
            ];
        }

        if (!empty($requests)) {
            $rawResponses = $this->httpClient->sendBatchRequests($requests, $concurrency);

            foreach ($rawResponses as $key => $raw) {
                if ($raw === false) {
                    $results[$key] = ['data' => false, 'rawXml' => ''];
                    continue;
                }

                try {
                    $cleaned = $this->xmlParser->clean($raw);
                    $parsed = $this->xmlParser->parse($cleaned);
                    $results[$key] = ['data' => $parsed, 'rawXml' => $cleaned];

                    // Cache responses that contain prices
                    $prices = $parsed->xpath('//Price');
                    if (!empty($prices)) {
                        $params = $requestParams[$key];
                        $cacheParams = [
                            'hotel_id' => $params['hotel_id'] ?? '',
                            'room_id' => $params['room_id'] ?? '',
                            'board_id' => $params['board_id'] ?? '',
                            'check_in' => $params['check_in'] ?? '',
                            'check_out' => $params['check_out'] ?? '',
                            'adults' => $params['adults'] ?? 2,
                            'children' => $params['children'] ?? [],
                        ];
                        $cacheKey = $this->buildCacheKey(Constants::API_FUNCTION_ROOM_PRICE, $cacheParams);
                        $this->saveToCache(Constants::API_FUNCTION_ROOM_PRICE, $cacheKey, $cleaned);
                    }
                } catch (\Exception $e) {
                    $results[$key] = ['data' => false, 'rawXml' => ''];
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
    public function getRoomPrice(array $params): \SimpleXMLElement|false
    {
        $bypassCache = !empty($params['nocache']);

        $cacheParams = [
            'hotel_id' => $params['hotel_id'] ?? '',
            'room_id' => $params['room_id'] ?? '',
            'board_id' => $params['board_id'] ?? '',
            'check_in' => $params['check_in'] ?? '',
            'check_out' => $params['check_out'] ?? '',
            'adults' => $params['adults'] ?? 2,
            'children' => $params['children'] ?? [],
        ];
        $cacheKey = $this->buildCacheKey(Constants::API_FUNCTION_ROOM_PRICE, $cacheParams);

        $roomId = $params['room_id'] ?? '';
        $boardId = $params['board_id'] ?? '';
        $checkIn = $params['check_in'] ?? '';
        $checkOut = $params['check_out'] ?? '';

        $adultsCount = (int) ($params['adults'] ?? 2);
        if ($adultsCount < 1) {
            $adultsCount = 2;
        }

        // Always set lastRequestFormatted so debug/logging works even on cache hits
        $this->lastRequestFormatted = [
            'hotel_id' => $params['hotel_id'] ?? '',
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'room_id' => $roomId ?: '(empty - all rooms)',
            'board_id' => $boardId ?: '(empty - all boards)',
            'adults' => $adultsCount
        ];

        if (!$bypassCache) {
            $cachedXml = $this->getFromCache(Constants::API_FUNCTION_ROOM_PRICE, $cacheKey);
            if ($cachedXml !== null && is_string($cachedXml)) {
                $this->lastResponse = $cachedXml;
                try {
                    return $this->xmlParser->parse($cachedXml);
                } catch (XmlParsingException $e) {
                    // Cached data corrupted, fall through
                }
            }
        }

        $xml = $this->buildRoomPriceXml($params);
        $this->lastRequest = $xml;

        $response = $this->callApi(Constants::API_FUNCTION_ROOM_PRICE, $xml, $params['lang'] ?? 'UK');

        try {
            $result = $this->xmlParser->parse($response);
        } catch (XmlParsingException $e) {
            $this->lastError = $e->getMessage();
            return false;
        }

        $prices = $result->xpath('//Price');
        if (!empty($prices) && count($prices) > 0) {
            $this->saveToCache(Constants::API_FUNCTION_ROOM_PRICE, $cacheKey, $response);
        }

        return $result;
    }

    /**
     * Get room prices for an entire resort
     *
     * @return \SimpleXMLElement|false
     */
    public function getRoomPriceByResort(array $params): \SimpleXMLElement|false
    {
        $resort = $params['resort'] ?? '';
        $checkIn = $params['check_in'] ?? '';
        $checkOut = $params['check_out'] ?? '';
        $adultsCount = (int) ($params['adults'] ?? 2);
        if ($adultsCount < 1) {
            $adultsCount = 2;
        }
        $boardId = $params['board_id'] ?? '';

        $childrenXml = !empty($params['children']) && is_array($params['children'])
            ? $this->buildChildrenAgesXml($params['children'])
            : '';

        // Match the exact Novoton API room_price request format.
        // Do NOT include <PackageName> or <IdRoom> — these act as filters and
        // an empty value causes the API to return zero results.
        // The resort name is embedded raw (no CDATA, no htmlspecialchars) because
        // the Novoton API performs literal string extraction, not proper XML parsing.
        // Resort names come from the API's own resort_list, not user input.
        $xml = $this->xmlHeader() . '
        <room_price>
            ' . $this->xmlCredentials() . '
            <IdHotel></IdHotel>
            <Resort>' . $resort . '</Resort>
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

        $this->lastRequest = $xml;
        $this->lastRequestFormatted = [
            'resort' => $resort,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'adults' => $adultsCount
        ];

        $response = $this->callApi(Constants::API_FUNCTION_ROOM_PRICE, $xml, $params['lang'] ?? 'UK');

        // Resort-based responses return multiple <room_price> siblings without
        // a wrapper root element (not valid XML).  SimpleXML would only parse
        // the first element and silently discard the rest.
        // Fix: wrap all <room_price> elements in a <room_prices> root.
        $response = $this->wrapMultiRootResponse($response, 'room_price', 'room_prices');

        try {
            return $this->xmlParser->parse($response);
        } catch (XmlParsingException $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    /**
     * Wrap a response that contains multiple sibling root elements into a single root.
     *
     * Some Novoton API responses (e.g. resort-based room_price) return multiple
     * <room_price> siblings with no wrapper.  This is not valid XML and SimpleXML
     * would only parse the first element.  This method detects the condition and
     * wraps the content in a new root element.
     */
    private function wrapMultiRootResponse(string $response, string $elementName, string $wrapperName): string
    {
        // Quick check: does the response contain more than one closing tag?
        $closingTag = '</' . $elementName . '>';
        $firstPos = strpos($response, $closingTag);
        if ($firstPos === false) {
            return $response; // No element found at all
        }
        $secondPos = strpos($response, $closingTag, $firstPos + strlen($closingTag));
        if ($secondPos === false) {
            return $response; // Only one element — valid XML, no wrapping needed
        }

        // Multiple root elements detected — strip XML declaration and wrap
        $body = preg_replace('/<\?xml[^?]*\?>\s*/', '', $response);
        return '<?xml version="1.0" encoding="windows-1251"?><' . $wrapperName . '>'
            . trim($body)
            . '</' . $wrapperName . '>';
    }

    /**
     * Get room prices for an entire resort - RAW response (no XML parsing)
     *
     * @return string|false Raw XML response
     */
    public function getRoomPriceByResortRaw(array $params): string
    {
        $resort = $params['resort'] ?? '';
        $checkIn = $params['check_in'] ?? '';
        $checkOut = $params['check_out'] ?? '';
        $adultsCount = (int) ($params['adults'] ?? 2);
        if ($adultsCount < 1) {
            $adultsCount = 2;
        }
        $boardId = $params['board_id'] ?? '';

        $childrenXml = !empty($params['children']) && is_array($params['children'])
            ? $this->buildChildrenAgesXml($params['children'])
            : '';

        // Same raw embedding as getRoomPriceByResort() — see comment there.
        $xml = $this->xmlHeader() . '
        <room_price>
            ' . $this->xmlCredentials() . '
            <IdHotel></IdHotel>
            <Resort>' . $resort . '</Resort>
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

        $this->lastRequest = $xml;
        return $this->callApi(Constants::API_FUNCTION_ROOM_PRICE, $xml, $params['lang'] ?? 'UK');
    }

    /**
     * 13. priceinfo - Season prices request
     *
     * @return \SimpleXMLElement|false
     */
    public function getPriceInfo(string $hotelId, string $packageName, string $lang = 'UK'): \SimpleXMLElement
    {
        $xml = $this->xmlHeader() . '
        <priceinfo>
            ' . $this->xmlCredentials() . '
            <IdHotel>' . htmlspecialchars($hotelId) . '</IdHotel>
            <PackageName>' . htmlspecialchars($packageName) . '</PackageName>
        </priceinfo>';

        return $this->callApiAndParse(Constants::API_FUNCTION_PRICE_INFO, $xml, $lang);
    }

    /**
     * 10. spo - EB (Early booking), extras and other discounts
     *
     * @return \SimpleXMLElement|false
     */
    public function getSpecialOffers(string $hotelId, string $packageName = '', string $lang = 'UK'): \SimpleXMLElement
    {
        $packageXml = $packageName ? '<PackageName>' . htmlspecialchars($packageName) . '</PackageName>' : '';

        $xml = $this->xmlHeader() . '
        <spo>
            ' . $this->xmlCredentials() . '
            <IdHotel>' . htmlspecialchars($hotelId) . '</IdHotel>
            ' . $packageXml . '
        </spo>';

        return $this->callApiAndParse(Constants::API_FUNCTION_SPECIAL_OFFERS, $xml, $lang);
    }
}

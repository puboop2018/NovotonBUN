<?php
declare(strict_types=1);
namespace Tygh\Addons\NovotonHolidays\Api;

use Tygh\Addons\NovotonHolidays\CommissionCalculator;
use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\NovotonHttpClient;
use Tygh\Addons\NovotonHolidays\NovotonXmlParser;
use Tygh\Addons\NovotonHolidays\Services\CacheService;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Exceptions\XmlParsingException;

class PricingApiClient extends ApiClientBase
{
    private CommissionCalculator $commissionCalculator;

    protected array $cacheTtl = [
        Constants::API_FUNCTION_ROOM_PRICE => 300,
    ];

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
    }

    public function applyCommission(float $price): float
    {
        return $this->commissionCalculator->apply($price);
    }

    /**
     * 3. room_price - Accommodation prices (REAL-TIME RATES)
     *
     * @return \SimpleXMLElement|false
     */
    public function getRoomPrice(array $params)
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

        $childrenXml = '';
        if (!empty($params['children']) && is_array($params['children'])) {
            foreach ($params['children'] as $age) {
                $childrenXml .= '<Age>' . (int) $age . '</Age>';
            }
        }

        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <room_price>
            <usr>' . htmlspecialchars($this->httpClient->getApiUser()) . '</usr>
            <psw>' . htmlspecialchars($this->httpClient->getApiPassword()) . '</psw>
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
    public function getRoomPriceByResort(array $params)
    {
        $resort = $params['resort'] ?? '';
        $checkIn = $params['check_in'] ?? '';
        $checkOut = $params['check_out'] ?? '';
        $adultsCount = (int) ($params['adults'] ?? 2);
        $boardId = $params['board_id'] ?? '';

        $adultAges = $params['adult_ages'] ?? [];
        $adultsXml = '';
        for ($i = 0; $i < $adultsCount; $i++) {
            $age = isset($adultAges[$i]) ? (int) $adultAges[$i] : Constants::DEFAULT_ADULT_AGE;
            $adultsXml .= '<Age>' . $age . '</Age>';
        }

        $childrenXml = '';
        if (!empty($params['children']) && is_array($params['children'])) {
            foreach ($params['children'] as $age) {
                $childrenXml .= '<Age>' . (int) $age . '</Age>';
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

        return $this->callApiAndParse(Constants::API_FUNCTION_ROOM_PRICE, $xml, $params['lang'] ?? 'UK');
    }

    /**
     * Get room prices for an entire resort - RAW response (no XML parsing)
     *
     * @return string|false Raw XML response
     */
    public function getRoomPriceByResortRaw(array $params)
    {
        $resort = $params['resort'] ?? '';
        $checkIn = $params['check_in'] ?? '';
        $checkOut = $params['check_out'] ?? '';
        $adultsCount = (int) ($params['adults'] ?? 2);
        if ($adultsCount < 1) {
            $adultsCount = 2;
        }
        $boardId = $params['board_id'] ?? '';

        $childrenXml = '';
        if (!empty($params['children']) && is_array($params['children'])) {
            foreach ($params['children'] as $age) {
                $childrenXml .= '<Age>' . (int) $age . '</Age>';
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
    public function getPriceInfo(string $hotelId, string $packageName, string $lang = 'UK')
    {
        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <priceinfo>
            <usr>' . htmlspecialchars($this->httpClient->getApiUser()) . '</usr>
            <psw>' . htmlspecialchars($this->httpClient->getApiPassword()) . '</psw>
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

        return $this->callApiAndParse(Constants::API_FUNCTION_SPECIAL_OFFERS, $xml, $lang);
    }
}

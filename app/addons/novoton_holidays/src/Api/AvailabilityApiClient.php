<?php
declare(strict_types=1);
namespace Tygh\Addons\NovotonHolidays\Api;

use Tygh\Addons\NovotonHolidays\CommissionCalculator;
use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\NovotonHttpClient;
use Tygh\Addons\NovotonHolidays\NovotonXmlParser;
use Tygh\Addons\NovotonHolidays\Services\CacheService;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;

class AvailabilityApiClient extends ApiClientBase
{
    private CommissionCalculator $commissionCalculator;

    protected array $cacheTtl = [
        Constants::API_FUNCTION_HOTEL_QUOTA => 180,
        'search' => 300,
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

    /**
     * 4. hotel_quota - Free allotments for all rooms
     *
     * @return array Associative array of room_id => quota value
     */
    public function getHotelQuotaAll(string $hotelId, string $checkIn, string $checkOut): array
    {
        $cacheParams = ['hotel_id' => $hotelId, 'check_in' => $checkIn, 'check_out' => $checkOut];
        $cacheKey = $this->buildCacheKey(Constants::API_FUNCTION_HOTEL_QUOTA, $cacheParams);

        $cached = $this->getFromCache(Constants::API_FUNCTION_HOTEL_QUOTA, $cacheKey);
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

        $response = $this->callApi(Constants::API_FUNCTION_HOTEL_QUOTA, $xml);
        $parsed = $this->xmlParser->parse($response);

        $quotaMap = [];

        if (isset($parsed->Package)) {
            foreach ($parsed->Package as $package) {
                $packageXml = $package->asXML();
                if ($packageXml === false) {
                    continue;
                }

                preg_match_all('/<IdRoom>([^<]+)<\/IdRoom>/', $packageXml, $roomMatches);
                preg_match_all('/<Quota>([^<]+)<\/Quota>/', $packageXml, $quotaMatches);

                if (!empty($roomMatches[1]) && !empty($quotaMatches[1])) {
                    $matchCount = min(count($roomMatches[1]), count($quotaMatches[1]));
                    for ($i = 0; $i < $matchCount; $i++) {
                        $roomId = trim($roomMatches[1][$i]);
                        $quota = trim($quotaMatches[1][$i]);

                        if (!isset($quotaMap[$roomId])) {
                            $quotaMap[$roomId] = $quota;
                        } else {
                            $existing = is_numeric($quotaMap[$roomId]) ? (int) $quotaMap[$roomId] : 0;
                            $new = is_numeric($quota) ? (int) $quota : 0;
                            if ($new < $existing || $quotaMap[$roomId] === 'RQ') {
                                $quotaMap[$roomId] = $quota;
                            }
                        }
                    }
                }
            }
        }

        if (defined('NOVOTON_DEBUG') || ConfigProvider::isDebugLogging()) {
            fn_log_event('general', 'runtime', [
                'message' => "hotel_quota for hotel {$hotelId}: " . json_encode($quotaMap)
            ]);
        }

        $this->saveToCache(Constants::API_FUNCTION_HOTEL_QUOTA, $cacheKey, $quotaMap);
        return $quotaMap;
    }

    /**
     * 4. hotel_quota - Free allotments for a single room
     *
     * @return \SimpleXMLElement
     */
    public function getHotelQuota(string $hotelId, string $roomId, string $checkIn, string $checkOut, string $roomType = ''): \SimpleXMLElement
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

        $response = $this->callApi(Constants::API_FUNCTION_HOTEL_QUOTA, $xml);

        if (defined('NOVOTON_DEBUG') || ConfigProvider::isDebugLogging()) {
            fn_log_event('general', 'runtime', [
                'message' => "hotel_quota response for {$hotelId}/{$roomId}: " . substr($response, 0, 500)
            ]);
        }

        return $this->xmlParser->parse($response);
    }

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

        return $this->callApiAndParse(Constants::API_FUNCTION_HOTEL_QUOTA_ADD, $xml);
    }

    /**
     * Search availability using frmsearch API endpoint
     *
     * @return array Search results
     */
    public function searchAvailability(array $params): array
    {
        $adultsCount = (int) ($params['adults'] ?? 2);
        $adultAges = $params['adult_ages'] ?? [];
        $adultsXml = '';
        for ($i = 0; $i < $adultsCount; $i++) {
            $age = isset($adultAges[$i]) ? (int) $adultAges[$i] : Constants::DEFAULT_ADULT_AGE;
            $adultsXml .= '<Age>' . $age . '</Age>';
        }

        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <frmsearch>
            <usr>' . htmlspecialchars($this->httpClient->getApiUser()) . '</usr>
            <psw>' . htmlspecialchars($this->httpClient->getApiPassword()) . '</psw>
            <Country>' . htmlspecialchars(strtoupper($params['country'] ?? '')) . '</Country>
            <City>' . htmlspecialchars(strtoupper($params['city'] ?? '')) . '</City>
            <Hotel>' . htmlspecialchars(strtoupper($params['hotel'] ?? '')) . '</Hotel>
            <Arr1>' . htmlspecialchars($params['check_in'] ?? '') . '</Arr1>
            <Dep1>' . htmlspecialchars($params['check_out'] ?? '') . '</Dep1>
            <OfferType>hotel</OfferType>
            <Adt>' . $adultsXml . '</Adt>
            <Currency>EUR</Currency>
        </frmsearch>';

        if (defined('NOVOTON_DEBUG') || ConfigProvider::isDebugLogging()) {
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton frmsearch Request',
                'xml' => $xml,
                'params' => $params
            ]);
        }

        $response = $this->callApi(Constants::API_FUNCTION_SEARCH, $xml);

        if (defined('NOVOTON_DEBUG') || ConfigProvider::isDebugLogging()) {
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton frmsearch Response',
                'response' => substr($response ?: '', 0, 2000)
            ]);
        }

        $result = $this->xmlParser->parse($response);
        return $this->parseSearchResults($result, $params);
    }

    private function parseSearchResults($result, $params): array
    {
        $results = [];
        if (!$result) {
            return $results;
        }

        // SimpleXMLElement children are Traversable - iterating directly handles
        // both single and multiple child elements correctly (is_array() is always
        // false for SimpleXMLElement, so wrapping in [$x] would lose siblings).
        $offers = [];
        if (isset($result->offer)) {
            $offers = $result->offer;
        } elseif (isset($result->hotel->offer)) {
            $offers = $result->hotel->offer;
        } elseif (isset($result->room)) {
            $offers = $result->room;
        }

        foreach ($offers as $offer) {
            $roomType = (string)($offer->IdRoom ?? $offer->Room ?? $offer->room ?? '');
            $boardCode = (string)($offer->IdBoard ?? $offer->Board ?? $offer->board ?? '');
            $price = (float) ($offer->Price ?? $offer->price ?? 0);
            $nights = (int) ($offer->Nights ?? $offer->nights ?? 7);
            $availability = (int) ($offer->Availability ?? $offer->Avail ?? $offer->avail ?? 0);

            if ($price <= 0) continue;

            $results[] = [
                'room_id' => $roomType,
                'room_name' => $roomType,
                'board_id' => $boardCode,
                'board_name' => \Tygh\Addons\NovotonHolidays\ValueObjects\BoardType::toDisplayName($boardCode),
                'check_in' => $params['check_in'],
                'check_out' => $params['check_out'],
                'nights' => $nights,
                'total_price' => $this->commissionCalculator->apply($price),
                'price_per_night' => round($this->commissionCalculator->apply($price) / max($nights, 1), 2),
                'currency' => ConfigProvider::getApiCurrency(),
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

    private function extractOffersRecursive($data, &$results, $params): void
    {
        if (!is_array($data)) return;

        if (isset($data['Price']) || isset($data['price'])) {
            $price = (float) ($data['Price'] ?? $data['price'] ?? 0);
            if ($price > 0) {
                $nights = (int) ($data['Nights'] ?? $data['nights'] ?? 7);
                $boardCode = $data['IdBoard'] ?? $data['Board'] ?? 'AI';
                $results[] = [
                    'room_id' => $data['IdRoom'] ?? $data['Room'] ?? 'ROOM',
                    'room_name' => $data['Room'] ?? $data['IdRoom'] ?? 'Room',
                    'board_id' => $boardCode,
                    'board_name' => \Tygh\Addons\NovotonHolidays\ValueObjects\BoardType::toDisplayName($boardCode),
                    'check_in' => $params['check_in'] ?? '',
                    'check_out' => $params['check_out'] ?? '',
                    'nights' => $nights,
                    'total_price' => $this->commissionCalculator->apply($price),
                    'price_per_night' => round($this->commissionCalculator->apply($price) / max($nights, 1), 2),
                    'currency' => ConfigProvider::getApiCurrency(),
                    'availability' => (int) ($data['Availability'] ?? $data['Avail'] ?? 1)
                ];
            }
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $this->extractOffersRecursive($value, $results, $params);
            }
        }
    }
}

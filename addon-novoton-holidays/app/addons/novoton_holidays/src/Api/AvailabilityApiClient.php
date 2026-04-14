<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Api;

use Tygh\Addons\NovotonHolidays\Api\Contracts\AvailabilityApiClientInterface;
use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\Helpers\DebugLogger;
use Tygh\Addons\NovotonHolidays\NovotonHttpClient;
use Tygh\Addons\NovotonHolidays\NovotonXmlParser;
use Tygh\Addons\NovotonHolidays\Services\CacheServiceInterface;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\TravelCore\Services\CommissionCalculator;

class AvailabilityApiClient extends ApiClientBase implements AvailabilityApiClientInterface
{
    private readonly CommissionCalculator $commissionCalculator;

    public function __construct(
        NovotonHttpClient $httpClient,
        NovotonXmlParser $xmlParser,
        ?CacheServiceInterface $cache,
        bool $enableCache,
        CommissionCalculator $commissionCalculator,
    ) {
        parent::__construct($httpClient, $xmlParser, $cache, $enableCache);
        $this->commissionCalculator = $commissionCalculator;
        $this->cacheTtl = [
            Constants::API_FUNCTION_HOTEL_QUOTA => ConfigProvider::getCacheTtlAvailability(),
            'search' => ConfigProvider::getCacheTtlSearch(),
        ];
    }

    /**
     * 4. hotel_quota - Free allotments for all rooms
     *
     * @return array<string, mixed> Associative array of room_id => quota value
     */
    #[\Override]
    public function getHotelQuotaAll(string $hotelId, string $checkIn, string $checkOut): array
    {
        $cacheParams = ['hotel_id' => $hotelId, 'check_in' => $checkIn, 'check_out' => $checkOut];
        $cacheKey = $this->buildCacheKey(Constants::API_FUNCTION_HOTEL_QUOTA, $cacheParams);

        $cached = $this->getFromCache(Constants::API_FUNCTION_HOTEL_QUOTA, $cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $xml = $this->buildHotelQuotaXml($hotelId, '', $checkIn, $checkOut);

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

        DebugLogger::log("hotel_quota for hotel {$hotelId}: " . json_encode($quotaMap));

        $this->saveToCache(Constants::API_FUNCTION_HOTEL_QUOTA, $cacheKey, $quotaMap);
        return $quotaMap;
    }

    /**
     * 4. hotel_quota - Free allotments for a single room
     */
    #[\Override]
    public function getHotelQuota(string $hotelId, string $roomId, string $checkIn, string $checkOut, string $roomType = ''): \SimpleXMLElement
    {
        $xml = $this->buildHotelQuotaXml($hotelId, $roomId, $checkIn, $checkOut, $roomType);

        $response = $this->callApi(Constants::API_FUNCTION_HOTEL_QUOTA, $xml);

        DebugLogger::log("hotel_quota response for {$hotelId}/{$roomId}: " . substr($response, 0, 500));

        return $this->xmlParser->parse($response);
    }

    /**
     * 21. hotel_quota_add - Allotments additional
     */
    #[\Override]
    public function getHotelQuotaAdditional(string $hotelId, string $roomId, string $checkIn, string $checkOut): \SimpleXMLElement
    {
        $xml = $this->buildHotelQuotaXml($hotelId, $roomId, $checkIn, $checkOut);

        return $this->callApiAndParse(Constants::API_FUNCTION_HOTEL_QUOTA_ADD, $xml);
    }

    /**
     * Build hotel_quota XML (shared by getHotelQuotaAll, getHotelQuota, getHotelQuotaAdditional).
     */
    private function buildHotelQuotaXml(string $hotelId, string $roomId, string $checkIn, string $checkOut, string $roomType = ''): string
    {
        $roomTypeXml = $roomType ? '<IdRoomType>' . htmlspecialchars($roomType) . '</IdRoomType>' : '';

        return $this->xmlHeader() . '
        <hotel_quota>
            <IdHotel>' . htmlspecialchars($hotelId) . '</IdHotel>
            <IdRoom>' . htmlspecialchars($roomId) . '</IdRoom>
            ' . $roomTypeXml . '
            <CheckIn>' . htmlspecialchars($checkIn) . '</CheckIn>
            <CheckOut>' . htmlspecialchars($checkOut) . '</CheckOut>
        </hotel_quota>';
    }

    /**
     * Build the frmsearch XML request body.
     *
     * Extracted for reuse by searchAvailabilityBatch().
     * @param array<string, mixed> $params
     */
    private function buildSearchXml(array $params): string
    {
        $adultsXml = $this->buildAdultAgesXml((int) ($params['adults'] ?? 2), $params['adult_ages'] ?? []);

        return $this->xmlHeader() . '
        <frmsearch>
            ' . $this->xmlCredentials() . '
            <Country>' . $this->xmlCdata(strtoupper($params['country'] ?? '')) . '</Country>
            <City>' . $this->xmlCdata(strtoupper($params['city'] ?? '')) . '</City>
            <Hotel>' . $this->xmlCdata(strtoupper($params['hotel'] ?? '')) . '</Hotel>
            <Arr1>' . htmlspecialchars($params['check_in'] ?? '') . '</Arr1>
            <Dep1>' . htmlspecialchars($params['check_out'] ?? '') . '</Dep1>
            <OfferType>hotel</OfferType>
            <Adt>' . $adultsXml . '</Adt>
            <Currency>EUR</Currency>
        </frmsearch>';
    }

    /**
     * Search availability using frmsearch API endpoint.
     *
     * NOTE: Returned `total_price` and `price_per_night` values already have
     * commission applied (see `parseSearchResults()` below). Callers must NOT
     * call `applyCommission()` on those values a second time.
     *
     * @return list<array<string, mixed>> Search results (commission applied)
     * @param array<string, mixed> $params
     */
    #[\Override]
    public function searchAvailability(array $params): array
    {
        $xml = $this->buildSearchXml($params);

        DebugLogger::log('Novoton frmsearch Request', ['xml' => $xml, 'params' => $params]);

        $response = $this->callApi(Constants::API_FUNCTION_SEARCH, $xml);

        DebugLogger::log('Novoton frmsearch Response', ['response' => substr($response ?: '', 0, 2000)]);

        $result = $this->xmlParser->parse($response);
        return $this->parseSearchResults($result, $params);
    }

    /**
     * Batch availability search using curl_multi.
     *
     * Sends multiple frmsearch requests in parallel and returns parsed results.
     * Same commission caveat as `searchAvailability()` — prices come out with
     * commission already applied; do not re-apply.
     *
     * @param array<string, array<string, mixed>> $paramsList Keyed array: key => search params
     * @param int $concurrency Max simultaneous requests
     * @return array<string, list<array<string, mixed>>> key => parsed search results array
     */
    #[\Override]
    public function searchAvailabilityBatch(array $paramsList, int $concurrency = 5): array
    {
        if (empty($paramsList)) {
            return [];
        }

        $requests = [];
        foreach ($paramsList as $key => $params) {
            $requests[$key] = [
                'function' => Constants::API_FUNCTION_SEARCH,
                'xml' => $this->buildSearchXml($params),
                'lang' => $params['lang'] ?? 'UK',
            ];
        }

        $rawResponses = $this->httpClient->sendBatchRequests($requests, $concurrency);
        $results = [];

        foreach ($rawResponses as $key => $raw) {
            if ($raw === false) {
                $results[$key] = [];
                continue;
            }

            try {
                $cleaned = $this->xmlParser->clean($raw);
                $parsed = $this->xmlParser->parse($cleaned);
                $results[$key] = $this->parseSearchResults($parsed, $paramsList[$key]);
            } catch (\Exception $e) {
                $results[$key] = [];
            }
        }

        return $results;
    }

    /**
     * @param \SimpleXMLElement|null $result
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
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

            if ($price <= 0) {
                continue;
            }

            $results[] = [
                'room_id' => $roomType,
                'room_name' => $roomType,
                'board_id' => $boardCode,
                'board_name' => \Tygh\Addons\TravelCore\ValueObjects\BoardType::toDisplayName($boardCode),
                'check_in' => $params['check_in'],
                'check_out' => $params['check_out'],
                'nights' => $nights,
                'total_price' => $this->commissionCalculator->apply($price),
                'price_per_night' => round($this->commissionCalculator->apply($price) / max($nights, 1), 2),
                'currency' => ConfigProvider::getApiCurrency(),
                'availability' => $availability,
            ];
        }

        if (empty($results) && $result) {
            $data = json_decode((string) json_encode($result), true);
            if (is_array($data)) {
                $this->extractOffersRecursive($data, $results, $params);
            }
        }

        return $results;
    }

    /**
     * @param mixed $data
     * @param list<array<string, mixed>> $results
     * @param array<string, mixed> $params
     */
    private function extractOffersRecursive($data, &$results, $params): void
    {
        if (!is_array($data)) {
            return;
        }

        if (isset($data['Price']) || isset($data['price'])) {
            $price = (float) ($data['Price'] ?? $data['price'] ?? 0);
            if ($price > 0) {
                $nights = (int) ($data['Nights'] ?? $data['nights'] ?? 7);
                $boardCode = $data['IdBoard'] ?? $data['Board'] ?? 'AI';
                $results[] = [
                    'room_id' => $data['IdRoom'] ?? $data['Room'] ?? 'ROOM',
                    'room_name' => $data['Room'] ?? $data['IdRoom'] ?? 'Room',
                    'board_id' => $boardCode,
                    'board_name' => \Tygh\Addons\TravelCore\ValueObjects\BoardType::toDisplayName($boardCode),
                    'check_in' => $params['check_in'] ?? '',
                    'check_out' => $params['check_out'] ?? '',
                    'nights' => $nights,
                    'total_price' => $this->commissionCalculator->apply($price),
                    'price_per_night' => round($this->commissionCalculator->apply($price) / max($nights, 1), 2),
                    'currency' => ConfigProvider::getApiCurrency(),
                    'availability' => (int) ($data['Availability'] ?? $data['Avail'] ?? 1),
                ];
            }
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $this->extractOffersRecursive($value, $results, $params);
            }
        }
    }
}

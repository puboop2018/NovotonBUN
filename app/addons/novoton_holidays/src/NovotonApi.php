<?php
declare(strict_types=1);
/**
 * Novoton API Integration - Facade
 *
 * Backward-compatible facade delegating to domain-specific API clients:
 * - HotelApiClient: hotel list, info, descriptions, images, facilities
 * - PricingApiClient: room prices, season prices, special offers, commission
 * - AvailabilityApiClient: quota, search
 * - ReservationApiClient: reservations, invoices, alternatives
 * - DestinationApiClient: resort list, offers updates, kickback
 *
 * @package NovotonHolidays
 * @since 3.4.0
 */

namespace Tygh\Addons\NovotonHolidays;

use Tygh\Addons\NovotonHolidays\Api\HotelApiClient;
use Tygh\Addons\NovotonHolidays\Api\PricingApiClient;
use Tygh\Addons\NovotonHolidays\Api\AvailabilityApiClient;
use Tygh\Addons\NovotonHolidays\Api\ReservationApiClient;
use Tygh\Addons\NovotonHolidays\Api\DestinationApiClient;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\Container;

class NovotonApi implements NovotonApiInterface
{
    private NovotonHttpClient $httpClient;
    private NovotonXmlParser $xmlParser;
    private CommissionCalculator $commissionCalculator;
    private ?\Tygh\Addons\NovotonHolidays\Services\CacheService $cache = null;
    private bool $enableCache = true;

    // Domain clients
    private HotelApiClient $hotelApi;
    private PricingApiClient $pricingApi;
    private AvailabilityApiClient $availabilityApi;
    private ReservationApiClient $reservationApi;
    private DestinationApiClient $destinationApi;

    // Debug properties (backward compat — synced from active domain client after each call)
    public string $lastRequest = '';
    public string $lastResponse = '';
    public string $lastResponseRaw = '';
    public array $lastRequestFormatted = [];
    public string $lastError = '';
    public int $lastHttpCode = 0;

    public function __construct()
    {
        $this->httpClient = new NovotonHttpClient(ConfigProvider::all());
        $this->xmlParser = new NovotonXmlParser();
        $this->commissionCalculator = new CommissionCalculator(
            ConfigProvider::getCommission(),
            ConfigProvider::isRoundPrices() ? 'Y' : 'N'
        );

        $this->enableCache = (ConfigProvider::get('enable_api_cache', 'Y') === 'Y');
        if ($this->enableCache) {
            $this->cache = Container::getInstance()->cacheService();
        }

        // Initialize domain clients with shared dependencies
        $this->hotelApi = new HotelApiClient($this->httpClient, $this->xmlParser, $this->cache, $this->enableCache);
        $this->pricingApi = new PricingApiClient($this->httpClient, $this->xmlParser, $this->cache, $this->enableCache, $this->commissionCalculator);
        $this->availabilityApi = new AvailabilityApiClient($this->httpClient, $this->xmlParser, $this->cache, $this->enableCache, $this->commissionCalculator);
        $this->reservationApi = new ReservationApiClient($this->httpClient, $this->xmlParser, $this->cache, $this->enableCache);
        $this->destinationApi = new DestinationApiClient($this->httpClient, $this->xmlParser, $this->cache, $this->enableCache);
    }

    // ========== DOMAIN CLIENT ACCESSORS ==========

    public function hotels(): HotelApiClient { return $this->hotelApi; }
    public function pricing(): PricingApiClient { return $this->pricingApi; }
    public function availability(): AvailabilityApiClient { return $this->availabilityApi; }
    public function reservations(): ReservationApiClient { return $this->reservationApi; }
    public function destinations(): DestinationApiClient { return $this->destinationApi; }

    // ========== SYNC DEBUG STATE ==========

    private function syncFrom($client): void
    {
        $this->lastRequest = $client->lastRequest;
        $this->lastResponse = $client->lastResponse;
        $this->lastResponseRaw = $client->lastResponseRaw;
        $this->lastRequestFormatted = $client->lastRequestFormatted;
        $this->lastError = $client->lastError;
        $this->lastHttpCode = $client->lastHttpCode;
    }

    // ========== BACKWARD-COMPATIBLE DELEGATES ==========

    // -- Hotels --

    public function getHotelList(string $country = '%', string $city = '%', string $hotel = '%', string $hotelType = '%'): \SimpleXMLElement
    {
        $result = $this->hotelApi->getHotelList($country, $city, $hotel, $hotelType);
        $this->syncFrom($this->hotelApi);
        return $result;
    }

    public function getHotelInfo(string $hotelId, string $lang = 'UK'): \SimpleXMLElement
    {
        $result = $this->hotelApi->getHotelInfo($hotelId, $lang);
        $this->syncFrom($this->hotelApi);
        return $result;
    }

    public function getHotelInfoBatch(array $hotelIds, string $lang = 'UK', int $concurrency = 5): array
    {
        $result = $this->hotelApi->getHotelInfoBatch($hotelIds, $lang, $concurrency);
        $this->syncFrom($this->hotelApi);
        return $result;
    }

    public function getHotelDescription(string $hotelId, string $lang = 'UK', bool $includePackage = false): \SimpleXMLElement
    {
        $result = $this->hotelApi->getHotelDescription($hotelId, $lang, $includePackage);
        $this->syncFrom($this->hotelApi);
        return $result;
    }

    public function getHotelImages(string $hotelId, string $lang = 'UK'): \SimpleXMLElement
    {
        $result = $this->hotelApi->getHotelImages($hotelId, $lang);
        $this->syncFrom($this->hotelApi);
        return $result;
    }

    public function getHotelFacilities(string $hotelId): \SimpleXMLElement
    {
        $result = $this->hotelApi->getHotelFacilities($hotelId);
        $this->syncFrom($this->hotelApi);
        return $result;
    }

    public function listFacilities(): \SimpleXMLElement
    {
        $result = $this->hotelApi->listFacilities();
        $this->syncFrom($this->hotelApi);
        return $result;
    }

    // -- Pricing --

    public function applyCommission(float $price): float
    {
        return $this->commissionCalculator->apply($price);
    }

    public function getRoomPrice(array $params): \SimpleXMLElement|false
    {
        $result = $this->pricingApi->getRoomPrice($params);
        $this->syncFrom($this->pricingApi);
        return $result;
    }

    /**
     * Batch room_price requests using curl_multi.
     *
     * @param array<string, array> $requestParams Keyed array: key => room_price params
     * @param int $concurrency Max simultaneous requests
     * @return array<string, array{data: \SimpleXMLElement|false, rawXml: string}>
     */
    public function getRoomPriceBatch(array $requestParams, int $concurrency = 5): array
    {
        $result = $this->pricingApi->getRoomPriceBatch($requestParams, $concurrency);
        $this->syncFrom($this->pricingApi);
        return $result;
    }

    public function getRoomPriceByResort(array $params): \SimpleXMLElement|false
    {
        $result = $this->pricingApi->getRoomPriceByResort($params);
        $this->syncFrom($this->pricingApi);
        return $result;
    }

    public function getRoomPriceByResortRaw(array $params): string
    {
        $result = $this->pricingApi->getRoomPriceByResortRaw($params);
        $this->syncFrom($this->pricingApi);
        return $result;
    }

    public function getPriceInfo(string $hotelId, string $packageName, string $lang = 'UK'): \SimpleXMLElement
    {
        $result = $this->pricingApi->getPriceInfo($hotelId, $packageName, $lang);
        $this->syncFrom($this->pricingApi);
        return $result;
    }

    public function getSpecialOffers(string $hotelId, string $packageName = '', string $lang = 'UK'): \SimpleXMLElement
    {
        $result = $this->pricingApi->getSpecialOffers($hotelId, $packageName, $lang);
        $this->syncFrom($this->pricingApi);
        return $result;
    }

    // -- Availability --

    public function getHotelQuotaAll(string $hotelId, string $checkIn, string $checkOut): array
    {
        $result = $this->availabilityApi->getHotelQuotaAll($hotelId, $checkIn, $checkOut);
        $this->syncFrom($this->availabilityApi);
        return $result;
    }

    public function getHotelQuota(string $hotelId, string $roomId, string $checkIn, string $checkOut, string $roomType = ''): \SimpleXMLElement
    {
        $result = $this->availabilityApi->getHotelQuota($hotelId, $roomId, $checkIn, $checkOut, $roomType);
        $this->syncFrom($this->availabilityApi);
        return $result;
    }

    public function getHotelQuotaAdditional(string $hotelId, string $roomId, string $checkIn, string $checkOut): \SimpleXMLElement
    {
        $result = $this->availabilityApi->getHotelQuotaAdditional($hotelId, $roomId, $checkIn, $checkOut);
        $this->syncFrom($this->availabilityApi);
        return $result;
    }

    public function searchAvailability(array $params): array
    {
        $result = $this->availabilityApi->searchAvailability($params);
        $this->syncFrom($this->availabilityApi);
        return $result;
    }

    /**
     * Batch availability search using curl_multi.
     *
     * @param array<string, array> $paramsList Keyed array: key => search params
     * @param int $concurrency Max simultaneous requests
     * @return array<string, array> key => parsed search results
     */
    public function searchAvailabilityBatch(array $paramsList, int $concurrency = 5): array
    {
        $result = $this->availabilityApi->searchAvailabilityBatch($paramsList, $concurrency);
        $this->syncFrom($this->availabilityApi);
        return $result;
    }

    // -- Reservations --

    public function createReservation(array $bookingData): \SimpleXMLElement
    {
        $result = $this->reservationApi->createReservation($bookingData);
        $this->syncFrom($this->reservationApi);
        return $result;
    }

    public function createHotelRequest(array $requestData, string $lang = 'UK', bool $returnXml = false): \SimpleXMLElement|array
    {
        $result = $this->reservationApi->createHotelRequest($requestData, $lang, $returnXml);
        $this->syncFrom($this->reservationApi);
        return $result;
    }

    public function generateHotelRequestXml(array $requestData): string
    {
        return $this->reservationApi->generateHotelRequestXml($requestData);
    }

    public function getAlternatives(string $idNum, string $lang = 'UK'): \SimpleXMLElement
    {
        $result = $this->reservationApi->getAlternatives($idNum, $lang);
        $this->syncFrom($this->reservationApi);
        return $result;
    }

    public function getReservationInfo(string $idNum = '', string $confirmAgency = '', string $lang = 'UK'): \SimpleXMLElement
    {
        $result = $this->reservationApi->getReservationInfo($idNum, $confirmAgency, $lang);
        $this->syncFrom($this->reservationApi);
        return $result;
    }

    public function getInvoiceHtml(string $idNum, string $lang = 'UK'): string
    {
        $result = $this->reservationApi->getInvoiceHtml($idNum, $lang);
        $this->syncFrom($this->reservationApi);
        return $result;
    }

    public function getInvoiceXml(string $idNum, string $lang = 'UK'): \SimpleXMLElement
    {
        $result = $this->reservationApi->getInvoiceXml($idNum, $lang);
        $this->syncFrom($this->reservationApi);
        return $result;
    }

    public function listInvoices(string $arrFrom = '', string $arrTo = '', string $lang = 'UK'): \SimpleXMLElement
    {
        $result = $this->reservationApi->listInvoices($arrFrom, $arrTo, $lang);
        $this->syncFrom($this->reservationApi);
        return $result;
    }

    // -- Destinations --

    public function getResortList(string $country = '', string $lang = 'UK'): \SimpleXMLElement
    {
        $result = $this->destinationApi->getResortList($country, $lang);
        $this->syncFrom($this->destinationApi);
        return $result;
    }

    public function getOffersUpdate(string $dateTime, string $country = '', string $resort = '', string $hotel = ''): \SimpleXMLElement
    {
        $result = $this->destinationApi->getOffersUpdate($dateTime, $country, $resort, $hotel);
        $this->syncFrom($this->destinationApi);
        return $result;
    }

    public function getKickbackInfo(string $lang = 'UK'): \SimpleXMLElement
    {
        $result = $this->destinationApi->getKickbackInfo($lang);
        $this->syncFrom($this->destinationApi);
        return $result;
    }

    // ========== DEBUG GETTERS ==========

    public function getLastRequest(): string { return $this->lastRequest ?? ''; }
    public function getLastResponse(): string { return $this->lastResponse ?? ''; }
    public function getLastRequestFormatted(): array { return $this->lastRequestFormatted ?? []; }

    public function getLastError(): string
    {
        $error = $this->lastError ?? '';
        if ($this->lastHttpCode && $this->lastHttpCode != 200) {
            $error .= " (HTTP {$this->lastHttpCode})";
        }
        return $error;
    }

    public function getLastResponseRaw(): string { return $this->lastResponseRaw ?? ''; }
    public function getLastHttpCode(): int { return $this->lastHttpCode ?? 0; }

    // ========== CIRCUIT BREAKER ==========

    public function getCircuitStatus(): array { return $this->httpClient->getCircuitStatus(); }
    public function resetCircuitBreaker(): void { $this->httpClient->resetCircuitBreaker(); }

    // ========== CACHE ==========

    public function clearCache(?string $function = null): int
    {
        if (!$this->cache) {
            return 0;
        }
        $prefix = $function ? 'nvt_api_' . $function : 'nvt_api_';
        return $this->cache->clear($prefix);
    }
}

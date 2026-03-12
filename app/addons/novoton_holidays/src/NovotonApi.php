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
 * Callers are encouraged to use the domain client accessors directly:
 *   $api->hotels()->getHotelList()
 *   $api->pricing()->getRoomPrice($params)
 *   $api->availability()->searchAvailability($params)
 *   $api->reservations()->createReservation($data)
 *   $api->destinations()->getResortList()
 *
 * The flat delegate methods (e.g. $api->getHotelList()) are retained for
 * backward compatibility but are deprecated.
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
use Tygh\Addons\NovotonHolidays\ValueObjects\RequestDebugInfo;

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

    private function syncFrom(object $client): void
    {
        $this->lastRequest = $client->lastRequest;
        $this->lastResponse = $client->lastResponse;
        $this->lastResponseRaw = $client->lastResponseRaw;
        $this->lastRequestFormatted = $client->lastRequestFormatted;
        $this->lastError = $client->lastError;
        $this->lastHttpCode = $client->lastHttpCode;
    }

    /**
     * Delegate a method call to a domain client and sync debug state.
     *
     * @param object $client The domain API client to delegate to
     * @param string $method The method name on the client
     * @param array $args Arguments to pass through
     * @return mixed The client method's return value
     */
    private function delegateTo(object $client, string $method, array $args): mixed
    {
        $result = $client->$method(...$args);
        $this->syncFrom($client);
        return $result;
    }

    /**
     * Get debug info as an immutable value object (preferred over raw public properties).
     */
    public function debugInfo(): RequestDebugInfo
    {
        return new RequestDebugInfo(
            $this->lastRequest,
            $this->lastResponse,
            $this->lastResponseRaw,
            $this->lastRequestFormatted,
            $this->lastError,
            $this->lastHttpCode
        );
    }

    // ========== BACKWARD-COMPATIBLE DELEGATES ==========
    // Prefer using domain client accessors directly: $api->hotels(), $api->pricing(), etc.

    // -- Hotels --

    /** @deprecated Use $api->hotels()->getHotelList() */
    public function getHotelList(string $country = '%', string $city = '%', string $hotel = '%', string $hotelType = '%'): \SimpleXMLElement
    {
        return $this->delegateTo($this->hotelApi, 'getHotelList', [$country, $city, $hotel, $hotelType]);
    }

    /** @deprecated Use $api->hotels()->getHotelInfo() */
    public function getHotelInfo(string $hotelId, string $lang = 'UK'): \SimpleXMLElement
    {
        return $this->delegateTo($this->hotelApi, 'getHotelInfo', [$hotelId, $lang]);
    }

    /** @deprecated Use $api->hotels()->getHotelInfoBatch() */
    public function getHotelInfoBatch(array $hotelIds, string $lang = 'UK', int $concurrency = 5): array
    {
        return $this->delegateTo($this->hotelApi, 'getHotelInfoBatch', [$hotelIds, $lang, $concurrency]);
    }

    /** @deprecated Use $api->hotels()->getHotelDescription() */
    public function getHotelDescription(string $hotelId, string $lang = 'UK', bool $includePackage = false): \SimpleXMLElement
    {
        return $this->delegateTo($this->hotelApi, 'getHotelDescription', [$hotelId, $lang, $includePackage]);
    }

    /** @deprecated Use $api->hotels()->getHotelImages() */
    public function getHotelImages(string $hotelId, string $lang = 'UK'): \SimpleXMLElement
    {
        return $this->delegateTo($this->hotelApi, 'getHotelImages', [$hotelId, $lang]);
    }

    /** @deprecated Use $api->hotels()->getHotelFacilities() */
    public function getHotelFacilities(string $hotelId): \SimpleXMLElement
    {
        return $this->delegateTo($this->hotelApi, 'getHotelFacilities', [$hotelId]);
    }

    /** @deprecated Use $api->hotels()->listFacilities() */
    public function listFacilities(): \SimpleXMLElement
    {
        return $this->delegateTo($this->hotelApi, 'listFacilities', []);
    }

    // -- Pricing --

    /** Not deprecated — delegates to CommissionCalculator, not a domain client. */
    public function applyCommission(float $price): float
    {
        return $this->commissionCalculator->apply($price);
    }

    /** @deprecated Use $api->pricing()->getRoomPrice() */
    public function getRoomPrice(array $params): \SimpleXMLElement|false
    {
        return $this->delegateTo($this->pricingApi, 'getRoomPrice', [$params]);
    }

    /** @deprecated Use $api->pricing()->getRoomPriceBatch() */
    public function getRoomPriceBatch(array $requestParams, int $concurrency = 5): array
    {
        return $this->delegateTo($this->pricingApi, 'getRoomPriceBatch', [$requestParams, $concurrency]);
    }

    /** @deprecated Use $api->pricing()->getRoomPriceByResort() */
    public function getRoomPriceByResort(array $params): \SimpleXMLElement|false
    {
        return $this->delegateTo($this->pricingApi, 'getRoomPriceByResort', [$params]);
    }

    /** @deprecated Use $api->pricing()->getRoomPriceByResortRaw() */
    public function getRoomPriceByResortRaw(array $params): string
    {
        return $this->delegateTo($this->pricingApi, 'getRoomPriceByResortRaw', [$params]);
    }

    /** @deprecated Use $api->pricing()->getPriceInfo() */
    public function getPriceInfo(string $hotelId, string $packageName, string $lang = 'UK'): \SimpleXMLElement
    {
        return $this->delegateTo($this->pricingApi, 'getPriceInfo', [$hotelId, $packageName, $lang]);
    }

    /** @deprecated Use $api->pricing()->getSpecialOffers() */
    public function getSpecialOffers(string $hotelId, string $packageName = '', string $lang = 'UK'): \SimpleXMLElement
    {
        return $this->delegateTo($this->pricingApi, 'getSpecialOffers', [$hotelId, $packageName, $lang]);
    }

    // -- Availability --

    /** @deprecated Use $api->availability()->getHotelQuotaAll() */
    public function getHotelQuotaAll(string $hotelId, string $checkIn, string $checkOut): array
    {
        return $this->delegateTo($this->availabilityApi, 'getHotelQuotaAll', [$hotelId, $checkIn, $checkOut]);
    }

    /** @deprecated Use $api->availability()->getHotelQuota() */
    public function getHotelQuota(string $hotelId, string $roomId, string $checkIn, string $checkOut, string $roomType = ''): \SimpleXMLElement
    {
        return $this->delegateTo($this->availabilityApi, 'getHotelQuota', [$hotelId, $roomId, $checkIn, $checkOut, $roomType]);
    }

    /** @deprecated Use $api->availability()->getHotelQuotaAdditional() */
    public function getHotelQuotaAdditional(string $hotelId, string $roomId, string $checkIn, string $checkOut): \SimpleXMLElement
    {
        return $this->delegateTo($this->availabilityApi, 'getHotelQuotaAdditional', [$hotelId, $roomId, $checkIn, $checkOut]);
    }

    /** @deprecated Use $api->availability()->searchAvailability() */
    public function searchAvailability(array $params): array
    {
        return $this->delegateTo($this->availabilityApi, 'searchAvailability', [$params]);
    }

    /** @deprecated Use $api->availability()->searchAvailabilityBatch() */
    public function searchAvailabilityBatch(array $paramsList, int $concurrency = 5): array
    {
        return $this->delegateTo($this->availabilityApi, 'searchAvailabilityBatch', [$paramsList, $concurrency]);
    }

    // -- Reservations --

    /** @deprecated Use $api->reservations()->createReservation() */
    public function createReservation(array $bookingData): \SimpleXMLElement
    {
        return $this->delegateTo($this->reservationApi, 'createReservation', [$bookingData]);
    }

    /** @deprecated Use $api->reservations()->createHotelRequest() */
    public function createHotelRequest(array $requestData, string $lang = 'UK', bool $returnXml = false): \SimpleXMLElement|array
    {
        return $this->delegateTo($this->reservationApi, 'createHotelRequest', [$requestData, $lang, $returnXml]);
    }

    /** @deprecated Use $api->reservations()->generateHotelRequestXml() — no debug sync needed. */
    public function generateHotelRequestXml(array $requestData): string
    {
        return $this->reservationApi->generateHotelRequestXml($requestData);
    }

    /** @deprecated Use $api->reservations()->getAlternatives() */
    public function getAlternatives(string $idNum, string $lang = 'UK'): \SimpleXMLElement
    {
        return $this->delegateTo($this->reservationApi, 'getAlternatives', [$idNum, $lang]);
    }

    /** @deprecated Use $api->reservations()->getReservationInfo() */
    public function getReservationInfo(string $idNum = '', string $confirmAgency = '', string $lang = 'UK'): \SimpleXMLElement
    {
        return $this->delegateTo($this->reservationApi, 'getReservationInfo', [$idNum, $confirmAgency, $lang]);
    }

    /** @deprecated Use $api->reservations()->getInvoiceHtml() */
    public function getInvoiceHtml(string $idNum, string $lang = 'UK'): string
    {
        return $this->delegateTo($this->reservationApi, 'getInvoiceHtml', [$idNum, $lang]);
    }

    /** @deprecated Use $api->reservations()->getInvoiceXml() */
    public function getInvoiceXml(string $idNum, string $lang = 'UK'): \SimpleXMLElement
    {
        return $this->delegateTo($this->reservationApi, 'getInvoiceXml', [$idNum, $lang]);
    }

    /** @deprecated Use $api->reservations()->listInvoices() */
    public function listInvoices(string $arrFrom = '', string $arrTo = '', string $lang = 'UK'): \SimpleXMLElement
    {
        return $this->delegateTo($this->reservationApi, 'listInvoices', [$arrFrom, $arrTo, $lang]);
    }

    // -- Destinations --

    /** @deprecated Use $api->destinations()->getResortList() */
    public function getResortList(string $country = '', string $lang = 'UK'): \SimpleXMLElement
    {
        return $this->delegateTo($this->destinationApi, 'getResortList', [$country, $lang]);
    }

    /** @deprecated Use $api->destinations()->getOffersUpdate() */
    public function getOffersUpdate(string $dateTime, string $country = '', string $resort = '', string $hotel = ''): \SimpleXMLElement
    {
        return $this->delegateTo($this->destinationApi, 'getOffersUpdate', [$dateTime, $country, $resort, $hotel]);
    }

    /** @deprecated Use $api->destinations()->getKickbackInfo() */
    public function getKickbackInfo(string $lang = 'UK'): \SimpleXMLElement
    {
        return $this->delegateTo($this->destinationApi, 'getKickbackInfo', [$lang]);
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

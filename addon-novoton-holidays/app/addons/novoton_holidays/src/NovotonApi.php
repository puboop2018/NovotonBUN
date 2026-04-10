<?php
declare(strict_types=1);
/**
 * Novoton API Integration - Facade
 *
 * Thin facade that composes five domain-specific API clients and exposes
 * them via accessor methods:
 *
 *   $api->hotels()->getHotelList()
 *   $api->pricing()->getRoomPrice($params)
 *   $api->availability()->searchAvailability($params)
 *   $api->reservations()->createReservation($data)
 *   $api->destinations()->getResortList()
 *
 * The debug getters on the facade (getLastRequest, getLastResponse, …)
 * proxy to the most-recently-accessed domain client, which makes the
 * common pattern `$api->hotels()->foo(); $api->getLastRequest();` work
 * without every caller needing to remember which client they just used.
 *
 * @package NovotonHolidays
 * @since 3.4.0
 */

namespace Tygh\Addons\NovotonHolidays;

use Tygh\Addons\NovotonHolidays\Api\ApiClientBase;
use Tygh\Addons\NovotonHolidays\Api\HotelApiClient;
use Tygh\Addons\NovotonHolidays\Api\PricingApiClient;
use Tygh\Addons\NovotonHolidays\Api\AvailabilityApiClient;
use Tygh\Addons\NovotonHolidays\Api\ReservationApiClient;
use Tygh\Addons\NovotonHolidays\Api\DestinationApiClient;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\TravelCore\ValueObjects\RequestDebugInfo;
use Tygh\Addons\TravelCore\Services\CommissionCalculator;

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

    /**
     * Most-recently-accessed domain client. Debug getters read from this so
     * `$api->hotels()->foo(); $api->getLastRequest()` returns the request
     * just made, without the caller having to track which client was used.
     */
    private ?ApiClientBase $lastActiveClient = null;

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
    // Each accessor records itself as the "last active" client so the debug
    // getters below can return state from the most recent call.

    public function hotels(): HotelApiClient
    {
        $this->lastActiveClient = $this->hotelApi;
        return $this->hotelApi;
    }

    public function pricing(): PricingApiClient
    {
        $this->lastActiveClient = $this->pricingApi;
        return $this->pricingApi;
    }

    public function availability(): AvailabilityApiClient
    {
        $this->lastActiveClient = $this->availabilityApi;
        return $this->availabilityApi;
    }

    public function reservations(): ReservationApiClient
    {
        $this->lastActiveClient = $this->reservationApi;
        return $this->reservationApi;
    }

    public function destinations(): DestinationApiClient
    {
        $this->lastActiveClient = $this->destinationApi;
        return $this->destinationApi;
    }

    // ========== COMMISSION ==========
    // Not a domain-client call — delegates to CommissionCalculator.

    public function applyCommission(float $price): float
    {
        return $this->commissionCalculator->apply($price);
    }

    // ========== DEBUG GETTERS ==========
    // Proxy to the most recently active domain client so
    // `$api->hotels()->foo(); $api->getLastResponse();` works.

    public function getLastRequest(): string
    {
        return $this->lastActiveClient->lastRequest ?? '';
    }

    public function getLastResponse(): string
    {
        return $this->lastActiveClient->lastResponse ?? '';
    }

    public function getLastRequestFormatted(): array
    {
        return $this->lastActiveClient->lastRequestFormatted ?? [];
    }

    public function getLastError(): string
    {
        $error = $this->lastActiveClient->lastError ?? '';
        $httpCode = $this->lastActiveClient->lastHttpCode ?? 0;
        if ($httpCode && $httpCode != 200) {
            $error .= " (HTTP {$httpCode})";
        }
        return $error;
    }

    public function getLastResponseRaw(): string
    {
        return $this->lastActiveClient->lastResponseRaw ?? '';
    }

    public function getLastHttpCode(): int
    {
        return $this->lastActiveClient->lastHttpCode ?? 0;
    }

    /**
     * Get debug info as an immutable value object.
     * Delegates to the most recently active domain client.
     */
    public function debugInfo(): RequestDebugInfo
    {
        return $this->lastActiveClient?->debugInfo() ?? new RequestDebugInfo();
    }

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

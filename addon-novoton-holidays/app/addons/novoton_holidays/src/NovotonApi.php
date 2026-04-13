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

use Tygh\Addons\NovotonHolidays\Api\Contracts\NovotonApiKitInterface;
use Tygh\Addons\NovotonHolidays\Api\HotelApiClient;
use Tygh\Addons\NovotonHolidays\Api\PricingApiClient;
use Tygh\Addons\NovotonHolidays\Api\AvailabilityApiClient;
use Tygh\Addons\NovotonHolidays\Api\ReservationApiClient;
use Tygh\Addons\NovotonHolidays\Api\DestinationApiClient;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\TravelCore\ValueObjects\RequestDebugInfo;
use Tygh\Addons\TravelCore\Services\CommissionCalculator;

class NovotonApi implements NovotonApiKitInterface
{
    private NovotonHttpClient $httpClient;
    private NovotonXmlParser $xmlParser;
    private CommissionCalculator $commissionCalculator;
    private ?\Tygh\Addons\NovotonHolidays\Services\CacheServiceInterface $cache = null;
    private bool $enableCache = true;

    // Domain clients
    private HotelApiClient $hotelApi;
    private PricingApiClient $pricingApi;
    private AvailabilityApiClient $availabilityApi;
    private ReservationApiClient $reservationApi;
    private DestinationApiClient $destinationApi;

    /**
     * Most recently returned sub-client — updated by each of the five
     * hotels()/pricing()/availability()/reservations()/destinations()
     * accessors. debugInfo() and the getLast*() accessors read their
     * state from this client, so a diagnostic caller that does
     *
     *     $api->pricing()->getRoomPrice($p);
     *     $api->getLastResponse();
     *
     * sees the XML returned by the pricing client — not stale state
     * from an earlier facade call.
     */
    private ?object $lastActiveClient = null;

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
    //
    // Return types are the concrete sub-client classes (covariant with the
    // interface return types declared on NovotonApiKitInterface). New callers
    // should type-hint the interfaces from Api\Contracts\ to decouple themselves
    // from the facade.

    #[\Override]
    public function hotels(): HotelApiClient { return $this->lastActiveClient = $this->hotelApi; }

    #[\Override]
    public function pricing(): PricingApiClient { return $this->lastActiveClient = $this->pricingApi; }

    #[\Override]
    public function availability(): AvailabilityApiClient { return $this->lastActiveClient = $this->availabilityApi; }

    #[\Override]
    public function reservations(): ReservationApiClient { return $this->lastActiveClient = $this->reservationApi; }

    #[\Override]
    public function destinations(): DestinationApiClient { return $this->lastActiveClient = $this->destinationApi; }

    // ========== DEBUG STATE ==========

    /**
     * Get debug info as an immutable value object.
     *
     * Reads from the most recently accessed sub-client. Returns an empty
     * RequestDebugInfo if no sub-client has been touched yet.
     */
    #[\Override]
    public function debugInfo(): RequestDebugInfo
    {
        if ($this->lastActiveClient === null) {
            return new RequestDebugInfo();
        }
        return RequestDebugInfo::fromClient($this->lastActiveClient);
    }
    // ========== DEBUG GETTERS ==========
    //
    // All getters delegate to the most-recently-accessed sub-client, so the
    // typical diagnostic pattern
    //
    //     $api->pricing()->getRoomPrice($p);
    //     $api->getLastResponse();
    //
    // returns the XML emitted by the pricing client. Prefer debugInfo() in
    // new code — these flat getters exist for the diagnostic / admin pages
    // that pre-date the value object.

    public function getLastRequest(): string
    {
        return (string) ($this->lastActiveClient->lastRequest ?? '');
    }

    public function getLastResponse(): string
    {
        return (string) ($this->lastActiveClient->lastResponse ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    public function getLastRequestFormatted(): array
    {
        return (array) ($this->lastActiveClient->lastRequestFormatted ?? []);
    }

    public function getLastError(): string
    {
        $error = (string) ($this->lastActiveClient->lastError ?? '');
        $code  = (int) ($this->lastActiveClient->lastHttpCode ?? 0);
        if ($code !== 0 && $code !== 200) {
            $error .= " (HTTP {$code})";
        }
        return $error;
    }

    public function getLastResponseRaw(): string
    {
        return (string) ($this->lastActiveClient->lastResponseRaw ?? '');
    }

    public function getLastHttpCode(): int
    {
        return (int) ($this->lastActiveClient->lastHttpCode ?? 0);
    }

    // ========== CIRCUIT BREAKER ==========

    /**
     * @return array<string, mixed>
     */
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

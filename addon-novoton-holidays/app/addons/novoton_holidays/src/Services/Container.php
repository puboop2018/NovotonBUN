<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Dependency Injection Container
 *
 * Manages service construction, dependency wiring, and singleton lifecycle.
 * All service/repository/helper access should go through this container.
 *
 * Usage:
 *   $container = Container::getInstance();
 *   $bookingService = $container->bookingService();
 *   $hotelRepo = $container->hotelRepository();
 *
 * @package NovotonHolidays
 * @since 3.3.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Repository\HotelRepository;
use Tygh\Addons\NovotonHolidays\Repository\HotelRepositoryInterface;
use Tygh\Addons\NovotonHolidays\Repository\BookingRepository;
use Tygh\Addons\NovotonHolidays\Repository\BookingRepositoryInterface;
use Tygh\Addons\NovotonHolidays\Repository\FacilityRepository;
use Tygh\Addons\NovotonHolidays\Repository\FacilityRepositoryInterface;
use Tygh\Addons\NovotonHolidays\Repository\SyncLogRepository;
use Tygh\Addons\NovotonHolidays\Repository\SyncLogRepositoryInterface;
use Tygh\Addons\NovotonHolidays\Repository\AlternativeRequestRepository;
use Tygh\Addons\NovotonHolidays\Repository\AlternativeRequestRepositoryInterface;
use Tygh\Addons\NovotonHolidays\Repository\HotelPackageRepository;
use Tygh\Addons\NovotonHolidays\Repository\HotelPackageRepositoryInterface;
use Tygh\Addons\NovotonHolidays\Repository\FeatureMappingRepository;
use Tygh\Addons\NovotonHolidays\Repository\FeatureMappingRepositoryInterface;
use Tygh\Addons\NovotonHolidays\Api\NovotonNormalizer;
use Tygh\Addons\NovotonHolidays\Api\PropertyTypeDetector;
use Tygh\Addons\TravelCore\Contracts\ProviderNormalizerInterface;
use Tygh\Addons\NovotonHolidays\Helpers\DatabaseHelper;
use Tygh\Addons\NovotonHolidays\Helpers\DatabaseHelperInterface;
use Tygh\Addons\NovotonHolidays\Helpers\DatabaseIterator;
use Tygh\Addons\NovotonHolidays\Helpers\DatabaseIteratorInterface;
use Tygh\Addons\NovotonHolidays\Helpers\ProductFactory;
use Tygh\Addons\NovotonHolidays\Helpers\ProductFactoryInterface;
use Tygh\Addons\NovotonHolidays\Helpers\BatchedHotelInfoSync;
use Tygh\Addons\NovotonHolidays\Helpers\SyncInterface;
use Tygh\Addons\NovotonHolidays\NovotonApi;

class Container
{
    private static ?self $instance = null;

    /** @var array<string, object> Resolved singleton instances */
    private array $instances = [];

    /** @var array<string, callable> Factory overrides for testing */
    private array $overrides = [];

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Replace the container instance (for testing).
     */
    public static function setInstance(?self $instance): void
    {
        self::$instance = $instance;
    }

    /**
     * Reset all cached instances (for testing or mid-request reload).
     */
    public function reset(): void
    {
        $this->instances = [];
    }

    /**
     * Override a factory for testing.
     * @param string $id Service identifier (method name)
     * @param callable $factory Factory returning the mock/stub
     */
    public function override(string $id, callable $factory): void
    {
        $this->overrides[$id] = $factory;
        unset($this->instances[$id]);
    }

    // ── Resolve helper ──

    private function resolve(string $id, callable $factory): object
    {
        if (isset($this->overrides[$id])) {
            return $this->instances[$id] ??= ($this->overrides[$id])();
        }
        return $this->instances[$id] ??= $factory();
    }

    // ═══════════════════════════════════════════════════════════════════
    // REPOSITORIES
    // ═══════════════════════════════════════════════════════════════════

    public function hotelRepository(): HotelRepositoryInterface
    {
        return $this->resolve('hotelRepository', fn() => new HotelRepository());
    }

    public function bookingRepository(): BookingRepositoryInterface
    {
        return $this->resolve('bookingRepository', fn() => new BookingRepository());
    }

    public function facilityRepository(): FacilityRepositoryInterface
    {
        return $this->resolve('facilityRepository', fn() => new FacilityRepository());
    }

    public function syncLogRepository(): SyncLogRepositoryInterface
    {
        return $this->resolve('syncLogRepository', fn() => new SyncLogRepository());
    }

    public function alternativeRequestRepository(): AlternativeRequestRepositoryInterface
    {
        return $this->resolve('alternativeRequestRepository', fn() => new AlternativeRequestRepository());
    }

    public function hotelPackageRepository(): HotelPackageRepositoryInterface
    {
        return $this->resolve('hotelPackageRepository', fn() => new HotelPackageRepository());
    }

    public function featureMappingRepository(): FeatureMappingRepositoryInterface
    {
        return $this->resolve('featureMappingRepository', fn() => new FeatureMappingRepository());
    }

    // ═══════════════════════════════════════════════════════════════════
    // SERVICES
    // ═══════════════════════════════════════════════════════════════════

    public function bookingService(): BookingServiceInterface
    {
        return $this->resolve('bookingService', fn() => new BookingService(
            $this->guestDataService(),
            $this->roomPriceService(),
            $this->bookingRepository(),
            $this->novotonApi(),
            $this->hotelRepository()
        ));
    }

    public function guestDataService(): \Tygh\Addons\TravelCore\Contracts\GuestDataServiceInterface
    {
        return $this->resolve('guestDataService', fn() => new \Tygh\Addons\TravelCore\Services\GuestDataService());
    }

    public function searchService(): SearchServiceInterface
    {
        return $this->resolve('searchService', fn() => new SearchService(
            $this->cacheService()
        ));
    }

    public function roomPriceService(): RoomPriceServiceInterface
    {
        return $this->resolve('roomPriceService', fn() => new RoomPriceService());
    }

    public function securityService(): SecurityServiceInterface
    {
        return $this->resolve('securityService', fn() => new SecurityService());
    }

    public function cacheService(): CacheServiceInterface
    {
        return $this->resolve('cacheService', fn() => new CacheService());
    }

    public function validationHelper(): \Tygh\Addons\TravelCore\Services\ValidationHelper
    {
        return $this->resolve('validationHelper', fn() => new \Tygh\Addons\TravelCore\Services\ValidationHelper());
    }

    public function priceInfoService(): PriceInfoServiceInterface
    {
        return $this->resolve('priceInfoService', fn() => new PriceInfoService());
    }

    public function dateHelper(): \Tygh\Addons\TravelCore\Services\DateHelper
    {
        return $this->resolve('dateHelper', fn() => new \Tygh\Addons\TravelCore\Services\DateHelper());
    }

    public function configProvider(): ConfigProvider
    {
        return $this->resolve('configProvider', fn() => ConfigProvider::instance());
    }

    public function cronService(): CronServiceInterface
    {
        return $this->resolve('cronService', fn() => new CronService());
    }

    public function searchParameterNormalizer(): SearchParameterNormalizer
    {
        return $this->resolve('searchParameterNormalizer', fn() => new SearchParameterNormalizer());
    }

    public function searchResultFormatter(): SearchResultFormatter
    {
        return $this->resolve('searchResultFormatter', fn() => new SearchResultFormatter());
    }

    public function diagnosticsService(): DiagnosticsServiceInterface
    {
        return $this->resolve('diagnosticsService', fn() => new DiagnosticsService());
    }

    public function alternativeRequestService(): AlternativeRequestServiceInterface
    {
        return $this->resolve('alternativeRequestService', fn() => new AlternativeRequestService(
            $this->securityService()
        ));
    }

    public function bookingSubmissionService(): BookingSubmissionServiceInterface
    {
        return $this->resolve('bookingSubmissionService', fn() => new BookingSubmissionService(
            $this->bookingRepository(),
            $this->novotonApi()
        ));
    }

    public function currencyService(): \Tygh\Addons\TravelCore\Services\CurrencyService
    {
        return $this->resolve('currencyService', fn() => new \Tygh\Addons\TravelCore\Services\CurrencyService(
            ConfigProvider::getApiCurrency()
        ));
    }

    public function preOrderPriceVerifier(): PreOrderPriceVerifier
    {
        return $this->resolve('preOrderPriceVerifier', fn() => new PreOrderPriceVerifier());
    }

    public function priceChangeDetector(): \Tygh\Addons\TravelCore\Services\PriceChangeDetector
    {
        return $this->resolve('priceChangeDetector', fn() => new \Tygh\Addons\TravelCore\Services\PriceChangeDetector(
            (float) ConfigProvider::get('price_change_tolerance_percent', 1.0)
        ));
    }

    public function featureMapper(): FeatureMapper
    {
        return $this->resolve('featureMapper', fn() => new FeatureMapper(
            $this->featureMappingRepository()
        ));
    }

    public function novotonNormalizer(): ProviderNormalizerInterface
    {
        return $this->resolve('novotonNormalizer', fn() => new NovotonNormalizer());
    }

    public function novotonApi(): NovotonApi
    {
        return $this->resolve('novotonApi', fn() => new NovotonApi());
    }

    public function adminCronService(): AdminCronService
    {
        return $this->resolve('adminCronService', fn() => new AdminCronService(
            $this->novotonApi()
        ));
    }

    public function propertyTypeDetector(): PropertyTypeDetector
    {
        return $this->resolve('propertyTypeDetector', fn() => new PropertyTypeDetector());
    }

    // ═══════════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════════

    public function databaseHelper(): DatabaseHelperInterface
    {
        return $this->resolve('databaseHelper', fn() => new DatabaseHelper());
    }

    public function databaseIterator(): DatabaseIteratorInterface
    {
        return $this->resolve('databaseIterator', fn() => new DatabaseIterator());
    }

    public function productFactory(): ProductFactoryInterface
    {
        return $this->resolve('productFactory', fn() => new ProductFactory(
            $this->databaseHelper()
        ));
    }

    /**
     * BatchedHotelInfoSync is NOT a singleton — new instance each call.
     */
    public function batchedHotelInfoSync(): SyncInterface
    {
        if (isset($this->overrides['batchedHotelInfoSync'])) {
            return ($this->overrides['batchedHotelInfoSync'])();
        }
        return new BatchedHotelInfoSync();
    }
}

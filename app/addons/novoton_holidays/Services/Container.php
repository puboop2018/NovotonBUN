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
use Tygh\Addons\NovotonHolidays\Repository\BookingRepository;
use Tygh\Addons\NovotonHolidays\Repository\FacilityRepository;
use Tygh\Addons\NovotonHolidays\Repository\SyncLogRepository;
use Tygh\Addons\NovotonHolidays\Repository\AlternativeRequestRepository;
use Tygh\Addons\NovotonHolidays\Helpers\DatabaseHelper;
use Tygh\Addons\NovotonHolidays\Helpers\DatabaseIterator;
use Tygh\Addons\NovotonHolidays\Helpers\ProductFactory;
use Tygh\Addons\NovotonHolidays\Helpers\BatchedHotelInfoSync;
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

    public function hotelRepository(): HotelRepository
    {
        return $this->resolve('hotelRepository', fn() => new HotelRepository());
    }

    public function bookingRepository(): BookingRepository
    {
        return $this->resolve('bookingRepository', fn() => new BookingRepository());
    }

    public function facilityRepository(): FacilityRepository
    {
        return $this->resolve('facilityRepository', fn() => new FacilityRepository());
    }

    public function syncLogRepository(): SyncLogRepository
    {
        return $this->resolve('syncLogRepository', fn() => new SyncLogRepository());
    }

    public function alternativeRequestRepository(): AlternativeRequestRepository
    {
        return $this->resolve('alternativeRequestRepository', fn() => new AlternativeRequestRepository());
    }

    // ═══════════════════════════════════════════════════════════════════
    // SERVICES
    // ═══════════════════════════════════════════════════════════════════

    public function bookingService(): BookingService
    {
        return $this->resolve('bookingService', fn() => new BookingService(
            $this->guestDataService(),
            $this->roomPriceService(),
            $this->bookingRepository()
        ));
    }

    public function guestDataService(): GuestDataService
    {
        return $this->resolve('guestDataService', fn() => new GuestDataService());
    }

    public function searchService(): SearchService
    {
        return $this->resolve('searchService', fn() => new SearchService(
            $this->cacheService()
        ));
    }

    public function roomPriceService(): RoomPriceService
    {
        return $this->resolve('roomPriceService', fn() => new RoomPriceService());
    }

    public function securityService(): SecurityService
    {
        return $this->resolve('securityService', fn() => new SecurityService());
    }

    public function cacheService(): CacheService
    {
        return $this->resolve('cacheService', fn() => new CacheService());
    }

    public function validationHelper(): ValidationHelper
    {
        return $this->resolve('validationHelper', fn() => new ValidationHelper());
    }

    public function priceInfoService(): PriceInfoService
    {
        return $this->resolve('priceInfoService', fn() => new PriceInfoService());
    }

    public function dateHelper(): DateHelper
    {
        return $this->resolve('dateHelper', fn() => new DateHelper());
    }

    public function cronService(): CronService
    {
        return $this->resolve('cronService', fn() => new CronService());
    }

    public function diagnosticsService(): DiagnosticsService
    {
        return $this->resolve('diagnosticsService', fn() => new DiagnosticsService());
    }

    public function alternativeRequestService(): AlternativeRequestService
    {
        return $this->resolve('alternativeRequestService', fn() => new AlternativeRequestService(
            $this->alternativeRequestRepository()
        ));
    }

    public function bookingSubmissionService(): BookingSubmissionService
    {
        return $this->resolve('bookingSubmissionService', fn() => new BookingSubmissionService(
            $this->bookingRepository(),
            new NovotonApi()
        ));
    }

    // ═══════════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════════

    public function databaseHelper(): DatabaseHelper
    {
        return $this->resolve('databaseHelper', fn() => new DatabaseHelper());
    }

    public function databaseIterator(): DatabaseIterator
    {
        return $this->resolve('databaseIterator', fn() => new DatabaseIterator());
    }

    public function productFactory(): ProductFactory
    {
        return $this->resolve('productFactory', fn() => new ProductFactory(
            $this->databaseHelper()
        ));
    }

    /**
     * BatchedHotelInfoSync is NOT a singleton — new instance each call.
     */
    public function batchedHotelInfoSync(): BatchedHotelInfoSync
    {
        if (isset($this->overrides['batchedHotelInfoSync'])) {
            return ($this->overrides['batchedHotelInfoSync'])();
        }
        return new BatchedHotelInfoSync();
    }
}

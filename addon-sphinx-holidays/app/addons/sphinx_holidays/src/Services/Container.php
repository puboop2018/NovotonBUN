<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\Api\SphinxHttpClient;
use Tygh\Addons\SphinxHolidays\Api\SphinxNormalizer;
use Tygh\Addons\SphinxHolidays\Helpers\SphinxProductFactory;
use Tygh\Addons\SphinxHolidays\Helpers\SphinxProductFactoryInterface;
use Tygh\Addons\SphinxHolidays\Repository\DestinationRepository;
use Tygh\Addons\SphinxHolidays\Repository\DestinationWhitelistRepository;
use Tygh\Addons\SphinxHolidays\Repository\HotelRepository;
use Tygh\Addons\SphinxHolidays\Repository\SphinxBookingRepository;
use Tygh\Addons\SphinxHolidays\Repository\SyncLogRepository;
use Tygh\Addons\SphinxHolidays\Services\SphinxFeatureAssigner;
use Tygh\Addons\SphinxHolidays\SphinxApi;

/**
 * Sphinx Holidays dependency injection container.
 *
 * Lazily instantiates and caches service instances.
 * Supports factory overrides for testing via override().
 *
 * Usage:
 *   $destRepo = Container::getDestinationRepository();
 *   $factory  = Container::getProductFactory();
 */
class Container
{
    /** @var array<string, object> Resolved singleton instances */
    private static array $instances = [];

    /** @var array<string, callable> Factory overrides for testing */
    private static array $overrides = [];

    // ── Resolve helper ──

    /**
     * Resolve a service by ID, using override factory if registered.
     *
     * @param string   $id      Service identifier
     * @param callable $factory Default factory returning the instance
     * @return object
     */
    private static function resolve(string $id, callable $factory): object
    {
        if (isset(self::$overrides[$id])) {
            return self::$instances[$id] ??= (self::$overrides[$id])();
        }

        return self::$instances[$id] ??= $factory();
    }

    /**
     * Override a factory for testing.
     *
     * @param string   $id      Service identifier (e.g. 'destinationRepository')
     * @param callable $factory Factory returning the mock/stub
     */
    public static function override(string $id, callable $factory): void
    {
        self::$overrides[$id] = $factory;
        unset(self::$instances[$id]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // API / INFRASTRUCTURE
    // ═══════════════════════════════════════════════════════════════════

    public static function getHttpClient(): SphinxHttpClient
    {
        /** @var SphinxHttpClient */
        return self::resolve('httpClient', static fn() => new SphinxHttpClient(
            ConfigProvider::getApiBaseUrl(),
            ConfigProvider::getApiKey(),
            ConfigProvider::getMaxRetries(),
            ConfigProvider::getRetryDelayMs(),
            ConfigProvider::getRetryMultiplier(),
            ConfigProvider::getCircuitBreakerThreshold(),
            ConfigProvider::getCircuitBreakerTimeout(),
            ConfigProvider::isDebugLogging()
        ));
    }

    public static function getApi(): SphinxApi
    {
        /** @var SphinxApi */
        return self::resolve('api', static fn() => new SphinxApi(self::getHttpClient()));
    }

    public static function getNormalizer(): SphinxNormalizer
    {
        /** @var SphinxNormalizer */
        return self::resolve('normalizer', static fn() => new SphinxNormalizer());
    }

    public static function getFeatureAssigner(): SphinxFeatureAssigner
    {
        /** @var SphinxFeatureAssigner */
        return self::resolve('featureAssigner', static fn() => new SphinxFeatureAssigner(self::getNormalizer()));
    }

    public static function getCartService(): CartService
    {
        /** @var CartService */
        return self::resolve('cartService', static fn() => new CartService());
    }

    // ═══════════════════════════════════════════════════════════════════
    // REPOSITORIES
    // ═══════════════════════════════════════════════════════════════════

    public static function getDestinationRepository(): DestinationRepository
    {
        /** @var DestinationRepository */
        return self::resolve('destinationRepository', static fn() => new DestinationRepository());
    }

    public static function getHotelRepository(): HotelRepository
    {
        /** @var HotelRepository */
        return self::resolve('hotelRepository', static fn() => new HotelRepository());
    }

    public static function getBookingRepository(): SphinxBookingRepository
    {
        /** @var SphinxBookingRepository */
        return self::resolve('bookingRepository', static fn() => new SphinxBookingRepository());
    }

    public static function getDestinationWhitelistRepository(): DestinationWhitelistRepository
    {
        /** @var DestinationWhitelistRepository */
        return self::resolve('destinationWhitelistRepository', static fn() => new DestinationWhitelistRepository());
    }

    public static function getSyncLogRepository(): SyncLogRepository
    {
        /** @var SyncLogRepository */
        return self::resolve('syncLogRepository', static fn() => new SyncLogRepository());
    }

    // ═══════════════════════════════════════════════════════════════════
    // SERVICES / HELPERS
    // ═══════════════════════════════════════════════════════════════════

    public static function getProductFactory(): SphinxProductFactoryInterface
    {
        /** @var SphinxProductFactoryInterface */
        return self::resolve('productFactory', static fn() => new SphinxProductFactory(
            self::getHotelRepository(),
            self::getFeatureAssigner()
        ));
    }

    public static function getSecurityService(): SecurityService
    {
        /** @var SecurityService */
        return self::resolve('securityService', static fn() => new SecurityService());
    }

    public static function getPreOrderPriceVerifier(): PreOrderPriceVerifier
    {
        /** @var PreOrderPriceVerifier */
        return self::resolve('preOrderPriceVerifier', static fn() => new PreOrderPriceVerifier());
    }

    // ═══════════════════════════════════════════════════════════════════
    // LIFECYCLE
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Reset all cached instances (for testing).
     */
    public static function reset(): void
    {
        self::$instances = [];
        self::$overrides = [];
    }
}

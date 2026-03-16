<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\Api\SphinxHttpClient;
use Tygh\Addons\SphinxHolidays\Api\SphinxNormalizer;
use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Addons\SphinxHolidays\Services\SphinxFeatureAssigner;
use Tygh\Addons\SphinxHolidays\Repository\SphinxBookingRepository;

/**
 * Sphinx Holidays dependency injection container.
 *
 * Lazily instantiates and caches service instances.
 */
class Container
{
    private static ?SphinxHttpClient $httpClient = null;
    private static ?SphinxApi $api = null;
    private static ?SphinxNormalizer $normalizer = null;
    private static ?SphinxFeatureAssigner $featureAssigner = null;

    public static function getHttpClient(): SphinxHttpClient
    {
        if (self::$httpClient === null) {
            self::$httpClient = new SphinxHttpClient(
                ConfigProvider::getApiBaseUrl(),
                ConfigProvider::getApiKey(),
                ConfigProvider::getMaxRetries(),
                ConfigProvider::getRetryDelayMs(),
                ConfigProvider::getRetryMultiplier(),
                ConfigProvider::getCircuitBreakerThreshold(),
                ConfigProvider::getCircuitBreakerTimeout(),
                ConfigProvider::isDebugLogging()
            );
        }

        return self::$httpClient;
    }

    public static function getApi(): SphinxApi
    {
        if (self::$api === null) {
            self::$api = new SphinxApi(self::getHttpClient());
        }

        return self::$api;
    }

    public static function getNormalizer(): SphinxNormalizer
    {
        if (self::$normalizer === null) {
            self::$normalizer = new SphinxNormalizer();
        }

        return self::$normalizer;
    }

    public static function getFeatureAssigner(): SphinxFeatureAssigner
    {
        if (self::$featureAssigner === null) {
            self::$featureAssigner = new SphinxFeatureAssigner(self::getNormalizer());
        }

        return self::$featureAssigner;
    }

    private static ?SphinxBookingRepository $bookingRepo = null;
    private static ?SecurityService $securityService = null;
    private static ?PreOrderPriceVerifier $preOrderPriceVerifier = null;
    private static ?CacheEndpointService $cacheEndpointService = null;

    public static function getBookingRepository(): SphinxBookingRepository
    {
        if (self::$bookingRepo === null) {
            self::$bookingRepo = new SphinxBookingRepository();
        }

        return self::$bookingRepo;
    }

    public static function getSecurityService(): SecurityService
    {
        if (self::$securityService === null) {
            self::$securityService = new SecurityService();
        }

        return self::$securityService;
    }

    public static function getPreOrderPriceVerifier(): PreOrderPriceVerifier
    {
        if (self::$preOrderPriceVerifier === null) {
            self::$preOrderPriceVerifier = new PreOrderPriceVerifier();
        }

        return self::$preOrderPriceVerifier;
    }

    public static function getCacheEndpointService(): CacheEndpointService
    {
        if (self::$cacheEndpointService === null) {
            self::$cacheEndpointService = new CacheEndpointService(
                self::getApi(),
                ConfigProvider::getCommission(),
                ConfigProvider::shouldRoundPrices()
            );
        }

        return self::$cacheEndpointService;
    }

    /**
     * Reset all cached instances (for testing).
     */
    public static function reset(): void
    {
        self::$httpClient = null;
        self::$api = null;
        self::$normalizer = null;
        self::$featureAssigner = null;
        self::$bookingRepo = null;
        self::$securityService = null;
        self::$preOrderPriceVerifier = null;
        self::$cacheEndpointService = null;
    }
}

<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\Api\SphinxHttpClient;
use Tygh\Addons\SphinxHolidays\Api\SphinxNormalizer;
use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Addons\SphinxHolidays\Services\SphinxFeatureAssigner;

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

    /**
     * Reset all cached instances (for testing).
     */
    public static function reset(): void
    {
        self::$httpClient = null;
        self::$api = null;
        self::$normalizer = null;
        self::$featureAssigner = null;
    }
}

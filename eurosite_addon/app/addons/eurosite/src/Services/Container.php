<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

use Tygh\Addons\Eurosite\Api\EurositeApiClient;
use Tygh\Addons\Eurosite\EurositeHttpClient;
use Tygh\Addons\Eurosite\EurositeXmlBuilder;
use Tygh\Addons\Eurosite\EurositeXmlParser;

/**
 * Tiny service container for the Eurosite addon.
 *
 * Lazily wires the API stack from the addon settings (ConfigProvider) — HTTP
 * client, XML builder, XML parser, API facade — and caches the singletons per
 * request. This is the composition root the controllers/hooks call
 * (ConfigProvider is the only thing that reads the Registry).
 */
final class Container
{
    private static ?EurositeApiClient $api = null;

    private static ?EurositeHttpClient $http = null;

    public static function getApi(): EurositeApiClient
    {
        if (self::$api === null) {
            self::$api = new EurositeApiClient(
                self::getHttpClient(),
                self::getXmlBuilder(),
                new EurositeXmlParser(),
                ConfigProvider::getTourOpCode(),
            );
        }

        return self::$api;
    }

    public static function getHttpClient(): EurositeHttpClient
    {
        if (self::$http === null) {
            self::$http = new EurositeHttpClient(ConfigProvider::toClientSettings());
        }

        return self::$http;
    }

    public static function getXmlBuilder(): EurositeXmlBuilder
    {
        return new EurositeXmlBuilder(
            ConfigProvider::getApiUser(),
            ConfigProvider::getApiPassword(),
            ConfigProvider::getDefaultLanguage(),
        );
    }

    /**
     * Reset cached singletons (tests / after a settings change).
     */
    public static function reset(): void
    {
        self::$api = null;
        self::$http = null;
    }
}

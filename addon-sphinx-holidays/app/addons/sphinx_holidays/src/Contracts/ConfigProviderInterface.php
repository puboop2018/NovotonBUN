<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Contracts;

/**
 * Contract for Sphinx configuration provider.
 *
 * Provides typed access to addon settings stored in CS-Cart's registry.
 */
interface ConfigProviderInterface
{
    public static function getApiBaseUrl(): string;

    public static function getApiKey(): string;

    public static function isApiCacheEnabled(): bool;

    public static function getCacheTtlSearch(): int;

    public static function getDefaultCurrency(): string;

    public static function getCommission(): float;

    public static function shouldRoundPrices(): bool;

    public static function getHotelsCategoryId(): int;

    public static function getMaxRetries(): int;

    public static function getRetryDelayMs(): int;

    public static function getRetryMultiplier(): float;

    public static function isDebugLogging(): bool;

    public static function getCronAccessKey(): string;

    public static function getProductCodePrefix(): string;

    /** @return list<string> */
    public static function getSelectedCountryCodes(): array;

    public static function isConfigured(): bool;

    /** @return list<int> */
    public static function getAllowedDestinationIds(): array;
}

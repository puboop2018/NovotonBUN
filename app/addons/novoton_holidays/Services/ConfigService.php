<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Configuration Facade
 *
 * Thin facade that delegates to focused classes for backwards compatibility.
 * All 60+ existing ConfigService:: call sites continue to work unchanged.
 *
 * New code should prefer the focused classes directly:
 *   - ConfigProvider   — settings access, constants, environment
 *   - PathResolver     — addon path resolution
 *   - DirectoryManager — directory creation
 *
 * @package NovotonHolidays
 * @since 3.2.0 (facade since 3.3.0)
 */

namespace Tygh\Addons\NovotonHolidays\Services;

class ConfigService
{
    // ── Constants (delegated from ConfigProvider for backwards compatibility) ──

    const ADDON_ID                  = ConfigProvider::ADDON_ID;
    const API_DELAY_MS              = ConfigProvider::API_DELAY_MS;
    const DEFAULT_BATCH_SIZE        = ConfigProvider::DEFAULT_BATCH_SIZE;
    const DEFAULT_MAX_EXECUTION_TIME = ConfigProvider::DEFAULT_MAX_EXECUTION_TIME;
    const MIN_BATCH_SIZE            = ConfigProvider::MIN_BATCH_SIZE;
    const MAX_BATCH_SIZE            = ConfigProvider::MAX_BATCH_SIZE;
    const MIN_EXECUTION_TIME        = ConfigProvider::MIN_EXECUTION_TIME;
    const MAX_EXECUTION_TIME        = ConfigProvider::MAX_EXECUTION_TIME;
    const IMAGE_BASE_URL            = ConfigProvider::IMAGE_BASE_URL;
    const MAX_IMAGES_PER_HOTEL      = ConfigProvider::MAX_IMAGES_PER_HOTEL;
    const PRODUCT_CODE_PREFIX       = ConfigProvider::PRODUCT_CODE_PREFIX;
    const FULL_SYNC_INTERVAL_DAYS   = ConfigProvider::FULL_SYNC_INTERVAL_DAYS;
    const PRICE_SYNC_INTERVAL_DAYS  = ConfigProvider::PRICE_SYNC_INTERVAL_DAYS;
    const STALE_HOURS               = ConfigProvider::STALE_HOURS;

    // ── Settings Delegation (ConfigProvider) ──

    public static function reset(): void { ConfigProvider::reset(); }
    public static function isDebugMode(): bool { return ConfigProvider::isDebugMode(); }
    public static function isDebugLogging(): bool { return ConfigProvider::isDebugLogging(); }
    public static function isApiDisabled(): bool { return ConfigProvider::isApiDisabled(); }
    public static function isRoundPrices(): bool { return ConfigProvider::isRoundPrices(); }
    public static function isTestBooking(): bool { return ConfigProvider::isTestBooking(); }
    public static function isDeleteProductsOnUninstall(): bool { return ConfigProvider::isDeleteProductsOnUninstall(); }
    public static function getCommission(): float { return ConfigProvider::getCommission(); }
    public static function getCurrencyRiskCommission(): float { return ConfigProvider::getCurrencyRiskCommission(); }
    public static function getApiCurrency(): string { return ConfigProvider::getApiCurrency(); }
    public static function getApiUrl(): string { return ConfigProvider::getApiUrl(); }
    public static function getApiUser(): string { return ConfigProvider::getApiUser(); }
    public static function getApiPassword(): string { return ConfigProvider::getApiPassword(); }
    public static function getApiKey(): string { return ConfigProvider::getApiKey(); }
    public static function getCronAccessKey(): string { return ConfigProvider::getCronAccessKey(); }
    public static function getDefaultCountry(): string { return ConfigProvider::getDefaultCountry(); }
    public static function getLastExchangeRateUpdate(): string { return ConfigProvider::getLastExchangeRateUpdate(); }
    public static function getVersion(): string { return ConfigProvider::getVersion(); }
    public static function getSelectedCountries(): array { return ConfigProvider::getSelectedCountries(); }
    public static function getProductCodePrefixes(): array { return ConfigProvider::getProductCodePrefixes(); }
    public static function getFirstProductCodePrefix(): string { return ConfigProvider::getFirstProductCodePrefix(); }
    public static function getExcludedResorts(): array { return ConfigProvider::getExcludedResorts(); }
    public static function all(): array { return ConfigProvider::all(); }
    public static function getTimezone(): string { return ConfigProvider::getTimezone(); }
    public static function getAdminEmail(): string { return ConfigProvider::getAdminEmail(); }
    public static function getCompanyId(): int { return ConfigProvider::getCompanyId(); }

    /** @return mixed */
    public static function get(string $key, $default = null) { return ConfigProvider::get($key, $default); }

    // ── Path Delegation (PathResolver) ──

    public static function getPaths(): array { return PathResolver::getPaths(); }
    public static function getPath(string $key): string { return PathResolver::getPath($key); }

    // ── Directory Delegation (DirectoryManager) ──

    public static function ensureCacheDir(): bool { return DirectoryManager::ensureCacheDir(); }
    public static function ensureReportsDir(): bool { return DirectoryManager::ensureReportsDir(); }
}

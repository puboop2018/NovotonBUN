<?php
declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Contracts\TravelProviderInterface;

/**
 * Registry of active travel providers.
 *
 * Each API addon (novoton_holidays, sphinx_holidays) registers its provider
 * here during init.php. The registry resolves which provider handles a given
 * hotel and delegates API calls accordingly.
 */
class TravelProviderRegistry
{
    /** @var array<string, TravelProviderInterface> */
    private static array $providers = [];

    /**
     * Register a travel provider.
     */
    public static function register(TravelProviderInterface $provider): void
    {
        self::$providers[$provider->getName()] = $provider;
    }

    /**
     * Get a provider by name.
     */
    public static function get(string $name): ?TravelProviderInterface
    {
        return self::$providers[$name] ?? null;
    }

    /**
     * Get all registered providers.
     *
     * @return array<string, TravelProviderInterface>
     */
    public static function all(): array
    {
        return self::$providers;
    }

    /**
     * Get all active providers.
     *
     * @return array<string, TravelProviderInterface>
     */
    public static function active(): array
    {
        return array_filter(self::$providers, fn($p) => $p->isActive());
    }

    /**
     * Determine which provider handles a given hotel ID.
     *
     * Hotel IDs are prefixed with the provider name (e.g., "novoton_12345", "sphinx_s1-hotel-99").
     */
    public static function getProviderForHotel(string $hotelId): ?TravelProviderInterface
    {
        foreach (self::$providers as $name => $provider) {
            if (str_starts_with($hotelId, $name . '_')) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * Check if a provider is registered.
     */
    public static function has(string $name): bool
    {
        return isset(self::$providers[$name]);
    }

    /**
     * Reset registry (for testing).
     */
    public static function reset(): void
    {
        self::$providers = [];
    }
}

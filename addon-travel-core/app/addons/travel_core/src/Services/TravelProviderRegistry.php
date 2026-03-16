<?php
declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Contracts\BookingAdminProviderInterface;
use Tygh\Addons\TravelCore\Contracts\ProviderNormalizerInterface;

/**
 * Registry of active travel providers.
 *
 * Each API addon (novoton_holidays, sphinx_holidays) registers its provider
 * here during init.php. The registry resolves which provider handles a given
 * hotel and provides access to provider normalizers.
 */
class TravelProviderRegistry
{
    /**
     * Known provider addon names.
     * Used by travel_core to check dependencies at uninstall time.
     */
    public const KNOWN_PROVIDER_ADDONS = ['novoton_holidays', 'sphinx_holidays'];

    /** @var array<string, array{name: string, label: string, normalizer: ProviderNormalizerInterface}> */
    private static array $providers = [];

    /** @var array<string, BookingAdminProviderInterface> */
    private static array $adminProviders = [];

    /**
     * Register a travel provider.
     */
    public static function register(string $name, string $label, ProviderNormalizerInterface $normalizer): void
    {
        self::$providers[$name] = [
            'name' => $name,
            'label' => $label,
            'normalizer' => $normalizer,
        ];
    }

    /**
     * Get a provider entry by name.
     *
     * @return array{name: string, label: string, normalizer: ProviderNormalizerInterface}|null
     */
    public static function get(string $name): ?array
    {
        return self::$providers[$name] ?? null;
    }

    /**
     * Get the normalizer for a provider.
     */
    public static function getNormalizer(string $name): ?ProviderNormalizerInterface
    {
        return isset(self::$providers[$name]) ? self::$providers[$name]['normalizer'] : null;
    }

    /**
     * Get all registered providers.
     *
     * @return array<string, array{name: string, label: string, normalizer: ProviderNormalizerInterface}>
     */
    public static function all(): array
    {
        return self::$providers;
    }

    /**
     * Determine which provider handles a given hotel ID.
     *
     * Hotel IDs are prefixed with the provider name (e.g., "novoton_12345", "sphinx_s1-hotel-99").
     *
     * @return array{name: string, label: string, normalizer: ProviderNormalizerInterface}|null
     */
    public static function getProviderForHotel(string $hotelId): ?array
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
     * Register an admin provider for booking management.
     */
    public static function registerAdminProvider(string $name, BookingAdminProviderInterface $provider): void
    {
        self::$adminProviders[$name] = $provider;
    }

    /**
     * Get the admin provider for a given provider name.
     */
    public static function getAdminProvider(string $name): ?BookingAdminProviderInterface
    {
        return self::$adminProviders[$name] ?? null;
    }

    /**
     * Reset registry (for testing).
     */
    public static function reset(): void
    {
        self::$providers = [];
        self::$adminProviders = [];
    }
}

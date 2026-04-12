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
 * hotel and provides access to provider normalizers and booking admin providers.
 */
class TravelProviderRegistry
{
    /**
     * Known provider addon names.
     * Used by travel_core to check dependencies at uninstall time.
     */
    public const KNOWN_PROVIDER_ADDONS = ['novoton_holidays', 'sphinx_holidays'];

    /** @var array<string, array{name: string, label: string, normalizer: ProviderNormalizerInterface, booking_admin_provider?: BookingAdminProviderInterface, status_sync_callback?: callable, single_status_callback?: callable, scan_config?: array{table: string, id_col: string, json_col: string}}> */
    private static array $providers = [];

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
     * Set the booking admin provider for a registered provider.
     */
    public static function setBookingAdminProvider(string $name, BookingAdminProviderInterface $adminProvider): void
    {
        if (isset(self::$providers[$name])) {
            self::$providers[$name]['booking_admin_provider'] = $adminProvider;
        }
    }

    /**
     * Set callbacks for status sync operations.
     *
     * @param string $name Provider name
     * @param callable|null $bulkCallback Callback for bulk status sync (no args, returns ['checked'=>int,'changed'=>int])
     * @param callable|null $singleCallback Callback for single booking status check (booking_id arg)
     */
    public static function setStatusCallbacks(string $name, ?callable $bulkCallback, ?callable $singleCallback): void
    {
        if (isset(self::$providers[$name])) {
            if ($bulkCallback !== null) {
                self::$providers[$name]['status_sync_callback'] = $bulkCallback;
            }
            if ($singleCallback !== null) {
                self::$providers[$name]['single_status_callback'] = $singleCallback;
            }
        }
    }

    /**
     * Get the BookingAdminProviderInterface for a provider, if registered.
     */
    public static function getBookingAdminProvider(string $name): ?BookingAdminProviderInterface
    {
        return self::$providers[$name]['booking_admin_provider'] ?? null;
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
     * Register facility scan configuration for a provider.
     *
     * @param string $name    Provider name ('sphinx', 'novoton', etc.)
     * @param string $table   Database table containing hotel data
     * @param string $idCol   Column name for hotel ID
     * @param string $jsonCol Column name containing facilities JSON array
     */
    public static function setScanConfig(string $name, string $table, string $idCol, string $jsonCol): void
    {
        if (isset(self::$providers[$name])) {
            self::$providers[$name]['scan_config'] = [
                'table'    => $table,
                'id_col'   => $idCol,
                'json_col' => $jsonCol,
            ];
        }
    }

    /**
     * Get facility scan configuration for a provider.
     *
     * @return array{table: string, id_col: string, json_col: string}|null
     */
    public static function getScanConfig(string $name): ?array
    {
        return self::$providers[$name]['scan_config'] ?? null;
    }

    /**
     * Get all providers that have scan configuration registered.
     *
     * @return array<string, array{table: string, id_col: string, json_col: string}>
     */
    public static function getAllScanConfigs(): array
    {
        $configs = [];
        foreach (self::$providers as $name => $provider) {
            if (!empty($provider['scan_config'])) {
                $configs[$name] = $provider['scan_config'];
            }
        }
        return $configs;
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

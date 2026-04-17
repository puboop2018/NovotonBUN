<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\Contracts\CacheServiceInterface;
use Tygh\Addons\SphinxHolidays\Repository\SphinxCacheRepository;

/**
 * Cache service using the sphinx_cache table.
 *
 * Provides key-value caching with TTL for short-lived data (search results).
 * NOT for static data (destinations/hotels) — those use dedicated DB tables.
 */
class CacheService implements CacheServiceInterface
{
    private static ?SphinxCacheRepository $repo = null;

    private static function repo(): SphinxCacheRepository
    {
        return self::$repo ??= new SphinxCacheRepository();
    }

    /**
     * Get a cached value by key.
     *
     * @return array<int|string, mixed>|null Decoded data or null if missing/expired
     */
    public static function get(string $key): ?array
    {
        // Probabilistic cleanup: ~1% of reads trigger expired entry removal
        if (random_int(1, 100) === 1) {
            self::cleanup();
        }

        $row = self::repo()->findByKey($key);

        if (!$row || (int) $row['expires_at'] < time()) {
            return null;
        }

        $decoded = json_decode($row['cache_data'], true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Store a value in cache with a TTL.
     *
     * @param string $key Cache key
     * @param array<int|string, mixed> $data Data to cache (must be JSON-serializable)
     * @param int $ttl Time-to-live in seconds
     */
    public static function set(string $key, array $data, int $ttl): void
    {
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return;
        }

        self::repo()->upsert($key, $encoded, time() + $ttl);
    }

    /**
     * Delete a specific cache entry.
     */
    public static function delete(string $key): void
    {
        self::repo()->deleteByKey($key);
    }

    /**
     * Remove all expired entries.
     */
    public static function cleanup(): void
    {
        self::repo()->deleteExpired();
    }

    /**
     * Build a deterministic cache key from search parameters.
     * @param array<string, mixed> $params
     */
    public static function buildSearchKey(array $params): string
    {
        // Sort for deterministic key regardless of param order
        ksort($params);
        return 'search:' . md5((string) json_encode($params));
    }
}

<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

/**
 * Cache service using the sphinx_cache table.
 *
 * Provides key-value caching with TTL for short-lived data (search results).
 * NOT for static data (destinations/hotels) — those use dedicated DB tables.
 */
class CacheService
{
    /**
     * Get a cached value by key.
     *
     * @return array|null Decoded data or null if missing/expired
     */
    public static function get(string $key): ?array
    {
        // Probabilistic cleanup: ~1% of reads trigger expired entry removal
        if (mt_rand(1, 100) === 1) {
            self::cleanup();
        }

        $row = db_get_row(
            "SELECT cache_data, expires_at FROM ?:sphinx_cache WHERE cache_key = ?s",
            $key
        );

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
     * @param array $data Data to cache (must be JSON-serializable)
     * @param int $ttl Time-to-live in seconds
     */
    public static function set(string $key, array $data, int $ttl): void
    {
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return;
        }

        db_query(
            "INSERT INTO ?:sphinx_cache (cache_key, cache_data, expires_at)
             VALUES (?s, ?s, ?i)
             ON DUPLICATE KEY UPDATE cache_data = VALUES(cache_data), expires_at = VALUES(expires_at)",
            $key,
            $encoded,
            time() + $ttl
        );
    }

    /**
     * Delete a specific cache entry.
     */
    public static function delete(string $key): void
    {
        db_query("DELETE FROM ?:sphinx_cache WHERE cache_key = ?s", $key);
    }

    /**
     * Remove all expired entries.
     */
    public static function cleanup(): void
    {
        db_query("DELETE FROM ?:sphinx_cache WHERE expires_at < ?i", time());
    }

    /**
     * Build a deterministic cache key from search parameters.
     */
    public static function buildSearchKey(array $params): string
    {
        // Sort for deterministic key regardless of param order
        ksort($params);
        return 'search:' . md5(json_encode($params));
    }
}

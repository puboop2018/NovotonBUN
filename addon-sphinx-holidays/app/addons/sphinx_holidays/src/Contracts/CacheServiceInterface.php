<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Contracts;

/**
 * Contract for the Sphinx cache service.
 *
 * Provides database-backed caching for API responses (search results,
 * availability checks) with TTL-based expiration.
 */
interface CacheServiceInterface
{
    /**
     * Get a cached value by key.
     *
     * @return array<string, mixed>|null Cached data or null if expired/missing
     */
    public static function get(string $key): ?array;

    /**
     * Store a value in cache with a TTL.
     *
     * @param string $key  Cache key
     * @param array<string, mixed>  $data Data to cache
     * @param int    $ttl  Time-to-live in seconds
     */
    public static function set(string $key, array $data, int $ttl): void;

    /**
     * Delete a specific cache entry.
     */
    public static function delete(string $key): void;

    /**
     * Remove all expired cache entries.
     */
    public static function cleanup(): void;

    /**
     * Build a deterministic cache key from search parameters.
     * @param array<string, mixed> $params
     */
    public static function buildSearchKey(array $params): string;
}

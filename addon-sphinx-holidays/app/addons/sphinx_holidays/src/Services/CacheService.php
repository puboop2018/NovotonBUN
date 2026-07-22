<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\Repository\SphinxCacheRepository;
use Tygh\Addons\TravelCore\Contracts\CacheServiceInterface;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Cache service using the sphinx_cache table.
 *
 * Provides key-value caching with TTL for short-lived data (search results).
 * NOT for static data (destinations/hotels) — those use dedicated DB tables.
 *
 * Implements the provider-neutral travel_core cache contract (get/set/
 * delete/cleanup) as an instance service obtained via
 * Container::getCacheService() — this closed the last documented hold-out of
 * the shared contract (the service was previously all-static, and a static
 * API cannot implement an instance contract). Only arrays are cacheable on
 * this backend (values are stored as JSON rows).
 */
class CacheService implements CacheServiceInterface
{
    private const int DEFAULT_TTL = 300;

    private SphinxCacheRepository $repo;

    public function __construct(?SphinxCacheRepository $repo = null)
    {
        $this->repo = $repo ?? new SphinxCacheRepository();
    }

    /**
     * Get a cached value by key.
     *
     * @return array<string, mixed>|null Decoded data or null if missing/expired
     */
    public function get(string $key): ?array
    {
        // Probabilistic cleanup: ~1% of reads trigger expired entry removal
        if (random_int(1, 100) === 1) {
            $this->cleanup();
        }

        $row = $this->repo->findByKey($key);

        if ($row === null || TypeCoerce::toInt($row['expires_at']) < time()) {
            return null;
        }

        $decoded = json_decode(TypeCoerce::toString($row['cache_data']), true);
        return is_array($decoded) ? TypeCoerce::toStringMap($decoded) : null;
    }

    /**
     * Store a value in cache with a TTL. Non-array values are rejected
     * (this backend stores JSON rows of search-result shapes).
     *
     * @param int|null $ttl Time-to-live in seconds (null = 300s default)
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        if (!is_array($value)) {
            return false;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return false;
        }

        $this->repo->upsert($key, $encoded, time() + ($ttl ?? self::DEFAULT_TTL));

        return true;
    }

    /**
     * Delete a specific cache entry.
     */
    public function delete(string $key): bool
    {
        return $this->repo->deleteByKey($key) > 0;
    }

    /**
     * Remove all expired entries.
     *
     * @return int Number of expired entries removed
     */
    public function cleanup(): int
    {
        return $this->repo->deleteExpired();
    }

    /**
     * Build a deterministic cache key from search parameters.
     * @param array<string, mixed> $params
     */
    public static function buildSearchKey(array $params): string
    {
        // Sort for deterministic key regardless of param order.
        ksort($params);
        // Key version (v2): bumped when the cached result shape changed —
        // results are now filtered to immediate-confirmation offers, so pre-v2
        // entries (unfiltered, missing the confirmation field) must not be reused.
        return 'search:v2:' . md5((string) json_encode($params));
    }
}

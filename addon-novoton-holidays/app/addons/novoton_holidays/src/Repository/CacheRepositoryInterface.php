<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Repository;

/**
 * Interface for cache data access (novoton_cache table).
 *
 * @since 3.7.0
 */
interface CacheRepositoryInterface
{
    /**
     * Get a cached row by key.
     *
     * @return array{cache_data: string, expires_at: int}|null
     */
    public function findByKey(string $key): ?array;

    /**
     * Insert or replace a cache entry.
     */
    public function upsert(string $key, string $data, int $expiresAt): void;

    /**
     * Delete a cache entry by key.
     */
    public function deleteByKey(string $key): void;

    /**
     * Delete all cache entries, or those matching a key prefix.
     */
    public function deleteAll(?string $prefix = null): int;

    /**
     * Delete expired entries.
     */
    public function deleteExpired(): int;

    /**
     * Count total entries.
     */
    public function countAll(): int;

    /**
     * Count expired entries.
     */
    public function countExpired(): int;
}

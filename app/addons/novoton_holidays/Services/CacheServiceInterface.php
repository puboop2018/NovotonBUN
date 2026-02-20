<?php
declare(strict_types=1);
/**
 * Novoton Cache Service Interface
 *
 * Contract for cache operations: get, set, delete, clear, and remember.
 *
 * @package NovotonHolidays
 * @since 3.1.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

interface CacheServiceInterface
{
    /**
     * @return mixed|null Cached value or null if not found/expired
     */
    public function get(string $key);

    public function set(string $key, $value, ?int $ttl = null): bool;
    public function delete(string $key): bool;

    /**
     * @return int Number of entries cleared
     */
    public function clear(?string $prefix = null): int;

    /**
     * Get or compute and cache a value.
     *
     * @return mixed
     */
    public function remember(string $key, callable $callback, ?int $ttl = null);

    /**
     * @return int Number of expired entries removed
     */
    public function cleanup(): int;

    public function getStats(): array;
}

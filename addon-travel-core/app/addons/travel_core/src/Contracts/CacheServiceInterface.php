<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Contracts;

/**
 * Provider-neutral cache contract: the minimal get / set / delete / cleanup
 * surface a travel addon's value cache exposes, so cross-provider tooling and
 * future addons can depend on the abstraction rather than a concrete service.
 *
 * Novoton's CacheService is the reference implementation; its richer
 * clear() / remember() / getStats() extras live in its own extending interface.
 * Sphinx's CacheService implements this contract directly (instance service
 * via Container::getCacheService(); its static-API divergence was closed —
 * only buildSearchKey() remains static, as a pure key builder).
 */
interface CacheServiceInterface
{
    /**
     * @return mixed Cached value, or null if not found / expired.
     */
    public function get(string $key): mixed;

    /**
     * Store a value under a key.
     *
     * @param int|null $ttl Time-to-live in seconds (null = implementation default).
     * @return bool True when the value was stored.
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool;

    /**
     * @return bool True when an entry was removed.
     */
    public function delete(string $key): bool;

    /**
     * Remove all expired entries.
     *
     * @return int Number of expired entries removed.
     */
    public function cleanup(): int;
}

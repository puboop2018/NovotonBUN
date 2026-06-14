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
 *
 * Sphinx's cache is intentionally NOT a consumer: it is a static,
 * search-result-only API, and a static API cannot implement an instance
 * contract. That divergence is deliberate — see ARCHITECTURE_PLAN.md §3.
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

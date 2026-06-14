<?php

declare(strict_types=1);

/**
 * Novoton Cache Service Interface
 *
 * Extends the provider-neutral travel_core cache contract (get/set/delete/
 * cleanup) with novoton's richer extras: prefix clear, remember(), and stats.
 *
 * @package NovotonHolidays
 * @since 3.1.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\TravelCore\Contracts\CacheServiceInterface as CoreCacheServiceInterface;

interface CacheServiceInterface extends CoreCacheServiceInterface
{
    /**
     * @return int Number of entries cleared
     */
    public function clear(?string $prefix = null): int;

    /**
     * Get or compute and cache a value.
     */
    public function remember(string $key, callable $callback, ?int $ttl = null): mixed;

    /**
     * @return array<string, mixed>
     */
    public function getStats(): array;
}

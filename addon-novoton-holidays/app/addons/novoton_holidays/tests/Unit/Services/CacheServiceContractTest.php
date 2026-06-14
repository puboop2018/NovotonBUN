<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\CacheService;
use Tygh\Addons\NovotonHolidays\Services\CacheServiceInterface;
use Tygh\Addons\TravelCore\Contracts\CacheServiceInterface as CoreCacheServiceInterface;

/**
 * Locks the Phase 4 cache consolidation: novoton's CacheService must satisfy
 * the provider-neutral travel_core cache contract (get/set/delete/cleanup),
 * and novoton's richer local interface must extend the core one. Uses
 * reflection so no DB/filesystem-backed instance is constructed.
 */
#[CoversNothing]
class CacheServiceContractTest extends TestCase
{
    public function testCacheServiceImplementsCoreContract(): void
    {
        $this->assertTrue(
            is_subclass_of(CacheService::class, CoreCacheServiceInterface::class),
            'novoton CacheService must satisfy the travel_core CacheServiceInterface',
        );
    }

    public function testLocalInterfaceExtendsCoreContract(): void
    {
        $this->assertTrue(
            is_subclass_of(CacheServiceInterface::class, CoreCacheServiceInterface::class),
            'novoton CacheServiceInterface must extend the travel_core contract',
        );
    }

    public function testCoreContractDeclaresTheMinimalSurface(): void
    {
        $reflection = new \ReflectionClass(CoreCacheServiceInterface::class);
        $methods = array_map(static fn (\ReflectionMethod $m): string => $m->getName(), $reflection->getMethods());
        sort($methods);

        $this->assertSame(['cleanup', 'delete', 'get', 'set'], $methods);
    }
}

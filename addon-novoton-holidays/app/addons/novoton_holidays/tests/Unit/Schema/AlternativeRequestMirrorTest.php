<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the dual-write: every novoton alternative request is mirrored into the
 * shared travel_alternative_requests registry (provider='novoton',
 * provider_request_id linking back) and triggers the shared admin
 * notification — wrapped so a mirror failure never breaks the customer flow.
 */
final class AlternativeRequestMirrorTest extends TestCase
{
    public function testServiceMirrorsIntoSharedRegistryAndNotifies(): void
    {
        $path = dirname(__DIR__, 3) . '/src/Services/AlternativeRequestService.php';
        $src = (string) file_get_contents($path);

        self::assertStringContainsString('TravelCore\Repository\AlternativeRequestRepository', $src);
        self::assertStringContainsString('TravelCore\Services\AlternativeRequestNotifier', $src);
        self::assertStringContainsString("'provider' => 'novoton'", $src);
        self::assertStringContainsString("'provider_request_id' => \$requestId", $src);
        // Mirror failures must be swallowed + logged, not break the flow.
        self::assertStringContainsString('catch (\Throwable $mirrorError)', $src);
    }

    /**
     * Lifecycle propagation: provider-side transitions must reach the shared
     * mirror — status changes via update() (the choke point every mark*
     * helper funnels through), bulk expiry with the same criteria, and
     * mirror-row removal on delete. All best-effort (never break the
     * provider workflow).
     */
    public function testRepositoryPropagatesTransitionsToTheSharedMirror(): void
    {
        $path = dirname(__DIR__, 3) . '/src/Repository/AlternativeRequestRepository.php';
        $src = (string) file_get_contents($path);

        self::assertStringContainsString('SharedAlternativeRequestRepository', $src);
        self::assertStringContainsString("updateStatusByProviderRef('novoton', \$request_id", $src);
        self::assertStringContainsString("expireOlderThan(\$days, 'novoton')", $src);
        self::assertStringContainsString("deleteByProviderRef('novoton', \$request_id)", $src);
        self::assertStringContainsString('mirrorBestEffort', $src);
        self::assertStringContainsString('catch (\Throwable $mirrorError)', $src);
        // The propagation hangs off the generic status write, so every
        // transition helper (markAlternativesFound/markNotified) is covered.
        self::assertStringContainsString("isset(\$data['status'])", $src);
    }
}

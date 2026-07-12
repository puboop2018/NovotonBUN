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
}

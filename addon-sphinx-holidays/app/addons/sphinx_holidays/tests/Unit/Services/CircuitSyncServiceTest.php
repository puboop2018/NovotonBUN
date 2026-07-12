<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Services\CircuitSyncService;
use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Addons\SphinxHolidays\Tests\Support\DbStub;

/**
 * The circuits sync runs incrementally by default: sync(false) must read the
 * last-synced watermark (MAX(last_synced_at)) so only circuits changed since
 * then are re-fetched; sync(true) must NOT consult the watermark (full re-fetch).
 */
final class CircuitSyncServiceTest extends TestCase
{
    protected function setUp(): void
    {
        DbStub::reset();
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    public function testIncrementalSyncReadsTheWatermark(): void
    {
        $api = $this->createMock(SphinxApi::class);
        $api->method('getCircuits')->willReturn(null);

        $queries = [];
        DbStub::$getField = static function (string $q, ...$p) use (&$queries): string {
            $queries[] = $q;
            return '2026-07-01 00:00:00';
        };

        (new CircuitSyncService($api))->sync(false);

        self::assertStringContainsString(
            'MAX(last_synced_at) FROM ?:sphinx_circuits',
            implode("\n", $queries),
            'incremental sync must read the last-synced watermark',
        );
    }

    public function testFullSyncDoesNotReadTheWatermark(): void
    {
        $api = $this->createMock(SphinxApi::class);
        $api->method('getCircuits')->willReturn(null);

        $queries = [];
        DbStub::$getField = static function (string $q, ...$p) use (&$queries): string {
            $queries[] = $q;
            return '';
        };

        (new CircuitSyncService($api))->sync(true);

        self::assertStringNotContainsString(
            'MAX(last_synced_at) FROM ?:sphinx_circuits',
            implode("\n", $queries),
            'full sync must not consult the incremental watermark',
        );
    }
}

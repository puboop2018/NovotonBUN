<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Helpers\HotelProductLinkReconciler;
use Tygh\Addons\NovotonHolidays\Helpers\SyncLoggerInterface;
use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;

/**
 * Characterization coverage for HotelProductLinkReconciler — the hotel<->product
 * link reconciliation extracted from BatchedHotelInfoSyncV2. DB access is routed
 * through DbStub; the logger is mocked. Tests pin the re-link pass (orphaned
 * hotels matched by product-code prefix), the stale-reference cleanup, and the
 * no-op-when-nothing-changed behaviour.
 */
#[CoversClass(HotelProductLinkReconciler::class)]
class HotelProductLinkReconcilerTest extends TestCase
{
    private SyncLoggerInterface $logger;

    protected function setUp(): void
    {
        DbStub::reset();
        $this->logger = $this->createMock(SyncLoggerInterface::class);
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    private function reconciler(): HotelProductLinkReconciler
    {
        return new HotelProductLinkReconciler($this->logger, ['NVT']);
    }

    public function testNoOutputWhenNothingReconciled(): void
    {
        DbStub::$getFields = static fn (): array => [];   // no orphaned hotels
        DbStub::$query = static fn (): int => 0;          // cleanup clears nothing

        $this->logger->expects($this->never())->method('output');

        $this->reconciler()->reconcile();
    }

    public function testRelinksOrphanedHotelsByProductCodePrefix(): void
    {
        DbStub::$getFields = static fn (): array => ['H1', 'H2'];      // orphaned
        DbStub::$getHashSingleArray = static fn (): array => ['NVTH1' => '5']; // only H1 has a product

        $captured = [];
        DbStub::$query = static function (string $query, ...$params) use (&$captured): int {
            $captured[] = [$query, $params];
            return str_contains($query, 'LEFT JOIN') ? 0 : 1; // cleanup 0, link update 1
        };

        $this->logger->expects($this->once())->method('output')
            ->with($this->stringContains('re-linked 1 hotels, cleared 0'));

        $this->reconciler()->reconcile();

        // The link UPDATE was issued for H1 with its product id.
        $linkCalls = array_values(array_filter(
            $captured,
            static fn (array $c): bool => str_contains($c[0], 'SET product_id = ?i WHERE hotel_id = ?s'),
        ));
        $this->assertCount(1, $linkCalls);
        $this->assertSame(['5', 'H1'], $linkCalls[0][1]);
    }

    public function testClearsStaleReferences(): void
    {
        DbStub::$getFields = static fn (): array => [];   // no orphaned hotels
        DbStub::$query = static fn (string $query): int => str_contains($query, 'LEFT JOIN') ? 3 : 0;

        $this->logger->expects($this->once())->method('output')
            ->with($this->stringContains('re-linked 0 hotels, cleared 3'));

        $this->reconciler()->reconcile();
    }
}

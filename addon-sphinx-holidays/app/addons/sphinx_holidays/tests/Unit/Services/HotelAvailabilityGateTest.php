<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Api\SphinxHttpClient;
use Tygh\Addons\SphinxHolidays\Repository\HotelSkipRepository;
use Tygh\Addons\SphinxHolidays\Services\HotelAvailabilityGate;
use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Addons\SphinxHolidays\Tests\Support\ProductFnStub;

/**
 * Characterization coverage for HotelAvailabilityGate — the immediate-availability
 * gate extracted from HotelSyncService. The API, HTTP client and skip repository
 * are mocked; the gate is built with zero delays so the tests run without real
 * sleeps. They pin the delete/clear decision logic, the probed-destination
 * accounting, the circuit-breaker short-circuit, error handling, and the cursor
 * poll path.
 */
#[CoversClass(HotelAvailabilityGate::class)]
class HotelAvailabilityGateTest extends TestCase
{
    private SphinxApi $api;
    private SphinxHttpClient $httpClient;
    private HotelSkipRepository $skip;
    /** @var list<string> */
    private array $output = [];

    protected function setUp(): void
    {
        ProductFnStub::reset();
        $this->httpClient = $this->createMock(SphinxHttpClient::class);
        $this->httpClient->method('isCircuitOpen')->willReturn(false);
        $this->api = $this->createMock(SphinxApi::class);
        $this->api->method('getHttpClient')->willReturn($this->httpClient);
        $this->skip = $this->createMock(HotelSkipRepository::class);
        $this->output = [];
    }

    protected function tearDown(): void
    {
        ProductFnStub::reset();
    }

    private function gate(): HotelAvailabilityGate
    {
        // destDelayUs = 0, pollIntervalSecs = 0 → no real sleeps in tests.
        return new HotelAvailabilityGate($this->api, $this->skip, 0, 0);
    }

    private function sink(): callable
    {
        return function (string $m): void {
            $this->output[] = $m;
        };
    }

    private function outputContains(string $needle): bool
    {
        foreach ($this->output as $line) {
            if (str_contains($line, $needle)) {
                return true;
            }
        }
        return false;
    }

    public function testReturnsStatsForNoDestinations(): void
    {
        $this->skip->expects($this->never())->method('findAvailabilityGateCandidates');
        $stats = ['synced' => 3];

        $this->assertSame($stats, $this->gate()->apply('GR', [], $stats, $this->sink()));
    }

    public function testNoCandidatesShortCircuits(): void
    {
        $this->skip->method('findAvailabilityGateCandidates')->willReturn([]);
        $this->api->expects($this->never())->method('searchHotels');

        $stats = $this->gate()->apply('GR', [5], ['synced' => 1], $this->sink());

        $this->assertSame(['synced' => 1], $stats);
        $this->assertTrue($this->outputContains('no hotels to check'));
    }

    public function testDeletesUnavailableAndClearsAvailable(): void
    {
        $this->skip->method('findAvailabilityGateCandidates')->willReturn([
            ['hotel_id' => 'H1', 'destination_id' => 5, 'product_skip_reason' => ''],
            ['hotel_id' => 'H2', 'destination_id' => 5, 'product_skip_reason' => 'no_availability'],
        ]);
        // Only H2 has an immediate offer → H2 becomes bookable, H1 stays unavailable.
        $this->api->method('searchHotels')->willReturn([
            'results' => [['confirmation' => 'immediate', 'hotel_id' => 'H2']],
        ]);

        // H1 (destination probed, no immediate offer) is deleted outright;
        // H2 (was flagged, now available) gets its legacy flag cleared.
        $this->skip->expects($this->once())->method('deleteUnlinkedBatch')
            ->with(['H1'])->willReturn(1);
        $this->skip->expects($this->once())->method('clearSkipReasonBatch')
            ->with(['H2'], 'no_availability')->willReturn(1);

        $stats = $this->gate()->apply('GR', [5], [], $this->sink());

        $this->assertSame(1, $stats['availability_probed']);
        $this->assertSame(1, $stats['availability_deleted']);
        $this->assertSame(1, $stats['availability_cleared']);
        $this->assertSame(0, $stats['availability_errors']);
    }

    /**
     * "Hotels with immediate confirmation" extends to the product catalog:
     * a LINKED hotel that lost its immediate offer loses the CS-Cart product
     * first and its row second, so no unbookable hotel stays purchasable.
     */
    public function testLinkedUnavailableHotelLosesItsProductAndThenItsRow(): void
    {
        $this->skip->method('findAvailabilityGateCandidates')->willReturn([
            ['hotel_id' => 'H1', 'destination_id' => 5, 'product_id' => 42, 'product_skip_reason' => ''],
        ]);
        $this->api->method('searchHotels')->willReturn(['results' => []]);

        $this->skip->expects($this->once())->method('deleteUnlinkedBatch')
            ->with([])->willReturn(0);
        $this->skip->expects($this->once())->method('deleteBatch')
            ->with(['H1'])->willReturn(1);

        $stats = $this->gate()->apply('GR', [5], [], $this->sink());

        $this->assertSame([42], ProductFnStub::$deleted, 'the CS-Cart product is deleted exactly once');
        $this->assertSame(1, $stats['availability_deleted']);
        $this->assertSame(1, $stats['availability_products_deleted']);
    }

    /**
     * A product the platform refuses to delete blocks the hotel row: deleting
     * the row anyway would strand an orphan product that stays purchasable
     * with no hotel behind it. The pair is kept intact for the next run.
     */
    public function testProductThatRefusesDeletionKeepsItsHotelRowForTheNextRun(): void
    {
        ProductFnStub::$delete = static fn (int $id): bool => false;
        ProductFnStub::$getName = static fn (int $id): string => 'Sunis Hotel Su'; // still exists

        $this->skip->method('findAvailabilityGateCandidates')->willReturn([
            ['hotel_id' => 'H1', 'destination_id' => 5, 'product_id' => 42, 'product_skip_reason' => ''],
        ]);
        $this->api->method('searchHotels')->willReturn(['results' => []]);

        $this->skip->expects($this->once())->method('deleteBatch')
            ->with([])->willReturn(0);

        $stats = $this->gate()->apply('GR', [5], [], $this->sink());

        $this->assertSame(0, $stats['availability_deleted']);
        $this->assertSame(0, $stats['availability_products_deleted']);
        $this->assertTrue($this->outputContains('could not delete product 42'));
    }

    /**
     * A product_id pointing at a product that no longer exists (deleted by
     * hand, or by deduplication) must not immortalise the hotel row.
     */
    public function testAlreadyDeletedProductStillReleasesTheHotelRow(): void
    {
        ProductFnStub::$delete = static fn (int $id): bool => false;
        ProductFnStub::$getName = static fn (int $id): string => ''; // gone

        $this->skip->method('findAvailabilityGateCandidates')->willReturn([
            ['hotel_id' => 'H1', 'destination_id' => 5, 'product_id' => 42, 'product_skip_reason' => ''],
        ]);
        $this->api->method('searchHotels')->willReturn(['results' => []]);

        $this->skip->expects($this->once())->method('deleteBatch')
            ->with(['H1'])->willReturn(1);

        $stats = $this->gate()->apply('GR', [5], [], $this->sink());

        $this->assertSame(1, $stats['availability_deleted']);
        $this->assertSame(0, $stats['availability_products_deleted']);
    }

    /**
     * The probe is multi-window: a hotel with inventory only in a later
     * window (e.g. summer-only availability missing from the +14d probe)
     * must NOT be deleted.
     */
    public function testHotelAvailableOnlyInLaterWindowIsNotDeleted(): void
    {
        $this->skip->method('findAvailabilityGateCandidates')->willReturn([
            ['hotel_id' => 'H1', 'destination_id' => 5, 'product_skip_reason' => ''],
        ]);
        $calls = 0;
        $this->api->method('searchHotels')->willReturnCallback(function () use (&$calls): array {
            $calls++;
            // Window 1: nothing. Window 2: H1 has an immediate offer.
            return $calls === 1
                ? ['results' => []]
                : ['results' => [['confirmation' => 'immediate', 'hotel_id' => 'H1']]];
        });

        $this->skip->expects($this->once())->method('deleteUnlinkedBatch')
            ->with([])->willReturn(0);
        $this->skip->expects($this->once())->method('clearSkipReasonBatch')
            ->with([], 'no_availability')->willReturn(0);

        $stats = $this->gate()->apply('GR', [5], [], $this->sink());

        $this->assertGreaterThanOrEqual(2, $calls, 'later windows must be probed');
        $this->assertSame(0, $stats['availability_deleted']);
    }

    public function testStopsProbingFurtherWindowsOnceAllCandidatesAvailable(): void
    {
        $this->skip->method('findAvailabilityGateCandidates')->willReturn([
            ['hotel_id' => 'H1', 'destination_id' => 5, 'product_skip_reason' => ''],
        ]);
        $calls = 0;
        $this->api->method('searchHotels')->willReturnCallback(function () use (&$calls): array {
            $calls++;
            return ['results' => [['confirmation' => 'immediate', 'hotel_id' => 'H1']]];
        });
        $this->skip->method('deleteUnlinkedBatch')->willReturn(0);
        $this->skip->method('clearSkipReasonBatch')->willReturn(0);

        $this->gate()->apply('GR', [5], [], $this->sink());

        $this->assertSame(1, $calls, 'all candidates available after window 1 — remaining windows skipped');
    }

    public function testSearchRequestCarriesDestinationAndCurrency(): void
    {
        $this->skip->method('findAvailabilityGateCandidates')->willReturn([
            ['hotel_id' => 'H1', 'destination_id' => 5, 'product_skip_reason' => ''],
        ]);
        $captured = null;
        $this->api->method('searchHotels')->willReturnCallback(function (array $params) use (&$captured): array {
            $captured = $params;
            return ['results' => []];
        });
        $this->skip->method('deleteUnlinkedBatch')->willReturn(1);
        $this->skip->method('clearSkipReasonBatch')->willReturn(0);

        $this->gate()->apply('GR', [5], [], $this->sink());

        $this->assertIsArray($captured);
        $this->assertSame(5, $captured['destination_id']);
        $this->assertSame('EUR', $captured['currency']);
        $this->assertSame([['adults' => 2, 'children_ages' => []]], $captured['occupancy']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $captured['check_in']);
    }

    public function testCircuitBreakerStopsProbeAndDeletesNothing(): void
    {
        $httpClient = $this->createMock(SphinxHttpClient::class);
        $httpClient->method('isCircuitOpen')->willReturn(true);
        $api = $this->createMock(SphinxApi::class);
        $api->method('getHttpClient')->willReturn($httpClient);
        $api->expects($this->never())->method('searchHotels');

        $this->skip->method('findAvailabilityGateCandidates')->willReturn([
            ['hotel_id' => 'H1', 'destination_id' => 5, 'product_skip_reason' => ''],
        ]);
        // Nothing probed → nothing deleted or cleared (called with empty lists).
        $this->skip->expects($this->once())->method('deleteUnlinkedBatch')->with([])->willReturn(0);
        $this->skip->expects($this->once())->method('clearSkipReasonBatch')->with([], 'no_availability')->willReturn(0);

        $gate = new HotelAvailabilityGate($api, $this->skip, 0, 0);
        $stats = $gate->apply('GR', [5], [], $this->sink());

        $this->assertSame(0, $stats['availability_probed']);
        $this->assertTrue($this->outputContains('Circuit breaker open'));
    }

    public function testSearchErrorIsCountedAndNotProbed(): void
    {
        $this->skip->method('findAvailabilityGateCandidates')->willReturn([
            ['hotel_id' => 'H1', 'destination_id' => 5, 'product_skip_reason' => ''],
        ]);
        $this->api->method('searchHotels')->willReturn(null); // request failed

        // Destination not probed → H1 survives; nothing to clear.
        $this->skip->expects($this->once())->method('deleteUnlinkedBatch')->with([])->willReturn(0);
        $this->skip->method('clearSkipReasonBatch')->willReturn(0);

        $stats = $this->gate()->apply('GR', [5], [], $this->sink());

        $this->assertSame(0, $stats['availability_probed']);
        // One error per probe window (the destination is retried for each).
        $this->assertSame(3, $stats['availability_errors']);
    }

    public function testLegacyFlaggedCandidateStillUnavailableIsPurged(): void
    {
        // Rows flagged by the old flag-mode gate are candidates too — when
        // the probe still finds no immediate offer, they are deleted, so a
        // store that ran the previous behavior converges on first sync.
        $this->skip->method('findAvailabilityGateCandidates')->willReturn([
            ['hotel_id' => 'H9', 'destination_id' => 5, 'product_skip_reason' => 'no_availability'],
        ]);
        $this->api->method('searchHotels')->willReturn(['results' => []]);

        $this->skip->expects($this->once())->method('deleteUnlinkedBatch')
            ->with(['H9'])->willReturn(1);
        $this->skip->expects($this->once())->method('clearSkipReasonBatch')
            ->with([], 'no_availability')->willReturn(0);

        $stats = $this->gate()->apply('GR', [5], [], $this->sink());

        $this->assertSame(1, $stats['availability_deleted']);
    }

    public function testPollPathCollectsFromCursor(): void
    {
        $this->skip->method('findAvailabilityGateCandidates')->willReturn([
            ['hotel_id' => 'H1', 'destination_id' => 5, 'product_skip_reason' => 'no_availability'],
        ]);
        // Inline results empty, but a cursor is returned → poll picks up H1.
        $this->api->method('searchHotels')->willReturn(['results' => [], 'cursor' => 'CUR-1']);
        $this->api->expects($this->once())->method('getHotelResults')->with('', 'CUR-1')->willReturn([
            'results' => [['confirmation' => 'immediate', 'hotel_id' => 'H1']],
            'cursor' => '', // terminal page
        ]);

        $this->skip->method('deleteUnlinkedBatch')->willReturn(0);
        $this->skip->expects($this->once())->method('clearSkipReasonBatch')
            ->with(['H1'], 'no_availability')->willReturn(1);

        $stats = $this->gate()->apply('GR', [5], [], $this->sink());

        $this->assertSame(1, $stats['availability_cleared']);
        $this->assertSame(1, $stats['availability_probed']);
    }
}

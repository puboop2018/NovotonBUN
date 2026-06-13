<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Api\SphinxHttpClient;
use Tygh\Addons\SphinxHolidays\Repository\HotelSkipRepository;
use Tygh\Addons\SphinxHolidays\Services\HotelAvailabilityGate;
use Tygh\Addons\SphinxHolidays\SphinxApi;

/**
 * Characterization coverage for HotelAvailabilityGate — the immediate-availability
 * gate extracted from HotelSyncService. The API, HTTP client and skip repository
 * are mocked; the gate is built with zero delays so the tests run without real
 * sleeps. They pin the mark/clear decision logic, the probed-destination
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
        $this->httpClient = $this->createMock(SphinxHttpClient::class);
        $this->httpClient->method('isCircuitOpen')->willReturn(false);
        $this->api = $this->createMock(SphinxApi::class);
        $this->api->method('getHttpClient')->willReturn($this->httpClient);
        $this->skip = $this->createMock(HotelSkipRepository::class);
        $this->output = [];
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
        $this->assertTrue($this->outputContains('no unlinked hotels to check'));
    }

    public function testMarksUnavailableAndClearsAvailable(): void
    {
        $this->skip->method('findAvailabilityGateCandidates')->willReturn([
            ['hotel_id' => 'H1', 'destination_id' => 5, 'product_skip_reason' => ''],
            ['hotel_id' => 'H2', 'destination_id' => 5, 'product_skip_reason' => 'no_availability'],
        ]);
        // Only H2 has an immediate offer → H2 becomes bookable, H1 stays unavailable.
        $this->api->method('searchHotels')->willReturn([
            'results' => [['confirmation' => 'immediate', 'hotel_id' => 'H2']],
        ]);

        // H1 (no reason, destination probed) gets flagged; H2 (was flagged, now available) gets cleared.
        $this->skip->expects($this->once())->method('markSkippedBatch')
            ->with(['H1'], 'no_availability')->willReturn(1);
        $this->skip->expects($this->once())->method('clearSkipReasonBatch')
            ->with(['H2'], 'no_availability')->willReturn(1);

        $stats = $this->gate()->apply('GR', [5], [], $this->sink());

        $this->assertSame(1, $stats['availability_probed']);
        $this->assertSame(1, $stats['availability_gated']);
        $this->assertSame(1, $stats['availability_cleared']);
        $this->assertSame(0, $stats['availability_errors']);
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
        $this->skip->method('markSkippedBatch')->willReturn(1);
        $this->skip->method('clearSkipReasonBatch')->willReturn(0);

        $this->gate()->apply('GR', [5], [], $this->sink());

        $this->assertIsArray($captured);
        $this->assertSame(5, $captured['destination_id']);
        $this->assertSame('EUR', $captured['currency']);
        $this->assertSame([['adults' => 2, 'children_ages' => []]], $captured['occupancy']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $captured['check_in']);
    }

    public function testCircuitBreakerStopsProbeAndMarksNothing(): void
    {
        $httpClient = $this->createMock(SphinxHttpClient::class);
        $httpClient->method('isCircuitOpen')->willReturn(true);
        $api = $this->createMock(SphinxApi::class);
        $api->method('getHttpClient')->willReturn($httpClient);
        $api->expects($this->never())->method('searchHotels');

        $this->skip->method('findAvailabilityGateCandidates')->willReturn([
            ['hotel_id' => 'H1', 'destination_id' => 5, 'product_skip_reason' => ''],
        ]);
        // Nothing probed → nothing marked or cleared (called with empty lists).
        $this->skip->expects($this->once())->method('markSkippedBatch')->with([], 'no_availability')->willReturn(0);
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

        // Destination not probed → H1 not marked; nothing to clear.
        $this->skip->expects($this->once())->method('markSkippedBatch')->with([], 'no_availability')->willReturn(0);
        $this->skip->method('clearSkipReasonBatch')->willReturn(0);

        $stats = $this->gate()->apply('GR', [5], [], $this->sink());

        $this->assertSame(0, $stats['availability_probed']);
        $this->assertSame(1, $stats['availability_errors']);
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

        $this->skip->method('markSkippedBatch')->willReturn(0);
        $this->skip->expects($this->once())->method('clearSkipReasonBatch')
            ->with(['H1'], 'no_availability')->willReturn(1);

        $stats = $this->gate()->apply('GR', [5], [], $this->sink());

        $this->assertSame(1, $stats['availability_cleared']);
        $this->assertSame(1, $stats['availability_probed']);
    }
}

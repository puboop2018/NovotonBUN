<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Api\Contracts\DestinationApiClientInterface;
use Tygh\Addons\NovotonHolidays\Api\Contracts\NovotonApiKitInterface;
use Tygh\Addons\NovotonHolidays\Exceptions\ApiException;
use Tygh\Addons\NovotonHolidays\Helpers\ChangedHotelDetector;
use Tygh\Addons\NovotonHolidays\Helpers\SyncLoggerInterface;
use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;

/**
 * Characterization coverage for ChangedHotelDetector — the offers_update change
 * detection extracted from BatchedHotelInfoSyncV2. The API kit and logger are
 * mocked; DB access goes through DbStub. Tests pin the no-previous-sync
 * short-circuit, the union of changed + never-synced hotels, and that a
 * per-country API error is swallowed rather than aborting detection.
 */
#[CoversClass(ChangedHotelDetector::class)]
class ChangedHotelDetectorTest extends TestCase
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

    /** @param \SimpleXMLElement|\Throwable $offersResult */
    private function api(mixed $offersResult): NovotonApiKitInterface
    {
        $destinations = $this->createMock(DestinationApiClientInterface::class);
        if ($offersResult instanceof \Throwable) {
            $destinations->method('getOffersUpdate')->willThrowException($offersResult);
        } else {
            $destinations->method('getOffersUpdate')->willReturn($offersResult);
        }
        $api = $this->createMock(NovotonApiKitInterface::class);
        $api->method('destinations')->willReturn($destinations);
        return $api;
    }

    public function testReturnsEmptyWhenNoPreviousSync(): void
    {
        DbStub::$getField = static fn () => '';   // no completed hotelinfo sync yet

        $api = $this->createMock(NovotonApiKitInterface::class);
        $api->expects($this->never())->method('destinations');

        $this->assertSame([], (new ChangedHotelDetector($this->logger))->detect($api, ['GR']));
    }

    public function testUnionsChangedOffersWithNeverSynced(): void
    {
        DbStub::$getField = static fn (): string => '2026-01-01 00:00:00';
        DbStub::$getFields = static fn (): array => ['H9'];  // never-synced

        $xml = simplexml_load_string('<r><Offer><IdHotel>H5</IdHotel></Offer></r>');
        self::assertInstanceOf(\SimpleXMLElement::class, $xml);

        $result = (new ChangedHotelDetector($this->logger))->detect($this->api($xml), ['GR']);

        $this->assertSame(['H5', 'H9'], $result);
    }

    public function testApiErrorForCountryIsSwallowed(): void
    {
        DbStub::$getField = static fn (): string => '2026-01-01 00:00:00';
        DbStub::$getFields = static fn (): array => [];  // nothing never-synced either

        $result = (new ChangedHotelDetector($this->logger))
            ->detect($this->api(new ApiException('upstream down')), ['GR']);

        // Error was logged and skipped → no changed hotels, detection still returns cleanly.
        $this->assertSame([], $result);
    }
}

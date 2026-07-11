<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Repository\SphinxBookingRepository;
use Tygh\Addons\SphinxHolidays\Services\BookingAdminProvider;
use Tygh\Addons\SphinxHolidays\Services\BookingRetryService;
use Tygh\Addons\TravelCore\TravelConstants;

/**
 * Wires the "paid order, failed reservation" recovery path: the Retry action
 * must (a) render for failed bookings with the PROVIDER booking id and the
 * provider_action routing params — the previous action posted the unified
 * SURROGATE id to a travel_bookings.retry_booking mode that never existed,
 * so the button was a dead end — and (b) actually invoke BookingRetryService
 * when the unified controller dispatches retry_booking.
 */
#[CoversClass(BookingAdminProvider::class)]
final class BookingAdminProviderTest extends TestCase
{
    private function provider(?BookingRetryService $retry = null): BookingAdminProvider
    {
        return new BookingAdminProvider(
            $this->createMock(SphinxBookingRepository::class),
            $retry,
        );
    }

    public function testFailedBookingGetsRetryActionRoutedViaProviderAction(): void
    {
        // Enriched travel_bookings row: booking_id is the unified surrogate,
        // provider_booking_id is sphinx's own PK — retry needs the latter.
        $actions = $this->provider()->getAvailableActions([
            'booking_id' => 99,
            'provider_booking_id' => '7',
            'provider' => 'sphinx',
            'status' => TravelConstants::STATUS_FAILED,
        ]);

        self::assertCount(1, $actions);
        $action = $actions[0];
        self::assertSame('retry_booking', $action['name']);
        self::assertSame('travel_bookings.provider_action', $action['url']);
        self::assertSame('POST', $action['method']);
        self::assertSame('7', $action['booking_id'], 'retry must target the PROVIDER id, not the surrogate');
        self::assertSame(['provider_action' => 'retry_booking'], $action['extra_params']);
    }

    public function testNonFailedBookingsGetNoActions(): void
    {
        foreach ([TravelConstants::STATUS_CONFIRMED, TravelConstants::STATUS_PENDING, TravelConstants::STATUS_CANCELLED] as $status) {
            self::assertSame([], $this->provider()->getAvailableActions([
                'booking_id' => 1,
                'provider_booking_id' => '1',
                'status' => $status,
            ]));
        }
    }

    public function testHandleRetryBookingInvokesServiceAndReportsSuccess(): void
    {
        $retry = $this->createMock(BookingRetryService::class);
        $retry->expects(self::once())
            ->method('retry')
            ->with(7)
            ->willReturn(['success' => true, 'message' => 'Booking retry successful', 'booking_ref' => 'SPX-9']);

        $result = $this->provider($retry)->handleAction('retry_booking', ['booking_id' => '7']);

        self::assertSame('N', $result['notification']['type']);
        self::assertStringContainsString('SPX-9', $result['notification']['message']);
        self::assertStringContainsString('provider=sphinx', $result['redirect']);
    }

    public function testHandleRetryBookingReportsFailureReason(): void
    {
        $retry = $this->createMock(BookingRetryService::class);
        $retry->method('retry')
            ->willReturn(['success' => false, 'message' => 'Offer no longer available', 'booking_ref' => null]);

        $result = $this->provider($retry)->handleAction('retry_booking', ['booking_id' => '7']);

        self::assertSame('E', $result['notification']['type']);
        self::assertStringContainsString('Offer no longer available', $result['notification']['message']);
    }

    public function testHandleRetryBookingRejectsInvalidIdWithoutCallingService(): void
    {
        $retry = $this->createMock(BookingRetryService::class);
        $retry->expects(self::never())->method('retry');

        $result = $this->provider($retry)->handleAction('retry_booking', ['booking_id' => '0']);

        self::assertSame('E', $result['notification']['type']);
    }

    public function testUnknownActionWarns(): void
    {
        $result = $this->provider()->handleAction('nonsense', []);

        self::assertSame('W', $result['notification']['type']);
        self::assertStringContainsString('nonsense', $result['notification']['message']);
    }

    // ── pricing breakdown rows (pricing_json → formatted view rows) ─────────

    public function testDisplayDataFormatsPricingBreakdown(): void
    {
        $repo = $this->createMock(SphinxBookingRepository::class);
        $repo->method('findById')->with(7)->willReturn([
            'api_booking_ref' => 'ABC123XYZ',
            'offer_id' => 'OFF-1',
            'status' => TravelConstants::STATUS_CONFIRMED,
            'currency' => 'EUR',
            'pricing_json' => json_encode([
                'marketing_price' => 5000.0,
                'discount' => 1000.0,
                'selling_price' => 4000.0,
                'commission' => 400.0,
                'supplier_price' => 3600.0,
                'currency' => 'RON',
                'taxes' => [],
                'additional_fees' => [['name' => 'City tax', 'amount' => 12.5]],
            ]),
        ]);

        $display = (new BookingAdminProvider($repo, null))->getDisplayData('7');

        self::assertSame('ABC123XYZ', $display['provider_ref']);
        self::assertSame('ABC123XYZ', $display['booking_confirmation'], 'row label source for the unified view');
        // Money formatted in PHP (admin templates cannot use Smarty modifiers),
        // with the pricing object's own currency winning over the row currency.
        self::assertSame('5,000.00 RON', $display['api_marketing_price']);
        self::assertSame('1,000.00 RON', $display['api_discount']);
        self::assertSame('4,000.00 RON', $display['api_selling_price']);
        self::assertSame('400.00 RON', $display['api_commission']);
        self::assertSame('3,600.00 RON', $display['api_supplier_price']);
        self::assertArrayNotHasKey('api_taxes', $display, 'empty arrays are omitted');
        self::assertSame([['name' => 'City tax', 'amount' => 12.5]], $display['api_additional_fees']);
    }

    public function testDisplayDataWithoutPricingJsonEmitsNoPricingRows(): void
    {
        $repo = $this->createMock(SphinxBookingRepository::class);
        $repo->method('findById')->with(8)->willReturn([
            'offer_id' => 'OFF-2',
            'status' => TravelConstants::STATUS_PENDING,
        ]);

        $display = (new BookingAdminProvider($repo, null))->getDisplayData('8');

        foreach (['api_marketing_price', 'api_discount', 'api_selling_price', 'api_commission', 'api_supplier_price', 'api_taxes', 'api_additional_fees'] as $key) {
            self::assertArrayNotHasKey($key, $display);
        }
    }
}

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
}

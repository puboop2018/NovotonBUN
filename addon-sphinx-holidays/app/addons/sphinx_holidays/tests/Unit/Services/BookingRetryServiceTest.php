<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Repository\SphinxBookingRepository;
use Tygh\Addons\SphinxHolidays\Services\BookingRetryService;
use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Addons\TravelCore\TravelConstants;

/**
 * Regression guard for the retry submission path: it must submit the SAME
 * factory-built payload as order placement. The previous implementation
 * called occupancy-builder functions that were never defined anywhere, so
 * every retry threw "Call to undefined function", landed in the catch block,
 * and re-marked the booking failed — retries could never succeed.
 */
#[CoversClass(BookingRetryService::class)]
final class BookingRetryServiceTest extends TestCase
{
    /** @return array<string, mixed> */
    private function failedBookingRow(): array
    {
        return [
            'booking_id' => 7,
            'status' => TravelConstants::STATUS_FAILED,
            'offer_id' => 'OFF-77',
            'room_type' => 'DBL Superior',   // hotel booking (not circuit/package/experience)
            'order_id' => 85,
            'guest_email' => 'guest@example.ro',
            'guest_phone' => '+40737000000',
            'guests_data' => json_encode([
                'room1_adult_1' => ['name' => 'Pop, Ana', 'type' => 'adult', 'is_holder' => 1],
            ]),
            'total_price' => 725.0,
            'currency' => 'EUR',
        ];
    }

    public function testRetrySubmitsFactoryPayloadAndConfirms(): void
    {
        $repo = $this->createMock(SphinxBookingRepository::class);
        $api = $this->createMock(SphinxApi::class);

        $repo->method('findById')->with(7)->willReturn($this->failedBookingRow());

        // Verify step: decoded payload with offer signals → available.
        $api->method('verifyHotelOffer')->with('OFF-77')->willReturn([
            'available' => true,
            'selling_price' => 725.0,
        ]);

        $api->expects(self::once())
            ->method('bookHotel')
            ->with([
                'offer_id' => 'OFF-77',
                'guests' => [
                    'room1_adult_1' => ['name' => 'Pop, Ana', 'type' => 'adult', 'is_holder' => 1],
                ],
                'contact' => ['email' => 'guest@example.ro', 'phone' => '+40737000000'],
                'reference_code' => '85',
            ])
            // Documented book response shape (tests/Fixtures/api/hotels_book.json):
            // the voucher code is booking_confirmation_number, NOT booking_reference.
            ->willReturn([
                'order_id' => 123456,
                'contract_id' => 112233,
                'booking_confirmation_number' => 'SPX-REF-1',
                'reference_code' => '85',
                'status' => 'confirmed',
            ]);

        $repo->expects(self::once())
            ->method('updateApiResponse')
            ->with(7, 'SPX-REF-1', self::isString());

        // pending (before submit) then confirmed (after success)
        $statusUpdates = [];
        $repo->method('update')
            ->willReturnCallback(function (int $id, array $data) use (&$statusUpdates): bool {
                if (isset($data['status'])) {
                    $statusUpdates[] = $data['status'];
                }
                return true;
            });

        $result = (new BookingRetryService($api, $repo))->retry(7);

        self::assertTrue($result['success']);
        self::assertSame('SPX-REF-1', $result['booking_ref']);
        self::assertSame([TravelConstants::STATUS_PENDING, TravelConstants::STATUS_CONFIRMED], $statusUpdates);
    }

    public function testRetryRefusesNonFailedBookings(): void
    {
        $repo = $this->createMock(SphinxBookingRepository::class);
        $api = $this->createMock(SphinxApi::class);

        $row = $this->failedBookingRow();
        $row['status'] = TravelConstants::STATUS_CONFIRMED;
        $repo->method('findById')->willReturn($row);
        $api->expects(self::never())->method('bookHotel');

        $result = (new BookingRetryService($api, $repo))->retry(7);

        self::assertFalse($result['success']);
    }

    public function testUnavailableOfferAbortsBeforeSubmission(): void
    {
        $repo = $this->createMock(SphinxBookingRepository::class);
        $api = $this->createMock(SphinxApi::class);

        $repo->method('findById')->willReturn($this->failedBookingRow());
        // Explicitly unavailable offer payload
        $api->method('verifyHotelOffer')->willReturn(['available' => false]);
        $api->expects(self::never())->method('bookHotel');

        $result = (new BookingRetryService($api, $repo))->retry(7);

        self::assertFalse($result['success']);
        self::assertSame('Offer no longer available', $result['message']);
    }
}

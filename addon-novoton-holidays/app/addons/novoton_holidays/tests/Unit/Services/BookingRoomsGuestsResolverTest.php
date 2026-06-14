<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Repository\BookingRepositoryInterface;
use Tygh\Addons\NovotonHolidays\Services\BookingRoomsGuestsResolver;
use Tygh\Addons\TravelCore\Services\GuestDataNormalizer;

/**
 * Characterization coverage for BookingRoomsGuestsResolver — the rooms/guests
 * resolution extracted from BookingSubmissionService. Pins the single-room
 * synthesis when rooms_data is absent, the rooms_data JSON decode, and the
 * three-step guests fallback chain: cart data, DB re-fetch by booking id, then
 * an unassigned pending booking matched by hotel + dates.
 */
#[CoversClass(BookingRoomsGuestsResolver::class)]
class BookingRoomsGuestsResolverTest extends TestCase
{
    private GuestDataNormalizer $normalizer;
    private BookingRepositoryInterface $repo;

    protected function setUp(): void
    {
        $this->normalizer = $this->createMock(GuestDataNormalizer::class);
        $this->repo = $this->createMock(BookingRepositoryInterface::class);
    }

    private function resolver(): BookingRoomsGuestsResolver
    {
        return new BookingRoomsGuestsResolver($this->normalizer, $this->repo);
    }

    public function testSynthesizesSingleRoomWhenRoomsDataAbsent(): void
    {
        $this->repo->method('findUnassignedByHotelDates')->willReturn(null);

        [$rooms, $guests] = $this->resolver()->resolveRoomsAndGuests([
            'room_id' => 'DBL 2+1',
            'board_id' => 'AI',
            'package_name' => 'Summer',
            'check_in' => '2026-07-01',
            'check_out' => '2026-07-08',
            'adults' => 2,
            'children' => 1,
            'children_ages' => '5',
            'final_price' => 1000.0,
        ], false);

        $this->assertCount(1, $rooms);
        $this->assertSame('DBL 2+1', $rooms[0]['room_id']);
        $this->assertSame(2, $rooms[0]['adults']);
        $this->assertSame(1, $rooms[0]['children']);
        $this->assertSame([5], $rooms[0]['childrenAges']);
        $this->assertSame(1000.0, $rooms[0]['price']);
        $this->assertSame([], $guests);
    }

    public function testDecodesRoomsDataJson(): void
    {
        $this->repo->method('findUnassignedByHotelDates')->willReturn(null);

        [$rooms] = $this->resolver()->resolveRoomsAndGuests([
            'rooms_data' => json_encode([['room_id' => 'A'], ['room_id' => 'B']]),
        ], false);

        $this->assertCount(2, $rooms);
        $this->assertSame('A', $rooms[0]['room_id']);
        $this->assertSame('B', $rooms[1]['room_id']);
    }

    public function testGuestsFromCartData(): void
    {
        $this->normalizer->method('normalize')->willReturn([['name' => 'Cart Guest']]);

        [, $guests] = $this->resolver()->resolveRoomsAndGuests([
            'rooms_data' => json_encode([['room_id' => 'A']]),
            'guests_data' => [['name' => 'Cart Guest']],
        ], false);

        $this->assertSame([['name' => 'Cart Guest']], $guests);
    }

    public function testGuestsFallBackToDatabaseByBookingId(): void
    {
        // No cart guests; the DB re-fetch by booking id supplies them.
        $this->normalizer->method('normalize')->willReturn([['name' => 'DB Guest']]);
        $this->repo->method('getGuestsData')->with(42)->willReturn('[{"name":"DB Guest"}]');

        [, $guests] = $this->resolver()->resolveRoomsAndGuests([
            'rooms_data' => json_encode([['room_id' => 'A']]),
            'novoton_booking_id' => 42,
        ], false);

        $this->assertSame([['name' => 'DB Guest']], $guests);
    }

    public function testGuestsFallBackToUnassignedPendingBooking(): void
    {
        $this->normalizer->method('normalize')->willReturn([['name' => 'Pending Guest']]);
        $this->repo->method('findUnassignedByHotelDates')->willReturn([
            'guests_data' => '[{"name":"Pending Guest"}]',
            'holder_name' => 'Holder',
        ]);

        [, $guests] = $this->resolver()->resolveRoomsAndGuests([
            'rooms_data' => json_encode([['room_id' => 'A']]),
            'hotel_id' => 'H1',
            'check_in' => '2026-07-01',
            'check_out' => '2026-07-08',
        ], false);

        $this->assertSame([['name' => 'Pending Guest']], $guests);
    }
}

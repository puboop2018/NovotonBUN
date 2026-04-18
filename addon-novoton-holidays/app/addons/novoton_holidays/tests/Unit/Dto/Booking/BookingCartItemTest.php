<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Dto\Booking;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Dto\Booking\BoardSelection;
use Tygh\Addons\TravelCore\Dto\Booking\BookingCartItem;
use Tygh\Addons\TravelCore\Dto\Booking\BookingCartItemBuilder;
use Tygh\Addons\TravelCore\Dto\Booking\BookingPricing;
use Tygh\Addons\TravelCore\Dto\Booking\BookingTerms;
use Tygh\Addons\TravelCore\Dto\Booking\ContactInfo;
use Tygh\Addons\TravelCore\Dto\Booking\GuestList;
use Tygh\Addons\TravelCore\Dto\Booking\HotelSummary;
use Tygh\Addons\TravelCore\Dto\Booking\RoomSelection;
use Tygh\Addons\TravelCore\Dto\Booking\StayDates;

#[CoversClass(BookingCartItem::class)]
#[CoversClass(BookingCartItemBuilder::class)]
#[CoversClass(HotelSummary::class)]
#[CoversClass(RoomSelection::class)]
#[CoversClass(BoardSelection::class)]
#[CoversClass(StayDates::class)]
#[CoversClass(GuestList::class)]
#[CoversClass(ContactInfo::class)]
#[CoversClass(BookingTerms::class)]
#[CoversClass(BookingPricing::class)]
final class BookingCartItemTest extends TestCase
{
    private function makeItem(): BookingCartItem
    {
        return (new BookingCartItemBuilder())
            ->productId(4201)
            ->bookingId(99)
            ->packageName('Summer 2026 All Inclusive')
            ->numRooms(2)
            ->roomsData([
                ['adults' => 2, 'children' => 1, 'childrenAges' => [6]],
                ['adults' => 2, 'children' => 0, 'childrenAges' => []],
            ])
            ->hotel(new HotelSummary(
                hotelId: 'NVT12345',
                name: 'Palace Hotel',
                city: 'Barcelona',
                region: 'Catalonia',
                country: 'Spain',
            ))
            ->room(new RoomSelection('DBL', 'DBL', 'Double Room'))
            ->board(new BoardSelection('AI', 'All Inclusive'))
            ->stay(StayDates::fromDates('2026-07-01', '2026-07-08'))
            ->guests(GuestList::fromGuestsData(
                [
                    ['name' => 'Alice Example', 'type' => 'adult'],
                    ['name' => 'Bob Example', 'type' => 'adult'],
                    ['name' => 'Charlie Example', 'type' => 'child', 'age' => 6],
                    ['name' => 'Dana Example', 'type' => 'adult'],
                ],
                4,
                1,
            ))
            ->contact(new ContactInfo('alice@example.com', '+34-600-123-456'))
            ->terms(new BookingTerms(
                payment: 'Pay on arrival',
                paymentRaw: 'PAY_AT_HOTEL',
                cancellation: 'Free cancellation up to 48h before',
                cancellationRaw: 'FREE_48H',
            ))
            ->pricing(new BookingPricing(
                totalPrice: 1499.99,
                currency: 'EUR',
                remark: 'Sea view',
                important: '',
            ))
            ->build();
    }

    public function testToCartExtraIncludesAllKeys(): void
    {
        $extra = $this->makeItem()->toCartExtra();

        // Spot-check all 30+ keys used by CS-Cart cart system + downstream hooks.
        $this->assertTrue($extra['travel_booking']);
        $this->assertTrue($extra['novoton_booking']);
        $this->assertSame(99, $extra['novoton_booking_id']);
        $this->assertSame('NVT12345', $extra['hotel_id']);
        $this->assertSame('Palace Hotel', $extra['hotel_name']);
        $this->assertSame('Barcelona', $extra['hotel_city']);
        $this->assertSame('Catalonia', $extra['hotel_region']);
        $this->assertSame('Spain', $extra['hotel_country']);
        $this->assertSame('Summer 2026 All Inclusive', $extra['package_name']);
        $this->assertSame('DBL', $extra['room_id']);
        $this->assertSame('DBL', $extra['room_name']);
        $this->assertSame('Double Room', $extra['room_type_display']);
        $this->assertSame('AI', $extra['board_id']);
        $this->assertSame('All Inclusive', $extra['board_name']);
        $this->assertSame('2026-07-01', $extra['check_in']);
        $this->assertSame('2026-07-08', $extra['check_out']);
        $this->assertSame(7, $extra['nights']);
        $this->assertSame(4, $extra['adults']);
        $this->assertSame(1, $extra['children']);
        $this->assertSame('6', $extra['children_ages']);
        $this->assertSame(2, $extra['num_rooms']);
        $this->assertCount(2, $extra['rooms_data']);
        $this->assertSame('Alice Example, Bob Example, Charlie Example, Dana Example', $extra['guest_names']);
        $this->assertSame('Alice Example', $extra['holder_name']);
        $this->assertIsString($extra['guests_data']);
        $this->assertSame('alice@example.com', $extra['contact_email']);
        $this->assertSame('+34-600-123-456', $extra['contact_phone']);
        $this->assertSame('Pay on arrival', $extra['terms_of_payment']);
        $this->assertSame('Free cancellation up to 48h before', $extra['terms_of_cancellation']);
        $this->assertSame('PAY_AT_HOTEL', $extra['terms_of_payment_raw']);
        $this->assertSame('FREE_48H', $extra['terms_of_cancellation_raw']);
        $this->assertSame('Sea view', $extra['remark']);
        $this->assertSame('', $extra['important']);
        $this->assertSame(1499.99, $extra['total_price']);
        $this->assertSame('EUR', $extra['currency']);
    }

    public function testRoundTripThroughCartExtra(): void
    {
        $original = $this->makeItem();
        $rebuilt = BookingCartItem::fromCartExtra(
            $original->toCartExtra(),
            ['product_id' => $original->productId],
        );

        $this->assertEquals($original, $rebuilt);
    }

    public function testToCartProductRowOuterShape(): void
    {
        $row = $this->makeItem()->toCartProductRow();

        $this->assertSame(4201, $row['product_id']);
        $this->assertSame(1, $row['amount']);
        $this->assertSame(1499.99, $row['price']);
        $this->assertSame(1499.99, $row['base_price']);
        $this->assertSame(1499.99, $row['original_price']);
        $this->assertSame('Y', $row['stored_price']);
        $this->assertIsArray($row['extra']);
    }

    public function testBuilderRequiresHotel(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('hotel()');
        (new BookingCartItemBuilder())->build();
    }

    public function testFromCartExtraMinimalInput(): void
    {
        $item = BookingCartItem::fromCartExtra([]);

        $this->assertSame('', $item->hotel->hotelId);
        $this->assertSame('', $item->hotel->name);
        $this->assertSame(0, $item->bookingId);
        $this->assertSame(1, $item->numRooms);
        $this->assertSame(0.0, $item->pricing->totalPrice);
        $this->assertSame([], $item->roomsData);
        $this->assertSame(0, $item->guests->adults);
        $this->assertSame(0, $item->guests->children);
    }

    public function testStayDatesNightsDerivation(): void
    {
        $s = StayDates::fromDates('2026-07-01', '2026-07-08');
        $this->assertSame(7, $s->nights);

        $sameDay = StayDates::fromDates('2026-07-01', '2026-07-01');
        $this->assertSame(0, $sameDay->nights);

        $invalid = StayDates::fromDates('2026-07-08', '2026-07-01');
        $this->assertSame(0, $invalid->nights);
    }

    public function testGuestListFromGuestsDataExtractsHolderAndAges(): void
    {
        $g = GuestList::fromGuestsData(
            [
                ['name' => 'Parent 1', 'type' => 'adult'],
                ['name' => 'Parent 2', 'type' => 'adult'],
                ['name' => 'Kid', 'type' => 'child', 'age' => 8],
            ],
            2,
            1,
        );

        $this->assertSame('Parent 1', $g->holderName);
        $this->assertSame('Parent 1, Parent 2, Kid', $g->guestNamesCsv);
        $this->assertSame([8], $g->childrenAges);
        $this->assertSame('8', $g->childrenAgesCsv());
    }

    public function testGuestListFallsBackToAgesCsvWhenNoGuestRows(): void
    {
        $g = GuestList::fromGuestsData([], 2, 2, '5,10');
        $this->assertSame([5, 10], $g->childrenAges);
        $this->assertSame('5,10', $g->childrenAgesCsv());
    }

    public function testContactInfoFromBookingDataNestedContact(): void
    {
        $c = ContactInfo::fromBookingData([
            'contact' => ['email' => 'x@y.z', 'phone' => '555'],
        ]);
        $this->assertSame('x@y.z', $c->email);
        $this->assertSame('555', $c->phone);
    }

    public function testContactInfoFromBookingDataMissingContactGivesEmpties(): void
    {
        $c = ContactInfo::fromBookingData([]);
        $this->assertSame('', $c->email);
        $this->assertSame('', $c->phone);
    }

    public function testHotelSummaryUsesDefaultCountryWhenMissing(): void
    {
        $h = HotelSummary::fromCartExtra(['hotel_id' => 'X'], 'DEFAULT_COUNTRY');
        $this->assertSame('DEFAULT_COUNTRY', $h->country);
    }

    public function testRoomSelectionTypeDisplayMayBeEmpty(): void
    {
        $r = RoomSelection::fromCartExtra(['room_id' => 'DBL']);
        $this->assertSame('DBL', $r->roomId);
        $this->assertSame('', $r->typeDisplay);
    }

    public function testBoardSelectionPreservesStrings(): void
    {
        $b = BoardSelection::fromCartExtra(['board_id' => 'HB', 'board_name' => 'Half Board']);
        $this->assertSame('HB', $b->boardId);
        $this->assertSame('Half Board', $b->boardName);
    }
}

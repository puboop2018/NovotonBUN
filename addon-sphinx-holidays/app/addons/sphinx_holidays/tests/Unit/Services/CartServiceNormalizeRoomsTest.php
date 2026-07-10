<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Services\CartService;

/**
 * Pins the canonical rooms_data contract feeding travel_core's shared
 * booking_guest_cards.tpl partial: API quote rooms (package/circuit deliver
 * `name` + snake_case `children_ages`) normalize to room_name/adults/
 * children/childrenAges, and an empty quote synthesizes ONE room from the
 * booking-level occupancy — the case the old templates duplicated a whole
 * fallback branch for.
 */
#[CoversClass(CartService::class)]
final class CartServiceNormalizeRoomsTest extends TestCase
{
    public function testNormalizesApiQuoteRoomShape(): void
    {
        $rooms = CartService::normalizeRoomsForDisplay([
            ['code' => 'DBL', 'name' => 'Double Room', 'adults' => 2, 'children_ages' => ['7', 12]],
            ['name' => 'Single', 'adults' => 1],
        ], 2, '');

        self::assertSame([
            [
                'room_id' => 'DBL',
                'room_name' => 'Double Room',
                'board_name' => '',
                'adults' => 2,
                'children' => 2,
                'childrenAges' => [7, 12],
            ],
            [
                'room_id' => '',
                'room_name' => 'Single',
                'board_name' => '',
                'adults' => 1,
                'children' => 0,
                'childrenAges' => [],
            ],
        ], $rooms);
    }

    public function testAlreadyCanonicalRoomsPassThrough(): void
    {
        $rooms = CartService::normalizeRoomsForDisplay([
            ['room_id' => 'R1', 'room_name' => 'Suite', 'board_name' => 'AI', 'adults' => 3, 'childrenAges' => [5]],
        ], 2, '');

        self::assertSame('Suite', $rooms[0]['room_name']);
        self::assertSame('AI', $rooms[0]['board_name']);
        self::assertSame([5], $rooms[0]['childrenAges']);
        self::assertSame(1, $rooms[0]['children']);
    }

    public function testEmptyQuoteSynthesizesOneRoomFromBookingOccupancy(): void
    {
        $rooms = CartService::normalizeRoomsForDisplay([], 2, '7, 12');

        self::assertCount(1, $rooms);
        self::assertSame(2, $rooms[0]['adults']);
        self::assertSame(2, $rooms[0]['children']);
        self::assertSame([7, 12], $rooms[0]['childrenAges']);
    }

    public function testSynthesizedRoomWithNoChildrenAndZeroAdultsFloorsAtOneAdult(): void
    {
        $rooms = CartService::normalizeRoomsForDisplay([], 0, '');

        self::assertSame(1, $rooms[0]['adults']);
        self::assertSame(0, $rooms[0]['children']);
        self::assertSame([], $rooms[0]['childrenAges']);
    }
}

<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Helpers\BookingRoomAssembler;

/**
 * Characterization coverage for BookingRoomAssembler — the multi-room grouping
 * and per-group guest/room assembly extracted from BookingSubmissionService.
 * Pure transformation; the tests pin the package grouping, the guest-name
 * resolution and fallbacks, the child-age fallback, and the commission reversal.
 */
#[CoversClass(BookingRoomAssembler::class)]
class BookingRoomAssemblerTest extends TestCase
{
    private BookingRoomAssembler $assembler;

    protected function setUp(): void
    {
        $this->assembler = new BookingRoomAssembler();
    }

    // ── groupRoomsByPackage ──────────────────────────────────────────────────

    public function testGroupsRoomsSharingPackageAndDates(): void
    {
        $groups = $this->assembler->groupRoomsByPackage([
            ['package_name' => 'Summer', 'check_in' => '2026-07-01', 'check_out' => '2026-07-08'],
            ['package_name' => 'Summer', 'check_in' => '2026-07-01', 'check_out' => '2026-07-08'],
            ['package_name' => 'Winter', 'check_in' => '2026-12-01', 'check_out' => '2026-12-08'],
        ], []);

        $this->assertCount(2, $groups); // Summer (2 rooms) + Winter (1 room)
        $roomCounts = array_map(static fn (array $g): int => count($g['rooms']), array_values($groups));
        sort($roomCounts);
        $this->assertSame([1, 2], $roomCounts);

        // original_index is preserved on each grouped room
        $indices = [];
        foreach ($groups as $g) {
            foreach ($g['rooms'] as $room) {
                $indices[] = $room['original_index'];
            }
        }
        sort($indices);
        $this->assertSame([0, 1, 2], $indices);
    }

    public function testGroupRoomsFallsBackToBookingDefaults(): void
    {
        $groups = $this->assembler->groupRoomsByPackage(
            [[]], // room with no package/dates of its own
            ['package_name' => 'Default Pkg', 'check_in' => '2026-01-01', 'check_out' => '2026-01-08'],
        );

        $group = array_values($groups)[0];
        $this->assertSame('Default Pkg', $group['package_name']);
        $this->assertSame('2026-01-01', $group['check_in']);
    }

    public function testGroupRoomsSkipsNonArrayElements(): void
    {
        $groups = $this->assembler->groupRoomsByPackage(['not-an-array', ['package_name' => 'P']], []);

        $total = array_sum(array_map(static fn (array $g): int => count($g['rooms']), $groups));
        $this->assertSame(1, $total);
    }

    // ── buildGroupGuestsAndRooms ─────────────────────────────────────────────

    public function testBuildsGuestsRoomsAndReversesCommission(): void
    {
        $group = ['rooms' => [[
            'original_index' => 0,
            'adults' => 2,
            'children' => 1,
            'childrenAges' => [8],
            'price' => 110.0,
            'room_id' => 'R1',
            'board_id' => 'AI',
        ]]];
        $guestsData = [
            'room1_adult_1' => ['name' => 'Alice', 'age' => 30],
            'room1_adult_2' => ['name' => 'Bob'],
            'room1_child_1' => ['name' => 'Cara', 'age' => 8],
        ];

        [$allGuests, $apiRooms, $totalApiPrice, $totalGroupPrice] =
            $this->assembler->buildGroupGuestsAndRooms($group, $guestsData, [], 10.0);

        $this->assertCount(3, $allGuests);
        $this->assertSame('Alice', $allGuests[0]['name']);
        $this->assertSame('adult', $allGuests[0]['type']);
        $this->assertSame('child', $allGuests[2]['type']);
        $this->assertSame(8, $allGuests[2]['age']);

        $this->assertCount(1, $apiRooms);
        $this->assertSame('R1', $apiRooms[0]['room_id']);
        $this->assertSame('AI', $apiRooms[0]['board_id']);
        $this->assertCount(3, $apiRooms[0]['guests']);

        $this->assertSame(110.0, $totalGroupPrice);
        $this->assertEqualsWithDelta(100.0, $totalApiPrice, 0.0001); // 110 / 1.10
    }

    public function testFirstAdultFallsBackToHolderName(): void
    {
        $group = ['rooms' => [['original_index' => 0, 'adults' => 1, 'children' => 0, 'price' => 0]]];

        [$allGuests] = $this->assembler->buildGroupGuestsAndRooms(
            $group,
            [], // no guest names supplied
            ['holder_name' => 'Holder Name'],
            0.0,
        );

        $this->assertSame('Holder Name', $allGuests[0]['name']);
    }

    public function testChildAgeFallsBackToChildrenAges(): void
    {
        $group = ['rooms' => [[
            'original_index' => 0,
            'adults' => 0,
            'children' => 1,
            'childrenAges' => [5],
            'price' => 0,
        ]]];

        [$allGuests] = $this->assembler->buildGroupGuestsAndRooms(
            $group,
            ['room1_child_1' => ['name' => 'Kid']], // name but no age
            [],
            0.0,
        );

        $this->assertSame(5, $allGuests[0]['age']);
    }
}

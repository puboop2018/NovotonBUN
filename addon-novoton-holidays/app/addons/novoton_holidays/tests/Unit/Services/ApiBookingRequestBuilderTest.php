<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\ApiBookingRequestBuilder;

/**
 * Characterization coverage for ApiBookingRequestBuilder — the reservation API
 * payload construction extracted from BookingSubmissionService. Pins the holder
 * resolution (first guest, else booking holder, else "Guest"), the per-group
 * order-number suffix, and the single-room shortcut that pins room_id/board_id.
 */
#[CoversClass(ApiBookingRequestBuilder::class)]
class ApiBookingRequestBuilderTest extends TestCase
{
    private ApiBookingRequestBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ApiBookingRequestBuilder();
    }

    /** @return array<string, mixed> */
    private function group(): array
    {
        return [
            'package_name' => 'Summer',
            'check_in' => '2026-07-01',
            'check_out' => '2026-07-08',
            'rooms' => [['room_id' => 'DBL', 'board_id' => 'AI']],
        ];
    }

    public function testSingleGroupSingleRoomPayload(): void
    {
        $result = $this->builder->build(
            $this->group(),
            [['name' => 'John Doe']],
            [['Room' => 'DBL']],
            ['hotel_id' => 'H1', 'holder_name' => 'Holder', 'room_id' => 'RX', 'board_id' => 'BX'],
            555,
            1,
            1,
            'note',
        );

        $this->assertSame('H1', $result['hotel_id']);
        $this->assertSame('Summer', $result['package_name']);
        $this->assertSame('John Doe', $result['holder']); // first guest wins
        $this->assertSame('555', $result['order_num']);   // no suffix for a single group
        $this->assertSame('note', $result['comment']);
        $this->assertSame('DBL', $result['room_id']);     // single-room shortcut
        $this->assertSame('AI', $result['board_id']);
    }

    public function testHolderFallsBackToBookingHolder(): void
    {
        $result = $this->builder->build($this->group(), [], [], ['holder_name' => 'Booking Holder'], 1, 1, 1);

        $this->assertSame('Booking Holder', $result['holder']);
    }

    public function testHolderDefaultsToGuestWhenNothingAvailable(): void
    {
        $result = $this->builder->build($this->group(), [], [], [], 1, 1, 1);

        $this->assertSame('Guest', $result['holder']);
    }

    public function testOrderNumberSuffixedPerGroup(): void
    {
        $result = $this->builder->build($this->group(), [], [], [], 555, 2, 3);

        $this->assertSame('555-G2', $result['order_num']);
    }

    public function testMultiRoomGroupHasNoRoomShortcut(): void
    {
        $group = $this->group();
        $group['rooms'] = [['room_id' => 'DBL'], ['room_id' => 'SGL']];

        $result = $this->builder->build($group, [], [], [], 1, 1, 1);

        $this->assertArrayNotHasKey('room_id', $result);
        $this->assertArrayNotHasKey('board_id', $result);
    }
}

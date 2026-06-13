<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Repository\BookingSyncRepository;
use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;

/**
 * Characterization coverage for BookingSyncRepository — all novoton ->
 * travel_bookings mirroring, extracted from BookingRepository. Each test pins
 * the SQL and the parameters issued so the consolidation provably preserves
 * behaviour, including the field mapping, the upsert defaults, and the
 * empty-input guards. DB access is routed through DbStub.
 */
#[CoversClass(BookingSyncRepository::class)]
class BookingSyncRepositoryTest extends TestCase
{
    private BookingSyncRepository $repo;

    protected function setUp(): void
    {
        DbStub::reset();
        $this->repo = new BookingSyncRepository();
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    // ── upsertFromBooking ────────────────────────────────────────────────────

    public function testUpsertMapsFieldsAndUpserts(): void
    {
        $captured = [];
        DbStub::$query = static function (string $query, ...$params) use (&$captured): int {
            $captured = [$query, $params];
            return 1;
        };

        $this->repo->upsertFromBooking(77, [
            'order_id' => 5,
            'user_id' => 9,
            'hotel_id' => 'H1',
            'hotel_name' => 'Hotel One',
            'room_type' => 'Double',
            'board_id' => 'AI',
            'check_in' => '2026-07-01',
            'check_out' => '2026-07-08',
            'nights' => 7,
            'adults' => 2,
            'children' => 1,
            'children_ages' => '8',
            'total_price' => 1234.5,
            'currency' => 'EUR',
            'status' => 'confirmed',
            'guests_data' => '{"a":1}',
        ]);

        $this->assertStringContainsString(
            'INSERT INTO ?:travel_bookings ?e ON DUPLICATE KEY UPDATE ?u',
            $captured[0],
        );

        // db_query is called with the record twice (?e and ?u use the same array).
        $this->assertSame($captured[1][0], $captured[1][1]);

        $this->assertSame([
            'provider' => 'novoton',
            'provider_booking_id' => '77',
            'order_id' => 5,
            'user_id' => 9,
            'hotel_id' => 'H1',
            'hotel_name' => 'Hotel One',
            'room_name' => 'Double',
            'board_code' => 'AI',
            'check_in' => '2026-07-01',
            'check_out' => '2026-07-08',
            'nights' => 7,
            'adults' => 2,
            'children' => 1,
            'children_ages' => '8',
            'total_price' => 1234.5,
            'currency' => 'EUR',
            'status' => 'confirmed',
            'guests_json' => '{"a":1}',
        ], $captured[1][0]);
    }

    public function testUpsertAppliesDefaultsForMissingFields(): void
    {
        $captured = [];
        DbStub::$query = static function (string $query, ...$params) use (&$captured): int {
            $captured = [$query, $params];
            return 1;
        };

        $this->repo->upsertFromBooking(3, []);

        $record = $captured[1][0];
        $this->assertSame('novoton', $record['provider']);
        $this->assertSame('3', $record['provider_booking_id']);
        $this->assertSame(0, $record['order_id']);
        $this->assertSame(0, $record['user_id']);
        $this->assertSame(2, $record['adults']);
        $this->assertSame(0, $record['children']);
        $this->assertSame(0.0, $record['total_price']);
        $this->assertSame('EUR', $record['currency']);
        $this->assertSame('pending', $record['status']);
        $this->assertSame('{}', $record['guests_json']);
    }

    // ── applyBookingUpdate ───────────────────────────────────────────────────

    public function testApplyUpdateMapsOnlyMirroredFields(): void
    {
        $captured = [];
        DbStub::$query = static function (string $query, ...$params) use (&$captured): int {
            $captured = [$query, $params];
            return 1;
        };

        // room_type -> room_name, board_id -> board_code, guests_data -> guests_json;
        // unmapped novoton-only fields (e.g. api_request) are dropped.
        $this->repo->applyBookingUpdate(42, [
            'status' => 'cancelled',
            'room_type' => 'Suite',
            'board_id' => 'HB',
            'guests_data' => '{"x":2}',
            'api_request' => 'ignored',
        ]);

        $this->assertStringContainsString(
            "UPDATE ?:travel_bookings SET ?u WHERE provider = 'novoton' AND provider_booking_id = ?s",
            $captured[0],
        );
        // Order follows UPDATE_FIELD_MAP iteration, not the input array order.
        $this->assertSame([
            'room_name' => 'Suite',
            'board_code' => 'HB',
            'status' => 'cancelled',
            'guests_json' => '{"x":2}',
        ], $captured[1][0]);
        $this->assertSame('42', $captured[1][1]);
    }

    public function testApplyUpdateIsNoOpWhenNoMirroredFields(): void
    {
        $called = false;
        DbStub::$query = static function () use (&$called): int {
            $called = true;
            return 0;
        };

        $this->repo->applyBookingUpdate(42, ['api_request' => 'x', 'novoton_status' => 'ASK']);

        $this->assertFalse($called, 'no travel_bookings update when nothing mirrored changed');
    }

    // ── deleteByBookingId ────────────────────────────────────────────────────

    public function testDeleteByBookingId(): void
    {
        $captured = [];
        DbStub::$query = static function (string $query, ...$params) use (&$captured): int {
            $captured = [$query, $params];
            return 1;
        };

        $this->repo->deleteByBookingId(55);

        $this->assertStringContainsString(
            "DELETE FROM ?:travel_bookings WHERE provider = 'novoton' AND provider_booking_id = ?s",
            $captured[0],
        );
        $this->assertSame(['55'], $captured[1]);
    }

    // ── deleteOrphansOlderThan ───────────────────────────────────────────────

    public function testDeleteOrphansOlderThan(): void
    {
        $captured = [];
        DbStub::$query = static function (string $query, ...$params) use (&$captured): int {
            $captured = [$query, $params];
            return 4;
        };

        $this->repo->deleteOrphansOlderThan(48);

        $this->assertStringContainsString('DELETE tb FROM ?:travel_bookings tb', $captured[0]);
        $this->assertStringContainsString('INNER JOIN ?:novoton_bookings nb', $captured[0]);
        $this->assertStringContainsString('nb.order_id = 0', $captured[0]);
        $this->assertSame([48], $captured[1]);
    }

    // ── deleteByBookingIds ───────────────────────────────────────────────────

    public function testDeleteByBookingIdsShortCircuitsOnEmpty(): void
    {
        $called = false;
        DbStub::$query = static function () use (&$called): int {
            $called = true;
            return 0;
        };

        $this->repo->deleteByBookingIds([]);
        $this->assertFalse($called);
    }

    public function testDeleteByBookingIds(): void
    {
        $captured = [];
        DbStub::$query = static function (string $query, ...$params) use (&$captured): int {
            $captured = [$query, $params];
            return 2;
        };

        $this->repo->deleteByBookingIds(['10', '11']);

        $this->assertStringContainsString(
            "DELETE FROM ?:travel_bookings WHERE provider = 'novoton' AND provider_booking_id IN (?a)",
            $captured[0],
        );
        $this->assertSame([['10', '11']], $captured[1]);
    }

    // ── assignUser ───────────────────────────────────────────────────────────

    public function testAssignUserShortCircuitsOnEmpty(): void
    {
        $called = false;
        DbStub::$query = static function () use (&$called): int {
            $called = true;
            return 0;
        };

        $this->repo->assignUser(9, []);
        $this->assertFalse($called);
    }

    public function testAssignUser(): void
    {
        $captured = [];
        DbStub::$query = static function (string $query, ...$params) use (&$captured): int {
            $captured = [$query, $params];
            return 2;
        };

        $this->repo->assignUser(9, ['10', '11']);

        $this->assertStringContainsString(
            "UPDATE ?:travel_bookings SET user_id = ?i WHERE provider = 'novoton' AND provider_booking_id IN (?a)",
            $captured[0],
        );
        $this->assertSame([9, ['10', '11']], $captured[1]);
    }
}

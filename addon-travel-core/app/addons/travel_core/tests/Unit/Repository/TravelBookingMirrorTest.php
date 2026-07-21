<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Repository\TravelBookingMirror;
use Tygh\Addons\TravelCore\Tests\Support\DbStub;

/**
 * The consolidated provider → travel_bookings dual-write. Pins the upsert
 * shape (full record for ?e; the ?u update-set EXCLUDES order_id, which only
 * moves forward via the IF(VALUES(order_id) > 0, ...) clause — a re-upsert
 * must never reset a linked booking back to "Order ID -"), the
 * provider-parameterized partial update with field-map translation, the
 * no-op guard, delete, and the guests_json string-coercion guard on BOTH
 * paths — the update-path guard is new (it existed only on sphinx's insert
 * path before the hoist).
 */
#[CoversClass(TravelBookingMirror::class)]
final class TravelBookingMirrorTest extends TestCase
{
    /** @var list<array{string, list<mixed>}> */
    private array $queries = [];

    protected function setUp(): void
    {
        DbStub::reset();
        $this->queries = [];
        DbStub::$query = function (string $q, ...$p): int {
            $this->queries[] = [$q, $p];

            return 1;
        };
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    public function testUpsertBuildsProviderRecordWithDefaults(): void
    {
        (new TravelBookingMirror('sphinx'))->upsert(9, ['hotel_name' => 'Falez']);

        [$sql, $params] = $this->queries[0];
        self::assertStringContainsString('INSERT INTO ?:travel_bookings ?e ON DUPLICATE KEY UPDATE ?u', $sql);
        self::assertSame('sphinx', $params[0]['provider']);
        self::assertSame('9', $params[0]['provider_booking_id']);
        self::assertSame('Falez', $params[0]['hotel_name']);
        self::assertSame(2, $params[0]['adults']);
        self::assertSame('EUR', $params[0]['currency']);
        self::assertSame('pending', $params[0]['status']);
        self::assertSame('{}', $params[0]['guests_json']);
    }

    public function testUpsertNeverResetsALinkedOrderId(): void
    {
        // Bookings are born unlinked (order_id=0 at add-to-cart) and linked
        // later; a re-upsert without order_id in $data must NOT blank the
        // mirror's linked value — order_id only moves forward here.
        (new TravelBookingMirror('novoton'))->upsert(7, ['hotel_name' => 'Edart']);

        [$sql, $params] = $this->queries[0];
        // Insert row still carries the full record (order_id defaults to 0)…
        self::assertSame(0, $params[0]['order_id']);
        // …but the duplicate-key update-set excludes it; the preserving IF()
        // clause is the only writer.
        self::assertArrayNotHasKey('order_id', $params[1]);
        self::assertSame(
            array_diff_key($params[0], ['order_id' => true]),
            $params[1],
            'update-set must be the insert record minus order_id',
        );
        self::assertStringContainsString(
            'order_id = IF(VALUES(order_id) > 0, VALUES(order_id), order_id)',
            $sql,
        );

        // A caller that DOES pass a positive order_id forwards it on insert.
        (new TravelBookingMirror('novoton'))->upsert(8, ['order_id' => 12]);
        self::assertSame(12, $this->queries[1][1][0]['order_id']);
        self::assertArrayNotHasKey('order_id', $this->queries[1][1][1]);
    }

    public function testGuestsJsonGuardCoercesArraysOnBothPaths(): void
    {
        $mirror = new TravelBookingMirror('novoton');

        $mirror->upsert(1, ['guests_data' => ['rooms' => [['adults' => 2]]]]);
        $mirror->applyUpdate(1, ['guests_data' => ['rooms' => []]]);

        self::assertSame('{"rooms":[{"adults":2}]}', $this->queries[0][1][0]['guests_json']);
        self::assertSame('{"rooms":[]}', $this->queries[1][1][0]['guests_json']);
    }

    public function testApplyUpdateTranslatesFieldsAndBindsProvider(): void
    {
        (new TravelBookingMirror('novoton'))->applyUpdate(42, [
            'room_type' => 'Suite',
            'board_id' => 'HB',
            'status' => 'confirmed',
            'api_request' => 'not mirrored',
        ]);

        [$sql, $params] = $this->queries[0];
        self::assertStringContainsString('UPDATE ?:travel_bookings SET ?u WHERE provider = ?s AND provider_booking_id = ?s', $sql);
        self::assertSame(['room_name' => 'Suite', 'board_code' => 'HB', 'status' => 'confirmed'], $params[0]);
        self::assertSame('novoton', $params[1]);
        self::assertSame('42', $params[2]);
    }

    public function testApplyUpdateIsNoOpWithoutMirroredFields(): void
    {
        (new TravelBookingMirror('sphinx'))->applyUpdate(42, ['api_response' => 'x']);

        self::assertSame([], $this->queries);
    }

    public function testDeleteKeysOnProviderPair(): void
    {
        (new TravelBookingMirror('sphinx'))->deleteByProviderBookingId(7);

        [$sql, $params] = $this->queries[0];
        self::assertStringContainsString('DELETE FROM ?:travel_bookings WHERE provider = ?s AND provider_booking_id = ?s', $sql);
        self::assertSame(['sphinx', '7'], $params);
    }
}

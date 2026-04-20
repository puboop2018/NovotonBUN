<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Tygh\Addons\NovotonHolidays\Repository\BookingRepository;

/**
 * Integration tests for BookingRepository — the seed test that proves the
 * Docker + MySQL + transactional-rollback harness end-to-end.
 *
 * Each test inserts fixture data via the IntegrationTestCase base class,
 * exercises a single repository method against real MySQL, and asserts
 * hydrated return shapes. The transaction opened in setUp() is rolled back
 * in tearDown(), so no cleanup bookkeeping is needed.
 *
 * Placeholder coverage in this file:
 *   ?:  + ?i  → findById, findByOrderId
 *   ?s        → findBySessionId
 *   ?s  + ?i  → findByHotelId
 */
#[CoversClass(BookingRepository::class)]
#[Group('integration')]
final class BookingRepositoryIntegrationTest extends IntegrationTestCase
{
    private BookingRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new BookingRepository();
    }

    public function testFindByIdReturnsNullForMissingBooking(): void
    {
        self::assertNull($this->repo->findById(999999999));
    }

    public function testFindByIdHydratesExistingBooking(): void
    {
        $id = $this->insertBooking([
            'hotel_id'   => 'NVT_INT_1',
            'hotel_name' => 'Integration Hotel',
            'currency'   => 'EUR',
            'status'     => 'pending',
            'base_price' => '200.00',
            'total_price' => '230.00',
        ]);

        $row = $this->repo->findById($id);

        self::assertIsArray($row);
        self::assertSame('NVT_INT_1', $row['hotel_id']);
        self::assertSame('Integration Hotel', $row['hotel_name']);
        self::assertSame('pending', $row['status']);
        // CS-Cart's DB layer returns numeric columns as strings; verify the
        // real MySQL layer matches that contract so repository consumers
        // written against string-typed price columns stay correct.
        self::assertSame('200.00', (string) $row['base_price']);
        self::assertSame('230.00', (string) $row['total_price']);
    }

    public function testFindByOrderIdReturnsAllBookingsInInsertionOrder(): void
    {
        $idA = $this->insertBooking(['order_id' => 42, 'hotel_id' => 'H_A']);
        $idB = $this->insertBooking(['order_id' => 42, 'hotel_id' => 'H_B']);
        $idOther = $this->insertBooking(['order_id' => 99, 'hotel_id' => 'H_X']);

        $rows = $this->repo->findByOrderId(42);

        self::assertCount(2, $rows);
        self::assertSame($idA, (int) $rows[0]['booking_id']);
        self::assertSame($idB, (int) $rows[1]['booking_id']);
        foreach ($rows as $row) {
            self::assertNotSame($idOther, (int) $row['booking_id']);
        }
    }

    public function testFindBySessionIdExercisesStringPlaceholder(): void
    {
        $this->insertBooking([
            'order_id'   => 0,
            'session_id' => 'sess-abc-123',
            'hotel_id'   => 'H_S',
        ]);
        $this->insertBooking([
            'order_id'   => 0,
            'session_id' => 'sess-other',
            'hotel_id'   => 'H_OTHER',
        ]);

        $rows = $this->repo->findBySessionId('sess-abc-123');

        self::assertCount(1, $rows);
        self::assertSame('H_S', $rows[0]['hotel_id']);
    }

    public function testFindByHotelIdCombinesStringAndIntPlaceholders(): void
    {
        for ($n = 0; $n < 3; $n++) {
            $this->insertBooking(['hotel_id' => 'H_COMBO', 'order_id' => 1 + $n]);
        }
        $this->insertBooking(['hotel_id' => 'H_OTHER']);

        $rows = $this->repo->findByHotelId('H_COMBO', limit: 2);

        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame('H_COMBO', $row['hotel_id']);
        }
    }

    public function testRollbackIsolatesBookingsBetweenTests(): void
    {
        // An unusual hotel_id used only in this test. If the transactional
        // rollback in tearDown() is working, no earlier test's inserts
        // (even those using the same hotel_id) should leak here.
        $rows = $this->repo->findByHotelId('H_INSERTED_IN_THIS_TEST_ONLY');
        self::assertSame([], $rows);

        $this->insertBooking(['hotel_id' => 'H_INSERTED_IN_THIS_TEST_ONLY']);

        self::assertCount(1, $this->repo->findByHotelId('H_INSERTED_IN_THIS_TEST_ONLY'));
    }
}

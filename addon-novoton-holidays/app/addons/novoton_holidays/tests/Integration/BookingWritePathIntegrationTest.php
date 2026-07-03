<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Tygh\Addons\NovotonHolidays\Api\Contracts\ReservationApiClientInterface;
use Tygh\Addons\NovotonHolidays\Exceptions\ApiException;
use Tygh\Addons\NovotonHolidays\Repository\BookingRepository;
use Tygh\Addons\NovotonHolidays\Repository\BookingSyncRepositoryInterface;
use Tygh\Addons\NovotonHolidays\Services\BookingSubmissionService;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;

/**
 * DB-backed coverage of the booking MONEY PATH writes (audit C1 / H5).
 *
 * These are the tests the old suite structurally could not have: before the
 * nesting-aware withTransaction() fix, BookingRepository::create()/update()
 * opened their own transactions, which implicitly committed the harness's
 * isolation transaction. Now writes join it, so the full write path — the
 * novoton_bookings row, the travel_bookings mirror, and submitOrder's
 * API-success / API-failure recording — runs against a real database and
 * rolls back cleanly between tests.
 */
#[Group('integration')]
class BookingWritePathIntegrationTest extends IntegrationTestCase
{
    private BookingRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new BookingRepository();
    }

    /** @return array<string, mixed> */
    private function bookingData(array $overrides = []): array
    {
        return array_merge([
            'order_id'    => 9001,
            'product_id'  => 501,
            'hotel_id'    => 'H-INT-1',
            'hotel_name'  => 'Integration Hotel',
            'room_id'     => 'R1',
            'room_type'   => 'Double Room',
            'board_id'    => 'BB',
            'check_in'    => '2026-09-01',
            'check_out'   => '2026-09-08',
            'nights'      => 7,
            'adults'      => 2,
            'children'    => 0,
            'base_price'  => 100.00,
            'total_price' => 115.00,
            'currency'    => 'EUR',
            'status'      => 'pending',
            'guests_data' => '[{"name":"John Tester"}]',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function mirrorRow(int $bookingId): array
    {
        $stmt = $this->db()->prepare(
            "SELECT * FROM cscart_travel_bookings WHERE provider = 'novoton' AND provider_booking_id = ?",
        );
        $stmt->execute([(string) $bookingId]);
        $row = $stmt->fetch();
        return $row === false ? [] : $row;
    }

    /** @return array<string, mixed> */
    private function bookingRow(int $bookingId): array
    {
        $stmt = $this->db()->prepare('SELECT * FROM cscart_novoton_bookings WHERE booking_id = ?');
        $stmt->execute([$bookingId]);
        $row = $stmt->fetch();
        return $row === false ? [] : $row;
    }

    // ── Repository writes + travel_bookings mirror ──────────────────────────

    public function testCreatePersistsBookingAndTravelMirror(): void
    {
        $id = $this->repo->create($this->bookingData());

        $this->assertGreaterThan(0, $id);

        $booking = $this->bookingRow($id);
        $this->assertSame('H-INT-1', $booking['hotel_id']);
        $this->assertSame('pending', $booking['status']);
        $this->assertSame('115.00', $booking['total_price']);

        $mirror = $this->mirrorRow($id);
        $this->assertNotSame([], $mirror, 'travel_bookings mirror row must exist');
        $this->assertSame('Integration Hotel', $mirror['hotel_name']);
        $this->assertSame('Double Room', $mirror['room_name'], 'room_type must map to room_name');
        $this->assertSame('BB', $mirror['board_code'], 'board_id must map to board_code');
        $this->assertSame('pending', $mirror['status']);
    }

    public function testUpdatePropagatesMappedFieldsToMirror(): void
    {
        $id = $this->repo->create($this->bookingData());

        $this->repo->update($id, ['status' => 'confirmed', 'total_price' => 120.50]);

        $this->assertSame('confirmed', $this->bookingRow($id)['status']);
        $mirror = $this->mirrorRow($id);
        $this->assertSame('confirmed', $mirror['status']);
        $this->assertSame('120.50', $mirror['total_price']);
    }

    public function testUpdateOfUnmirroredFieldLeavesMirrorUntouched(): void
    {
        $id = $this->repo->create($this->bookingData());
        $before = $this->mirrorRow($id);

        // 'notes' is not in the mirror field map — the sync must be skipped.
        $this->repo->update($id, ['notes' => 'internal note']);

        $this->assertSame('internal note', $this->bookingRow($id)['notes']);
        $this->assertSame($before, $this->mirrorRow($id));
    }

    public function testDeleteRemovesBookingAndMirror(): void
    {
        $id = $this->repo->create($this->bookingData());

        $this->assertTrue($this->repo->delete($id));

        $this->assertSame([], $this->bookingRow($id));
        $this->assertSame([], $this->mirrorRow($id), 'mirror row must be deleted with the booking');
    }

    // ── Real transaction semantics (repository-owned) ───────────────────────

    public function testSyncFailureRollsBackTheBookingInsertForReal(): void
    {
        // Let the repository OWN its transaction so the ROLLBACK path executes
        // against the real database (not the harness's outer transaction).
        self::setBookingTxDepth(0);

        $failingSync = new class implements BookingSyncRepositoryInterface {
            public function upsertFromBooking(int $booking_id, array $data): void
            {
                throw new \RuntimeException('mirror write failed');
            }

            public function applyBookingUpdate(int $booking_id, array $data): void
            {
            }

            public function deleteByBookingId(int $booking_id): void
            {
            }

            public function deleteOrphansOlderThan(int $hours): void
            {
            }

            public function deleteByBookingIds(array $booking_ids): void
            {
            }

            public function assignUser(int $user_id, array $booking_ids): void
            {
            }
        };

        $repo = new BookingRepository($failingSync);
        $data = $this->bookingData(['order_id' => 9002]);

        try {
            $repo->create($data);
            $this->fail('expected the mirror failure to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('mirror write failed', $e->getMessage());
        } finally {
            self::setBookingTxDepth(1);
        }

        // The booking INSERT preceded the mirror failure — the repository's
        // transaction must have rolled it back. Nothing may persist.
        $count = $this->db()->query(
            "SELECT COUNT(*) FROM cscart_novoton_bookings WHERE order_id = 9002",
        )->fetchColumn();
        $this->assertSame(0, (int) $count, 'failed create must leave no partial booking row');
    }

    // ── BookingSubmissionService::submitOrder — the money path ──────────────

    /** @return array<string, mixed> */
    private function cart(array $extraOverrides = []): array
    {
        return [
            'notes' => '',
            'products' => [[
                'product_id' => 501,
                'item_id'    => 'itm-1',
                'price'      => 115.0,
                'extra'      => array_merge([
                    'novoton_booking'    => true,
                    'novoton_booking_id' => 0,
                    'hotel_id'           => 'H-INT-1',
                    'hotel_name'         => 'Integration Hotel',
                    'room_id'            => 'R1',
                    'room_type'          => 'Double Room',
                    'board_id'           => 'BB',
                    'check_in'           => '2026-09-01',
                    'check_out'          => '2026-09-08',
                    'adults'             => 2,
                    'children'           => 0,
                    'base_price'         => 100.0,
                    'total_price'        => 115.0,
                    'holder_name'        => 'John Tester',
                ], $extraOverrides),
            ]],
        ];
    }

    public function testSubmitOrderRecordsApiSuccessInBothTables(): void
    {
        $api = $this->createStub(ReservationApiClientInterface::class);
        $api->method('createReservation')->willReturn(new \SimpleXMLElement(
            '<r><IdNum>NVT-777</IdNum><Status>OK</Status><Price>115</Price><Currency>EUR</Currency><Quota>Y</Quota></r>',
        ));

        (new BookingSubmissionService($this->repo, $api))->submitOrder(9100, $this->cart());

        $booking = $this->db()->query(
            'SELECT * FROM cscart_novoton_bookings WHERE order_id = 9100',
        )->fetch();
        $this->assertNotFalse($booking, 'submitOrder must persist a booking row');
        $this->assertSame('confirmed', $booking['status'], "API 'OK' must map to internal 'confirmed'");
        $this->assertSame('NVT-777', $booking['novoton_invoice_id']);
        $this->assertStringContainsString('"IdNum":"NVT-777"', (string) $booking['api_response']);
        $this->assertSame(7, (int) $booking['nights']);

        $mirror = $this->mirrorRow((int) $booking['booking_id']);
        $this->assertSame('confirmed', $mirror['status'], 'mirror must reflect the confirmed status');
    }

    public function testSubmitOrderApiFailureIsRecordedNotLost(): void
    {
        $api = $this->createStub(ReservationApiClientInterface::class);
        $api->method('createReservation')->willThrowException(
            new ApiException('upstream rejected', 'res_new', 500),
        );

        (new BookingSubmissionService($this->repo, $api))->submitOrder(9200, $this->cart());

        // The C1 guarantee: an API failure must leave a FAILED booking row
        // (visible to admins), never a silently-lost or half-written one.
        $booking = $this->db()->query(
            'SELECT * FROM cscart_novoton_bookings WHERE order_id = 9200',
        )->fetch();
        $this->assertNotFalse($booking, 'failed submission must still persist the booking row');
        $this->assertSame('failed', $booking['status']);
        $this->assertStringContainsString('res_new', (string) $booking['notes']);
        $this->assertStringContainsString('HTTP 500', (string) $booking['notes']);

        $mirror = $this->mirrorRow((int) $booking['booking_id']);
        $this->assertSame('failed', $mirror['status'], 'mirror must reflect the failure');
    }

    public function testSubmitOrderWithApiDisabledSavesLocally(): void
    {
        \Tygh\Registry::set('addons.novoton_holidays', ['disable_api_submission' => 'Y', 'debug_logging' => 'N']);
        ConfigProvider::resetSettingsCache();
        try {
            $api = $this->createMock(ReservationApiClientInterface::class);
            $api->expects($this->never())->method('createReservation');

            (new BookingSubmissionService($this->repo, $api))->submitOrder(9300, $this->cart());

            $booking = $this->db()->query(
                'SELECT * FROM cscart_novoton_bookings WHERE order_id = 9300',
            )->fetch();
            $this->assertNotFalse($booking);
            $this->assertStringContainsString('API submission disabled', (string) $booking['notes']);
        } finally {
            \Tygh\Registry::set('addons.novoton_holidays', null);
            ConfigProvider::resetSettingsCache();
        }
    }
}

<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base class for DB-backed integration tests in the novoton_holidays addon.
 *
 * Each test runs inside a transaction that is rolled back in tearDown(),
 * guaranteeing isolation without fixture-teardown bookkeeping. Repositories
 * under test reach the DB through the global db_* functions defined in
 * tests/Integration/bootstrap_integration.php, which share the same PDO
 * connection this class exposes via $this->db().
 *
 * Caveat: DDL statements (CREATE/ALTER/TRUNCATE) implicitly commit in
 * MySQL and break the rollback guarantee. No test in the current suite
 * issues DDL — keep it that way.
 *
 * @package NovotonHolidays\Tests\Integration
 */
abstract class IntegrationTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->db()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->db()->inTransaction()) {
            $this->db()->rollBack();
        }
        parent::tearDown();
    }

    /**
     * Live PDO connection shared with the bootstrap's db_* globals. Use this
     * for raw fixture inserts that sidestep CS-Cart's placeholder layer.
     */
    protected function db(): PDO
    {
        return _novoton_integration_pdo();
    }

    /**
     * Insert a row into cscart_novoton_bookings and return its booking_id.
     * Non-null columns get sensible defaults; pass $overrides to customise.
     *
     * @param array<string, mixed> $overrides
     */
    protected function insertBooking(array $overrides = []): int
    {
        $row = array_merge([
            'order_id'      => 1,
            'product_id'    => 1,
            'hotel_id'      => 'TEST_HOTEL',
            'hotel_name'    => 'Test Hotel',
            'room_id'       => 'R1',
            'board_id'      => 'BB',
            'check_in'      => '2026-06-01',
            'check_out'     => '2026-06-08',
            'nights'        => 7,
            'base_price'    => '100.00',
            'total_price'   => '115.00',
            'currency'      => 'EUR',
            'status'        => 'pending',
        ], $overrides);

        $cols = array_keys($row);
        $placeholders = array_fill(0, count($cols), '?');
        $sql = sprintf(
            'INSERT INTO cscart_novoton_bookings (%s) VALUES (%s)',
            implode(', ', array_map(static fn (string $c): string => "`{$c}`", $cols)),
            implode(', ', $placeholders)
        );

        $stmt = $this->db()->prepare($sql);
        $stmt->execute(array_values($row));

        return (int) $this->db()->lastInsertId();
    }
}

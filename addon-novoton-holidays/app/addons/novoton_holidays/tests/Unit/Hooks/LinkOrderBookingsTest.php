<?php

declare(strict_types=1);

namespace {
    // Load the procedural hook functions under test in the GLOBAL namespace,
    // as CS-Cart loads them (pattern: travel_core's UpdateCscartCurrenciesTest).
    if (!defined('BOOTSTRAP')) {
        define('BOOTSTRAP', true);
    }

    require_once dirname(__DIR__, 3) . '/src/Services/ServiceLoader.php';
    require_once dirname(__DIR__, 3) . '/hooks/order_hooks.php';
}

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Hooks {

    use PHPUnit\Framework\TestCase;
    use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;

    /**
     * Regression (devx: Travel Bookings grid showed "Order ID: -" for booking #1
     * although CS-Cart order #83 existed): a booking left without order_id by a
     * historical submission bug stayed orphaned forever, because linking only
     * happened inline during checkout. fn_novoton_holidays_link_order_bookings
     * is the idempotent reconciler run (a) always after submitOrder in
     * place_order_post and (b) by travel_tools' "Reconcile booking–order links"
     * backfill via the travel_link_order_bookings hook.
     */
    final class LinkOrderBookingsTest extends TestCase
    {
        /** @var list<string> */
        private array $queries = [];

        /** @var array<int, int> booking_id => currently linked order_id */
        private array $currentOrders = [];

        protected function setUp(): void
        {
            DbStub::reset();
            $this->queries = [];

            DbStub::$getOrderInfo = static fn (int $order_id): array => [
                'order_id' => $order_id,
                'products' => [
                    // Unlinked novoton booking → must be linked
                    ['extra' => ['novoton_booking' => true, 'novoton_booking_id' => 1]],
                    // Already-linked novoton booking → must be left untouched
                    ['extra' => ['novoton_booking' => true, 'novoton_booking_id' => 2]],
                    // Foreign / plain product → ignored
                    ['extra' => ['sphinx_booking' => true, 'travel_booking_id' => 9]],
                ],
            ];

            // Current order per booking (the "already linked?" probe)
            DbStub::$getField = fn (string $query, ...$params): int
                => $this->currentOrders[(int) ($params[0] ?? 0)] ?? 0;

            DbStub::$query = function (string $query): int {
                $this->queries[] = $query;
                return 1;
            };
        }

        protected function tearDown(): void
        {
            DbStub::reset();
        }

        public function testLinksOnlyUnlinkedBookingsAndMirrorsToTravelBookings(): void
        {
            $this->currentOrders = [1 => 0, 2 => 99];

            $linked = \fn_novoton_holidays_link_order_bookings(83);

            self::assertSame(1, $linked, 'only the unlinked booking is linked');

            $updates = array_values(array_filter(
                $this->queries,
                static fn (string $q): bool => str_contains($q, 'UPDATE ?:novoton_bookings'),
            ));
            self::assertCount(1, $updates, 'exactly one novoton_bookings UPDATE (booking #2 already linked)');

            // The repository write mirrors into the shared table the admin
            // Travel Bookings grid reads — without this the grid keeps "-".
            self::assertNotEmpty(
                array_filter(
                    $this->queries,
                    static fn (string $q): bool => str_contains($q, 'UPDATE ?:travel_bookings'),
                ),
                'order link must be mirrored into travel_bookings',
            );
        }

        public function testIdempotentWhenEverythingAlreadyLinked(): void
        {
            $this->currentOrders = [1 => 83, 2 => 83];

            self::assertSame(0, \fn_novoton_holidays_link_order_bookings(83));
            self::assertSame(
                [],
                array_filter(
                    $this->queries,
                    static fn (string $q): bool => str_contains($q, 'UPDATE ?:novoton_bookings'),
                ),
                'no writes when all bookings are already linked',
            );
        }

        public function testHookAccumulatorWiresIntoTravelToolsBackfill(): void
        {
            $this->currentOrders = [1 => 0, 2 => 99];

            $linked = 5;
            \fn_novoton_holidays_travel_link_order_bookings(83, $linked);

            self::assertSame(6, $linked, 'hook adds its newly-linked count to the accumulator');
        }

        public function testReturnsZeroForMissingOrderOrProducts(): void
        {
            DbStub::$getOrderInfo = static fn (int $order_id): array => [];

            self::assertSame(0, \fn_novoton_holidays_link_order_bookings(83));
            self::assertSame(0, \fn_novoton_holidays_link_order_bookings(0));
        }
    }
}

<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the Order ID self-heal on the shared bookings grid: when the listing
 * contains unlinked rows (order_id <= 0), travel_bookings.manage replays the
 * providers' order-link reconcilers over the newest orders — the same
 * travel_link_order_bookings hook the Travel Tools button fires — and
 * re-fetches, so any booking whose order exists shows its Order ID without
 * manual reconciling. Throttled via ?:storage_data; bookings whose checkout
 * never completed legitimately keep "-".
 */
final class BookingsAutolinkTest extends TestCase
{
    public function testManageModeAutolinksAndRefetches(): void
    {
        $controller = dirname(__DIR__, 3) . '/controllers/backend/travel_bookings.php';
        self::assertFileExists($controller);
        $src = (string) file_get_contents($controller);

        // The helper exists, is throttled, replays the reconcile hook over
        // the newest orders, and reports whether anything was linked.
        self::assertStringContainsString('function _travel_bookings_autolink_if_needed(array $bookings): bool', $src);
        self::assertStringContainsString("fn_get_storage_data('travel_bookings_autolink_ts')", $src);
        self::assertStringContainsString("fn_set_hook('travel_link_order_bookings'", $src);
        self::assertStringContainsString('ORDER BY order_id DESC LIMIT 100', $src);

        // The manage mode calls it and RE-FETCHES on success so the healed
        // Order IDs render on the same page load.
        $call = strpos($src, 'if (_travel_bookings_autolink_if_needed($bookings)) {');
        self::assertNotFalse($call, 'manage mode must invoke the self-heal');
        $after = substr($src, $call, 400);
        self::assertStringContainsString(
            '$bookingRepo->getPaginated($condition, $sortColumn, $sortOrder, $offset, $limit)',
            $after,
            'a successful heal must re-fetch the listing',
        );
    }
}

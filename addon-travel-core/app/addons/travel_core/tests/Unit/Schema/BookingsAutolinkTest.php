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
 *
 * Candidate orders come pre-filtered from OrderLinkCandidateRepository
 * (unlinked-booking time window + order_details booking-item probe) because
 * each hook replay runs a full fn_get_order_info() per provider — the old
 * blind newest-100 walk cost thousands of queries per throttle window.
 */
final class BookingsAutolinkTest extends TestCase
{
    public function testManageModeAutolinksAndRefetches(): void
    {
        $controller = dirname(__DIR__, 3) . '/controllers/backend/travel_bookings.php';
        self::assertFileExists($controller);
        $src = (string) file_get_contents($controller);

        // The helper exists, is throttled, replays the reconcile hook over
        // pre-filtered candidates, and short-circuits (no replay, no
        // re-fetch) when nothing is linkable.
        self::assertStringContainsString('function _travel_bookings_autolink_if_needed(array $bookings): bool', $src);
        self::assertStringContainsString("fn_get_storage_data('travel_bookings_autolink_ts')", $src);
        self::assertStringContainsString("fn_set_hook('travel_link_order_bookings'", $src);
        self::assertStringContainsString('(new OrderLinkCandidateRepository())->findAutoCandidates(100)', $src);
        self::assertStringContainsString("if (\$orderIds === []) {\n        // Nothing the hook could possibly link", $src);

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

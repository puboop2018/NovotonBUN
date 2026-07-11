<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the order-link self-heal in fn_sphinx_holidays_place_order_post
 * (novoton parity): the admin grid's Order ID must appear WITHOUT the manual
 * "Reconcile booking–order links" tool. That requires the idempotent
 * reconciler fn_sphinx_holidays_link_order_bookings() to run on BOTH hook
 * paths —
 *  - after the submission loop (items skipped by the per-item guard would
 *    otherwise stay orphaned forever), and
 *  - as the whole body of the empty-cart fallback (payment callbacks and
 *    order-status re-triggers fire place_order_post without a cart).
 * Regression history: sphinx bookings kept showing "Order ID: -" until the
 * operator pressed reconcile.
 */
final class OrderLinkSelfHealTest extends TestCase
{
    public function testPlaceOrderPostSelfHealsOnBothPaths(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/func.php');

        $start = strpos($source, 'function fn_sphinx_holidays_place_order_post');
        self::assertNotFalse($start, 'place_order_post hook function exists');
        $end = strpos($source, 'function fn_sphinx_holidays_link_order_bookings', $start);
        self::assertNotFalse($end, 'reconciler helper exists after the hook');
        $hookBody = substr($source, $start, $end - $start);

        self::assertSame(
            2,
            substr_count($hookBody, 'fn_sphinx_holidays_link_order_bookings($resolved_order_id)'),
            'place_order_post must self-heal on both paths: the empty-cart '
                . 'fallback AND after the submission loop (novoton parity — '
                . 'without these, the grid shows "Order ID: -" until manual reconcile)',
        );

        // The empty-cart fallback must precede the submission loop and the
        // guard may no longer swallow the callback invocations entirely.
        self::assertStringNotContainsString(
            "if (empty(\$resolved_order_id) || empty(\$cart['products']))",
            $hookBody,
            'the combined guard must be split so empty-cart invocations still link',
        );
    }
}

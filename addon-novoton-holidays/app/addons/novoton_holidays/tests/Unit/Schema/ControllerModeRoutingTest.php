<?php
declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * CS-Cart has no automatic per-mode subdirectory include — the booking
 * dispatcher's explicit allowlist IS the routing. A mode file that exists but
 * is not listed 404s at runtime ("Page Not Found"). Regression: only 4 of 15
 * novoton_booking modes were routed, so search_poll 404ed on every poll and
 * every availability search ended in "no hotels found" without ever fetching
 * results (cache_deals and all circuit/experience/package modes were equally
 * dead). This test pins file ↔ dispatcher parity.
 */
final class ControllerModeRoutingTest extends TestCase
{
    private const DISPATCHER = '/controllers/frontend/novoton_booking.php';
    private const MODE_DIR = '/controllers/frontend/novoton_booking';

    public function testEveryModeFileIsRoutedByTheDispatcher(): void
    {
        $addonRoot = dirname(__DIR__, 3);

        $dispatcher = file_get_contents($addonRoot . self::DISPATCHER);
        self::assertIsString($dispatcher);

        $orphans = [];
        foreach (glob($addonRoot . self::MODE_DIR . '/*.php') ?: [] as $modeFile) {
            $mode = basename($modeFile, '.php');
            if (!preg_match("/'" . preg_quote($mode, '/') . "'/", $dispatcher)) {
                $orphans[] = $mode;
            }
        }

        self::assertSame(
            [],
            $orphans,
            'Mode files not routed by the dispatcher allowlist — these modes 404 at runtime',
        );
    }
}

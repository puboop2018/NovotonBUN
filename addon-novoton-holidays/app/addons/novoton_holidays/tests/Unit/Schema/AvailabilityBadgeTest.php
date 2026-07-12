<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the availability badge on the novoton search results:
 * "✓ Available: N room(s), M offer(s) for X adults[, Y children]".
 * Rooms count DISTINCT room types — two board variants of the same room are
 * ONE room (regression: the quota-based count showed "2 camere" for two
 * boards of the same QUAD). The party suffix ties availability to the
 * searched guests, and the JS badge helper must preserve it.
 */
final class AvailabilityBadgeTest extends TestCase
{
    private const TPL_RELATIVE = '/templates/addons/novoton_holidays/views/novoton_booking/search.tpl';

    private static function themeTpl(string $theme): string
    {
        $path = dirname(__DIR__, 6) . '/design/themes/' . $theme . self::TPL_RELATIVE;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testBadgeCountsDistinctRoomTypesNotQuota(): void
    {
        $tpl = self::themeTpl('responsive');

        self::assertStringContainsString('$badge_room_keys', $tpl, 'rooms are counted as distinct room types');
        self::assertStringNotContainsString(
            '($total_quota > 0) ? $total_quota',
            $tpl,
            'the quota-based room count is gone — two boards of one room must count as one room',
        );
    }

    public function testBadgeCarriesThePartySuffix(): void
    {
        $tpl = self::themeTpl('responsive');

        self::assertStringContainsString('novoton_holidays.for', $tpl);
        self::assertStringContainsString('data-party-suffix', $tpl);
        // The JS helper appends the same suffix when it rewrites the badge.
        self::assertStringContainsString("badge.getAttribute('data-party-suffix')", $tpl);
    }

    public function testThemeCopiesAreByteIdentical(): void
    {
        self::assertSame(
            self::themeTpl('responsive'),
            self::themeTpl('nova_theme'),
            'the responsive and nova_theme search.tpl copies must stay in sync',
        );
    }
}

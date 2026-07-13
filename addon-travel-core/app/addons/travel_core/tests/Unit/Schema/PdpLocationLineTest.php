<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the PDP location line under the hotel name: the products:title hook
 * template exists in both themes (byte-identical), renders the assigned line
 * + a coordinates-based Google Maps link, and the PDP hook assigns the
 * variables for BOTH providers via the shared HotelSeoData path.
 */
final class PdpLocationLineTest extends TestCase
{
    private static function themeTpl(string $theme): string
    {
        $path = dirname(__DIR__, 6)
            . '/design/themes/' . $theme . '/templates/addons/travel_core/hooks/products/main_info_title.post.tpl';
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testHookTemplateRendersLineAndMapLink(): void
    {
        $tpl = self::themeTpl('responsive');

        self::assertStringContainsString('$travel_hotel_location_line', $tpl);
        self::assertStringContainsString('https://www.google.com/maps?q={$travel_hotel_lat},{$travel_hotel_lng}', $tpl);
        self::assertStringContainsString('travel_core.location_show_map', $tpl);
        self::assertStringContainsString('target="_blank"', $tpl);
        self::assertStringContainsString('rel="noopener"', $tpl);
    }

    public function testThemeCopiesAreByteIdentical(): void
    {
        self::assertSame(self::themeTpl('responsive'), self::themeTpl('nova_theme'));
    }

    public function testPdpHookAssignsTheLocationVariables(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/hooks/cart_hooks.php');

        self::assertStringContainsString("assign('travel_hotel_location_line'", $src);
        self::assertStringContainsString("assign('travel_hotel_lat'", $src);
        self::assertStringContainsString("assign('travel_hotel_lng'", $src);
        self::assertStringContainsString('HotelLocationLine::build', $src);
    }
}

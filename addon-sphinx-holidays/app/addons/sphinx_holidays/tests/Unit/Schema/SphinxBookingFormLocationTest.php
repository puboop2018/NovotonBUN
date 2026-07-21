<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the hotel location line + "Locație - arată pe hartă" map link on the
 * sphinx guest/booking form. The guest form previously showed no location at
 * all; it now mirrors the search card / PDP — sanitized HotelLocationLine
 * text plus a HotelMapUrl link (coordinate pin when geocoded, place-search
 * fallback otherwise, so the link is present for every hotel).
 */
final class SphinxBookingFormLocationTest extends TestCase
{
    private static function tpl(): string
    {
        $path = dirname(__DIR__, 6)
            . '/design/themes/responsive/templates/addons/sphinx_holidays/views/sphinx_booking/booking_form.tpl';
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private static function controller(): string
    {
        $path = dirname(__DIR__, 3) . '/controllers/frontend/sphinx_booking/booking_form.php';
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testTemplateRendersTheLocationLineAndMapLink(): void
    {
        $tpl = self::tpl();

        self::assertStringContainsString('travel-hotel-map-link', $tpl);
        self::assertStringContainsString(
            'href="{$sphinx_booking_data.hotel_map_url|escape:html}"',
            $tpl,
        );
        self::assertStringContainsString('$sphinx_booking_data.hotel_location_line', $tpl);
        self::assertStringContainsString('sphinx_holidays.location_show_map', $tpl);
    }

    public function testControllerBuildsTheLineAndUrlViaSharedServices(): void
    {
        $controller = self::controller();

        // Hotel row loaded for the address/coordinates, then the shared
        // builders produce the sanitized line + always-present map URL.
        self::assertStringContainsString('Container::getHotelRepository()->findById', $controller);
        self::assertStringContainsString('HotelLocationLine::build($bookingHotelSeo', $controller);
        self::assertStringContainsString('HotelMapUrl::build($bookingHotelSeo', $controller);
        self::assertStringContainsString("'hotel_map_url' => \$hotelMapUrl", $controller);
    }
}

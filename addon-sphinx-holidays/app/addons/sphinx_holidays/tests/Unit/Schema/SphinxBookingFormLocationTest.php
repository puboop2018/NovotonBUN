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

    public function testHotelIdentityComesFromTheSharedSidebar(): void
    {
        $tpl = self::tpl();

        // Name/stars/location/map-link markup lives ONCE in travel_core — now
        // in components/booking_sidebar.tpl, which the 2-column redesign made
        // the single home of the whole summary column (stars ABOVE the name,
        // per the design brief). This page only includes it and owns no
        // identity markup of its own.
        self::assertStringContainsString('addons/travel_core/components/booking_sidebar.tpl', $tpl);
        self::assertStringContainsString('class="travel-booking-layout"', $tpl);
        self::assertStringNotContainsString('travel-hotel-name-link', $tpl, 'no locally duplicated name markup');
        self::assertStringNotContainsString('travel-hotel-map-link', $tpl, 'no locally duplicated map-link markup');
        self::assertStringNotContainsString('ty-product-block-title', $tpl);
        self::assertStringNotContainsString('travel-booking-summary--hero', $tpl, 'the blue-gradient hero is gone');
        self::assertStringNotContainsString('<h2>', $tpl, 'the header heading is an h1 (single-hotel page semantics)');

        // The location line + map link the sidebar renders keep the classes
        // the existing pin-icon CSS targets, so the icon still appears.
        $sidebar = (string) file_get_contents(
            dirname(__DIR__, 7)
            . '/addon-travel-core/design/themes/responsive/templates/addons/travel_core/components/booking_sidebar.tpl',
        );
        self::assertStringContainsString('travel-hotel-location', $sidebar);
        self::assertStringContainsString('travel-hotel-map-link', $sidebar);
    }

    public function testControllerBuildsTheLineAndUrlViaSharedServices(): void
    {
        $controller = self::controller();

        // Hotel row loaded for the address/coordinates, then the shared
        // builders produce the sanitized line + always-present map URL,
        // handed to the component through HotelHeaderViewModel; stars come
        // from the classification column.
        self::assertStringContainsString('Container::getHotelRepository()->findById', $controller);
        self::assertStringContainsString('HotelHeaderFactory::fromSeo(', $controller);
        self::assertStringContainsString("assign('travel_hotel_header'", $controller);
        self::assertStringContainsString("\$hotelRow['classification']", $controller);
    }
}

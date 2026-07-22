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

    public function testHotelIdentityComesFromTheSharedComponent(): void
    {
        $tpl = self::tpl();

        // Name/stars/location/map-link markup lives ONCE in travel_core's
        // hotel_header.tpl (pinned by HotelHeaderComponentTest); this page
        // only includes it — new-tab link so an in-progress form is never
        // lost, sphinx-* hook classes via params, inside the light
        // .travel-booking-summary card (no --hero blue gradient).
        self::assertStringContainsString('addons/travel_core/components/hotel_header.tpl', $tpl);
        self::assertStringContainsString('hh_extra_class="sphinx-hotel-header-name"', $tpl);
        self::assertStringContainsString('hh_location_class="sphinx-hotel-header-location"', $tpl);
        self::assertStringContainsString('hh_new_tab=true', $tpl);
        self::assertStringNotContainsString('travel-hotel-name-link', $tpl, 'no locally duplicated name markup');
        self::assertStringNotContainsString('travel-hotel-map-link', $tpl, 'no locally duplicated map-link markup');
        self::assertStringNotContainsString('ty-product-block-title', $tpl);
        self::assertStringNotContainsString('travel-booking-summary--hero', $tpl, 'the blue-gradient hero is gone');
        self::assertStringNotContainsString('<h2>', $tpl, 'the header heading is an h1 (single-hotel page semantics)');
    }

    public function testControllerBuildsTheLineAndUrlViaSharedServices(): void
    {
        $controller = self::controller();

        // Hotel row loaded for the address/coordinates, then the shared
        // builders produce the sanitized line + always-present map URL,
        // handed to the component through HotelHeaderViewModel; stars come
        // from the classification column.
        self::assertStringContainsString('Container::getHotelRepository()->findById', $controller);
        self::assertStringContainsString('HotelLocationLine::build($bookingHotelSeo', $controller);
        self::assertStringContainsString('HotelMapUrl::build($bookingHotelSeo', $controller);
        self::assertStringContainsString('new HotelHeaderViewModel(', $controller);
        self::assertStringContainsString("assign('travel_hotel_header'", $controller);
        self::assertStringContainsString("\$hotelRow['classification']", $controller);
    }
}

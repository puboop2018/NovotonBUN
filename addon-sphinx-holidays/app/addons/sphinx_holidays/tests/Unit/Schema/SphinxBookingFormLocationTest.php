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

    public function testHeaderIsTheMinimalistSearchStyleCard(): void
    {
        $tpl = self::tpl();

        // Product-listing name (ty-product-list__item-name + product-title link)
        // with bidi isolation, gold stars from the classification — the light
        // .travel-booking-summary card (no --hero blue gradient), parity with the
        // search results header. The name links to the product page in a new tab.
        self::assertStringContainsString('<h1 class="ty-product-list__item-name sphinx-hotel-header-name">', $tpl);
        self::assertStringContainsString('class="product-title travel-hotel-name-link"', $tpl);
        self::assertStringContainsString('target="_blank" rel="noopener"', $tpl);
        self::assertStringNotContainsString('ty-product-block-title', $tpl, 'the big PDP block title is replaced by the listing name');
        self::assertStringNotContainsString('travel-booking-summary--hero', $tpl, 'the blue-gradient hero is gone');
        self::assertStringNotContainsString('<h2>', $tpl, 'the header heading is an h1 (single-hotel page semantics)');
        self::assertStringContainsString('travel-hotel-stars sphinx-stars', $tpl);

        // Stars come from the hotel classification via the controller.
        self::assertStringContainsString("'hotel_stars' =>", self::controller());
        self::assertStringContainsString("\$hotelRow['classification']", self::controller());
    }
}

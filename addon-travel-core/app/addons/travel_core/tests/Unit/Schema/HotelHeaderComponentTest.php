<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the shared hotel-identity header component — the ONE template that
 * renders the hotel name/stars/location block for both providers' search
 * and booking-form pages. The per-surface pin tests only assert that each
 * page INCLUDES this component; the markup contract lives here, once.
 */
final class HotelHeaderComponentTest extends TestCase
{
    private const TPL_RELATIVE = '/templates/addons/travel_core/components/hotel_header.tpl';

    private static function tpl(): string
    {
        $path = dirname(__DIR__, 6) . '/design/themes/responsive' . self::TPL_RELATIVE;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testNameUsesTheProductListingClassesWithBidiIsolation(): void
    {
        $tpl = self::tpl();

        // Listing-style name: ty-product-list__item-name wrapper + the
        // theme's product-title link class; <bdi> WRAPS the link (RTL-safe),
        // matching the storefront's own listing markup.
        self::assertStringContainsString('<h1 class="ty-product-list__item-name{if $hh_extra_class} {$hh_extra_class}{/if}">', $tpl);
        self::assertStringContainsString('class="product-title travel-hotel-name-link"', $tpl);
        self::assertStringContainsString('<bdi><a href=', $tpl);
        self::assertStringContainsString('<bdi>{$travel_hotel_header.name|escape:html}</bdi>', $tpl);
        self::assertStringNotContainsString('ty-product-block-title', $tpl, 'the big PDP block title stays retired');
    }

    public function testNameIsAlwaysHtmlEscaped(): void
    {
        $tpl = self::tpl();

        // Every render of the name goes through |escape:html (novoton's old
        // header rendered it raw).
        self::assertSame(
            0,
            preg_match('/\{\$travel_hotel_header\.name\}(?!\|)/', $tpl),
            'the hotel name must never render unescaped',
        );
        self::assertStringContainsString('title="{$travel_hotel_header.name|escape:html}"', $tpl);
    }

    public function testProductLinkGatesOnProductIdAndSupportsNewTab(): void
    {
        $tpl = self::tpl();

        self::assertStringContainsString('{if $travel_hotel_header.product_id}', $tpl);
        self::assertStringContainsString('products.view?product_id=`$travel_hotel_header.product_id`', $tpl);
        // hh_new_tab=true (booking forms) adds target+noopener; search pages omit it.
        self::assertStringContainsString('{if $hh_new_tab} target="_blank" rel="noopener"{/if}', $tpl);
    }

    public function testStarsRenderFromTheCountWithAnAccessibleLabel(): void
    {
        $tpl = self::tpl();

        self::assertStringContainsString('{if $travel_hotel_header.stars > 0}', $tpl);
        self::assertStringContainsString('{"★"|str_repeat:$travel_hotel_header.stars}', $tpl);
        self::assertStringContainsString('class="travel-hotel-stars"', $tpl);
        self::assertStringContainsString('travel_core.stars_rating', $tpl);
        self::assertStringContainsString('role="img"', $tpl);
    }

    public function testLocationLineKeepsThePdpSeparatorAndMapLinkGating(): void
    {
        $tpl = self::tpl();

        // Map link is gated on the pre-built URL (HotelMapUrl — always present,
        // even without coordinates); " - " separator only between text and link.
        self::assertStringContainsString('{if $travel_hotel_header.location_line || $travel_hotel_header.map_url}', $tpl);
        self::assertStringContainsString('{if $travel_hotel_header.location_line} - {/if}', $tpl);
        self::assertStringContainsString('href="{$travel_hotel_header.map_url|escape:html}"', $tpl);
        self::assertStringContainsString('class="travel-hotel-map-link"', $tpl);
        self::assertStringContainsString('travel_core.location_show_map', $tpl);
        self::assertStringContainsString('class="travel-hotel-location{if $hh_location_class} {$hh_location_class}{/if}"', $tpl);
    }

    public function testAllFourProviderSurfacesIncludeTheComponent(): void
    {
        $repoRoot = dirname(__DIR__, 7);
        $surfaces = [
            'addon-novoton-holidays/design/themes/responsive/templates/addons/novoton_holidays/views/novoton_booking/search.tpl',
            'addon-novoton-holidays/design/themes/responsive/templates/addons/novoton_holidays/views/novoton_booking/booking_form.tpl',
            'addon-sphinx-holidays/design/themes/responsive/templates/addons/sphinx_holidays/views/sphinx_booking/search.tpl',
            'addon-sphinx-holidays/design/themes/responsive/templates/addons/sphinx_holidays/views/sphinx_booking/booking_form.tpl',
        ];

        foreach ($surfaces as $surface) {
            $page = (string) file_get_contents($repoRoot . '/' . $surface);
            self::assertStringContainsString(
                'addons/travel_core/components/hotel_header.tpl',
                $page,
                "surface must render the shared header component: {$surface}",
            );
            self::assertStringNotContainsString(
                'ty-product-list__item-name',
                $page,
                "surface must not duplicate the header markup locally: {$surface}",
            );
        }
    }

    public function testHeaderLangKeysAreSeeded(): void
    {
        $vars = require dirname(__DIR__, 3) . '/lang_keys.php';
        self::assertIsArray($vars);
        self::assertArrayHasKey('travel_core.location_show_map', $vars);
        self::assertArrayHasKey('travel_core.stars_rating', $vars);
        self::assertSame('Locație - arată pe hartă', $vars['travel_core.location_show_map']['ro']);
        self::assertStringContainsString('[rating]', $vars['travel_core.stars_rating']['ro']);
    }

    public function testThemeCopiesAreByteIdentical(): void
    {
        $base = dirname(__DIR__, 6) . '/design/themes/';
        self::assertSame(
            (string) file_get_contents($base . 'responsive' . self::TPL_RELATIVE),
            (string) file_get_contents($base . 'nova_theme' . self::TPL_RELATIVE),
            'run `composer mirror`',
        );
    }
}

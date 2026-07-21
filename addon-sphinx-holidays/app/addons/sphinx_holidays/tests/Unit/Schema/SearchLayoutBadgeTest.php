<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the sphinx search results page to novoton parity:
 * 1. Page order — the hotel header renders ABOVE the booking search form.
 * 2. Availability badge — same format as novoton's
 *    ("✓ Available: N room(s), M offer(s) for X adults[, Y children]"),
 *    rooms = DISTINCT room types, party suffix from the searched guests,
 *    on BOTH render paths (server-rendered results and the poll JS that
 *    streams offers in).
 */
final class SearchLayoutBadgeTest extends TestCase
{
    private static function searchTpl(): string
    {
        $path = dirname(__DIR__, 6)
            . '/design/themes/responsive/templates/addons/sphinx_holidays/views/sphinx_booking/search.tpl';
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testHotelHeaderRendersAboveTheBookingForm(): void
    {
        $tpl = self::searchTpl();

        $headerPos = strpos($tpl, 'travel-hotel-header ');
        $formPos = strpos($tpl, 'travel-search-form-wrapper');
        self::assertIsInt($headerPos);
        self::assertIsInt($formPos);
        self::assertLessThan($formPos, $headerPos, 'the hotel header must come before the search form (novoton parity)');
        // The badge sits in the hotel header row, like novoton's.
        self::assertStringContainsString('travel-hotel-header-row', $tpl);
    }

    public function testHotelNameLinksToTheProductPage(): void
    {
        $tpl = self::searchTpl();

        self::assertStringContainsString('travel-hotel-name-link', $tpl);
        self::assertStringContainsString('products.view?product_id=`$sphinx_search_params.product_id`', $tpl);
    }

    public function testHeaderShowsMapLinkFromTheBuiltUrl(): void
    {
        $tpl = self::searchTpl();

        // The map link is gated on a pre-built URL (HotelMapUrl::build), NOT on
        // raw coordinates — so coordinate-less hotels keep the link (Google
        // place-search fallback), matching the PDP.
        self::assertStringContainsString('travel-hotel-map-link', $tpl);
        self::assertStringContainsString('href="{$sphinx_hotel_map_url|escape:html}"', $tpl);
        self::assertStringContainsString('{if $sphinx_hotel_map_url}', $tpl);
        self::assertStringNotContainsString(
            'https://www.google.com/maps?q={$sphinx_hotel_lat},{$sphinx_hotel_lng}',
            $tpl,
            'the hardcoded coordinate URL is gone — the link must survive without coordinates',
        );
        self::assertStringContainsString('sphinx_holidays.location_show_map', $tpl);

        // The controller builds the URL via the shared builder and still reads
        // the address field into the DTO.
        $controller = (string) file_get_contents(
            dirname(__DIR__, 3) . '/controllers/frontend/sphinx_booking/search.php',
        );
        self::assertStringContainsString('HotelMapUrl::build($hotelSeo', $controller);
        self::assertStringContainsString("sphinx_hotel_map_url", $controller);
        self::assertStringContainsString("\$hotelRow['address']", $controller);
    }

    public function testHotelHeaderMatchesPdpTypographyAndTextPipeline(): void
    {
        $tpl = self::searchTpl();

        // Name parity: the theme's PDP title class on the heading.
        self::assertStringContainsString('<h1 class="ty-product-block-title sphinx-hotel-header-name">', $tpl);
        // Location parity: the PDP's " - " separator before the map link.
        self::assertStringContainsString('{if $sphinx_hotel_location} - {/if}', $tpl);

        // The TEXT comes from the shared PDP pipeline (HotelLocationLine) with
        // the provider's field mapping — not an inline implode.
        $controller = (string) file_get_contents(
            dirname(__DIR__, 3) . '/controllers/frontend/sphinx_booking/search.php',
        );
        self::assertStringContainsString('HotelLocationLine::build', $controller);
        self::assertStringContainsString("\$hotelRow['region_name']", $controller);
        self::assertStringNotContainsString("implode(', ', \$locationParts)", $controller);
    }

    public function testServerBadgeMatchesNovotonFormat(): void
    {
        $tpl = self::searchTpl();

        self::assertStringContainsString('$sx_badge_room_keys', $tpl, 'rooms are counted as distinct room types');
        self::assertStringContainsString('sphinx_holidays.available', $tpl);
        // Two-bullet split (novoton parity): guests + rooms/offers on their own
        // lines; the "for" connector is dropped. data-party-suffix now carries
        // the guests-only string for the poll JS.
        self::assertSame(
            2,
            substr_count($tpl, 'class="travel-availability-line"'),
            'the count line splits into exactly two bullet lines (guests, rooms/offers)',
        );
        self::assertStringContainsString('data-party-suffix', $tpl);
        self::assertStringNotContainsString('sphinx_holidays.for', $tpl, 'the "for" connector is gone with the split');
        // Server badge uses CS-Cart plural forms ("1 ofertă" / "2 oferte").
        self::assertStringContainsString('sphinx_holidays.n_offers', $tpl);
        self::assertStringContainsString('sphinx_holidays.n_rooms', $tpl);
        self::assertStringContainsString('sphinx_holidays.n_adults', $tpl);
        // The old "N results found" count span is gone.
        self::assertStringNotContainsString('sphinx-results-count', $tpl);
    }

    public function testPollJsRebuildsTheTwoBulletLines(): void
    {
        $tpl = self::searchTpl();

        self::assertStringContainsString('updateBadgeText', $tpl);
        self::assertStringContainsString('seenRoomKeys', $tpl, 'the poll path deduplicates room types too');
        self::assertStringContainsString("title.getAttribute('data-party-suffix')", $tpl);
        // The poll JS fills the two bullet spans by id.
        self::assertStringContainsString('sphinx-availability-guests', $tpl);
        self::assertStringContainsString('sphinx-availability-rooms', $tpl);
        // Badge vocabulary is exported to the poll JS.
        foreach (['available:', 'room:', 'rooms:', 'offer:', 'offers:'] as $label) {
            self::assertStringContainsString($label, $tpl, "__sphinxConfig.labels must carry {$label}");
        }
    }

    public function testHotelNameIsBidiIsolated(): void
    {
        $tpl = self::searchTpl();

        self::assertStringContainsString('<bdi>{$sphinx_hotel_name|escape:html}</bdi>', $tpl);
    }
}

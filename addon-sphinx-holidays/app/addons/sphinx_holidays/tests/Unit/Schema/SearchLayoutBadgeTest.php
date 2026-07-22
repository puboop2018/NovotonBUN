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

    public function testHotelIdentityComesFromTheSharedComponent(): void
    {
        $tpl = self::searchTpl();

        // Name/stars/location/map-link markup lives ONCE in travel_core's
        // hotel_header.tpl (pinned by HotelHeaderComponentTest); this page
        // only includes it, keeping its sphinx-* hook classes via params.
        self::assertStringContainsString('addons/travel_core/components/hotel_header.tpl', $tpl);
        self::assertStringContainsString('hh_extra_class="sphinx-hotel-header-name"', $tpl);
        self::assertStringContainsString('hh_location_class="sphinx-hotel-header-location"', $tpl);
        self::assertStringNotContainsString('travel-hotel-name-link', $tpl, 'no locally duplicated name markup');
        self::assertStringNotContainsString('travel-hotel-map-link', $tpl, 'no locally duplicated map-link markup');
        self::assertStringNotContainsString('ty-product-block-title', $tpl);
    }

    public function testControllerFeedsTheSharedHeaderViewModel(): void
    {
        // Header data still comes from the shared PDP pipeline — sanitized
        // line via HotelLocationLine (provider field mapping, no inline
        // implode), always-present map URL via HotelMapUrl — handed to the
        // component through HotelHeaderViewModel under the shared name.
        $controller = (string) file_get_contents(
            dirname(__DIR__, 3) . '/controllers/frontend/sphinx_booking/search.php',
        );
        self::assertStringContainsString('HotelLocationLine::build', $controller);
        self::assertStringContainsString('HotelMapUrl::build($hotelSeo', $controller);
        self::assertStringContainsString("\$hotelRow['address']", $controller);
        self::assertStringContainsString("\$hotelRow['region_name']", $controller);
        self::assertStringNotContainsString("implode(', ', \$locationParts)", $controller);
        self::assertStringContainsString('new HotelHeaderViewModel(', $controller);
        self::assertStringContainsString("assign('travel_hotel_header'", $controller);
        self::assertStringContainsString('productId: TypeCoerce::toInt($product_id)', $controller);
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
        // The poll behavior lives in the extracted JS module (loaded via
        // {script}, imported directly by vitest); the template keeps only the
        // config prelude with the translated badge vocabulary.
        $tpl = self::searchTpl();
        $js = self::searchJs();

        self::assertStringContainsString('{script src="js/addons/sphinx_holidays/search-results.js"}', $tpl);
        self::assertStringContainsString('updateBadgeText', $js);
        self::assertStringContainsString('seenRoomKeys', $js, 'the poll path deduplicates room types too');
        self::assertStringContainsString("title.getAttribute('data-party-suffix')", $js);
        // The poll JS fills the two bullet spans by id.
        self::assertStringContainsString('sphinx-availability-guests', $js);
        self::assertStringContainsString('sphinx-availability-rooms', $js);
        // Badge vocabulary is exported to the poll JS from the template.
        foreach (['available:', 'room:', 'rooms:', 'offer:', 'offers:'] as $label) {
            self::assertStringContainsString($label, $tpl, "__sphinxConfig.labels must carry {$label}");
        }
        // No behavioral JS left inline — config objects only.
        self::assertStringNotContainsString('{literal}', $tpl, 'inline JS bodies are extracted to the module');
    }

    private static function searchJs(): string
    {
        $path = dirname(__DIR__, 6) . '/js/addons/sphinx_holidays/search-results.js';
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

}

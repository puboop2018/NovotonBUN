<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the availability status on the novoton search results — a compact
 * "✓ Available" pill with the guest-count confirmation as plain text below:
 * "N room(s), M offer(s) for X adults[, Y children]".
 * Rooms count DISTINCT room types — two board variants of the same room are
 * ONE room (regression: the quota-based count showed "2 camere" for two
 * boards of the same QUAD). The party suffix ties availability to the
 * searched guests.
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

    public function testBadgeSplitsStatusPillFromGuestCountLine(): void
    {
        $tpl = self::themeTpl('responsive');

        // Three-tier hierarchy: the compact pill, then TWO bullet lines —
        // guests (searched) and rooms/offers (found), each its own
        // travel-availability-line. The "for" connector is dropped (the lines
        // are separate now); the guest + room/offer words are unchanged.
        self::assertStringContainsString('travel-availability-block', $tpl);
        self::assertStringContainsString('travel-availability-badge', $tpl);
        self::assertStringContainsString('travel-availability-details', $tpl);
        self::assertSame(
            2,
            substr_count($tpl, 'class="travel-availability-line"'),
            'the count line splits into exactly two bullet lines (guests, rooms/offers)',
        );
        // CS-Cart plural-form keys ("1 ofertă" / "2 oferte"), not the old
        // {if count==1}{singular}{else}{plural}{/if} conditionals.
        self::assertStringContainsString('novoton_holidays.n_adults', $tpl);
        self::assertStringContainsString('novoton_holidays.n_rooms', $tpl);
        self::assertStringContainsString('novoton_holidays.n_offers', $tpl);
        self::assertStringNotContainsString('novoton_holidays.for', $tpl, 'the "for" connector is gone with the split');
        // The dead JS badge rewriter and its data-attribute contract are gone.
        self::assertStringNotContainsString('updateAvailabilityBadge', $tpl);
        self::assertStringNotContainsString('data-party-suffix', $tpl);
    }

    public function testHotelIdentityComesFromTheSharedComponent(): void
    {
        $tpl = self::themeTpl('responsive');

        // Name/stars/location/map-link markup lives ONCE in travel_core's
        // hotel_header.tpl (pinned by HotelHeaderComponentTest); this page
        // only includes it. No hh_new_tab: search links stay same-tab.
        self::assertStringContainsString('{include file="addons/travel_core/components/hotel_header.tpl"}', $tpl);
        self::assertStringNotContainsString('travel-hotel-name-link', $tpl, 'no locally duplicated name markup');
        self::assertStringNotContainsString('travel-hotel-map-link', $tpl, 'no locally duplicated map-link markup');
    }

    public function testHotelHeaderRendersAboveTheBookingForm(): void
    {
        $tpl = self::themeTpl('responsive');

        $headerPos = strpos($tpl, 'travel-hotel-header ');
        $formPos = strpos($tpl, 'travel-search-form-wrapper');
        self::assertIsInt($headerPos);
        self::assertIsInt($formPos);
        self::assertLessThan($formPos, $headerPos, 'the hotel header must come before the search form (sphinx parity)');
    }

    public function testHotelNameLinksToTheProductPage(): void
    {
        // The link markup lives in the shared component; the formatter feeds
        // it the product id through the view model.
        $php = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Services/SearchResultFormatter.php',
        );
        self::assertStringContainsString('new HotelHeaderViewModel(', $php);
        self::assertStringContainsString('productId: $productId', $php);
        self::assertStringContainsString("assign('travel_hotel_header'", $php);
    }

    public function testHeaderShownWheneverHotelKnownBadgeOnlyWithResults(): void
    {
        $tpl = self::themeTpl('responsive');

        // The header is gated on the hotel being KNOWN, not on results: a
        // zero-availability search must still show which hotel was searched
        // (name -> product page, address, map link).
        $headerGuardPos = strpos($tpl, '{if $hotel_name}');
        $headerPos = strpos($tpl, 'travel-hotel-header ');
        $firstResultsGuardPos = strpos($tpl, '{if $novoton_results');
        self::assertIsInt($headerGuardPos);
        self::assertIsInt($headerPos);
        self::assertIsInt($firstResultsGuardPos);
        self::assertLessThan($headerPos, $headerGuardPos, 'the header opens under the hotel-known guard');
        self::assertLessThan(
            $firstResultsGuardPos,
            $headerPos,
            'the header must not be inside a results guard — only the badge is results-gated',
        );
    }

    public function testSearchFormatterBuildsTheMapUrlViaTheSharedBuilder(): void
    {
        $php = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Services/SearchResultFormatter.php',
        );

        // The formatter must build the map URL via HotelMapUrl::build with
        // coordinates fed into the DTO (coordinate pin when available,
        // place-search fallback otherwise) and hand it to the shared header
        // through the view model.
        self::assertStringContainsString('HotelMapUrl::build($hotelSeo', $php);
        self::assertStringContainsString('latitude: $hotelLat', $php);
        self::assertStringContainsString('mapUrl: $mapUrl', $php);
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

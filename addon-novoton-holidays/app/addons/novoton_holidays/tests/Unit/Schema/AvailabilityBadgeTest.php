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

        // Split hierarchy: compact status pill, then the count/party line below.
        self::assertStringContainsString('travel-availability-block', $tpl);
        self::assertStringContainsString('travel-availability-badge', $tpl);
        self::assertStringContainsString('travel-availability-details', $tpl);
        // The party suffix still renders (now inside the details line).
        self::assertStringContainsString('novoton_holidays.for', $tpl);
        // The dead JS badge rewriter and its data-attribute contract are gone.
        self::assertStringNotContainsString('updateAvailabilityBadge', $tpl);
        self::assertStringNotContainsString('data-party-suffix', $tpl);
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
        $tpl = self::themeTpl('responsive');

        self::assertStringContainsString('travel-hotel-name-link', $tpl);
        self::assertStringContainsString('products.view?product_id=`$novoton_params.product_id`', $tpl);
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

    public function testHeaderShowsMapLinkFromTheBuiltUrl(): void
    {
        $tpl = self::themeTpl('responsive');

        // The map link is gated on a pre-built URL (HotelMapUrl::build), NOT on
        // raw coordinates: coordinate-less hotels previously lost the link
        // entirely; now they get a Google place-search fallback. So the map
        // link is present for every hotel — matching the PDP.
        self::assertStringContainsString('travel-hotel-map-link', $tpl);
        self::assertStringContainsString('href="{$hotel_map_url|escape:html}"', $tpl);
        self::assertStringContainsString('{if $hotel_map_url}', $tpl);
        self::assertStringNotContainsString(
            'https://www.google.com/maps?q={$hotel_lat},{$hotel_lng}',
            $tpl,
            'the hardcoded coordinate URL is gone — the link must survive without coordinates',
        );
        self::assertStringContainsString('novoton_holidays.location_show_map', $tpl);
    }

    public function testSearchFormatterBuildsTheMapUrlViaTheSharedBuilder(): void
    {
        $php = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Services/SearchResultFormatter.php',
        );

        // The controller must build hotel_map_url via HotelMapUrl::build with
        // coordinates fed into the DTO (so the coordinate pin still wins when
        // available and the place-search fallback fires otherwise).
        self::assertStringContainsString("assign('hotel_map_url'", $php);
        self::assertStringContainsString('HotelMapUrl::build($hotelSeo', $php);
        self::assertStringContainsString('latitude: $hotelLat', $php);
    }

    public function testHotelNameReusesThePdpTitleClass(): void
    {
        $tpl = self::themeTpl('responsive');

        // Font/size/weight/color parity with the product page comes from
        // REUSING the theme's PDP title class, not from copied CSS values.
        self::assertStringContainsString('<h1 class="ty-product-block-title">', $tpl);
        self::assertStringNotContainsString('<h2>', $tpl, 'the header heading is an h1 now (PDP tag parity)');
    }

    public function testLocationLineUsesThePdpSeparatorBeforeTheMapLink(): void
    {
        $tpl = self::themeTpl('responsive');

        // " - " between the location text and the map link, only when both
        // render — exactly like main_info_title.post.tpl on the PDP.
        self::assertStringContainsString('{if $hotel_location_line} - {/if}', $tpl);
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

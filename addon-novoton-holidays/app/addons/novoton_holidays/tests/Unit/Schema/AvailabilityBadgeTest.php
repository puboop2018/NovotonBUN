<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the availability badge on the novoton search results:
 * "✓ Available: N room(s), M offer(s) for X adults[, Y children]".
 * Rooms count DISTINCT room types — two board variants of the same room are
 * ONE room (regression: the quota-based count showed "2 camere" for two
 * boards of the same QUAD). The party suffix ties availability to the
 * searched guests, and the JS badge helper must preserve it.
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

    public function testBadgeCarriesThePartySuffix(): void
    {
        $tpl = self::themeTpl('responsive');

        self::assertStringContainsString('novoton_holidays.for', $tpl);
        self::assertStringContainsString('data-party-suffix', $tpl);
        // The JS helper appends the same suffix when it rewrites the badge.
        self::assertStringContainsString("badge.getAttribute('data-party-suffix')", $tpl);
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

    public function testHeaderShowsMapLinkFromCoordinates(): void
    {
        $tpl = self::themeTpl('responsive');

        self::assertStringContainsString('travel-hotel-map-link', $tpl);
        self::assertStringContainsString('https://www.google.com/maps?q={$hotel_lat},{$hotel_lng}', $tpl);
        self::assertStringContainsString('novoton_holidays.location_show_map', $tpl);
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

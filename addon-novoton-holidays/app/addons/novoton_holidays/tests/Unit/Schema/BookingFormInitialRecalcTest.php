<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the booking form's initial-load price verification to the REAL searched
 * occupancy. Regression: the DOMContentLoaded "binding price" call hardcoded
 * children_ages: [], so the operator re-quoted for adults-only occupancy,
 * omitted the selected child-capacity room (QUAD 3+1 SEA), and the endpoint's
 * capacity-blind "first available price" fallback substituted the first
 * 2-adult room (DBL 2+0 @ -194 EUR) — popping a bogus "Camera s-a modificat"
 * modal before any DOB was typed. Also pins: the re-quoted price is committed
 * only when the room is unchanged or after the guest ACCEPTS a room change,
 * and the recalc endpoint's logging is wired to a setting key that exists.
 */
final class BookingFormInitialRecalcTest extends TestCase
{
    private const TPL_RELATIVE = '/templates/addons/novoton_holidays/views/novoton_booking/booking_form.tpl';

    private static function themeTpl(string $theme): string
    {
        $path = dirname(__DIR__, 6) . '/design/themes/' . $theme . self::TPL_RELATIVE;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testInitialLoadRecalcSendsCollectedChildrenAges(): void
    {
        $tpl = self::themeTpl('responsive');

        self::assertStringNotContainsString(
            'triggerPriceRecalculationInline([]',
            $tpl,
            'the on-load binding-price call must never hardcode an empty children list — '
            . 'it re-quotes for adults-only occupancy and triggers a bogus room substitution',
        );
        self::assertStringContainsString('function collectChildrenAges(', $tpl);
        // Both DOMContentLoaded branches (multi-room + single-room) pass real ages.
        self::assertStringContainsString('triggerPriceRecalculationInline(collectChildrenAges(roomNum), roomNum, true)', $tpl);
        self::assertStringContainsString('triggerPriceRecalculationInline(collectChildrenAges(1), 1, true)', $tpl);
    }

    public function testPriceCommitIsGatedOnRoomChangeDecision(): void
    {
        $tpl = self::themeTpl('responsive');

        self::assertStringContainsString('function applyRecalculatedPrice(', $tpl);

        // The success handler applies the price only when the room is unchanged...
        self::assertStringContainsString('applyRecalculatedPrice(data, roomNum, isMultiRoom, isInitialLoad)', $tpl);

        // ...and a room change defers the commit to the accept handler.
        $acceptPos = strpos($tpl, 'function acceptRoomChangeInline()');
        self::assertIsInt($acceptPos);
        self::assertStringContainsString(
            'applyRecalculatedPrice(',
            substr($tpl, $acceptPos),
            'accepting a room change must commit price + room together',
        );
    }

    public function testThemeCopiesAreByteIdentical(): void
    {
        self::assertSame(
            self::themeTpl('responsive'),
            self::themeTpl('nova_theme'),
            'the responsive and nova_theme booking_form.tpl copies must stay in sync',
        );
    }

    public function testBookingFormHeaderIsTheMinimalistSearchStyleCard(): void
    {
        $tpl = self::themeTpl('responsive');

        // The hotel identity (name/stars/location/map link) comes from the
        // shared travel_core component — new-tab link so an in-progress form
        // is never lost — inside the light reservation-header card with the
        // inline availability pill.
        self::assertStringContainsString('{include file="addons/travel_core/components/hotel_header.tpl" hh_new_tab=true}', $tpl);
        self::assertStringContainsString('class="travel-hero-badge" id="availability-badge"', $tpl);
        self::assertStringNotContainsString('travel-hero-location', $tpl, 'the blue-gradient hero location is gone');
        self::assertStringNotContainsString('ty-product-block-title', $tpl, 'the big PDP block title stays retired');

        // The reservation-header rule is now a light card, not the blue hero;
        // the location line (emitted by the component before the pill) is
        // pushed below the pill row via flex order.
        $css = (string) file_get_contents(
            dirname(__DIR__, 7) . '/addon-travel-core/design/themes/responsive/css/addons/travel_core/booking-pages.css',
        );
        $start = strpos($css, '.travel-booking-page .travel-reservation-header {');
        self::assertNotFalse($start);
        $rule = substr($css, $start, (int) strpos($css, '}', $start) - $start);
        self::assertStringContainsString('background: var(--nvt-bg', $rule);
        self::assertStringNotContainsString('linear-gradient', $rule, 'the header must be a light card, not the blue hero');

        $locStart = strpos($css, '.travel-booking-page .travel-hotel-location {');
        self::assertNotFalse($locStart);
        $locRule = substr($css, $locStart, (int) strpos($css, '}', $locStart) - $locStart);
        self::assertStringContainsString('order: 10', $locRule);
    }

    public function testBookingFormFeedsTheSharedHeaderViewModel(): void
    {
        // The controller builds the header data (map URL via the shared
        // HotelMapUrl builder) and hands it to the component through
        // HotelHeaderViewModel under the shared variable name.
        $controller = (string) file_get_contents(
            dirname(__DIR__, 3) . '/controllers/frontend/novoton_booking/booking_form.php',
        );
        self::assertStringContainsString('HotelMapUrl::build($bookingHotelSeo', $controller);
        self::assertStringContainsString('new HotelHeaderViewModel(', $controller);
        self::assertStringContainsString("assign('travel_hotel_header'", $controller);
        // The old template's city/region/country fallback lives on in the VM.
        self::assertStringContainsString('$headerLocationLine', $controller);
    }

    public function testRecalcDebugGateUsesARealSettingKey(): void
    {
        $path = dirname(__DIR__, 3) . '/controllers/frontend/novoton_booking/ajax_recalculate_price.php';
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);

        self::assertStringNotContainsString(
            "ConfigProvider::get('debug'",
            $src,
            "the addon has no 'debug' setting — this gate is permanently off",
        );
        self::assertStringContainsString('ConfigProvider::isDebugMode()', $src);
    }

    public function testRoomSubstitutionIsLoggedUnconditionally(): void
    {
        $path = dirname(__DIR__, 3) . '/controllers/frontend/novoton_booking/ajax_recalculate_price.php';
        $src = (string) file_get_contents($path);

        self::assertStringContainsString(
            'Room substitution',
            $src,
            'a room substitution must leave evidence in Administration → Logs regardless of debug settings',
        );
    }
}

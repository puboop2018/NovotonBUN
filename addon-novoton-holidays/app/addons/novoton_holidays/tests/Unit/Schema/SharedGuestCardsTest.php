<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins novoton's booking form to the SHARED guest cards (travel_core
 * booking_guest_room_body.tpl) instead of its former inline copy of the
 * name/DOB card markup. Novoton keeps its per-room scaffolding (price
 * banners its re-price JS updates) and passes its provider machinery as
 * parameters; the guest-card markup itself lives in ONE place for both
 * providers.
 */
final class SharedGuestCardsTest extends TestCase
{
    private static function bookingFormTpl(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 6)
            . '/design/themes/responsive/templates/addons/novoton_holidays/views/novoton_booking/booking_form.tpl',
        );
    }

    public function testBookingFormDelegatesGuestCardsToTheSharedRoomBody(): void
    {
        $tpl = self::bookingFormTpl();

        self::assertStringContainsString('addons/travel_core/components/booking_guest_room_body.tpl', $tpl);
        // The provider machinery rides in as parameters, not forked markup:
        // fixed adult age for the pricing API, the DOB age-recheck that
        // re-prices, booking-wide sequential labels, name/DOB prefill.
        self::assertStringContainsString('gb_adult_age_value="30"', $tpl);
        self::assertStringContainsString('gb_child_dob_onblur="validateAndCheckAge"', $tpl);
        self::assertStringContainsString('gb_seq_offset=$guest_seq', $tpl);
        self::assertStringContainsString('gb_prefill=$guest_prefill|default:[]', $tpl);

        // The inline guest-card fork is gone — no raw guest inputs remain.
        self::assertStringNotContainsString('name="guests[room', $tpl, 'guest inputs must come from the shared room body');
        self::assertStringNotContainsString('booking_data.guests_data', $tpl, 'prefill flows through the guest_prefill map now');
    }

    public function testSharedRoomBodyCarriesTheIdsNovotonJsTargets(): void
    {
        $body = (string) file_get_contents(
            dirname(__DIR__, 7)
            . '/addon-travel-core/design/themes/responsive/templates/addons/travel_core/components/booking_guest_room_body.tpl',
        );

        // booking-form.js (validateAndCheckAge / collectChildrenAges) reads
        // these ids; vitest fixtures pin child_age_r{N}_c{i} too.
        self::assertStringContainsString('id="child_age_r{$_room_num}_c{$_i}"', $body);
        self::assertStringContainsString('id="child_type_r{$_room_num}_c{$_i}"', $body);
        self::assertStringContainsString('id="dob_r{$_room_num}_c{$_i}"', $body);
        self::assertStringContainsString('id="child_age_display_r{$_room_num}_c{$_i}"', $body);
        // The onblur recheck + its message areas are opt-in (novoton only).
        self::assertStringContainsString('{if $_dob_onblur}', $body);
        self::assertStringContainsString('id="dob_info_r{$_room_num}_c{$_i}"', $body);
        self::assertStringContainsString('id="dob_error_r{$_room_num}_c{$_i}"', $body);
    }

    public function testEditBookingBuildsThePrefillMapForTheSharedCards(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/controllers/frontend/novoton_booking/edit_booking.php',
        );

        self::assertStringContainsString("assign('guest_prefill', \$guest_prefill)", $src);
        // Keys come straight from the normalizer (room{N}_{type}_{i}) with
        // the DD/MM/YYYY dob conversion applied before projection.
        self::assertStringContainsString("'dob' => PriceInfoFormatter::toScalar(\$gval['dob'] ?? '')", $src);
    }

    public function testBookingFormJsUsesTheSharedDobIdScheme(): void
    {
        $js = (string) file_get_contents(
            dirname(__DIR__, 6) . '/js/addons/novoton_holidays/booking-form.js',
        );

        self::assertStringContainsString("getElementById('dob_' + id)", $js);
        self::assertStringNotContainsString("getElementById('child_dob_'", $js, 'the pre-convergence id scheme must stay gone');
    }
}

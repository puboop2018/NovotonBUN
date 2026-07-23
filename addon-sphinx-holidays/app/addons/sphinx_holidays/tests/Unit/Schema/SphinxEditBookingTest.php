<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the sphinx "Edit guest details" flow behind the shared cart card:
 * edit_booking re-opens the booking form (ownership-checked, NO offer
 * re-verify) with prefilled names; update_booking persists guest data only
 * — offer, price and dates are untouchable through this path.
 */
final class SphinxEditBookingTest extends TestCase
{
    private static function src(string $rel): string
    {
        $path = dirname(__DIR__, 3) . '/' . $rel;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testEditBookingIsOwnershipCheckedAndNeverReVerifies(): void
    {
        $src = self::src('controllers/frontend/sphinx_booking/edit_booking.php');

        self::assertStringContainsString('findByIdWithOwnership($booking_id, $current_user_id, $current_session_id)', $src);
        self::assertStringNotContainsString('verifyHotelOffer', $src, 'edit mode must not re-verify — the carted offer/price stays');
        self::assertStringContainsString("assign('is_edit_mode', true)", $src);
        self::assertStringContainsString("assign('guest_prefill', \$guest_prefill)", $src);
        // Prefill keys match the shared guest-card input names.
        self::assertStringContainsString('"room{$room}_{$type}_"', $src);
    }

    public function testOwnershipQueryIsScopedToCartStageBookings(): void
    {
        $repo = self::src('src/Repository/SphinxBookingRepository.php');

        self::assertStringContainsString('function findByIdWithOwnership(', $repo);
        self::assertStringContainsString('order_id = 0', $repo, 'ordered bookings must not be editable via stale cart links');
        self::assertStringContainsString('user_id = ?i OR session_id = ?s', $repo);
    }

    public function testUpdateBookingTouchesGuestDataOnly(): void
    {
        $src = self::src('controllers/frontend/sphinx_booking/update_booking.php');

        self::assertStringContainsString('findByIdWithOwnership', $src);
        self::assertStringContainsString('parseGuests', $src, 'same validation pipeline as add_to_cart');
        self::assertStringNotContainsString('verifyHotelOffer', $src);
        self::assertStringNotContainsString("'total_price'", $src, 'price must be untouchable through the edit path');
        // Cart extras refresh is integrity-checked against the booking id.
        self::assertStringContainsString("travel_booking_id'] ?? 0) === \$booking_id", $src);
        self::assertStringContainsString('fn_save_cart_content', $src);
    }

    public function testBookingFormSwitchesToUpdateModeWithHiddenIds(): void
    {
        $tpl = (string) file_get_contents(
            dirname(__DIR__, 6)
            . '/design/themes/responsive/templates/addons/sphinx_holidays/views/sphinx_booking/booking_form.tpl',
        );

        self::assertStringContainsString('{if $is_edit_mode}{"sphinx_booking.update_booking"|fn_url}{else}{"sphinx_booking.add_to_cart"|fn_url}{/if}', $tpl);
        self::assertStringContainsString('name="booking_id" value="{$edit_booking_id}"', $tpl);
        self::assertStringContainsString('guest_prefill=$guest_prefill|default:[]', $tpl);

        // The edit dispatch renders through the booking form (thin wrapper).
        $wrapper = (string) file_get_contents(
            dirname(__DIR__, 6)
            . '/design/themes/responsive/templates/addons/sphinx_holidays/views/sphinx_booking/edit_booking.tpl',
        );
        self::assertStringContainsString('views/sphinx_booking/booking_form.tpl', $wrapper);
    }
}

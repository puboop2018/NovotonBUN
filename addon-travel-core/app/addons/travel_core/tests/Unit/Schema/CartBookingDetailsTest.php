<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the shared cart/checkout booking-details card — ONE component for
 * both providers' cart lines (ported from novoton's per-provider hooks,
 * which are deleted). Gated on the provider-neutral travel_booking extra;
 * fields render only when their extras exist, so a provider lacking a
 * detail simply omits the line; the edit link derives its dispatch from
 * travel_provider.
 */
final class CartBookingDetailsTest extends TestCase
{
    private static function tpl(string $rel): string
    {
        $path = dirname(__DIR__, 6) . '/design/themes/responsive/templates/addons/travel_core/' . $rel;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testCardIsProviderNeutralAndGatedOnTravelBooking(): void
    {
        $card = self::tpl('components/cart_booking_details.tpl');

        self::assertStringContainsString('{if !empty($product.extra.travel_booking)}', $card);
        // Edit dispatch per provider.
        self::assertStringContainsString("sphinx_booking.edit_booking", $card);
        self::assertStringContainsString("novoton_booking.edit_booking", $card);
        self::assertStringContainsString("extra.travel_provider == 'sphinx'", $card);
        // Labels come from travel_core keys, never provider ones.
        self::assertStringNotContainsString('novoton_holidays.', $card);
        self::assertStringNotContainsString('sphinx_holidays.', $card);
        // Collapse toggle is external JS (InlineScriptRatchet).
        self::assertStringContainsString('{script src="js/addons/travel_core/cart-booking-details.js"}', $card);
        self::assertStringNotContainsString('<script>', $card);
    }

    public function testRoomBodyRendersOnlyWhatTheProviderSupplies(): void
    {
        $body = self::tpl('components/cart_booking_room_body.tpl');

        // Meal plan + guest list are conditional — a provider whose API
        // lacks a detail omits the line instead of rendering blanks.
        self::assertStringContainsString('{if $_cbd_board}', $body);
        self::assertStringContainsString('{if $cbd_extra.guests_data}', $body);
        // Guest markup contract: names escaped, holder badge, child age.
        self::assertStringContainsString('{$_cbd_name|escape:html}', $body);
        self::assertStringContainsString('travel_core.holder', $body);
        self::assertStringContainsString('travel_core.years_old', $body);
    }

    public function testTravelCoreOwnsTheCheckoutHooksAndNovotonCopiesAreGone(): void
    {
        foreach ([
            'hooks/checkout/product_info.post.tpl',
            'hooks/block_checkout/product_extra.post.tpl',
        ] as $hook) {
            self::assertStringContainsString(
                'addons/travel_core/components/cart_booking_details.tpl',
                self::tpl($hook),
                $hook,
            );
        }

        $repoRoot = dirname(__DIR__, 7);
        foreach ([
            'addon-novoton-holidays/design/themes/responsive/templates/addons/novoton_holidays/hooks/checkout/product_info.post.tpl',
            'addon-novoton-holidays/design/themes/responsive/templates/addons/novoton_holidays/hooks/block_checkout/product_extra.post.tpl',
        ] as $gone) {
            self::assertFileDoesNotExist($repoRoot . '/' . $gone, 'the per-provider hook copy must stay deleted');
        }
    }

    public function testGuestCardsSupportOptionalPrefillWithoutChangingDefaults(): void
    {
        $cards = self::tpl('components/booking_guest_cards.tpl');

        // Edit mode passes guest_prefill keyed like the input names; absent,
        // the value attributes resolve to '' (non-edit renders unchanged).
        self::assertStringContainsString('{$_prefill = $guest_prefill|default:[]}', $cards);
        self::assertStringContainsString("value=\"{\$_prefill.\$_pf_key.last_name|default:''|escape:html}\"", $cards);
        self::assertStringContainsString("value=\"{\$_prefill.\$_pf_key.first_name|default:''|escape:html}\"", $cards);
    }

    public function testCardLangKeysAreSeeded(): void
    {
        $vars = require dirname(__DIR__, 3) . '/lang_keys.php';
        self::assertIsArray($vars);
        foreach ([
            'travel_core.your_booking_details',
            'travel_core.total_stay',
            'travel_core.occupancy',
            'travel_core.guest_names',
            'travel_core.holder',
            'travel_core.meal_plan',
            'travel_core.save_changes',
        ] as $key) {
            self::assertArrayHasKey($key, $vars, $key);
        }
    }
}

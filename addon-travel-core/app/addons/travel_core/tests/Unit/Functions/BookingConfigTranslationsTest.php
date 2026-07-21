<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Functions;

use PHPUnit\Framework\TestCase;

/**
 * Pins the guest-picker i18n routing.
 *
 * GuestPicker.jsx reads t('childrenAges', ...) and t('childNAge', ...); on the
 * product page the map comes from the booking_config controller. Historically
 * the controller camelCased 'childrens_ages' into 'childrensAges' (never read
 * by the JSX) and omitted 'child_n_age' entirely, so the English fallbacks
 * compiled into the bundle won on the Romanian storefront. These pins keep the
 * emission aligned with the JSX keys, and keep the lang sources carrying both
 * keys for the self-seed.
 */
final class BookingConfigTranslationsTest extends TestCase
{
    private static function addonRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    public function testControllerEmitsChildNAgeAndChildrenAgesAlias(): void
    {
        $path = self::addonRoot() . '/controllers/frontend/travel_booking.php';
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);

        self::assertStringContainsString("'child_n_age'", $src);
        self::assertStringContainsString(
            "\$translations['childrenAges'] = __('travel_core.childrens_ages');",
            $src,
        );
    }

    public function testLangKeysCarryBothGuestPickerLabels(): void
    {
        $path = self::addonRoot() . '/lang_keys.php';
        self::assertFileExists($path);
        $vars = require $path;
        self::assertIsArray($vars);

        foreach (['travel_core.childrens_ages', 'travel_core.child_n_age'] as $key) {
            self::assertArrayHasKey($key, $vars);
            self::assertIsArray($vars[$key]);
            self::assertArrayHasKey('en', $vars[$key]);
            self::assertArrayHasKey('ro', $vars[$key]);
            self::assertNotSame('', trim((string) $vars[$key]['ro']));
        }

        self::assertSame('Vârsta copilului la check-in', $vars['travel_core.childrens_ages']['ro']);
        self::assertSame('Selectează vârsta copilului [n]', $vars['travel_core.child_n_age']['ro']);
    }

    public function testLangKeysSeedTheRawAdminLabels(): void
    {
        $vars = require self::addonRoot() . '/lang_keys.php';
        self::assertIsArray($vars);

        // Keys that rendered raw ("_travel_core.hotel_name" / "_back") until
        // seeded here — lang_keys.php membership bumps the seed hash so the
        // init.php probe reseeds them onto already-installed stores.
        $expected = [
            'travel_core.hotel_name' => 'Nume hotel',
            'travel_core.back'       => 'Înapoi',
            'travel_core.appearance_page_title' => 'Culori formular rezervare',
        ];
        foreach ($expected as $key => $ro) {
            self::assertArrayHasKey($key, $vars, "{$key} must be seeded via lang_keys.php");
            self::assertIsArray($vars[$key]);
            self::assertNotSame('', trim((string) ($vars[$key]['en'] ?? '')));
            self::assertSame($ro, $vars[$key]['ro']);
        }
    }
}

<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the persistence contract of the booking-styles controller.
 *
 * Source of truth is CS-Cart's ?:storage_data KV
 * (fn_travel_core_save/get_appearance_colors): the Settings API proved
 * unreliable for these addon-section rows on live installs — updateValue()
 * "saves" landed only in the per-area registry cache, so the admin form kept
 * the colors while the storefront (a separate cache) rendered defaults and
 * any cache clear reset the form too. Both admin form and storefront must
 * therefore read the SAME canonical helper.
 *
 * The Settings write stays as best-effort secondary sync and must keep the
 * auto_create=true 4th argument — the 3-arg form silently drops writes for
 * rows that were never seeded (PHPStan cannot catch this: Tygh\Settings is
 * an unknown core class, globally ignored in phpstan.neon).
 */
final class BookingStylesSaveIdiomTest extends TestCase
{
    private static function controllerSource(): string
    {
        $controller = dirname(__DIR__, 3) . '/controllers/backend/travel_booking_styles.php';
        self::assertFileExists($controller);

        return (string) file_get_contents($controller);
    }

    public function testSaveAndManageGoThroughTheCanonicalAppearanceStore(): void
    {
        $src = self::controllerSource();

        self::assertStringContainsString(
            'fn_travel_core_save_appearance_colors($toSave)',
            $src,
            'The save handler must persist through the storage-backed helper — Settings/Registry alone lose the values on cache clear.',
        );
        self::assertStringContainsString(
            'fn_travel_core_get_appearance_colors()',
            $src,
            'The manage form must read the same canonical store the storefront renders from.',
        );
    }

    public function testStorefrontRenderersReadTheCanonicalStore(): void
    {
        $hotels = (string) file_get_contents(dirname(__DIR__, 3) . '/functions/hotels.php');
        self::assertStringContainsString(
            '$tc = fn_travel_core_get_appearance_colors();',
            $hotels,
            'The inline booking-engine renderer must read the canonical store, not the registry map.',
        );

        $hooks = (string) file_get_contents(dirname(__DIR__, 3) . '/hooks/cart_hooks.php');
        self::assertStringContainsString(
            "assign('_addons_travel_core'",
            $hooks,
            'The PDP hook must inject the canonical colors — booking_engine.tpl prefers $_addons_travel_core over the registry-backed $addons global.',
        );
    }

    public function testSecondarySettingsWriteKeepsAutoCreate(): void
    {
        $src = self::controllerSource();

        self::assertStringContainsString(
            '->updateValue($settingName, $value, \'travel_core\', true)',
            $src,
            'The best-effort Settings sync must pass auto_create=true — the 3-arg form silently drops writes for rows that were never seeded.',
        );
        self::assertDoesNotMatchRegularExpression(
            "/->updateValue\\([^)]*'travel_core'\\s*\\)/",
            $src,
            'Found a 3-argument updateValue call — every settings write must use the auto_create idiom.',
        );
    }
}

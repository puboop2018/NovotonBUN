<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the ?travel_debug=1 diagnostic surface.
 *
 * The visible debug panel renders through the products:product_detail_bottom
 * theme anchor — exactly the thing that's broken when someone is debugging a
 * missing booking form. The console emit therefore rides the index:scripts
 * hook (fires on every storefront page, any theme), and the payload must
 * carry the fields that answer the three real-world questions: which theme is
 * active, is the master switch on, and did any provider claim the product.
 */
final class TravelDebugSurfaceTest extends TestCase
{
    private static function repoRoot(): string
    {
        return dirname(__DIR__, 7);
    }

    public function testConsoleEmitRidesTheAlwaysFiringScriptsHookInBothThemes(): void
    {
        foreach (['responsive', 'nova_theme'] as $theme) {
            $path = self::repoRoot()
                . "/addon-travel-core/design/themes/{$theme}/templates/addons/travel_core/hooks/index/scripts.post.tpl";
            self::assertFileExists($path);
            $tpl = (string) file_get_contents($path);

            self::assertStringContainsString('{if $travel_debug_enabled && $travel_debug_output}', $tpl, $theme);
            self::assertStringContainsString("console.log('[travel_debug]'", $tpl, $theme);
            // </ must be broken up so a value containing "</script>" cannot
            // terminate the inline script block.
            self::assertStringContainsString("|replace:'</':'<\\/' nofilter", $tpl, $theme);
        }
    }

    public function testDebugPayloadCarriesThemeSettingsAndProviderRegistry(): void
    {
        $src = (string) file_get_contents(
            self::repoRoot() . '/addon-travel-core/app/addons/travel_core/hooks/cart_hooks.php',
        );

        // Theme + the two display settings that gate the mount.
        self::assertStringContainsString("\$debug['theme']", $src);
        self::assertStringContainsString("'show_booking_form' =>", $src);
        self::assertStringContainsString("'booking_form_position' =>", $src);

        // Provider-registry introspection (empty registry must still say so).
        self::assertStringContainsString("\$debug['registered_providers']", $src);
        self::assertStringContainsString('TravelProviderRegistry::all()', $src);

        // File checks must follow the ACTIVE theme, not hardcoded responsive,
        // and must cover the mount + location-line templates.
        self::assertStringContainsString('design/themes/{$checkTheme}/templates', $src);
        self::assertStringContainsString('main_info_title.post.tpl', $src);
        self::assertStringContainsString('booking_form_mount.tpl', $src);
    }
}

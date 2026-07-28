<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins that the geocoding switch is reachable on an ALREADY-INSTALLED store.
 *
 * Field failure: the three settings were declared in addon.xml, but CS-Cart
 * only creates settings rows from that file at install/upgrade time — it never
 * re-reads it on a code deploy or a cache clear. On the live store the Travel
 * Core settings page ended at "Display Settings" and there was no checkbox to
 * tick, so `geocode_hotels` could only ever answer "Geocoding is disabled".
 *
 * Fix mirrors what the booking-form colors already do: ?:storage_data is the
 * source of truth (no upgrade needed, shared by both areas — the cron runs in
 * 'C'), addon settings remain a fallback, and the form lives on the Tools page
 * which is plain template + controller and therefore always present.
 */
final class GeocodingSettingsReachTest extends TestCase
{
    private static function src(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
    }

    public function testSettingsAreStorageBackedNotOnlyAddonXml(): void
    {
        $fn = self::src('functions/geocoding.php');
        self::assertStringContainsString("const TRAVEL_CORE_GEOCODING_STORAGE_KEY = 'travel_core_geocoding'", $fn);
        self::assertStringContainsString('fn_get_storage_data(TRAVEL_CORE_GEOCODING_STORAGE_KEY)', $fn);
        self::assertStringContainsString('fn_set_storage_data(TRAVEL_CORE_GEOCODING_STORAGE_KEY', $fn);
        // Registry (addon.xml settings) stays as the fallback so a fresh
        // install, where the rows DO exist, behaves identically.
        self::assertStringContainsString("Registry::get('addons.travel_core')", $fn);

        // Loaded for every request, including the storefront cron.
        self::assertStringContainsString(
            "require_once __DIR__ . '/functions/geocoding.php';",
            self::src('init.php'),
        );
    }

    public function testConfigReadsThroughTheStorageAwareHelper(): void
    {
        $config = self::src('src/Services/TravelCoreConfig.php');
        self::assertStringContainsString('fn_travel_core_get_geocoding_settings()', $config);
        // The three getters must not go straight back to Registry.
        self::assertStringNotContainsString("Registry::get('addons.travel_core.geocoding_enabled') === 'Y';", $config);
    }

    public function testToolsPageExposesTheFormAndRefusesEnablingWithoutAContact(): void
    {
        $controller = self::src('controllers/backend/travel_tools.php');
        self::assertStringContainsString("\$mode === 'save_geocoding'", $controller);
        self::assertStringContainsString('fn_travel_core_save_geocoding_settings(', $controller);
        // OSM requires an identifiable contact; enabling without one would
        // produce a switch that is on but refuses to run.
        self::assertStringContainsString('FILTER_VALIDATE_EMAIL', $controller);
        self::assertStringContainsString('travel_core.geocoding_email_required', $controller);
        // The manage view needs the current values to render the form.
        self::assertStringContainsString("assign('geocoding', fn_travel_core_get_geocoding_settings())", $controller);

        $tpl = (string) file_get_contents(
            dirname(__DIR__, 6)
            . '/design/backend/templates/addons/travel_core/views/travel_tools/manage.tpl',
        );
        self::assertStringContainsString('travel_tools.save_geocoding', $tpl);
        self::assertStringContainsString('name="geocoding[enabled]"', $tpl);
        self::assertStringContainsString('name="geocoding[contact_email]"', $tpl);
        self::assertStringContainsString('name="geocoding[endpoint]"', $tpl);
        // An unchecked checkbox posts nothing, so the hidden 'N' companion is
        // what lets the switch be turned back OFF.
        self::assertStringContainsString('<input type="hidden" name="geocoding[enabled]" value="N" />', $tpl);
    }

    public function testLabelsShipThroughTheRuntimeLangSeederNotOnlyAddonXml(): void
    {
        // Language keys — unlike settings — do have a runtime seeder, so
        // lang_keys.php is what reaches an installed store.
        $lang = self::src('lang_keys.php');
        foreach ([
            'travel_core.geocoding_section',
            'travel_core.geocoding_enabled',
            'travel_core.geocoding_contact_email',
            'travel_core.geocoding_endpoint',
            'travel_core.geocoding_saved',
            'travel_core.geocoding_email_required',
        ] as $key) {
            self::assertStringContainsString("'{$key}' => [", $lang, "{$key} must be runtime-seeded");
        }
    }
}

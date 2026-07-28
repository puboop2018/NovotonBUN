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
 * Fix: SettingsMigrator creates the missing rows at runtime, so Settings →
 * Travel Core → Geocoding is the single home. ?:storage_data survives only as
 * a read-only legacy fallback for values captured while the Tools page briefly
 * carried the form, and the reader is loaded from init.php for every request
 * — including the storefront cron, which runs in area 'C'.
 */
final class GeocodingSettingsReachTest extends TestCase
{
    private static function src(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
    }

    public function testTheReaderIsLoadedInEveryAreaIncludingTheCron(): void
    {
        $fn = self::src('functions/geocoding.php');
        self::assertStringContainsString("const TRAVEL_CORE_GEOCODING_STORAGE_KEY = 'travel_core_geocoding'", $fn);
        self::assertStringContainsString('fn_get_storage_data(TRAVEL_CORE_GEOCODING_STORAGE_KEY)', $fn);
        // Registry (the settings rows) is what the reader answers from.
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

    /**
     * Geocoding is configured in ONE place: Settings -> Travel Core.
     *
     * The Tools page briefly carried a duplicate form, because the settings
     * rows did not exist on installed stores and that page was the only
     * surface guaranteed to render. SettingsMigrator removed the need for it,
     * and two switches for one behaviour is worse than an awkward location.
     */
    public function testGeocodingIsConfiguredOnlyInSettings(): void
    {
        $controller = self::src('controllers/backend/travel_tools.php');
        self::assertStringNotContainsString('save_geocoding', $controller);
        self::assertStringNotContainsString('geocoding', $controller);

        $tpl = (string) file_get_contents(
            dirname(__DIR__, 6)
            . '/design/backend/templates/addons/travel_core/views/travel_tools/manage.tpl',
        );
        self::assertStringNotContainsString('geocoding', $tpl);

        // The settings themselves stay declared, so the settings page renders
        // them and the migrator can heal them onto older stores.
        $xml = self::src('addon.xml');
        foreach (['geocoding_header', 'geocoding_enabled', 'geocoding_contact_email', 'geocoding_endpoint'] as $name) {
            self::assertStringContainsString('<item id="' . $name . '">', $xml);
        }
    }

    /**
     * Storage may only answer for a store whose settings rows do not exist
     * yet. If it could override a real setting, clearing a field in Settings
     * would silently resurrect the old Tools-page value.
     */
    public function testTheSettingWinsWheneverItExistsAndStorageIsLegacyOnly(): void
    {
        $fn = self::src('functions/geocoding.php');

        self::assertStringContainsString('array_key_exists($key, $registry)', $fn);
        self::assertStringContainsString("\$stored[\$key] ?? ''", $fn);
        // The writer is gone with the form it served.
        self::assertStringNotContainsString('function fn_travel_core_save_geocoding_settings', $fn);
    }

    /**
     * Settings-page labels live in the .po under the SettingsOptions /
     * SettingsTooltips scopes — the table CS-Cart's importer targets and the
     * one SettingsMigrator writes when healing. Without them the section
     * renders as blank rows, which is what happened in the field.
     */
    public function testSettingsLabelsExistInBothPoFiles(): void
    {
        foreach (['en', 'ro'] as $lang) {
            $po = (string) file_get_contents(
                dirname(__DIR__, 6) . '/var/langs/' . $lang . '/addons/travel_core.po',
            );
            foreach ([
                'SettingsOptions::travel_core::geocoding_header',
                'SettingsOptions::travel_core::geocoding_enabled',
                'SettingsOptions::travel_core::geocoding_contact_email',
                'SettingsOptions::travel_core::geocoding_endpoint',
            ] as $ctx) {
                self::assertStringContainsString($ctx, $po, "{$ctx} missing from {$lang}.po");
            }
        }
    }
}

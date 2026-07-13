<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the reverse-geocoding schema end to end: the street_address /
 * geocoded_at columns exist for fresh installs (addon.xml CREATE) AND for
 * upgrades (install.php $migrations), the dynamic-query whitelist knows
 * them, the PDP provider actually selects the street and feeds it to the
 * shared HotelSeoData->address (which flips HotelLocationLine to postal
 * style), the settings are declared opt-in, and the admin cron page lists
 * the new mode.
 */
final class GeocodeSchemaTest extends TestCase
{
    private static function addonRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function read(string $relative): string
    {
        $path = self::addonRoot() . '/' . $relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testFreshInstallCreateTableHasBothColumns(): void
    {
        $xml = self::read('addon.xml');

        self::assertStringContainsString('`street_address` varchar(255) DEFAULT NULL', $xml);
        self::assertStringContainsString('`geocoded_at` datetime DEFAULT NULL', $xml);
    }

    public function testUpgradeMigrationsAddBothColumns(): void
    {
        $install = self::read('functions/install.php');

        self::assertStringContainsString(
            'ALTER TABLE ?:novoton_hotels ADD COLUMN `street_address`',
            $install,
        );
        self::assertStringContainsString(
            'ALTER TABLE ?:novoton_hotels ADD COLUMN `geocoded_at`',
            $install,
        );
    }

    public function testDynamicQueryWhitelistKnowsTheNewColumns(): void
    {
        $helper = self::read('src/Helpers/DatabaseHelper.php');

        self::assertStringContainsString("'street_address', 'geocoded_at',", $helper);
    }

    public function testPdpProviderSelectsStreetAndFeedsDtoAddress(): void
    {
        $provider = self::read('src/Providers/NovotonHotelProductProvider.php');

        self::assertStringContainsString('street_address', $provider);
        self::assertStringContainsString("address: self::optString(\$row['street_address']", $provider);
    }

    public function testGeocodingSettingsAreDeclaredOptIn(): void
    {
        $xml = self::read('addon.xml');

        self::assertStringContainsString('<item id="geocoding_enabled">', $xml);
        // Opt-in: the checkbox must default to N (external HTTP + OSM
        // attribution obligation are conscious decisions, not surprises).
        $enabledPos = strpos($xml, '<item id="geocoding_enabled">');
        self::assertNotFalse($enabledPos);
        $enabledBlock = substr($xml, $enabledPos, 200);
        self::assertStringContainsString('<default_value>N</default_value>', $enabledBlock);

        self::assertStringContainsString('<item id="geocoding_contact_email">', $xml);
        self::assertStringContainsString('<item id="geocoding_endpoint">', $xml);
        self::assertStringContainsString('https://nominatim.openstreetmap.org', $xml);
    }

    public function testAdminCronPageListsTheMode(): void
    {
        $tpl = self::read('../../../design/backend/templates/addons/novoton_holidays/settings/cron_info.tpl');

        self::assertStringContainsString('geocode_addresses', $tpl);
    }

    public function testDashboardCronUrlsIncludeTheMode(): void
    {
        $controller = self::read('controllers/backend/novoton_holidays.php');
        self::assertStringContainsString("'geocode_addresses' =>", $controller);
        self::assertStringContainsString('mode=geocode_addresses', $controller);

        $dashboard = self::read('../../../design/backend/templates/addons/novoton_holidays/views/novoton_holidays/manage.tpl');
        self::assertStringContainsString('{$cron_urls.geocode_addresses}', $dashboard);
    }
}

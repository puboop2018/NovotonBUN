<?php
declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Guards the ownership of the shared product-page display settings.
 *
 * The booking form on hotel product pages is injected by travel_core for ALL
 * provider addons, so its display controls (show_booking_form,
 * booking_form_position) are travel_core settings. They were once dead
 * per-addon settings in novoton — this test keeps them defined in exactly one
 * place so per-provider duplicates cannot creep back.
 */
final class AddonSettingsTest extends TestCase
{
    private const SHARED_DISPLAY_SETTINGS = ['show_booking_form', 'booking_form_position'];

    private static function settingsXml(string $addonDir): \SimpleXMLElement
    {
        $repoRoot = dirname(__DIR__, 7);
        $path = "$repoRoot/$addonDir/addon.xml";
        self::assertFileExists($path);
        $xml = simplexml_load_file($path);
        self::assertNotFalse($xml, "$path must be well-formed XML");
        return $xml;
    }

    /** @return array<string, \SimpleXMLElement> setting id => item element */
    private static function settingItems(\SimpleXMLElement $addon): array
    {
        $items = [];
        foreach ($addon->xpath('//settings//item[@id]') ?: [] as $item) {
            $items[(string) $item['id']] = $item;
        }
        return $items;
    }

    public function testTravelCoreDefinesTheSharedDisplaySettings(): void
    {
        $items = self::settingItems(self::settingsXml('addon-travel-core/app/addons/travel_core'));

        self::assertArrayHasKey('show_booking_form', $items);
        self::assertSame('checkbox', (string) $items['show_booking_form']->type);
        self::assertSame('Y', (string) $items['show_booking_form']->default_value);

        self::assertArrayHasKey('booking_form_position', $items);
        $position = $items['booking_form_position'];
        self::assertSame('selectbox', (string) $position->type);
        self::assertSame('before_tabs', (string) $position->default_value);

        $variants = [];
        foreach ($position->variants->item ?? [] as $variant) {
            $variants[] = (string) $variant['id'];
        }
        sort($variants);
        self::assertSame(['after_description', 'before_tabs'], $variants);
    }

    public function testProviderAddonsDoNotRedefineTheSharedDisplaySettings(): void
    {
        $providers = [
            'addon-novoton-holidays/app/addons/novoton_holidays',
            'addon-sphinx-holidays/app/addons/sphinx_holidays',
        ];

        foreach ($providers as $addonDir) {
            $items = self::settingItems(self::settingsXml($addonDir));
            foreach (self::SHARED_DISPLAY_SETTINGS as $id) {
                self::assertArrayNotHasKey(
                    $id,
                    $items,
                    "$addonDir must not define '$id' — it is a shared travel_core setting",
                );
            }
        }
    }
}

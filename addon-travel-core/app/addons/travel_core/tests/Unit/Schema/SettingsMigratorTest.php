<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the settings self-heal against the real CS-Cart API.
 *
 * Field failure: travel_core's addon.xml declared 30 settings; the store's
 * database held 26. The four missing ones were the whole geocoding section,
 * so the switch could not be turned on from the settings page at all. CS-Cart
 * imports <settings> only at install/upgrade and never re-reads addon.xml on
 * a deploy, and this was the one delivery path with no self-heal (language
 * keys and the schema already had theirs).
 *
 * The API facts below were read from the store's own Tygh\Settings, not
 * assumed — in particular that update() DISCARDS 'value', which would have
 * created settings that exist but read empty.
 */
final class SettingsMigratorTest extends TestCase
{
    private static function src(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
    }

    public function testUsesTheSupportedCreationApiNotRawSql(): void
    {
        $m = self::src('src/Install/SettingsMigrator.php');

        // Section lookup, idempotency, creation — all through Tygh\Settings.
        self::assertStringContainsString('getSectionByName($addon, Settings::ADDON_SECTION)', $m);
        self::assertStringContainsString('$settings->isExists($name, $addon)', $m);
        self::assertStringContainsString('$settings->update(', $m);
        // No hand-written INSERT into the settings tables.
        self::assertStringNotContainsString('INSERT INTO ?:settings_', $m);
        self::assertStringNotContainsString('REPLACE INTO ?:settings_', $m);
    }

    /**
     * updateSettingObject() does `if (!empty($data['value'])) unset($data['value']);`
     * — a setting created in one call would exist and read empty, which is a
     * worse failure than the missing field because it looks configured.
     */
    public function testSeedsTheDefaultValueInASecondCallBecauseUpdateStripsIt(): void
    {
        $m = self::src('src/Install/SettingsMigrator.php');

        self::assertStringContainsString('$settings->updateValue($name, $item[\'default\'], $addon)', $m);
        self::assertStringContainsString("update() strips 'value'", $m);
    }

    /**
     * update() runs checkEdition() and silently returns false when it fails,
     * and handler/parent_id are NOT NULL. Copying the shape of an existing
     * sibling setting cannot fail either check.
     */
    public function testCopiesRowShapeFromAnExistingSiblingSetting(): void
    {
        $m = self::src('src/Install/SettingsMigrator.php');

        self::assertStringContainsString('siblingTemplate(', $m);
        self::assertStringContainsString("\$template['edition_type']", $m);
        self::assertStringContainsString("\$template['section_tab_id']", $m);
        self::assertStringContainsString("\$template['is_global']", $m);
        // NOT NULL columns REPLACE INTO would otherwise reject under strict mode.
        self::assertStringContainsString("'handler' => ''", $m);
        self::assertStringContainsString("'parent_id' => 0", $m);
    }

    public function testTypeMapUsesTheCsCartEnumNotLiteralChars(): void
    {
        $m = self::src('src/Install/SettingsMigrator.php');

        self::assertStringContainsString('use Tygh\Enum\SettingTypes;', $m);
        foreach ([
            "'checkbox' => SettingTypes::CHECKBOX",
            "'input' => SettingTypes::INPUT",
            "'selectbox' => SettingTypes::SELECTBOX",
            "'header' => SettingTypes::HEADER",
        ] as $pair) {
            self::assertStringContainsString($pair, $m);
        }
    }

    public function testHealRunsForEveryTravelAddonAndIsStampGated(): void
    {
        $heal = self::src('functions/self_heal.php');
        self::assertStringContainsString('function fn_travel_core_ensure_all_settings()', $heal);
        foreach (['travel_core', 'novoton_holidays', 'sphinx_holidays'] as $addon) {
            self::assertStringContainsString("'{$addon}'", $heal);
        }

        $init = self::src('init.php');
        self::assertStringContainsString('fn_travel_core_ensure_all_settings()', $init);
        // Fingerprinted on all three addon.xml files, so declaring a new
        // setting anywhere re-arms the heal.
        self::assertStringContainsString("fn_travel_core_self_heal_due('travel_settings'", $init);
        self::assertStringContainsString("fn_travel_core_self_heal_stamp('travel_settings'", $init);
    }

    public function testLabelsComeFromThePoFilesTheImporterWouldHaveRead(): void
    {
        $m = self::src('src/Install/SettingsMigrator.php');

        // CS-Cart routes msgctxt scopes to settings_descriptions; the healed
        // rows must land in the same table or the page renders raw keys.
        self::assertStringContainsString('Settings(Options|Tooltips)::', $m);
        self::assertStringContainsString('Settings::SETTING_DESCRIPTION', $m);
        self::assertStringContainsString('updateDescription', $m);
    }
}

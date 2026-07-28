<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Install;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Enum\SettingTypes;
use Tygh\Settings;

/**
 * Creates settings that addon.xml declares but the database never got.
 *
 * CS-Cart imports an addon's <settings> only when the addon is INSTALLED or
 * UPGRADED. It does not re-read addon.xml on a code deploy or a cache clear,
 * and this project deploys by pulling code. So every setting added after a
 * store's install is invisible there: the field is absent from the addon's
 * settings page and there is no way to set it. That is what left the
 * reverse-geocoding switch unreachable in the field.
 *
 * Language keys and the schema each already self-heal for exactly this
 * reason; settings were the one delivery path with no repair. This closes it.
 *
 * Written against the real CS-Cart API (Tygh\Settings):
 *   - getSectionByName($addon, ADDON_SECTION)  find the addon's section
 *   - isExists($name, $addon)                  idempotency — only fill gaps
 *   - update($data, $variants, $descriptions)  CREATES when object_id is
 *                                              omitted, returns the new id
 *   - updateValue($name, $value, $addon)       REQUIRED as a second call:
 *                                              update() deliberately unsets
 *                                              'value', so a setting created
 *                                              in one call would read empty
 *
 * Two deliberate robustness choices:
 *
 *  1. edition_type / section_tab_id / is_global are COPIED from a setting
 *     that already exists in the same section instead of being guessed.
 *     update() runs checkEdition() and silently returns false when that
 *     fails; reusing whatever CS-Cart already accepted for this addon cannot
 *     fail that check, and keeps new fields on the same tab as their
 *     neighbours.
 *  2. New settings are positioned after the last existing one, so healed
 *     fields appear at the end of the section in addon.xml order rather than
 *     interleaving unpredictably.
 *
 * Labels come from the addon's .po files, where CS-Cart's own importer would
 * have read them (msgctxt "SettingsOptions::<addon>::<name>" and
 * "SettingsTooltips::<addon>::<name>"), and are written through
 * updateDescription() — the same table that importer targets.
 */
final class SettingsMigrator
{
    /** addon.xml <type> → ?:settings_objects.type (Tygh\Enum\SettingTypes). */
    private const array TYPE_MAP = [
        'checkbox' => SettingTypes::CHECKBOX,
        'input' => SettingTypes::INPUT,
        'password' => SettingTypes::PASSWORD,
        'textarea' => SettingTypes::TEXTAREA,
        'selectbox' => SettingTypes::SELECTBOX,
        'multiple checkboxes' => SettingTypes::MULTIPLE_CHECKBOXES,
        'multiple select' => SettingTypes::MULTIPLE_SELECT,
        'header' => SettingTypes::HEADER,
        'info' => SettingTypes::INFO,
        'number' => SettingTypes::NUMBER,
        'template' => SettingTypes::TEMPLATE,
        'hidden' => SettingTypes::HIDDEN,
    ];

    /** @var array<string, true> one attempt per addon per request */
    private static array $done = [];

    /**
     * Create every setting addon.xml declares and the database lacks.
     *
     * @param string $addon Addon name, e.g. 'travel_core'
     * @param string $addonDir Directory holding addon.xml
     * @param string $langsDir var/langs root that holds <lc>/addons/<addon>.po
     * @return list<string> names of the settings created (empty when in sync)
     */
    public static function ensure(string $addon, string $addonDir, string $langsDir): array
    {
        if (isset(self::$done[$addon])) {
            return [];
        }
        self::$done[$addon] = true;

        $declared = self::parseAddonXml($addonDir . '/addon.xml');
        if ($declared === []) {
            return [];
        }

        $settings = Settings::instance();
        if (!$settings instanceof Settings) {
            return [];
        }

        $section = $settings->getSectionByName($addon, Settings::ADDON_SECTION);
        $sectionId = is_array($section) ? TypeCoerce::toInt($section['section_id'] ?? 0) : 0;
        if ($sectionId <= 0) {
            return []; // addon not installed — nothing to heal into
        }

        $template = self::siblingTemplate($sectionId);
        $position = TypeCoerce::toInt($template['position'] ?? 0);
        $labels = self::parsePoLabels($langsDir, $addon);

        $created = [];
        foreach ($declared as $item) {
            $name = $item['name'];
            if ($name === '') {
                continue;
            }

            if ($settings->isExists($name, $addon)) {
                // Present already — but possibly LABEL-LESS or VALUE-LESS. The
                // first heal on a Windows checkout parsed no labels (CRLF, see
                // below) and created these fields blank; isExists() alone would
                // then skip them forever. Repair both, never overwriting
                // anything an admin has actually set.
                $repaired = self::repairDescriptions($addon, $name, $labels);
                $repaired = self::repairValue($addon, $name, $item['default']) || $repaired;
                if ($repaired) {
                    $created[] = $name;
                }

                continue;
            }

            $type = self::TYPE_MAP[strtolower($item['type'])] ?? SettingTypes::INPUT;
            $position += 10;

            $objectId = $settings->update(
                [
                    'name' => $name,
                    'section_id' => $sectionId,
                    'section_tab_id' => TypeCoerce::toInt($template['section_tab_id'] ?? 0),
                    'type' => $type,
                    'position' => $position,
                    'is_global' => TypeCoerce::toString($template['is_global'] ?? 'N'),
                    'edition_type' => TypeCoerce::toString($template['edition_type'] ?? 'ROOT'),
                    // Both NOT NULL with no usable default: update() writes via
                    // REPLACE INTO, which under strict SQL mode fails on an
                    // omitted NOT NULL column. Neither applies to a plain
                    // addon setting, so they are set to their empty forms.
                    'handler' => '',
                    'parent_id' => 0,
                ],
                self::variantRows($item['variants']),
                self::descriptionRows($labels, $name),
            );

            if (!is_int($objectId) || $objectId <= 0) {
                continue;
            }

            // update() strips 'value' on purpose — seed the default separately
            // or the setting exists but reads empty (worse than missing).
            if ($item['default'] !== '') {
                $settings->updateValue($name, $item['default'], $addon);
            }

            $created[] = $name;
        }

        return $created;
    }

    /**
     * Seed an existing setting's default when it holds no value at all.
     *
     * A setting created before its default could be applied reads as an empty
     * field, which looks like a deliberate blank. Only a genuinely empty value
     * is filled — an admin who cleared a field on purpose, or set anything at
     * all, is never overridden. (An unticked checkbox stores 'N', not '', so
     * this cannot silently re-enable something.)
     */
    private static function repairValue(string $addon, string $name, string $default): bool
    {
        if ($default === '') {
            return false;
        }

        $settings = Settings::instance();
        if (!$settings instanceof Settings) {
            return false;
        }

        $current = $settings->getValue($name, $addon);
        if ($current !== null && $current !== '' && $current !== []) {
            return false;
        }

        $settings->updateValue($name, $default, $addon);

        return true;
    }

    /**
     * Give an existing setting its label back when it has none.
     *
     * Settings created by a heal that could not read the .po (CRLF) exist but
     * render as blank rows — a field with no name is arguably worse than a
     * missing one. Only writes when the setting genuinely has no description
     * in that language, so an admin-edited label is never overwritten.
     *
     * @param array<string, array<string, array{value: string, tooltip: string}>> $labels
     * @return bool whether anything was repaired
     */
    private static function repairDescriptions(string $addon, string $name, array $labels): bool
    {
        $rows = self::descriptionRows($labels, $name);
        if ($rows === null) {
            return false;
        }

        // Instances are cached by Settings::instance(), so re-fetching here
        // costs nothing and keeps the signature free of a kit-only type
        // (the CS-Cart source is not part of this repository).
        $settings = Settings::instance();
        if (!$settings instanceof Settings) {
            return false;
        }

        $objectId = TypeCoerce::toInt($settings->getId($name, $addon));
        if ($objectId <= 0) {
            return false;
        }

        $repaired = false;
        foreach ($rows as $row) {
            $existing = $settings->getDescription(
                $objectId,
                TypeCoerce::toString(Settings::SETTING_DESCRIPTION),
                $row['lang_code'],
            );
            if (is_string($existing) && trim($existing) !== '') {
                continue; // already labelled — leave admin edits alone
            }

            $row['object_id'] = (string) $objectId;
            $settings->updateDescription($row);
            $repaired = true;
        }

        return $repaired;
    }

    /**
     * A setting already living in this section, whose shape new rows copy.
     *
     * @return array<string, mixed>
     */
    private static function siblingTemplate(int $sectionId): array
    {
        $row = db_get_row(
            'SELECT edition_type, section_tab_id, is_global
             FROM ?:settings_objects WHERE section_id = ?i
             ORDER BY position DESC LIMIT 1',
            $sectionId,
        );
        $row = TypeCoerce::toStringMap($row);

        $row['position'] = TypeCoerce::toInt(db_get_field(
            'SELECT MAX(position) FROM ?:settings_objects WHERE section_id = ?i',
            $sectionId,
        ));

        return $row;
    }

    /**
     * Settings declared in addon.xml, in document order.
     *
     * @return list<array{name: string, type: string, default: string, variants: list<string>}>
     */
    private static function parseAddonXml(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $xml = @simplexml_load_file($path);
        if ($xml === false || !isset($xml->settings->sections->section)) {
            return [];
        }

        $items = [];
        foreach ($xml->settings->sections->section as $section) {
            foreach ($section->items->item ?? [] as $item) {
                $variants = [];
                foreach ($item->variants->item ?? [] as $variant) {
                    $variants[] = (string) $variant['id'];
                }

                $items[] = [
                    'name' => (string) $item['id'],
                    'type' => (string) $item->type,
                    'default' => (string) $item->default_value,
                    'variants' => $variants,
                ];
            }
        }

        return $items;
    }

    /**
     * Labels + tooltips from the addon's .po files — the same source and the
     * same table CS-Cart's own importer uses for settings.
     *
     * @return array<string, array<string, array{value: string, tooltip: string}>>
     *                                                                             setting name => lang code => texts
     */
    private static function parsePoLabels(string $langsDir, string $addon): array
    {
        $out = [];
        foreach ((array) glob($langsDir . '/*/addons/' . $addon . '.po') as $file) {
            if (!is_string($file) || !is_file($file)) {
                continue;
            }
            $lang = basename(dirname($file, 2));
            $body = (string) file_get_contents($file);

            // \r?\n, not \n: a Windows checkout stores these files with CRLF
            // (there is no .gitattributes forcing LF), and a \n-only pattern
            // silently matches nothing — which is exactly how the first heal
            // created the settings with blank labels.
            $pattern = '/msgctxt "Settings(Options|Tooltips)::' . preg_quote($addon, '/')
                . '::([a-zA-Z0-9_]+)"\r?\nmsgid "(?:[^"\\\\]|\\\\.)*"\r?\nmsgstr "((?:[^"\\\\]|\\\\.)*)"/';
            if (preg_match_all($pattern, $body, $matches, PREG_SET_ORDER) === false) {
                continue;
            }

            foreach ($matches as $m) {
                $name = $m[2];
                $text = str_replace(['\\"', '\\\\'], ['"', '\\'], $m[3]);
                $out[$name][$lang] ??= ['value' => '', 'tooltip' => ''];
                $out[$name][$lang][$m[1] === 'Options' ? 'value' : 'tooltip'] = $text;
            }
        }

        return $out;
    }

    /**
     * @param array<string, array<string, array{value: string, tooltip: string}>> $labels
     * @return list<array<string, string>>|null
     */
    private static function descriptionRows(array $labels, string $name): ?array
    {
        $perLang = $labels[$name] ?? [];
        if ($perLang === []) {
            return null;
        }

        $rows = [];
        foreach ($perLang as $lang => $texts) {
            $rows[] = [
                'object_type' => TypeCoerce::toString(Settings::SETTING_DESCRIPTION),
                'lang_code' => (string) $lang,
                'value' => $texts['value'],
                'tooltip' => $texts['tooltip'],
            ];
        }

        return $rows;
    }

    /**
     * Selectbox options — ?:settings_variants rows (name + position; the
     * object_id is injected by Settings::update()).
     *
     * @param list<string> $variants
     * @return list<array<string, string>>|null
     */
    private static function variantRows(array $variants): ?array
    {
        if ($variants === []) {
            return null;
        }

        $rows = [];
        $position = 0;
        foreach ($variants as $variant) {
            $position += 10;
            $rows[] = [
                'name' => $variant,
                'position' => (string) $position,
            ];
        }

        return $rows;
    }
}

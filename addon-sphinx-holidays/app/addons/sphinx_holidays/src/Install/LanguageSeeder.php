<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Install;

/**
 * Runtime language-key seeder for sphinx_holidays.
 *
 * Merges two sources (addon.xml wins on conflict — it is the maintained home
 * of settings labels):
 *   - addon.xml <language_variables>: settings labels/tooltips, section names
 *   - lang_keys.php: runtime keys used by pages that are not declared in
 *     addon.xml
 *
 * Also mirrors settings labels/tooltips into ?:settings_descriptions: CS-Cart
 * fills descriptions only when a settings object is created, so settings
 * shipped in later releases otherwise render with an empty label on existing
 * installations.
 *
 * Idempotent (INSERT ... ON DUPLICATE KEY UPDATE). Stamps the source content
 * hash into the sphinx_holidays._lang_seed_hash lang var; init.php probes
 * that stamp (via the fn_sphinx_holidays_* shells) and re-runs this seeder
 * whenever addon.xml or lang_keys.php change, so new labels appear on the
 * next admin page load without reinstalling.
 */
final class LanguageSeeder
{
    public static function seed(): void
    {
        $vars = self::variables();

        foreach ($vars as $name => $translations) {
            foreach ($translations as $lang_code => $value) {
                db_query(
                    'INSERT INTO ?:language_values (name, lang_code, value) VALUES (?s, ?s, ?s)
                     ON DUPLICATE KEY UPDATE value = ?s',
                    $name,
                    $lang_code,
                    $value,
                    $value,
                );
            }
        }

        // Mirror settings labels into settings_descriptions for every settings
        // object of this addon that has a matching sphinx_holidays.{name} lang var.
        // @db_query: defensive, like the settings_objects migrations in novoton —
        // a schema mismatch must never break the admin panel.
        $objects = db_get_array(
            "SELECT object_id, name FROM ?:settings_objects
             WHERE section_id IN (SELECT section_id FROM ?:settings_sections WHERE name = 'sphinx_holidays')",
        );
        foreach (is_array($objects) ? $objects : [] as $object) {
            if (!is_array($object)) {
                continue;
            }
            $key = 'sphinx_holidays.' . \Tygh\Addons\TravelCore\Helpers\TypeCoerce::toString($object['name'] ?? '');
            if (empty($vars[$key])) {
                continue;
            }
            foreach ($vars[$key] as $lang_code => $label) {
                $tooltip = $vars[$key . '.tooltip'][$lang_code] ?? '';
                @db_query(
                    "INSERT INTO ?:settings_descriptions (object_id, object_type, lang_code, value, tooltip)
                     VALUES (?i, 'O', ?s, ?s, ?s)
                     ON DUPLICATE KEY UPDATE value = ?s, tooltip = ?s",
                    $object['object_id'],
                    $lang_code,
                    $label,
                    $tooltip,
                    $label,
                    $tooltip,
                );
            }
        }

        // Stamp the seeded content so the init.php probe knows when to re-seed.
        $hash = self::seedHash();
        db_query(
            "INSERT INTO ?:language_values (name, lang_code, value) VALUES (?s, 'en', ?s)
             ON DUPLICATE KEY UPDATE value = ?s",
            'sphinx_holidays._lang_seed_hash',
            $hash,
            $hash,
        );

        // Labels are served through CS-Cart's registry cache; clear it so the
        // (re)seeded values render immediately instead of after a manual cc.
        if (function_exists('fn_clear_cache')) {
            fn_clear_cache();
        }
    }

    /**
     * All sphinx language variables from both sources, merged.
     *
     * @return array<string, array<string, string>> name => [lang_code => value]
     */
    public static function variables(): array
    {
        /** @var array<string, array<string, string>> $vars */
        $vars = require self::addonRoot() . '/lang_keys.php';

        $xml = @simplexml_load_file(self::addonRoot() . '/addon.xml');
        if ($xml !== false && isset($xml->language_variables)) {
            foreach ($xml->language_variables->item as $item) {
                $name = (string) $item['id'];
                $lang_code = (string) $item['lang'];
                if ($name === '' || $lang_code === '') {
                    continue;
                }
                $vars[$name][$lang_code] = (string) $item;
            }
        }

        return $vars;
    }

    /**
     * Content hash of the language-variable sources — the self-heal seed stamp.
     */
    public static function seedHash(): string
    {
        return md5(
            (string) @md5_file(self::addonRoot() . '/addon.xml')
            . (string) @md5_file(self::addonRoot() . '/lang_keys.php'),
        );
    }

    private static function addonRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}

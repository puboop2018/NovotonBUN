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
 * Idempotent (INSERT ... ON DUPLICATE KEY UPDATE). The label write itself —
 * resolving the store's installed languages, and refusing to stamp a language
 * whose values could not be read back — is shared with the other travel addons
 * in travel_core's LanguageDelivery; init.php probes that stamp (through
 * fn_travel_core_heal_language_keys) and re-runs this seeder whenever
 * addon.xml or lang_keys.php change, so new labels appear on the next page
 * load without reinstalling.
 */
final class LanguageSeeder
{
    public static function seed(): void
    {
        $vars = self::variables();

        // Label delivery (which installed language gets which source values,
        // and the read-back that must succeed before a language is stamped as
        // done) is shared with the other travel addons.
        \Tygh\Addons\TravelCore\Install\LanguageDelivery::seed(
            'sphinx_holidays._lang_seed_hash',
            $vars,
            self::seedHash(),
        );

        self::mirrorSettingsDescriptions($vars);
    }

    /**
     * Mirror settings labels into ?:settings_descriptions.
     *
     * CS-Cart fills descriptions only when a settings object is created, so
     * settings shipped in later releases otherwise render with an empty label
     * on existing installations.
     *
     * @param array<string, array<string, string>> $vars
     */
    public static function mirrorSettingsDescriptions(array $vars): void
    {
        // Every settings object of this addon that has a matching
        // sphinx_holidays.{name} lang var.
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
     * Fingerprint of the language-variable sources — the self-heal seed stamp.
     *
     * stat-based (size + mtime), not a content hash: the init.php probe runs
     * it on every admin request and addon.xml alone is hundreds of KB. Real
     * edits always touch size or mtime; a deploy that resets mtimes merely
     * re-arms one idempotent reseed.
     */
    public static function seedHash(): string
    {
        $parts = [];
        foreach ([self::addonRoot() . '/addon.xml', self::addonRoot() . '/lang_keys.php'] as $file) {
            $stat = @stat($file);
            $parts[] = $file . '|' . (is_array($stat) ? $stat['size'] . '|' . $stat['mtime'] : 'absent');
        }

        return md5(implode(';', $parts));
    }

    private static function addonRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}

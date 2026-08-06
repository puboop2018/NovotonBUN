<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Install;

use Tygh\Addons\FgoInvoicing\Helpers\TypeCoerce;

/**
 * Runtime language-key seeder for fgo_invoicing.
 *
 * Merges two sources (addon.xml wins on conflict — it is the maintained home
 * of settings labels):
 *   - addon.xml <language_variables>: settings labels, section names
 *   - lang_keys.php: runtime keys used by pages that are not declared in
 *     addon.xml (admin invoice list/detail, order-details panel, e-mail)
 *
 * Also mirrors settings labels into ?:settings_descriptions: CS-Cart fills
 * descriptions only when a settings object is CREATED, so a store that was
 * installed while the language pack was missing/broken keeps empty labels
 * forever — the whole settings form renders as bare ":" rows. That is the
 * exact failure this class repairs.
 *
 * Idempotent (INSERT ... ON DUPLICATE KEY UPDATE). Deliberately independent of
 * travel_core: fgo_invoicing is a horizontal addon that must install on shops
 * with no travel addons. When travel_core IS present, init.php routes the seed
 * through fn_travel_core_heal_language_keys() so we inherit its stamp throttle
 * and read-back verification; when it is absent we still mirror settings
 * descriptions at install time, and addon.xml's <name> elements keep labels
 * rendering regardless.
 */
final class LanguageSeeder
{
    /**
     * Force a full seed. Used by dev/tools/seed-langs.php and by anything that
     * needs the labels present right now rather than on the next probe.
     *
     * Falls back to mirroring settings descriptions alone when travel_core is
     * absent — that is the part that repairs the blank-label symptom, and it
     * needs no shared infrastructure.
     */
    public static function seed(): void
    {
        $vars = self::variables();

        if (class_exists(\Tygh\Addons\TravelCore\Install\LanguageDelivery::class)) {
            \Tygh\Addons\TravelCore\Install\LanguageDelivery::seed(
                'fgo_invoicing._lang_seed_hash',
                $vars,
                self::seedHash(),
            );
        }

        self::mirrorSettingsDescriptions($vars);
    }

    /**
     * All fgo language variables from both sources, merged.
     *
     * @return array<string, array<string, string>> name => [lang_code => value]
     */
    public static function variables(): array
    {
        $keysFile = self::addonRoot() . '/lang_keys.php';
        /** @var array<string, array<string, string>> $vars */
        $vars = is_file($keysFile) ? (array) require $keysFile : [];

        $xml = @simplexml_load_file(self::addonRoot() . '/addon.xml');
        if ($xml !== false && isset($xml->language_variables)) {
            foreach ($xml->language_variables->item as $item) {
                $name = (string) $item['id'];
                $langCode = (string) $item['lang'];
                if ($name === '' || $langCode === '') {
                    continue;
                }
                $vars[$name][$langCode] = (string) $item;
            }
        }

        return $vars;
    }

    /**
     * Fingerprint of the language-variable sources — the self-heal seed stamp.
     *
     * stat-based (size + mtime), not a content hash: the init.php probe runs it
     * on every request. Real edits always touch size or mtime; a deploy that
     * resets mtimes merely re-arms one idempotent reseed.
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

    /**
     * Mirror settings labels into ?:settings_descriptions.
     *
     * Without this, a settings object created with an empty description (which
     * is what happens when the .po was absent or unparseable at install time)
     * keeps that empty description forever, and the admin sees a form of blank
     * labels next to working widgets.
     *
     * @param array<string, array<string, string>>|null $vars pass null to load them
     */
    public static function mirrorSettingsDescriptions(?array $vars = null): void
    {
        if (!function_exists('db_get_array') || !function_exists('db_query')) {
            return;
        }

        $vars ??= self::variables();

        // @db_query throughout: a schema mismatch must never break the admin panel.
        $objects = @db_get_array(
            "SELECT object_id, name FROM ?:settings_objects
             WHERE section_id IN (SELECT section_id FROM ?:settings_sections WHERE name = 'fgo_invoicing')",
        );

        foreach (is_array($objects) ? $objects : [] as $object) {
            if (!is_array($object)) {
                continue;
            }
            $key = 'fgo_invoicing.' . TypeCoerce::toString($object['name'] ?? '');
            if (empty($vars[$key])) {
                continue;
            }
            foreach ($vars[$key] as $langCode => $label) {
                $tooltip = $vars[$key . '.tooltip'][$langCode] ?? '';
                @db_query(
                    "INSERT INTO ?:settings_descriptions (object_id, object_type, lang_code, value, tooltip)
                     VALUES (?i, 'O', ?s, ?s, ?s)
                     ON DUPLICATE KEY UPDATE value = ?s, tooltip = ?s",
                    $object['object_id'],
                    $langCode,
                    $label,
                    $tooltip,
                    $label,
                    $tooltip,
                );
            }
        }
    }

    private static function addonRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}

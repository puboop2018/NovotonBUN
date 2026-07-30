<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Install;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Delivers an addon's language variables to an ALREADY-INSTALLED store.
 *
 * CS-Cart imports addon.xml <language_variables> and the .po files exactly
 * once, at install. Every label added or changed in a later release is
 * therefore invisible to an existing store, and the storefront renders the raw
 * key ("_travel_core.your_booking_details") — CS-Cart's `__()` returns
 * '_' . $key for a missing variable, which is non-empty, so a template's
 * `|default:"…"` fallback never fires either. A runtime seeder is the only
 * delivery path, which makes it load-bearing: if it silently does nothing, the
 * customer sees raw keys forever.
 *
 * The previous per-addon seeders did silently do nothing. Three defects, all
 * fixed here:
 *
 *   1. They wrote the disarming stamp UNCONDITIONALLY, even when not a single
 *      label row had landed — permanently satisfying the init.php probe.
 *      Here the stamp is written only for languages whose values were read
 *      back from the database afterwards (see seed()).
 *   2. They wrote the literal lang codes found in lang_keys.php ('en', 'ro')
 *      and never looked at ?:languages. A store whose Romanian row is 'ro-RO'
 *      received ZERO matching rows while the stamp still landed under 'en'.
 *      Here every installed language is resolved to its best source language
 *      (see resolveLanguages()) and gets its own rows.
 *   3. The probe compared one stamp row against a size+mtime fingerprint, so
 *      it could never notice that the label rows themselves were missing.
 *      Here the stamp is stored PER LANGUAGE and isCurrent() asks the only
 *      question that matters: does every installed language carry this exact
 *      fingerprint? That recovers a store whose rows were lost without needing
 *      any file to change.
 *
 * Shared by all three addons — travel_core loads first, so its classes are
 * always available to the providers.
 */
final class LanguageDelivery
{
    /**
     * TRUE when every installed language already carries $fingerprint.
     *
     * Two plain single-table reads, compared in PHP. That is deliberate and it
     * is the second attempt at this method: the first asked the same question
     * with a LEFT JOIN carrying `?s` placeholders in its ON clause — the most
     * exotic SQL in these three addons — and the store then delivered exactly
     * nothing, not even the label upserts that run before any check. Whatever
     * the true cause, a PROBE is an optimisation; it must never be the reason
     * the delivery does not happen.
     *
     * Hence the try/catch: this fails OPEN to seeding. If the probe cannot
     * answer, the heal runs anyway — the seed is idempotent, so a needless run
     * costs a few hundred upserts once, while a needless skip costs a
     * storefront full of raw keys for months. A silent "current" is precisely
     * the bug this class exists to remove.
     */
    public static function isCurrent(string $stampName, string $fingerprint): bool
    {
        try {
            $installed = self::storeLanguages();
            if ($installed === []) {
                return false;
            }

            $stamps = self::rowsByLanguage($stampName);

            foreach ($installed as $code) {
                if (($stamps[$code] ?? null) !== $fingerprint) {
                    return false;
                }
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * One language variable's value in every language that has a row.
     *
     * @return array<string, string> lang_code => value
     */
    public static function rowsByLanguage(string $name): array
    {
        $rows = db_get_array('SELECT lang_code, value FROM ?:language_values WHERE name = ?s', $name);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = TypeCoerce::toString($row['lang_code'] ?? '');
            if (trim($code) !== '') {
                $out[trim($code)] = TypeCoerce::toString($row['value'] ?? '');
            }
        }

        return $out;
    }

    /**
     * Upsert every variable for every installed language, verify, then stamp.
     *
     * The return carries enough detail for the failure log and the dev tool to
     * say WHY rather than only THAT: the first version reported two bare lists,
     * and a silent store then told us nothing at all.
     *
     * @param array<string, array<string, string>> $vars name => [source lang => value]
     *
     * @return array{seeded: list<string>, failed: list<string>, canary: string, written: int, detail: array<string, string>}
     */
    public static function seed(string $stampName, array $vars, string $fingerprint): array
    {
        $result = ['seeded' => [], 'failed' => [], 'canary' => '', 'written' => 0, 'detail' => []];
        if ($vars === []) {
            return $result;
        }

        $canary = self::canaryKey($vars);
        $result['canary'] = $canary;

        foreach (self::resolveLanguages($vars) as $storeLang => $sourceLang) {
            foreach ($vars as $name => $translations) {
                $value = $translations[$sourceLang] ?? null;
                if (!is_string($value)) {
                    continue;
                }
                db_query(
                    'INSERT INTO ?:language_values (name, lang_code, value) VALUES (?s, ?s, ?s)'
                    . ' ON DUPLICATE KEY UPDATE value = ?s',
                    $name,
                    $storeLang,
                    $value,
                    $value,
                );
                $result['written']++;
            }

            // Read the canary back before claiming success. A write that the
            // database rejected (wrong collation, missing language row, denied
            // grant) must NOT disarm the probe — defect 1 above.
            $written = db_get_field(
                'SELECT value FROM ?:language_values WHERE name = ?s AND lang_code = ?s LIMIT 1',
                $canary,
                $storeLang,
            );
            $expected = $vars[$canary][$sourceLang] ?? '';

            if (!is_scalar($written) || (string) $written !== $expected) {
                $result['failed'][] = $storeLang;
                $result['detail'][$storeLang] = sprintf(
                    'read-back of "%s" gave %s, expected "%s"',
                    $canary,
                    is_scalar($written) ? '"' . (string) $written . '"' : get_debug_type($written),
                    $expected,
                );

                continue;
            }

            db_query(
                'INSERT INTO ?:language_values (name, lang_code, value) VALUES (?s, ?s, ?s)'
                . ' ON DUPLICATE KEY UPDATE value = ?s',
                $stampName,
                $storeLang,
                $fingerprint,
                $fingerprint,
            );
            $result['seeded'][] = $storeLang;
            $result['detail'][$storeLang] = 'seeded from source "' . $sourceLang . '"';
        }

        // Labels are served through CS-Cart's registry cache; clear it so the
        // (re)seeded values render on the next request rather than after a
        // manual cache clear.
        if ($result['seeded'] !== [] && function_exists('fn_clear_cache')) {
            fn_clear_cache();
        }

        return $result;
    }

    /**
     * Map every installed store language to the source language it should use.
     *
     * Ladder, per installed code: exact match ('ro' => 'ro') → same base
     * ('ro-RO' => 'ro', and 'ro' => 'ro_RO' if that is how the source is
     * spelled) → 'en' → the first source language. A store language with no
     * plausible source still gets English labels, which beats a raw key.
     *
     * Falls back to seeding the source languages themselves when ?:languages
     * cannot be read, which is the pre-existing behaviour.
     *
     * @param array<string, array<string, string>> $vars
     * @param null|list<string> $installed the store's languages; read from
     *                                     ?:languages when omitted (injectable so the ladder itself is
     *                                     testable without a database)
     *
     * @return array<string, string> installed lang_code => source lang key
     */
    public static function resolveLanguages(array $vars, ?array $installed = null): array
    {
        $sources = self::sourceLanguages($vars);
        if ($sources === []) {
            return [];
        }

        $bases = [];
        foreach ($sources as $source) {
            $bases[self::baseCode($source)] ??= $source;
        }
        $default = in_array('en', $sources, true) ? 'en' : $sources[0];

        $installed ??= self::storeLanguages();
        if ($installed === []) {
            $installed = $sources;
        }

        $map = [];
        foreach ($installed as $code) {
            if (in_array($code, $sources, true)) {
                $map[$code] = $code;

                continue;
            }
            $map[$code] = $bases[self::baseCode($code)] ?? $default;
        }

        return $map;
    }

    /**
     * Language codes the store actually has.
     *
     * Every row, not only the active ones: a language an admin has hidden can
     * be re-enabled at any time, and seeding it costs one upsert per label.
     *
     * @return list<string>
     */
    public static function storeLanguages(): array
    {
        $rows = @db_get_fields('SELECT lang_code FROM ?:languages');
        if (!is_array($rows)) {
            return [];
        }

        $codes = [];
        foreach ($rows as $row) {
            $code = is_scalar($row) ? trim((string) $row) : '';
            if ($code !== '' && !in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * The label whose presence proves a seed actually landed.
     *
     * Deterministic (first key by name) so the same row is checked on every
     * run and by the tests, rather than whichever key happened to be first in
     * the source file.
     *
     * @param array<string, array<string, string>> $vars
     */
    public static function canaryKey(array $vars): string
    {
        $names = array_keys($vars);
        sort($names, SORT_STRING);

        return (string) ($names[0] ?? '');
    }

    /**
     * @param array<string, array<string, string>> $vars
     *
     * @return list<string>
     */
    private static function sourceLanguages(array $vars): array
    {
        $langs = [];
        foreach ($vars as $translations) {
            foreach (array_keys($translations) as $lang) {
                $lang = trim((string) $lang);
                if ($lang !== '' && !in_array($lang, $langs, true)) {
                    $langs[] = $lang;
                }
            }
        }
        sort($langs, SORT_STRING);

        return $langs;
    }

    /** 'ro-RO' / 'ro_RO' / 'RO' all reduce to 'ro'. */
    private static function baseCode(string $code): string
    {
        $base = strtolower(trim($code));
        $cut = strcspn($base, '-_');

        return substr($base, 0, $cut);
    }
}

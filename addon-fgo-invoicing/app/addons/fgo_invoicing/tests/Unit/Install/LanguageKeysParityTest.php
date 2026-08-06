<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Install;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * lang_keys.php (the runtime self-heal source) and the two .po packs (the
 * install-time source) must describe the same labels — this pins them together.
 *
 * Drift here is exactly the bug this addon shipped with: a key present in one
 * delivery path but not the other renders blank (settings) or as a raw key
 * (pages) on whichever store used the other path.
 */
#[CoversNothing]
final class LanguageKeysParityTest extends TestCase
{
    private const ADDON_ROOT = __DIR__ . '/../../..';

    /**
     * @return array<string, array<string, string>> key => [lang => text]
     */
    private static function loadLangKeys(): array
    {
        /** @var array<string, array<string, string>> $vars */
        $vars = require self::ADDON_ROOT . '/lang_keys.php';

        return $vars;
    }

    /**
     * @return array<string, string> key => msgstr
     */
    private static function parsePo(string $lang): array
    {
        $path = self::ADDON_ROOT . '/../../../var/langs/' . $lang . '/addons/fgo_invoicing.po';
        self::assertFileExists($path, "missing {$lang} language pack");
        $src = (string) file_get_contents($path);
        $out = [];
        preg_match_all(
            '/msgctxt "Languages::(fgo_invoicing\.[a-z0-9_]+)"\nmsgid "(?:[^"\\\\]|\\\\.)*"\nmsgstr "((?:[^"\\\\]|\\\\.)*)"/',
            $src,
            $matches,
            PREG_SET_ORDER,
        );
        foreach ($matches as $m) {
            $out[$m[1]] = str_replace('\\"', '"', $m[2]);
        }

        return $out;
    }

    public function testLangKeysAndPoPacksCarryTheSameKeys(): void
    {
        $keys = self::loadLangKeys();
        $en = self::parsePo('en');
        $ro = self::parsePo('ro');

        self::assertNotEmpty($keys);
        self::assertSame([], array_diff_key($keys, $en), 'keys in lang_keys.php missing from en po');
        self::assertSame([], array_diff_key($en, $keys), 'keys in en po missing from lang_keys.php');
        self::assertSame(array_keys($en), array_keys($ro), 'en/ro po key sets differ');
    }

    public function testLangKeysTextsMatchThePoPacks(): void
    {
        $keys = self::loadLangKeys();
        $en = self::parsePo('en');
        $ro = self::parsePo('ro');

        foreach ($keys as $key => $texts) {
            self::assertSame($en[$key] ?? null, $texts['en'] ?? null, "en text drift for {$key}");
            self::assertSame($ro[$key] ?? null, $texts['ro'] ?? null, "ro text drift for {$key}");
        }
    }

    public function testEveryLangKeyHasBothLanguages(): void
    {
        foreach (self::loadLangKeys() as $key => $texts) {
            self::assertArrayHasKey('en', $texts, $key);
            self::assertArrayHasKey('ro', $texts, $key);
            self::assertNotSame('', trim($texts['en']), $key);
            self::assertNotSame('', trim($texts['ro']), $key);
        }
    }

    /**
     * Every settings item/section/variant declared in addon.xml must carry a
     * <name> fallback. CS-Cart's XmlScheme3 falls back to (string) $node->name
     * when the .po lookup misses; without it the settings form renders as bare
     * ":" rows next to working widgets.
     */
    public function testEverySettingDeclaresANameFallback(): void
    {
        $xml = simplexml_load_file(self::ADDON_ROOT . '/addon.xml');
        self::assertNotFalse($xml);

        foreach ($xml->settings->sections->section as $section) {
            self::assertNotSame('', trim((string) $section->name), 'section ' . $section['id']);

            foreach ($section->items->item as $item) {
                $id = (string) $item['id'];
                self::assertNotSame('', trim((string) $item->name), "item {$id}");

                if (!isset($item->variants->item)) {
                    continue;
                }
                foreach ($item->variants->item as $variant) {
                    self::assertNotSame(
                        '',
                        trim((string) $variant->name),
                        "variant {$id}::{$variant['id']}",
                    );
                }
            }
        }
    }

    /**
     * REGRESSION: a standalone `#` comment line immediately followed by a blank
     * line makes CS-Cart's PO parser abort the ENTIRE file.
     *
     * I18n_Pofile::read() (app/lib/other/poparser/poparser.php) treats a blank
     * line as an end-of-entry marker, and a `#` line opens a new entry
     * ($entry['tcomment'][] = ...; $just_new_entry = false). So comment + blank
     * closes an entry that has only a tcomment and no msgid, and the parser
     * hard-returns the STRING "Error in line: N. Expecting msgid in <file>"
     * instead of an array.
     *
     * Every downstream symptom this addon shipped with came from that one
     * string: the install-time "Expecting msgid" fatal, the
     * "foreach() argument must be of type array|object, string given" warning,
     * the "array_merge(): Argument #1 must be of type array, string given"
     * uninstall crash, and the entirely blank settings form.
     *
     * Section separators in a .po must therefore never be followed by a blank
     * line — the sibling addons simply use none.
     */
    public function testPoPacksHaveNoStandaloneCommentFollowedByBlankLine(): void
    {
        foreach (['en', 'ro'] as $lang) {
            $path = self::ADDON_ROOT . '/../../../var/langs/' . $lang . '/addons/fgo_invoicing.po';
            self::assertFileExists($path);

            $lines = explode("\n", (string) file_get_contents($path));
            foreach ($lines as $i => $line) {
                if (!isset($line[0]) || $line[0] !== '#') {
                    continue;
                }
                $next = $lines[$i + 1] ?? '';
                self::assertNotSame(
                    '',
                    trim($next),
                    sprintf(
                        'PO parser killer in %s pack: standalone comment on line %d ("%s") is followed by a '
                        . 'blank line, which aborts parsing of the whole file.',
                        $lang,
                        $i + 1,
                        $line,
                    ),
                );
            }
        }
    }

    /**
     * <language_variables> must not contain a two-level dotted id whose parent
     * is also present as a plain string: CS-Cart explodes dotted ids into a
     * nested array and uninstall then dies in array_merge(string, array).
     */
    public function testNoNestedDottedLanguageVariableIds(): void
    {
        $xml = simplexml_load_file(self::ADDON_ROOT . '/addon.xml');
        self::assertNotFalse($xml);

        $ids = [];
        foreach ($xml->language_variables->item as $item) {
            $ids[(string) $item['id']] = true;
        }

        foreach (array_keys($ids) as $id) {
            if (substr_count($id, '.') < 1) {
                continue;
            }
            $parent = preg_replace('/\.[^.]+$/', '', $id);
            if ($parent === 'fgo_invoicing') {
                continue; // addon-name base is the established sibling pattern
            }
            self::assertArrayNotHasKey(
                $parent,
                $ids,
                "nested dotted id '{$id}' collides with string id '{$parent}' — breaks uninstall",
            );
        }
    }
}

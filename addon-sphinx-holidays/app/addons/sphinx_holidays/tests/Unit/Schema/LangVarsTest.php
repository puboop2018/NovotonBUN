<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Every `sphinx_holidays.<key>` language variable referenced via __() in PHP
 * or templates must be DEFINED in one of the addon's three lang sources:
 * lang_keys.php (runtime keys, seeded/re-seeded by hash), addon.xml
 * <language_variables> (settings labels), or the var/langs .po files.
 * An undefined key renders as a raw "_sphinx_holidays.<key>" label on the
 * storefront (regression: offer_no_longer_available, searching_please_wait
 * and ~50 more booking strings were missing everywhere).
 */
final class LangVarsTest extends TestCase
{
    public function testEveryReferencedLangKeyIsDefined(): void
    {
        $addonRoot = dirname(__DIR__, 3);
        $repoAddonRoot = dirname(__DIR__, 6);

        $referenced = [];
        foreach (self::sourceFiles($addonRoot, $repoAddonRoot . '/design') as $path) {
            $source = (string) file_get_contents($path);
            if (preg_match_all("/__\\(\\s*['\"]sphinx_holidays\\.([a-z0-9_]+)/", $source, $m)) {
                foreach ($m[1] as $key) {
                    $referenced[$key] = true;
                }
            }
        }
        self::assertNotEmpty($referenced, 'sanity: the addon references lang keys');

        $defined = [];
        $langKeys = (string) file_get_contents($addonRoot . '/lang_keys.php');
        if (preg_match_all("/'sphinx_holidays\\.([a-z0-9_]+)'/", $langKeys, $m)) {
            foreach ($m[1] as $key) {
                $defined[$key] = true;
            }
        }
        $addonXml = (string) file_get_contents($addonRoot . '/addon.xml');
        if (preg_match_all('/id="sphinx_holidays\\.([a-z0-9_]+)"/', $addonXml, $m)) {
            foreach ($m[1] as $key) {
                $defined[$key] = true;
            }
        }
        $po = (string) file_get_contents($repoAddonRoot . '/var/langs/en/addons/sphinx_holidays.po');
        if (preg_match_all('/msgctxt "Languages::sphinx_holidays\\.([a-z0-9_]+)"/', $po, $m)) {
            foreach ($m[1] as $key) {
                $defined[$key] = true;
            }
        }

        $missing = array_keys(array_diff_key($referenced, $defined));
        sort($missing);

        self::assertSame(
            [],
            $missing,
            'lang keys referenced via __() but defined in no source (they render as raw _sphinx_holidays.* labels)',
        );
    }

    /** @return list<string> */
    private static function sourceFiles(string $appRoot, string $designRoot): array
    {
        $files = [];
        foreach ([$appRoot, $designRoot] as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo) {
                    continue;
                }
                $path = $file->getPathname();
                if (str_contains($path, '/vendor/') || str_contains($path, '/tests/')) {
                    continue;
                }
                if (in_array($file->getExtension(), ['php', 'tpl'], true)) {
                    $files[] = $path;
                }
            }
        }
        return $files;
    }
}

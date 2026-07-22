<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Support\PoFile;

/**
 * lang_keys.php is the CANONICAL translation source for this addon: the
 * self-seeder reaches already-installed stores (CS-Cart imports .po and
 * addon.xml only at install), so a key present only in .po drifts on
 * upgraded stores and a key present only in lang_keys.php never reaches
 * fresh installs. This test pins the two invariants that keep the three
 * sources coherent:
 *
 *  1. Every lang_keys.php entry exists in BOTH .po files with the EFFECTIVE
 *     seed value (addon.xml overrides lang_keys.php at seed time, so where
 *     addon.xml declares the key too, its value is the one .po must match).
 *  2. Where a key exists in both addon.xml and lang_keys.php, the values
 *     agree — a two-source disagreement means one edit silently lost.
 *
 * (The legacy .po-only corpus predating the self-seeder is deliberately NOT
 * forced into lang_keys.php: those keys reached every store at install; the
 * seeder matters for NEW and CHANGED keys, which land in lang_keys.php.)
 */
final class LangSeedParityTest extends TestCase
{
    private const ADDON = 'travel_core';

    public function testSeedSourcesAgreeAndReachFreshInstalls(): void
    {
        $addonRoot = dirname(__DIR__, 3);
        $repoAddonRoot = dirname(__DIR__, 6);

        /** @var array<string, array<string, string>> $langKeys */
        $langKeys = require $addonRoot . '/lang_keys.php';
        $poEn = PoFile::languages($repoAddonRoot . '/var/langs/en/addons/' . self::ADDON . '.po');
        $poRo = PoFile::languages($repoAddonRoot . '/var/langs/ro/addons/' . self::ADDON . '.po');
        self::assertNotSame([], $poEn, 'en .po must parse');
        self::assertNotSame([], $poRo, 'ro .po must parse');

        $xmlValues = [];
        $xml = simplexml_load_file($addonRoot . '/addon.xml');
        self::assertNotFalse($xml);
        if (isset($xml->language_variables)) {
            foreach ($xml->language_variables->item as $item) {
                $lang = (string) $item['lang'];
                if ($lang === 'en' || $lang === 'ro') {
                    $xmlValues[(string) $item['id']][$lang] = (string) $item;
                }
            }
        }

        $violations = [];
        foreach ($langKeys as $key => $values) {
            foreach (['en', 'ro'] as $lang) {
                $own = $values[$lang] ?? '';
                if ($own === '') {
                    $violations[] = "{$key}: empty {$lang} value in lang_keys.php";
                    continue;
                }
                if (isset($xmlValues[$key][$lang]) && $xmlValues[$key][$lang] !== $own) {
                    $violations[] = "{$key} [{$lang}]: addon.xml and lang_keys.php disagree";
                }
                $effective = $xmlValues[$key][$lang] ?? $own;
                $po = $lang === 'en' ? $poEn : $poRo;
                if (!isset($po[$key])) {
                    $violations[] = "{$key} [{$lang}]: missing from the .po (fresh installs never get it)";
                } elseif ($po[$key] !== $effective) {
                    $violations[] = "{$key} [{$lang}]: .po value drifted from the canonical seed value";
                }
            }
        }

        self::assertSame([], $violations, "lang seed sources drifted:\n" . implode("\n", $violations));
    }
}

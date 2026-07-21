<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Every travel_core.* language key referenced from a BACKEND template must be
 * present in a self-heal source — addon.xml <language_variables> OR
 * lang_keys.php — the exact union fn_travel_core_language_variables() seeds
 * into ?:language_values on the init.php hash probe.
 *
 * A key that lives ONLY in the .po files renders raw ("_travel_core.hotel_name")
 * on already-installed / upgraded stores: CS-Cart imports .po only at install,
 * and the self-heal seeder ignores .po. This guard bans that whole class —
 * it would have caught hotel_name (addon.xml-only, unseeded until a
 * lang_keys.php change) and the appearance-page labels (.po-only).
 */
final class AdminLangKeysSeededTest extends TestCase
{
    /** @return array<string, true> */
    private static function seededKeys(): array
    {
        $addonRoot = dirname(__DIR__, 3);

        $keys = [];

        // lang_keys.php
        $langKeys = require $addonRoot . '/lang_keys.php';
        foreach (array_keys(is_array($langKeys) ? $langKeys : []) as $key) {
            $keys[(string) $key] = true;
        }

        // addon.xml <language_variables><item id="…">
        $xml = simplexml_load_file($addonRoot . '/addon.xml');
        if ($xml !== false && isset($xml->language_variables)) {
            foreach ($xml->language_variables->item as $item) {
                $keys[(string) $item['id']] = true;
            }
        }

        return $keys;
    }

    public function testEveryBackendTravelCoreLabelIsSeeded(): void
    {
        $seeded = self::seededKeys();
        // Sanity: the union is non-trivial (parsing actually worked).
        self::assertArrayHasKey('travel_core.hotel_name', $seeded);

        $backendRoot = dirname(__DIR__, 6) . '/design/backend';
        self::assertDirectoryExists($backendRoot);

        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($backendRoot, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'tpl') {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            // Literal {__("travel_core.<key>")} references only — skip dynamic
            // {__("travel_core.`$x`")} interpolations.
            if (preg_match_all('/__\(\s*["\'](travel_core\.[a-z0-9_]+)["\']/i', $src, $m)) {
                foreach (array_unique($m[1]) as $key) {
                    if (!isset($seeded[$key])) {
                        $offenders[] = basename($file->getPathname()) . ' → ' . $key;
                    }
                }
            }
        }

        self::assertSame(
            [],
            array_values(array_unique($offenders)),
            'backend templates reference travel_core.* keys absent from addon.xml AND lang_keys.php — '
                . 'they render raw on installed stores; add them to lang_keys.php (bumps the seed hash)',
        );
    }
}

<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Install;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Install\CategoryOptionsBuilder;
use Tygh\Addons\SphinxHolidays\Install\FeatureAliasSeeder;
use Tygh\Addons\SphinxHolidays\Install\LanguageSeeder;
use Tygh\Addons\SphinxHolidays\Tests\Support\DbStub;

/**
 * Behavior tests for the install-time classes extracted from func.php —
 * category selectbox options, language-variable source merging and the
 * feature-alias seeding guards.
 */
final class InstallClassesTest extends TestCase
{
    protected function setUp(): void
    {
        DbStub::reset();
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    public function testCategoryOptionsBuildFullPathLabelsInTreeOrder(): void
    {
        DbStub::$getArray = static fn (): array => [
            ['category_id' => '1', 'id_path' => '1'],
            ['category_id' => '2', 'id_path' => '1/2'],
            ['category_id' => '3', 'id_path' => '1/2/3'],
        ];
        DbStub::$getHashSingleArray = static fn (): array => [
            1 => 'Hotels',
            2 => 'Albania',
            3 => 'Durres',
        ];

        $options = CategoryOptionsBuilder::build();

        self::assertSame('none', $options[0], 'the 0 => "None" option leads (the __ stub echoes keys)');
        self::assertSame('Hotels', $options[1]);
        self::assertSame('Hotels / Albania', $options[2]);
        self::assertSame('Hotels / Albania / Durres', $options[3]);
    }

    public function testCategoryOptionsFallBackToNoneWhenTheCatalogIsEmpty(): void
    {
        DbStub::$getArray = static fn (): array => [];

        self::assertSame([0 => 'none'], CategoryOptionsBuilder::build());
    }

    public function testLanguageVariablesMergeBothRealSources(): void
    {
        $vars = LanguageSeeder::variables();

        // lang_keys.php contributes runtime keys...
        $langKeys = require dirname(__DIR__, 3) . '/lang_keys.php';
        self::assertIsArray($langKeys);
        $sampleRuntimeKey = (string) array_key_first($langKeys);
        self::assertArrayHasKey($sampleRuntimeKey, $vars);

        // ...and addon.xml contributes settings labels on top (spot-check a
        // key that only exists in addon.xml's <language_variables>).
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/addon.xml');
        self::assertNotFalse($xml);
        $firstXmlItem = $xml->language_variables->item[0] ?? null;
        self::assertNotNull($firstXmlItem, 'addon.xml declares language variables');
        self::assertArrayHasKey((string) $firstXmlItem['id'], $vars);
    }

    public function testSeedHashIsStableAndTracksTheSourceFiles(): void
    {
        $hash = LanguageSeeder::seedHash();

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $hash);
        self::assertSame($hash, LanguageSeeder::seedHash(), 'same sources, same stamp');
    }

    public function testAliasSeedingSkipsWhenTheSharedTablesAreMissing(): void
    {
        // tableExists() answers 0 — both seeders must return without any
        // alias writes (the only db_query calls would be the alias upserts).
        DbStub::$getField = static fn (): int => 0;
        $writes = 0;
        DbStub::$query = static function () use (&$writes): int {
            $writes++;

            return 1;
        };

        FeatureAliasSeeder::seedAliases('pfx_');
        FeatureAliasSeeder::seedRegionMappings('pfx_');

        self::assertSame(0, $writes, 'no seeding writes when travel_core tables are absent');
    }
}
